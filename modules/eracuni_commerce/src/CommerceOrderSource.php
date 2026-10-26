<?php

declare(strict_types=1);

namespace Drupal\eracuni_commerce;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\eracuni\Client\EracuniException;
use Drupal\eracuni\Invoice\Buyer;
use Drupal\eracuni\Invoice\InvoiceRequest;
use Drupal\eracuni\Invoice\Line;
use Drupal\eracuni\Source\InvoiceSourceInterface;
use Drupal\eracuni_commerce\Event\OrderInvoiceEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * A Commerce order, said as an invoice.
 *
 * The lines are the order items at what was charged for them: their total
 * with every adjustment but tax, net of any tax the price included, at the
 * rate Commerce charged. Order-level adjustments — a promotion on the whole
 * order, a site's own discount or credit — are lines of their own, and are
 * read as gross amounts at the items' rates, split across the rates by the
 * items' gross: that is what makes the invoice come to exactly what the
 * buyer paid, with VAT that is the rate of its net. (Commerce taxes the
 * items alone, so an order-level discount is taken after tax: an invoice
 * that taxed it again would not match the payment.)
 *
 * The buyer is the billing profile's: its address, the order's email, and
 * the profile's `tax_number` where it has one; a business when it names an
 * organisation or a tax number. `OrderInvoiceEvent` is where a site that
 * knows better says so.
 */
