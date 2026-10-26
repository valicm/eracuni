<?php

declare(strict_types=1);

namespace Drupal\eracuni\Drush\Commands;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\eracuni\Client\EracuniClientInterface;
use Drupal\eracuni\Client\EracuniException;
use Drupal\eracuni\Entity\EracuniDocumentInterface;
use Drupal\eracuni\Issuer;
use Drush\Attributes\Argument;
use Drush\Attributes\Command;
use Drush\Attributes\Usage;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Asks e-računi by hand: whether it answers, what is owed, one sale now.
 */
final class EracuniCommands extends DrushCommands {

  public function __construct(
    private readonly EracuniClientInterface $client,
    private readonly Issuer $issuer,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get(EracuniClientInterface::class),
      $container->get(Issuer::class),
      $container->get('entity_type.manager'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Whether e-računi answers, and how many documents are in each state.
   *
   * The call is a lookup that finds nothing: it proves the credentials and
   * reads no customer's invoice.
   */
  #[Command(name: 'eracuni:status')]
  #[Usage(name: 'drush eracuni:status', description: 'Credentials, and documents by status.')]
  public function status(): int {
    if (!$this->client->isConfigured()) {
      $this->io()->error('No credentials: $settings[\'eracuni\'] is not set.');
      return self::EXIT_FAILURE;
    }

    $today = date('Y-m-d', $this->time->getRequestTime());
    try {
      $this->client->call('SalesInvoiceList', [
        'orderReference' => 'eracuni-status-' . $this->time->getRequestTime(),
        'dateFrom' => $today,
        'dateTo' => $today,
      ]);
      $this->io()->text('e-računi answers.');
    }
    catch (EracuniException $e) {
      $this->io()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }

    $storage = $this->entityTypeManager->getStorage('eracuni_document');
    foreach ([EracuniDocumentInterface::PENDING, EracuniDocumentInterface::ISSUED, EracuniDocumentInterface::FAILED] as $status) {
      $count = $storage->getQuery()->accessCheck(FALSE)->condition('status', $status)->count()->execute();
      $this->io()->text(sprintf('%s: %d', $status, $count));
    }

    return self::EXIT_SUCCESS;
  }

  /**
   * Records a sale as owed and issues it now, without waiting for the queue.
   */
  #[Command(name: 'eracuni:issue')]
  #[Argument(name: 'source', description: 'The source: commerce_order, or a site\'s own.')]
  #[Argument(name: 'id', description: 'The sold thing\'s id within it.')]
  #[Argument(name: 'reference', description: 'Its reference, the order number. Only used when it has no document yet.')]
  #[Usage(name: 'drush eracuni:issue commerce_order 123 123', description: 'Issue order 123 now.')]
  public function issue(string $source, string $id, string $reference = ''): int {
    $document = $this->issuer->issue($this->issuer->owe($source, $id, $reference !== '' ? $reference : $id));

    $this->io()->text(sprintf('%s: %s %s %s', $document->getReference(), $document->getStatus(), $document->getNumber(), (string) $document->get('error')->value));
    $warnings = trim((string) $document->get('warnings')->value);
    if ($warnings !== '') {
      $this->io()->warning($warnings);
    }

    return $document->isIssued() ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
  }

  /**
   * Queues every pending or failed document due another attempt.
   */
  #[Command(name: 'eracuni:retry')]
  #[Usage(name: 'drush eracuni:retry', description: 'Queue what is due another attempt.')]
  public function retry(): int {
    $this->io()->text(sprintf('%d document(s) queued.', $this->issuer->retryDue()));
    return self::EXIT_SUCCESS;
  }

  /**
   * Finds invoices in e-računi by order reference, prefix included.
   */
  #[Command(name: 'eracuni:lookup')]
  #[Argument(name: 'reference', description: 'The order reference e-računi files it under.')]
  #[Argument(name: 'from', description: 'The first day to look at, Y-m-d. The filter is exact only within a date range.')]
  #[Usage(name: 'drush eracuni:lookup vallic-123 2026-10-01', description: 'Find the invoice for vallic-123.')]
  public function lookup(string $reference, string $from): int {
    $rows = $this->client->call('SalesInvoiceList', [
      'orderReference' => $reference,
      'dateFrom' => $from,
      'dateTo' => date('Y-m-d', $this->time->getRequestTime()),
    ]);

    foreach ($rows as $row) {
      $row = is_array($row) ? $row : [];
      $this->io()->text(sprintf('%s %s %s %s %s', $row['documentID'] ?? '', $row['number'] ?? '-', $row['status'] ?? '', $row['date'] ?? '', $row['documentAmount'] ?? ''));
    }
    $this->io()->text(sprintf('%d invoice(s).', count($rows)));

    return self::EXIT_SUCCESS;
  }

}
