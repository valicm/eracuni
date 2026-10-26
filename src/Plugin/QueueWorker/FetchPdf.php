<?php

declare(strict_types=1);

namespace Drupal\eracuni\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\eracuni\Client\EracuniException;
use Drupal\eracuni\Entity\EracuniDocumentInterface;
use Drupal\eracuni\PdfStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Keeps an issued invoice's PDF on the site.
 *
 * Once. A fetch that fails is logged and left: the PDF is fetched again the
 * first time someone asks to download it.
 */
#[QueueWorker(
  id: PdfStore::QUEUE,
  title: new TranslatableMarkup('Keep e-računi invoice PDFs'),
  cron: ['time' => 30],
)]
final class FetchPdf extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Builds the worker.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity types.
   * @param \Drupal\eracuni\PdfStore $pdfStore
   *   Where the PDFs are kept.
   * @param \Psr\Log\LoggerInterface $logger
   *   The e-računi log.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly PdfStore $pdfStore,
    protected readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get(PdfStore::class),
      $container->get('logger.channel.eracuni'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $id = is_array($data) ? ($data['document'] ?? NULL) : NULL;
    $document = $id === NULL ? NULL : $this->entityTypeManager->getStorage('eracuni_document')->load($id);
    if (!$document instanceof EracuniDocumentInterface) {
      return;
    }

    try {
      $this->pdfStore->get($document);
    }
    catch (EracuniException $e) {
      $this->logger->notice('The PDF of @reference was not kept: @message. It is fetched when it is first downloaded.', [
        '@reference' => $document->getReference(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
