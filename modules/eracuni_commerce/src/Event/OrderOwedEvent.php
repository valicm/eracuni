<?php

declare(strict_types=1);

namespace Drupal\eracuni_commerce\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\commerce_order\Entity\OrderInterface;

/**
 * An order about to be owed its invoice: for a site to decline.
 *
 * Fired once the gateway's settings say the order is invoiced now, and by the
 * backfill for every order it would record. A site that knows an order is not
 * a sale — one of its own accounts, or the agreement a subscription billed in
 * arrears was bought on — declines it with the reason, and no document is
 * made for it.
 */
final class OrderOwedEvent extends Event {

  /**
   * The event's name.
   */
  public const string NAME = 'eracuni_commerce.order_owed';

  /**
   * Why it is not invoiced, once a subscriber has said.
   */
  private ?string $declined = NULL;

  public function __construct(public readonly OrderInterface $order) {}

  /**
   * Not invoiced, for the reason given; the log says it.
   */
  public function decline(string $reason): void {
    $this->declined = $reason;
    $this->stopPropagation();
  }

  /**
   * Why it was declined, or NULL when it is invoiced.
   */
  public function reason(): ?string {
    return $this->declined;
  }

}
