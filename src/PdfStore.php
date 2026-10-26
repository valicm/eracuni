<?php

declare(strict_types=1);

namespace Drupal\eracuni;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\eracuni\Client\EracuniClientInterface;
use Drupal\eracuni\Client\EracuniException;
use Drupal\eracuni\Entity\EracuniDocumentInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;

/**
 * The PDF of an issued invoice, kept on the site.
 *
 * Fetched once, after the invoice is issued, and kept in the private scheme
 * beside its document: the buyer downloads it from the site, which decides
 * who may, without the download waiting on e-računi or failing with it. A
 * document whose fetch failed is fetched when it is first asked for.
 *
 * The copy is the invoice as it was issued. e-računi stays the archive of
 * record, where a payment recorded later or a cancellation shows.
 */
final readonly class PdfStore {

  /**
   * The queue fetching runs on.
   */
  public const string QUEUE = 'eracuni_pdf';

  /**
   * Where the files go: a folder a month.
   */
  private const string DIRECTORY = 'private://eracuni';

  public function __construct(
    private EracuniClientInterface $client,
    private FileRepositoryInterface $fileRepository,
    private FileSystemInterface $fileSystem,
  ) {}

  /**
   * The document's PDF: the kept one, or fetched and kept now.
   *
   * @throws \Drupal\eracuni\Client\EracuniException
   *   When e-računi has none to give, or gave something that is not one.
   */
  public function get(EracuniDocumentInterface $document): FileInterface {
    $kept = $document->get('pdf')->entity;
    if ($kept instanceof FileInterface && file_exists($kept->getFileUri())) {
      return $kept;
    }

    return $this->fetch($document);
  }

  /**
   * Fetches the document's PDF from e-računi and keeps it.
   *
   * @throws \Drupal\eracuni\Client\EracuniException
   *   When e-računi has none to give, or gave something that is not one.
   */
  public function fetch(EracuniDocumentInterface $document): FileInterface {
    if (!$document->isIssued() || $document->isDraft() || $document->getDocumentId() === '') {
      // e-računi answers a draft's with "Access denied" (tried 26-10-2026).
      throw EracuniException::data(sprintf('%s is not an issued invoice, so it has no PDF.', $document->getReference()));
    }

    $result = $this->client->call('SalesInvoiceGetPDF', ['documentID' => $document->getDocumentId()]);
    $pdf = base64_decode((string) ($result['pdfFile'] ?? ''), TRUE);
    if ($pdf === FALSE || !str_starts_with($pdf, '%PDF-')) {
      throw EracuniException::transport(sprintf('e-računi gave no PDF for %s.', $document->getReference()));
    }

    $number = trim((string) ($result['number'] ?? ''));
    if ($document->getNumber() === '' && $number !== '') {
      $document->set('number', $number);
    }

    $directory = self::DIRECTORY . '/' . date('Y-m', (int) $document->get('issued')->value);
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $file = $this->fileRepository->writeData($pdf, $directory . '/' . self::fileName($document), FileExists::Rename);

    $document->set('pdf', $file);
    $document->save();

    return $file;
  }

  /**
   * What the file is called: the invoice's number, safe for a path.
   */
  public static function fileName(EracuniDocumentInterface $document): string {
    $name = $document->getNumber() !== '' ? $document->getNumber() : $document->getReference();

    return trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name), '-.') . '.pdf';
  }

}
