<?php

declare(strict_types=1);

namespace Drupal\eracuni;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\eracuni\Client\EracuniClientInterface;
use Drupal\eracuni\Client\EracuniException;
use Drupal\eracuni\Entity\EracuniDocumentInterface;
use Drupal\eracuni\Event\DocumentEvent;
use Drupal\eracuni\Event\EracuniEvents;
use Drupal\eracuni\Event\PayloadEvent;
use Drupal\eracuni\Invoice\PayloadBuilder;
use Drupal\eracuni\Source\SourceCollector;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Takes an invoice from owed to issued, and says when it is not.
 *
 * Owed is recorded first (`owe()`), in the request that made the sale, as a
 * pending document; e-računi is called later, from the queue, never inside a
 * checkout or a payment webhook. So a sale is never lost to a slow e-računi,
 * and the two-day alarm has a row to count from even when the queue never
 * ran.
 *
 * Issuing is safe to repeat. e-računi answers a create whose
 * `apiTransactionId` it has seen with the answer it gave the first time, so
 * an attempt whose answer was lost is sent again with the same id and gets
 * the invoice the first one made. It replays a refusal just as faithfully,
 * though, and nothing a site fixes afterwards would ever be read: so a
 * refused document is given a new id (`-r2`, `-r3`), which is safe because a
 * refusal made nothing (both tried on the demo account, 26-10-2026). A lock
 * keeps two workers from sending one document at once, and an issued
 * document is never sent again.
 */
