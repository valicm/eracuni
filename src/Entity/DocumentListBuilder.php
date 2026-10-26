<?php

declare(strict_types=1);

namespace Drupal\eracuni\Entity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Every invoice asked for, newest first, failures where they can be seen.
 */
final class DocumentListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  protected $limit = 50;

  /**
   * {@inheritdoc}
   *
   * @return array<int|string, int|string>
   *   Document IDs.
   */
  protected function getEntityIds(): array {
    $query = $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->sort('id', 'DESC');
    if ($this->limit) {
      $query->pager($this->limit);
    }

    return $query->execute();
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The header.
   */
  public function buildHeader(): array {
    return [
      'reference' => new TranslatableMarkup('Reference'),
      'status' => new TranslatableMarkup('Status'),
      'number' => new TranslatableMarkup('Number'),
      'treatment' => new TranslatableMarkup('VAT treatment'),
      'attempts' => new TranslatableMarkup('Attempts'),
      'error' => new TranslatableMarkup('Last error'),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The row.
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof EracuniDocumentInterface);

    return [
      'reference' => $entity->getReference(),
      'status' => $entity->getStatus(),
      'number' => $entity->getNumber(),
      'treatment' => (string) $entity->get('treatment')->value,
      'attempts' => $entity->getAttempts(),
      'error' => mb_substr((string) $entity->get('error')->value, 0, 200),
    ];
  }

}
