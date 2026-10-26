<?php

declare(strict_types=1);

namespace Drupal\eracuni\Source;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\eracuni\Invoice\InvoiceRequest;

/**
 * Something a site sells, said as an invoice.
 *
 * A service tagged `eracuni.source`. The Commerce submodule is one; a site
 * that bills without Commerce writes its own. Asked for a request only when
 * the invoice is about to be issued, not when it is queued, so what is sent
 * is what is true then — an address corrected in between is the one used.
 */
interface InvoiceSourceInterface {

  /**
   * The source's id, stored on every document it gives rise to.
   */
  public function id(): string;

  /**
   * The invoice for one sold thing.
   *
   * @param string $id
   *   The sold thing's id within the source.
   *
   * @throws \Drupal\eracuni\Client\EracuniException
   *   It cannot be invoiced as it stands: gone, or not adding up.
   */
  public function request(string $id): InvoiceRequest;

  /**
   * Whether someone may download the invoice for one sold thing.
   *
   * Whoever may see the sale may see its invoice: the source knows who that
   * is. Those with "administer eracuni" may download any, whatever this says.
   *
   * @param string $id
   *   The sold thing's id within the source.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Who asks.
   */
  public function access(string $id, AccountInterface $account): AccessResultInterface;

}