final readonly class CommerceOrderSource implements InvoiceSourceInterface {

  public const string ID = 'commerce_order';

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private ConfigFactoryInterface $configFactory,
    private EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return self::ID;
  }

  /**
   * {@inheritdoc}
   */
  public function request(string $id): InvoiceRequest {
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($id);
    if (!$order instanceof OrderInterface) {
      throw EracuniException::data(sprintf('Order %s is gone.', $id));
    }

    $method = $this->methodOf($order);
    if ($method === NULL) {
      $gateway = $this->gatewayOf($order);
      throw EracuniException::configuration($gateway === ''
        ? sprintf('Order %s has no payment gateway, and orders without one have no e-računi method of payment (eracuni_commerce.settings: without_gateway).', $id)
        : sprintf('Order %s was paid through %s, which has no e-računi method of payment (eracuni_commerce.settings: gateways).', $id, $gateway));
    }

    $total = $order->getTotalPrice();
    $placed = $order->getPlacedTime();

    $request = new InvoiceRequest(
      self::ID,
      $id,
      (string) ($order->getOrderNumber() ?: $id),
      $this->buyer($order),
      $this->lines($order),
      $total?->getCurrencyCode() ?? 'EUR',
      $method,
      $total === NULL ? '0' : $total->getNumber(),
      NULL,
      $placed ? date('Y-m-d', (int) $placed) : NULL,
    );

    $event = new OrderInvoiceEvent($order, $request);
    $this->eventDispatcher->dispatch($event, OrderInvoiceEvent::NAME);

    return $event->request;
  }

  /**
   * {@inheritdoc}
   *
   * Whoever may view the order, by Commerce's rules and any a site adds.
   */
  public function access(string $id, AccountInterface $account): AccessResultInterface {
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($id);

    return $order instanceof OrderInterface ? $order->access('view', $account, TRUE) : AccessResult::neutral();
  }

  /**
   * The gateway's settings, if it is invoiced at all.
   *
   * @return array{method: string, issue_on: string}|null
   *   Its method of payment and when it is invoiced.
   */
  public function gatewaySettings(OrderInterface $order): ?array {
    $config = $this->configFactory->get('eracuni_commerce.settings');
    $gateway = $this->gatewayOf($order);
    $settings = $gateway === ''
      ? $config->get('without_gateway')
      : ((array) ($config->get('gateways') ?? []))[$gateway] ?? NULL;

    if (!is_array($settings) || trim((string) ($settings['method'] ?? '')) === '') {
      return NULL;
    }

    return [
      'method' => trim((string) $settings['method']),
      'issue_on' => (string) ($settings['issue_on'] ?? 'paid'),
    ];
  }

  /**
   * The method of payment e-računi knows the order's gateway by.
   */
  private function methodOf(OrderInterface $order): ?string {
    return $this->gatewaySettings($order)['method'] ?? NULL;
  }

  /**
   * The order's payment gateway's id, or ''.
   */
  private function gatewayOf(OrderInterface $order): string {
    return $order->hasField('payment_gateway') ? (string) $order->get('payment_gateway')->target_id : '';
  }

  /**
   * The buyer, from the billing profile.
   */
  private function buyer(OrderInterface $order): Buyer {
    $profile = $order->getBillingProfile();
    /** @var \Drupal\address\AddressInterface|null $address */
    $address = $profile !== NULL && $profile->hasField('address') && !$profile->get('address')->isEmpty() ? $profile->get('address')->first() : NULL;

    $organization = $address === NULL ? '' : trim((string) $address->getOrganization());
    $person = $address === NULL ? '' : trim($address->getGivenName() . ' ' . $address->getFamilyName());
    $taxId = $profile !== NULL && $profile->hasField('tax_number') ? trim((string) $profile->get('tax_number')->value) : '';

    return new Buyer(
      $organization !== '' ? $organization : ($person !== '' ? $person : (string) $order->getEmail()),
      $address === NULL ? '' : trim($address->getAddressLine1() . ' ' . $address->getAddressLine2()),
      $address === NULL ? '' : (string) $address->getPostalCode(),
      $address === NULL ? '' : (string) $address->getLocality(),
      $address === NULL ? '' : (string) $address->getCountryCode(),
      (string) $order->getEmail(),
      $organization !== '' || $taxId !== '',
      $taxId,
    );
  }

  /**
   * The order's items and its order-level adjustments, as lines.
   *
   * @return \Drupal\eracuni\Invoice\Line[]
   *   The lines.
   */
  private function lines(OrderInterface $order): array {
    $settings = $this->configFactory->get('eracuni_commerce.settings');
    $unit = (string) ($settings->get('unit') ?: 'kom');
    $lines = [];
    $grossByRate = [];

    foreach ($order->getItems() as $item) {
      $quantity = (float) $item->getQuantity();
      if ($quantity <= 0.0) {
        continue;
      }

      [$net, $rate] = $this->netAndRate($item);
      $lines[] = new Line(
        (string) $item->getTitle(),
        $quantity,
        number_format($net / $quantity, 4, '.', ''),
        $rate,
        $unit,
        $this->kpd($item),
      );

      $key = (string) $rate;
      $grossByRate[$key] = ($grossByRate[$key] ?? 0.0) + $net * (1 + $rate / 100);
    }

    foreach ($order->getAdjustments() as $adjustment) {
      if ($adjustment->getType() === 'tax' || $adjustment->isIncluded()) {
        continue;
      }

      foreach ($this->split((float) $adjustment->getAmount()->getNumber(), $grossByRate) as $rate => $gross) {
        $rate = (float) $rate;
        $lines[] = new Line(
          (string) $adjustment->getLabel(),
          1.0,
          number_format($gross / (1 + $rate / 100), 4, '.', ''),
          $rate,
          $unit,
        );
      }
    }

    return $lines;
  }

  /**
   * An item's net total and the VAT rate charged on it, in percent.
   *
   * @return array{0: float, 1: float}
   *   Net total, rate.
   */
  private function netAndRate(OrderItemInterface $item): array {
    $net = (float) ($item->getTotalPrice()?->getNumber() ?? 0);
    $rate = 0.0;

    foreach ($item->getAdjustments() as $adjustment) {
      $amount = (float) $adjustment->getAmount()->getNumber();

      if ($adjustment->getType() === 'tax') {
        $rate += (float) $adjustment->getPercentage() * 100;
        if ($adjustment->isIncluded()) {
          $net -= $amount;
        }
      }
      elseif (!$adjustment->isIncluded()) {
        $net += $amount;
      }
    }

    return [$net, round($rate, 2)];
  }

  /**
   * An order-level amount split across the items' rates by their gross.
   *
   * @param float $amount
   *   The amount, gross.
   * @param array<string, float> $grossByRate
   *   The items' gross total by rate.
   *
   * @return array<int|string, float>
   *   Gross part by rate; the last rate takes what rounding left.
   */
  private function split(float $amount, array $grossByRate): array {
    $sum = array_sum($grossByRate);
    if ($sum <= 0.0) {
      return [array_key_first($grossByRate) ?? '0' => $amount];
    }

    $parts = [];
    $given = 0.0;
    $rates = array_keys($grossByRate);
    foreach ($rates as $i => $rate) {
      $part = $i === count($rates) - 1 ? $amount - $given : round($amount * $grossByRate[$rate] / $sum, 2);
      $parts[$rate] = $part;
      $given += $part;
    }

    return $parts;
  }

  /**
   * An item's KPD code: a field on what was bought, then its type's.
   */
  private function kpd(OrderItemInterface $item): ?string {
    $settings = $this->configFactory->get('eracuni_commerce.settings');
    $purchased = $item->getPurchasedEntity();

    $field = trim((string) ($settings->get('kpd_field') ?? ''));
    if ($field !== '' && $purchased instanceof FieldableEntityInterface && $purchased->hasField($field)) {
      $value = trim((string) $purchased->get($field)->value);
      if ($value !== '') {
        return $value;
      }
    }

    $byType = (array) ($settings->get('kpd_by_type') ?? []);
    foreach ([$purchased?->bundle(), $item->bundle()] as $type) {
      if ($type !== NULL && trim((string) ($byType[$type] ?? '')) !== '') {
        return trim((string) $byType[$type]);
      }
    }

    return NULL;
  }

}
