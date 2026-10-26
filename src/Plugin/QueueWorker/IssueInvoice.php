<?php

declare(strict_types=1);

namespace Drupal\eracuni\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\eracuni\Entity\EracuniDocumentInterface;
use Drupal\eracuni\Issuer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Issues one owed invoice in e-računi.
 *
 * Whatever happens is written on the document, never thrown: a refused
 * invoice retried by the queue at once would be refused again, and cron comes
 * back for the ones worth retrying (`Issuer::retryDue()`).
 */
#[QueueWorker(
  id: Issuer::QUEUE,
  title: new TranslatableMarkup('Issue e-računi invoices'),
  cron: ['time' => 60],
)]
final class IssueInvoice extends QueueWorkerBase implements ContainerFactoryPluginInterface {

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
   * @param \Drupal\eracuni\Issuer $issuer
   *   The issuer.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly Issuer $issuer,
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
      $container->get(Issuer::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $id = is_array($data) ? ($data['document'] ?? NULL) : NULL;
    $document = $id === NULL ? NULL : $this->entityTypeManager->getStorage('eracuni_document')->load($id);

    if ($document instanceof EracuniDocumentInterface) {
      $this->issuer->issue($document);
    }
  }

}