final readonly class Issuer {

  /**
   * The queue issuing runs on.
   */
  public const string QUEUE = 'eracuni_issue';

  /**
   * Longer than an attempt can take: two calls of a minute each.
   */
  private const int LOCK_SECONDS = 150;

  /**
   * How long a pending document waits before cron sends it again.
   */
  private const int RETRY_AFTER = 900;

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private EracuniClientInterface $client,
    private PayloadBuilder $payloadBuilder,
    private SourceCollector $sources,
    private QueueFactory $queueFactory,
    private LockBackendInterface $lock,
    private ConfigFactoryInterface $configFactory,
    private TimeInterface $time,
    private EventDispatcherInterface $eventDispatcher,
    private LoggerInterface $logger,
  ) {}

  /**
   * Records that something sold is owed an invoice, and queues it.
   *
   * Called again for the same sale, it does nothing new: the document there
   * is is returned, and an issued one is not queued.
   *
   * @param string $sourceType
   *   The source's id.
   * @param string $sourceId
   *   The sold thing's id.
   * @param string $reference
   *   What the buyer knows it by, the order number.
   * @param int|null $due
   *   When it became owed; now when NULL. The two days count from here.
   */
  public function owe(string $sourceType, string $sourceId, string $reference, ?int $due = NULL): EracuniDocumentInterface {
    $document = $this->find($sourceType, $sourceId);

    if ($document === NULL) {
      $prefix = trim((string) ($this->configFactory->get('eracuni.settings')->get('reference_prefix') ?? ''));
      $document = $this->storage()->create([
        'source_type' => $sourceType,
        'source_id' => $sourceId,
        'reference' => $prefix . $reference,
        // Derived, not random: a row lost and recorded again carries the
        // same id, and e-računi still answers with the first invoice.
        'transaction_id' => mb_substr($prefix . $sourceType . '-' . $sourceId, 0, 120),
        'status' => EracuniDocumentInterface::PENDING,
        'due' => $due ?? $this->time->getRequestTime(),
      ]);
      $document->save();
    }

    assert($document instanceof EracuniDocumentInterface);
    if (!$document->isIssued()) {
      $this->queueFactory->get(self::QUEUE)->createItem(['document' => $document->id()]);
    }

    return $document;
  }

  /**
   * The document for a sold thing, if one was recorded.
   */
  public function find(string $sourceType, string $sourceId): ?EracuniDocumentInterface {
    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('source_type', $sourceType)
      ->condition('source_id', $sourceId)
      ->range(0, 1)
      ->execute();

    $document = $ids === [] ? NULL : $this->storage()->load(reset($ids));

    return $document instanceof EracuniDocumentInterface ? $document : NULL;
  }

  /**
   * Sends one document to e-računi, unless it is there already.
   *
   * Never throws for what e-računi or the source said: the outcome is on the
   * document, for cron to try again and the alarm to read.
   */
  public function issue(EracuniDocumentInterface $document): EracuniDocumentInterface {
    $name = 'eracuni_document:' . $document->id();
    if (!$this->lock->acquire($name, self::LOCK_SECONDS)) {
      return $document;
    }

    try {
      $fresh = $this->storage()->loadUnchanged($document->id());
      if (!$fresh instanceof EracuniDocumentInterface || $fresh->isIssued()) {
        return $fresh instanceof EracuniDocumentInterface ? $fresh : $document;
      }

      return $this->send($fresh);
    }
    finally {
      $this->lock->release($name);
    }
  }

  /**
   * Queues what is pending or failed and due another attempt.
   *
   * @return int
   *   How many were queued.
   */
  public function retryDue(): int {
    $max = (int) ($this->configFactory->get('eracuni.settings')->get('max_attempts') ?: 5);
    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', [EracuniDocumentInterface::PENDING, EracuniDocumentInterface::FAILED], 'IN')
      ->condition('attempts', $max, '<')
      ->condition('changed', $this->time->getRequestTime() - self::RETRY_AFTER, '<')
      ->execute();

    foreach ($ids as $id) {
      $this->queueFactory->get(self::QUEUE)->createItem(['document' => $id]);
    }

    return count($ids);
  }

  /**
   * Announces every document owed for longer than the site allows. Once each.
   *
   * @return int
   *   How many were announced.
   */
  public function alertOverdue(): int {
    $hours = (int) ($this->configFactory->get('eracuni.settings')->get('alert_after_hours') ?: 24);
    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', EracuniDocumentInterface::ISSUED, '<>')
      ->condition('due', $this->time->getRequestTime() - $hours * 3600, '<')
      ->notExists('alerted')
      ->execute();

    $announced = 0;
    foreach ($this->storage()->loadMultiple($ids) as $document) {
      assert($document instanceof EracuniDocumentInterface);
      $this->logger->error('@reference is owed an invoice since @due and e-računi has none: @error. Croatian law gives two days to fiscalise it.', [
        '@reference' => $document->getReference(),
        '@due' => date('Y-m-d H:i', (int) $document->get('due')->value),
        '@error' => (string) $document->get('error')->value ?: 'not sent yet',
      ]);
      $document->set('alerted', $this->time->getRequestTime());
      $document->save();
      $this->eventDispatcher->dispatch(new DocumentEvent($document), EracuniEvents::OVERDUE);
      $announced++;
    }

    return $announced;
  }

  /**
   * One attempt, and a second at once if the answer was lost on the way.
   */
  private function send(EracuniDocumentInterface $document): EracuniDocumentInterface {
    try {
      $request = $this->sources->get((string) $document->get('source_type')->value)->request((string) $document->get('source_id')->value);
      $built = $this->payloadBuilder->build($request, $document->getTransactionId());

      $event = new PayloadEvent($document, $request, $built['parameters']);
      $this->eventDispatcher->dispatch($event, EracuniEvents::PAYLOAD);
      // The id is what makes a second attempt find the first one's invoice.
      $parameters = ['apiTransactionId' => $document->getTransactionId()] + $event->parameters;
    }
    catch (EracuniException $e) {
      return $this->fail($document, $e, EracuniDocumentInterface::FAILED);
    }

    $document->set('treatment', $built['treatment']->value);
    $document->set('method_of_payment', $request->methodOfPayment);
    $document->set('warnings', implode("\n", $built['warnings']));

    $try = 0;
    while (TRUE) {
      $try++;
      $document->set('attempts', $document->getAttempts() + 1);

      try {
        $result = $this->client->call('SalesInvoiceCreate', $parameters);
        break;
      }
      catch (EracuniException $e) {
        // A lost answer is asked again with the same transaction id, which
        // returns the invoice if the first request made one. Twice in one
        // run; after that cron comes back for it.
        if ($e->isRetryable() && $try < 2) {
          continue;
        }

        if ($e->kind === EracuniException::REFUSED) {
          $document->set('transaction_id', self::nextTransactionId($document->getTransactionId()));
        }

        return $this->fail($document, $e, $e->isRetryable() ? EracuniDocumentInterface::PENDING : EracuniDocumentInterface::FAILED);
      }
    }

    $documentId = (string) ($result['documentID'] ?? '');
    if ($documentId === '') {
      return $this->fail($document, EracuniException::transport('e-računi said ok and gave no document ID.'), EracuniDocumentInterface::PENDING);
    }

    $document->set('status', EracuniDocumentInterface::ISSUED);
    $document->set('draft', ($parameters['SalesInvoice']['status'] ?? '') === 'Draft');
    $document->set('document_id', $documentId);
    $document->set('number', (string) ($result['number'] ?? ''));
    $document->set('issued', $this->time->getRequestTime());
    $document->set('error', NULL);
    $document->save();

    $this->logger->info('@reference issued in e-računi as @number (@id).', [
      '@reference' => $document->getReference(),
      '@number' => $document->getNumber() ?: 'a draft',
      '@id' => $documentId,
    ]);
    $this->eventDispatcher->dispatch(new DocumentEvent($document), EracuniEvents::ISSUED);
    if (!$document->isDraft()) {
      $this->queueFactory->get(PdfStore::QUEUE)->createItem(['document' => $document->id()]);
    }

    return $document;
  }

  /**
   * The id the next attempt sends after a refusal: the next `-rN`.
   */
  public static function nextTransactionId(string $current): string {
    if (preg_match('/^(.*)-r(\d+)$/', $current, $match) === 1) {
      return $match[1] . '-r' . ((int) $match[2] + 1);
    }

    return $current . '-r2';
  }

  /**
   * Records why an attempt did not issue it.
   */
  private function fail(EracuniDocumentInterface $document, EracuniException $e, string $status): EracuniDocumentInterface {
    $document->set('status', $status);
    $document->set('error', $e->getMessage());
    $document->save();

    $this->logger->warning('@reference was not issued in e-računi (@kind): @message', [
      '@reference' => $document->getReference(),
      '@kind' => $e->kind,
      '@message' => $e->getMessage(),
    ]);

    return $document;
  }

  /**
   * The documents' storage.
   */
  private function storage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('eracuni_document');
  }

}
