<?php

declare(strict_types=1);

namespace Drupal\Tests\eracuni\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\eracuni\Client\EracuniException;
use Drupal\eracuni\Controller\PdfController;
use Drupal\eracuni\Entity\EracuniDocumentInterface;
use Drupal\eracuni\Issuer;
use Drupal\eracuni\PdfStore;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * An issued invoice's PDF, kept on the site and handed to its buyer.
 */
#[Group('eracuni')]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class PdfTest extends EracuniKernelTestBase {

  /**
   * What e-računi hands over: enough of a PDF to be taken for one.
   */
  private const string PDF = "%PDF-1.4\n% e-računi test\n";

  /**
   * Fetched once and kept privately, by the invoice's number.
   */
  public function testKeptOnceUnderItsNumber(): void {
    $document = $this->issued('13/EUG/261');
    StubClient::$answers[] = ['number' => '13/EUG/261', 'pdfFile' => base64_encode(self::PDF)];

    $store = $this->container->get(PdfStore::class);
    $file = $store->get($document);

    $this->assertStringStartsWith('private://eracuni/', $file->getFileUri());
    $this->assertStringEndsWith('/13-EUG-261.pdf', $file->getFileUri());
    $this->assertSame(self::PDF, file_get_contents($file->getFileUri()));
    $this->assertTrue($file->isPermanent());
    $this->assertSame(['documentID' => '60:1'], StubClient::$calls[1]['parameters']);

    $this->assertSame($file->id(), $store->get($document)->id());
    $this->assertCount(2, StubClient::$calls, 'Kept: e-računi is not asked again.');
  }

  /**
   * Something that is not a PDF is not kept.
   */
  public function testAnythingButPdfIsRefused(): void {
    $document = $this->issued('2026-0002');
    StubClient::$answers[] = ['number' => '2026-0002', 'pdfFile' => base64_encode('<html>Error</html>')];

    $this->expectException(EracuniException::class);
    $this->container->get(PdfStore::class)->get($document);
  }

  /**
   * The buyer and an admin may download it; nobody else, and nothing unissued.
   */
  public function testOnlyWhoMaySeeTheSaleDownloadsIt(): void {
    $controller = PdfController::create($this->container);
    $document = $this->issued('2026-0003');

    $this->assertTrue($controller->access($document, $this->account(StubSource::BUYER))->isAllowed(), 'The buyer.');
    $this->assertFalse($controller->access($document, $this->account(8))->isAllowed(), 'Someone else.');
    $this->assertTrue($controller->access($document, $this->account(9, TRUE))->isAllowed(), 'An admin.');

    $pending = $this->container->get(Issuer::class)->owe('stub', '20', '1020');
    $this->assertFalse($controller->access($pending, $this->account(9, TRUE))->isAllowed(), 'Not issued, not even an admin.');
  }

  /**
   * A draft has no PDF: none is fetched, and none can be downloaded.
   */
  public function testDraftHasNone(): void {
    $this->config('eracuni.settings')->set('status', 'Draft')->save();
    $document = $this->issued('');
    $this->assertTrue($document->isDraft());
    $this->assertSame(0, $this->container->get('queue')->get(PdfStore::QUEUE)->numberOfItems(), 'No fetch queued.');
    $this->assertFalse(PdfController::create($this->container)->access($document, $this->account(StubSource::BUYER))->isAllowed());

    try {
      $this->container->get(PdfStore::class)->get($document);
      $this->fail('A draft gave a PDF.');
    }
    catch (EracuniException) {
      $this->assertCount(1, StubClient::$calls, 'e-računi was not asked.');
    }
  }

  /**
   * A download is the kept file, named by the number; fetched if not kept.
   */
  public function testDownloadFetchesWhatWasNotKept(): void {
    $controller = PdfController::create($this->container);
    $document = $this->issued('2026-0004');
    StubClient::$answers[] = ['number' => '2026-0004', 'pdfFile' => base64_encode(self::PDF)];

    $response = $controller->download($document);
    $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    $this->assertStringContainsString('attachment; filename=2026-0004.pdf', (string) $response->headers->get('Content-Disposition'));
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    $this->assertSame(self::PDF, file_get_contents($response->getFile()->getPathname()));

    $other = $this->issued('2026-0005');
    StubClient::$answers[] = EracuniException::transport('Timed out.');
    $this->expectException(ServiceUnavailableHttpException::class);
    $controller->download($other);
  }

  /**
   * A document issued as the given number, without its PDF.
   */
  private function issued(string $number): EracuniDocumentInterface {
    static $sale = 0;
    $sale++;
    StubClient::$answers[] = ['documentID' => '60:' . $sale, 'number' => $number];

    $issuer = $this->container->get(Issuer::class);
    $document = $issuer->issue($issuer->owe('stub', (string) $sale, (string) (1000 + $sale)));
    $this->assertTrue($document->isIssued());

    return $document;
  }

  /**
   * An account with the given id, an admin of e-računi or not.
   */
  private function account(int $id, bool $admin = FALSE): AccountInterface {
    $account = $this->createStub(AccountInterface::class);
    $account->method('id')->willReturn($id);
    $account->method('hasPermission')->willReturnCallback(static fn (string $permission): bool => $admin && $permission === 'administer eracuni');

    return $account;
  }

}
