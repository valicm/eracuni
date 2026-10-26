<?php

declare(strict_types=1);

namespace Drupal\eracuni\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\eracuni\Client\EracuniException;
use Drupal\eracuni\Entity\EracuniDocumentInterface;
use Drupal\eracuni\PdfStore;
use Drupal\eracuni\Source\SourceCollector;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Hands an issued invoice's PDF to whoever may see what it invoices.
 */
final readonly class PdfController implements ContainerInjectionInterface {

  public function __construct(
    private PdfStore $pdfStore,
    private SourceCollector $sources,
    private LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get(PdfStore::class),
      $container->get(SourceCollector::class),
      $container->get('logger.channel.eracuni'),
    );
  }

  /**
   * Issued, and the source lets this account see the sale; or an admin.
   */
  public function access(EracuniDocumentInterface $eracuni_document, AccountInterface $account): AccessResultInterface {
    $issued = AccessResult::allowedIf($eracuni_document->isIssued() && !$eracuni_document->isDraft())
      ->addCacheableDependency($eracuni_document);
    if (!$issued->isAllowed()) {
      return $issued;
    }

    $admin = AccessResult::allowedIfHasPermission($account, 'administer eracuni');
    if ($admin->isAllowed()) {
      return $issued->andIf($admin);
    }

    try {
      $source = $this->sources->get((string) $eracuni_document->get('source_type')->value);
    }
    catch (EracuniException) {
      return $issued->andIf($admin);
    }

    return $issued->andIf($admin->orIf($source->access((string) $eracuni_document->get('source_id')->value, $account)));
  }

  /**
   * The PDF, as a download named by the invoice's number.
   */
  public function download(EracuniDocumentInterface $eracuni_document): BinaryFileResponse {
    try {
      $file = $this->pdfStore->get($eracuni_document);
    }
    catch (EracuniException $e) {
      $this->logger->warning('The PDF of @reference could not be had: @message', [
        '@reference' => $eracuni_document->getReference(),
        '@message' => $e->getMessage(),
      ]);
      throw new ServiceUnavailableHttpException(300, 'The invoice cannot be fetched just now.');
    }

    $response = new BinaryFileResponse($file->getFileUri(), 200, [
      'Content-Type' => 'application/pdf',
      'Cache-Control' => 'private, no-store',
      'X-Content-Type-Options' => 'nosniff',
    ]);
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, PdfStore::fileName($eracuni_document));

    return $response;
  }

}
