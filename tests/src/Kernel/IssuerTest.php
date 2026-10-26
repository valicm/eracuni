<?php

declare(strict_types=1);

namespace Drupal\Tests\eracuni\Kernel;

use Drupal\eracuni\Client\EracuniException;
use Drupal\eracuni\Entity\EracuniDocumentInterface;
use Drupal\eracuni\Event\EracuniEvents;
use Drupal\eracuni\Issuer;
use Drupal\eracuni\PdfStore;
use PHPUnit\Framework\Attributes\Group;

/**
 * From owed to issued, once, and an alarm when it is not.
 */
#[Group('eracuni')]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class IssuerTest extends EracuniKernelTestBase {

  /**
   * An owed sale is issued once; owed or issued again, it is not sent again.
   */
  public function testIssuedOnceAndNeverAgain(): void {
    $issuer = $this->container->get(Issuer::class);
    StubClient::$answers[] = ['documentID' => '60:1', 'number' => '2026-0001'];

    $document = $issuer->owe('stub', '7', '1007');
    $this->assertSame('test-1007', $document->getReference());
    $this->assertSame('test-stub-7', $document->getTransactionId());
    $this->assertSame(1, $this->container->get('queue')->get(Issuer::QUEUE)->numberOfItems());

    $issued = $issuer->issue($document);
    $this->assertTrue($issued->isIssued());
    $this->assertSame('2026-0001', $issued->getNumber());
    $this->assertSame('domestic_b2c', $issued->get('treatment')->value);
    $this->assertSame('test-stub-7', StubClient::$calls[0]['parameters']['apiTransactionId']);
    $this->assertSame(1, $this->container->get('queue')->get(PdfStore::QUEUE)->numberOfItems(), 'Its PDF is queued to be kept.');

    $again = $issuer->owe('stub', '7', '1007');
    $this->assertSame($document->id(), $again->id(), 'One document per sale.');
    $issuer->issue($again);
    $this->assertCount(1, StubClient::$calls, 'An issued document is not sent again.');
  }

  /**
   * An answer lost on the way is asked again at once, with the same id.
   */
  public function testLostAnswerIsAskedAgainWithTheSameId(): void {
    $issuer = $this->container->get(Issuer::class);
    StubClient::$answers[] = EracuniException::transport('Connection reset.');
    StubClient::$answers[] = ['documentID' => '60:2', 'number' => '2026-0002'];

    $document = $issuer->issue($issuer->owe('stub', '8', '1008'));

    $this->assertTrue($document->isIssued());
    $this->assertSame(2, $document->getAttempts());
    $this->assertCount(2, StubClient::$calls);
    $this->assertSame(StubClient::$calls[0]['parameters']['apiTransactionId'], StubClient::$calls[1]['parameters']['apiTransactionId']);
  }

  /**
   * A refusal is recorded, and the next attempt goes under a new id.
   *
   * The first answer to a transaction id is replayed by e-računi, a refusal
   * too, so the same id would be refused for ever whatever was fixed.
   */
  public function testRefusalIsRecordedAndRetriedUnderNewId(): void {
    $issuer = $this->container->get(Issuer::class);
    StubClient::$answers[] = EracuniException::refused('e-računi refused SalesInvoiceCreate: Unknown business unit.');

    $document = $issuer->issue($issuer->owe('stub', '9', '1009'));

    $this->assertSame(EracuniDocumentInterface::FAILED, $document->getStatus());
    $this->assertStringContainsString('Unknown business unit', (string) $document->get('error')->value);
    $this->assertCount(1, StubClient::$calls, 'A refusal is not asked again in the same run.');
    $this->assertSame('test-stub-9-r2', $document->getTransactionId());

    StubClient::$answers[] = ['documentID' => '60:9', 'number' => '2026-0009'];
    $this->assertTrue($issuer->issue($document)->isIssued());
    $this->assertSame('test-stub-9-r2', StubClient::$calls[1]['parameters']['apiTransactionId']);
    $this->assertSame('test-stub-9-r3', Issuer::nextTransactionId('test-stub-9-r2'));
  }

  /**
   * An answer lost on both tries keeps its id for cron's next attempt.
   */
  public function testLostTwiceKeepsItsId(): void {
    $issuer = $this->container->get(Issuer::class);
    StubClient::$answers[] = EracuniException::transport('Timed out.');
    StubClient::$answers[] = EracuniException::transport('Timed out.');

    $document = $issuer->issue($issuer->owe('stub', '12', '1012'));

    $this->assertSame(EracuniDocumentInterface::PENDING, $document->getStatus());
    $this->assertSame('test-stub-12', $document->getTransactionId(), 'It may have been made: the same id finds it.');
  }

  /**
   * Owed longer than the site allows, it is announced, once.
   */
  public function testOverdueIsAnnouncedOnce(): void {
    $issuer = $this->container->get(Issuer::class);
    $announced = [];
    $this->container->get('event_dispatcher')->addListener(EracuniEvents::OVERDUE, static function ($event) use (&$announced): void {
      $announced[] = $event->document->getReference();
    });

    $issuer->owe('stub', '10', '1010', $this->container->get('datetime.time')->getRequestTime() - 25 * 3600);
    $issuer->owe('stub', '11', '1011');

    $this->assertSame(1, $issuer->alertOverdue());
    $this->assertSame(['test-1010'], $announced, 'Only the one owed for 25 hours.');
    $this->assertSame(0, $issuer->alertOverdue(), 'Not announced twice.');
  }

}
