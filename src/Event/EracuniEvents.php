<?php

declare(strict_types=1);

namespace Drupal\eracuni\Event;

/**
 * What the module announces.
 */
final class EracuniEvents {

  /**
   * An invoice was issued in e-računi. A `DocumentEvent`.
   */
  public const string ISSUED = 'eracuni.document.issued';

  /**
   * An invoice owed and still not issued after `alert_after_hours`.
   *
   * Once per document. Croatian law gives two days to fiscalise one that was
   * not, so this is for a person, and a site sends it where people will see
   * it.
   */
  public const string OVERDUE = 'eracuni.document.overdue';

}
