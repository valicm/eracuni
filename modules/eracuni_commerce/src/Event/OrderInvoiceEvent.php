<?php

declare(strict_types=1);

namespace Drupal\eracuni_commerce\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\eracuni\Invoice\InvoiceRequest;

/**
 * An order's invoice, before it is sent: for a site to correct.
 *
 * Fired by `CommerceOrderSource` with what Commerce alone can say. A site
 * replaces the request where it knows better: whether the buyer is a
 * business (a team's own setting rather than an organisation field), a due
 * date it keeps on the order, the period a subscription's invoice supplies.
 */
final class OrderInvoiceEvent extends Event {

  /**
   * The event's name.
   */
  public const string NAME = 'eracuni_commerce.order_invoice';

  public function __construct(
    public readonly OrderInterface $order,
    public InvoiceRequest $request,
  ) {}

}
