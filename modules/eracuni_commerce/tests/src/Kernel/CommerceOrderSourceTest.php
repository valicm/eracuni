<?php

declare(strict_types=1);

namespace Drupal\Tests\eracuni_commerce\Kernel;

use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_price\Price;
use Drupal\eracuni\Entity\EracuniDocumentInterface;
use Drupal\eracuni\Invoice\PayloadBuilder;
use Drupal\eracuni\Issuer;
use Drupal\eracuni_commerce\CommerceOrderSource;
use Drupal\profile\Entity\Profile;
use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * An order said as an invoice, and owed one when its gateway says.
 */
#[Group('eracuni')]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class CommerceOrderSourceTest extends OrderKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'file',
    'options',
    'commerce_payment',
    'eracuni',
    'eracuni_commerce',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('commerce_payment');
    $this->installEntitySchema('eracuni_document');
    $this->installConfig(['commerce_payment', 'eracuni', 'eracuni_commerce']);

    PaymentGateway::create(['id' => 'bank', 'label' => 'Bank', 'plugin' => 'manual'])->save();
    $this->config('eracuni_commerce.settings')
      ->set('gateways', ['bank' => ['method' => 'BankTransfer', 'issue_on' => 'placed']])
      ->set('kpd_by_type', ['test' => '62.01'])
      ->save();
  }

  /**
   * Placed through a gateway paid later, the order is owed its invoice.
   *
   * And the invoice comes to what it charged, an order-level discount
   * included.
   */
  public function testPlacedOrderIsOwedAndAddsUp(): void {
    $order = $this->order();
    $order->getState()->applyTransitionById('place');
    $order->save();

    $document = $this->container->get(Issuer::class)->find(CommerceOrderSource::ID, (string) $order->id());
    $this->assertInstanceOf(EracuniDocumentInterface::class, $document, 'Placing it recorded it as owed.');
    $this->assertSame(EracuniDocumentInterface::PENDING, $document->getStatus());

    // A discount on the whole order, taken after tax, as a site's own
    // billing-period discount is.
    $order->addAdjustment(new Adjustment([
      'type' => 'custom',
      'label' => 'Billing period',
      'amount' => new Price('-10', 'USD'),
      'locked' => TRUE,
    ]));
    $order->save();

    $request = $this->container->get(CommerceOrderSource::class)->request((string) $order->id());
    $this->assertSame('BankTransfer', $request->methodOfPayment);
    $this->assertSame('Ana Kovač', $request->buyer->name);
    $this->assertFalse($request->buyer->business);
    $this->assertCount(2, $request->lines);
    $this->assertSame('57.0000', $request->lines[0]->netUnitPrice);
    $this->assertSame(25.0, $request->lines[0]->vatRate);
    $this->assertSame('62.01', $request->lines[0]->kpd);
    $this->assertSame('-8.0000', $request->lines[1]->netUnitPrice, 'Ten off gross is eight off net at 25%.');
    $this->assertSame(61.25, (float) $request->expectedTotal);

    $built = $this->container->get(PayloadBuilder::class)->build($request, 'txn');
    $this->assertSame('Retail', $built['parameters']['SalesInvoice']['type'], 'It adds up, so it builds.');
  }

  /**
   * A gateway invoiced on payment is not owed on placing.
   *
   * And one not listed is never owed.
   */
  public function testOnlyTheGatewaysMomentOwesIt(): void {
    $this->config('eracuni_commerce.settings')
      ->set('gateways', ['bank' => ['method' => 'BankTransfer', 'issue_on' => 'paid']])
      ->save();

    $order = $this->order();
    $order->getState()->applyTransitionById('place');
    $order->save();

    $this->assertNull($this->container->get(Issuer::class)->find(CommerceOrderSource::ID, (string) $order->id()));
  }

  /**
   * An order with no gateway is invoiced only when the site says how.
   *
   * The way a site that bills on invoice terms places its orders.
   */
  public function testOrderWithoutGatewayFollowsItsOwnSetting(): void {
    $issuer = $this->container->get(Issuer::class);

    $order = $this->order(NULL);
    $order->getState()->applyTransitionById('place');
    $order->save();
    $this->assertNull($issuer->find(CommerceOrderSource::ID, (string) $order->id()), 'Not invoiced by default.');

    $this->config('eracuni_commerce.settings')
      ->set('without_gateway', ['method' => 'BankTransfer', 'issue_on' => 'placed'])
      ->save();
    $order = $this->order(NULL);
    $order->getState()->applyTransitionById('place');
    $order->save();

    $this->assertInstanceOf(EracuniDocumentInterface::class, $issuer->find(CommerceOrderSource::ID, (string) $order->id()));
    $this->assertSame('BankTransfer', $this->container->get(CommerceOrderSource::class)->request((string) $order->id())->methodOfPayment);
  }

  /**
   * An order for net 57 at 25% to a consumer in Osijek.
   *
   * @param string|null $gateway
   *   The gateway it is paid through, the bank unless said otherwise.
   */
  private function order(?string $gateway = 'bank'): Order {
    $profile = Profile::create([
      'type' => 'customer',
      'address' => [
        'country_code' => 'HR',
        'given_name' => 'Ana',
        'family_name' => 'Kovač',
        'address_line1' => 'Ulica 1',
        'postal_code' => '31000',
        'locality' => 'Osijek',
      ],
    ]);
    $profile->save();

    $item = OrderItem::create([
      'type' => 'test',
      'title' => 'Hosting, October',
      'quantity' => 1,
      'unit_price' => new Price('57', 'USD'),
    ]);
    $item->addAdjustment(new Adjustment([
      'type' => 'tax',
      'label' => 'VAT',
      'amount' => new Price('14.25', 'USD'),
      'percentage' => '0.25',
      'source_id' => 'test|hr|standard',
      'locked' => TRUE,
    ]));
    $item->save();

    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store,
      'mail' => 'ana@example.com',
      'billing_profile' => $profile,
      'order_items' => [$item],
      'payment_gateway' => $gateway,
      'state' => 'draft',
    ]);
    $order->save();

    return $order;
  }

}
