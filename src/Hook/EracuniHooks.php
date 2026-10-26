<?php

declare(strict_types=1);

namespace Drupal\eracuni\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\eracuni\Issuer;

/**
 * Hook implementations for e-računi.
 */
final readonly class EracuniHooks {

  public function __construct(
    private Issuer $issuer,
  ) {}

  /**
   * Implements hook_cron().
   *
   * Sends again what is due another attempt, then raises the alarm for what
   * has waited too long, in that order: a document the retry issues is not
   * announced as late.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->issuer->retryDue();
    $this->issuer->alertOverdue();
  }

}
