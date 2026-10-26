<?php

declare(strict_types=1);

namespace Drupal\eracuni\Source;

use Drupal\eracuni\Client\EracuniException;

/**
 * The sources the site has, by id.
 */
final class SourceCollector {

  /**
   * The sources, by id.
   *
   * @var array<string, \Drupal\eracuni\Source\InvoiceSourceInterface>
   */
  private array $sources = [];

  /**
   * Adds a source. Called by the container for each `eracuni.source`.
   */
  public function addSource(InvoiceSourceInterface $source): void {
    $this->sources[$source->id()] = $source;
  }

  /**
   * The source with an id.
   *
   * @throws \Drupal\eracuni\Client\EracuniException
   *   No such source: its module was uninstalled with documents still owed.
   */
  public function get(string $id): InvoiceSourceInterface {
    return $this->sources[$id] ?? throw EracuniException::configuration(sprintf('There is no e-računi source called %s.', $id));
  }

}
