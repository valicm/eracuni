<?php

declare(strict_types=1);

namespace Drupal\eracuni_commerce\EventSubscriber;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Drupal\eracuni\Issuer;
use Drupal\eracuni_commerce\CommerceOrderSource;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Records an order as owed its invoice, at the moment its gateway says.
 *
 * Only records and queues: e-računi is called from the queue, never inside a
 * checkout or a payment webhook, where a slow answer would hold up the
 * payment and a failure would look like the payment's.
 */
final readonly class OrderSubscriber implements EventSubscriberInterface {

  public function __construct(
    private Issuer $issuer,
    private CommerceOrderSource $source,
    private TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   *
   * @return array<string, string>
   *   Events and their handlers.
   */
  public static function getSubscribedEvents(): array {
    return [
      OrderEvents::ORDER_PAID => 'onPaid',
      'commerce_order.place.post_transition' => 'onPlaced',
    ];
  }

  /**
   * A card order is owed its invoice once it is paid.
   */
  public function onPaid(OrderEvent $event): void {
    $this->owe($event->getOrder(), 'paid');
  }

  /**
   * An order paid later, by transfer, is owed its invoice once it is placed.
   */
  public function onPlaced(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if ($order instanceof OrderInterface) {
      $this->owe($order, 'placed');
    }
  }

  /**
   * Records the order as owed, if its gateway is invoiced at this moment.
   *
   * An order that comes to nothing is not invoiced: there is nothing to
   * fiscalise.
   */
  private function owe(OrderInterface $order, string $moment): void {
    $gateway = $this->source->gatewaySettings($order);
    $total = $order->getTotalPrice();

    if ($gateway === NULL || $gateway['issue_on'] !== $moment || $total === NULL || $total->isZero()) {
      return;
    }

    $this->issuer->owe(
      CommerceOrderSource::ID,
      (string) $order->id(),
      (string) ($order->getOrderNumber() ?: $order->id()),
      $this->time->getRequestTime(),
    );
  }

}
