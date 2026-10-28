<?php

declare(strict_types=1);

namespace Drupal\Tests\eracuni\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\eracuni\Client\EracuniException;
use Drupal\eracuni\Invoice\Buyer;
use Drupal\eracuni\Invoice\InvoiceRequest;
use Drupal\eracuni\Invoice\Line;
use Drupal\eracuni\Invoice\PayloadBuilder;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * An invoice request as e-računi takes it: the shape follows the buyer.
 */
#[Group('eracuni')]
final class PayloadBuilderTest extends UnitTestCase {

  /**
   * The settings every case starts from.
   *
   * @var array<string, mixed>
   */
  private const array SETTINGS = [
    'business_unit' => '0000',
    'reference_prefix' => 'vallic-',
    'status' => 'IssuedInvoice',
    'send_by_email' => TRUE,
    'document_language' => 'Croatian',
    'seller_country' => 'HR',
    'vat_transaction_types' => [
      'domestic_b2b' => '',
      'domestic_b2c' => '',
      'eu_b2b' => '16',
      'eu_b2c' => '',
      'export_b2b' => '',
      'export_b2c' => '3',
    ],
    'default_kpd' => '63.11.10',
    'describe_products' => FALSE,
    'alert_after_hours' => 24,
    'max_attempts' => 5,
  ];

  /**
   * A consumer's invoice is retail.
   *
   * Gross prices, the buyer on it, fiscalised by its business unit, the
   * reference prefixed and the transaction id sent.
   */
  public function testConsumerInvoiceIsRetail(): void {
    $built = $this->builder()->build($this->request('HR', FALSE, '', '125.00'), 'vallic-commerce_order-7');
    $parameters = $built['parameters'];
    $invoice = $parameters['SalesInvoice'];

    $this->assertSame('vallic-commerce_order-7', $parameters['apiTransactionId']);
    $this->assertTrue($parameters['sendIssuedInvoiceByEmail']);
    $this->assertSame('Retail', $invoice['type']);
    $this->assertSame('0000', $invoice['businessUnit']);
    $this->assertSame('vallic-1007', $invoice['orderReference']);
    $this->assertSame('Stripe', $invoice['methodOfPayment']);
    $this->assertSame('Ana Kovač', $invoice['buyerName']);
    $this->assertArrayNotHasKey('buyerTaxNumber', $invoice);
    $this->assertSame(125.0, $invoice['Items'][0]['price'], 'Net 100 at 25% is 125 on a retail invoice.');
    $this->assertSame(25.0, $invoice['Items'][0]['vatPercentage']);
    $this->assertSame('63.11.10', $invoice['Items'][0]['classificationCode'], 'The default KPD.');
    $this->assertArrayNotHasKey('vatTransactionType', $invoice['Items'][0]);
    $this->assertSame([], $built['warnings']);
  }

  /**
   * A business elsewhere in the EU is reverse charged.
   *
   * A net invoice, its VAT id on it, and the reverse charge's code.
   */
  public function testEuBusinessIsReverseCharged(): void {
    $built = $this->builder()->build($this->request('DE', TRUE, '123456789', '100.00', 0.0), 'txn');
    $invoice = $built['parameters']['SalesInvoice'];

    $this->assertSame('eu_b2b', $built['treatment']->value);
    $this->assertSame('Gross', $invoice['type']);
    $this->assertSame('DE123456789', $invoice['buyerTaxNumber']);
    $this->assertSame(100.0, $invoice['Items'][0]['netPrice']);
    $this->assertSame('16', $invoice['Items'][0]['vatTransactionType']);
  }

  /**
   * A treatment that needs a code nobody mapped is refused, not guessed.
   */
  public function testUnmappedTreatmentIsRefused(): void {
    $this->expectException(EracuniException::class);
    $this->expectExceptionMessage('export_b2b');

    $this->builder()->build($this->request('US', TRUE, '', '100.00', 0.0), 'txn');
  }

  /**
   * Lines that do not come to what was charged are refused, unsent.
   */
  public function testLinesThatDoNotAddUpAreRefused(): void {
    try {
      $this->builder()->build($this->request('HR', FALSE, '', '130.00'), 'txn');
      $this->fail('A total off by five passed.');
    }
    catch (EracuniException $e) {
      $this->assertSame(EracuniException::DATA, $e->kind);
      $this->assertFalse($e->isRetryable());
    }
  }

  /**
   * A business invoice comes to what was charged, to the cent.
   *
   * Order 121's lines: VAT rounded line by line charged 223.78, and e-računi,
   * which works VAT out on the net total, made the draft 223.75. One line's
   * net unit price takes the difference, and says so.
   */
  public function testBusinessInvoiceComesToWhatWasCharged(): void {
    $lines = [];
    $order = [
      [1, '24'], [2, '24'], [1, '24'], [1, '24'], [1, '24'],
      [1, '3.5'], [1, '5.5'], [1, '5.5'], [1, '5.5'], [1, '5.5'], [1, '5.5'],
      [1, '0'], [1, '4'],
    ];
    foreach ($order as [$quantity, $net]) {
      $lines[] = new Line('Line', (float) $quantity, $net, 25.0);
    }
    $buyer = new Buyer('Vallic d.o.o.', 'Ulica 1', '31000', 'Osijek', 'HR', 'billing@example.com', TRUE, '12345678903');
    $request = new InvoiceRequest('commerce_order', '152', '121', $buyer, $lines, 'EUR', 'BankTransfer', '223.78', '2026-10-28');

    $built = $this->builder()->build($request, 'txn');
    $items = $built['parameters']['SalesInvoice']['Items'];

    $net = 0.0;
    $changed = [];
    foreach ($items as $n => $item) {
      $net += round($item['quantity'] * $item['netPrice'], 2);
      if ((float) $item['netPrice'] !== (float) $lines[$n]->netUnitPrice) {
        $changed[] = $n;
      }
    }
    $this->assertSame('223.78', number_format(round($net, 2) + round($net * 0.25, 2), 2, '.', ''), 'The invoice as e-računi adds it up.');
    $this->assertCount(1, $changed, 'One line takes the difference.');
    $this->assertSame(1, $changed[0], 'The largest line: two machines.');
    $this->assertStringContainsString('comes to 223.78 as charged', implode(' ', $built['warnings']));
  }

  /**
   * A total that already agrees is sent as it is.
   */
  public function testAgreeingTotalIsLeftAlone(): void {
    $built = $this->builder()->build($this->request('HR', TRUE, '12345678903', '125.00'), 'txn');

    $this->assertSame(100.0, $built['parameters']['SalesInvoice']['Items'][0]['netPrice']);
    $this->assertStringNotContainsString('as charged', implode(' ', $built['warnings']));
  }

  /**
   * A domestic line at 0% and a line with no KPD code are warned of.
   */
  public function testDoubtfulLinesAreWarnedOf(): void {
    $settings = self::SETTINGS;
    $settings['default_kpd'] = '';

    $built = $this->builder($settings)->build($this->request('HR', FALSE, '', '100.00', 0.0), 'txn');

    $this->assertCount(2, $built['warnings']);
    $this->assertStringContainsString('no KPD code', $built['warnings'][0]);
    $this->assertStringContainsString('0% VAT', $built['warnings'][1]);
  }

  /**
   * A catalogue product's line prints the product's name and KPD code.
   *
   * Not the site's default KPD, which would win over the product's, and
   * nothing warned of. The line's own KPD code still wins, and the site may
   * print the line's own description.
   */
  public function testCatalogueProductKeepsItsNameAndKpd(): void {
    $built = $this->builder()->build($this->request('HR', FALSE, '', '125.00', 25.0, 'HOST'), 'txn');
    $item = $built['parameters']['SalesInvoice']['Items'][0];

    $this->assertSame('HOST', $item['productCode']);
    $this->assertArrayNotHasKey('description', $item, 'e-računi refuses one for a product that does not allow changing it.');
    $this->assertArrayNotHasKey('classificationCode', $item);
    $this->assertSame(125.0, $item['price'], "The price is still the line's.");
    $this->assertSame([], $built['warnings']);

    $settings = self::SETTINGS;
    $settings['describe_products'] = TRUE;
    $item = $this->builder($settings)->build($this->request('HR', FALSE, '', '125.00', 25.0, 'HOST', 'L64.99.09'), 'txn')['parameters']['SalesInvoice']['Items'][0];

    $this->assertSame('Hosting, October', $item['description']);
    $this->assertSame('L64.99.09', $item['classificationCode']);
  }

  /**
   * A consumer without an address is invoiced; a business is not.
   *
   * An invoice without a city is refused by e-računi, and a consumer's
   * address is not needed on one.
   */
  public function testBuyerWithoutAddress(): void {
    $invoice = $this->builder()->build($this->request('HR', FALSE, '', '125.00', address: FALSE), 'txn')['parameters']['SalesInvoice'];
    $this->assertSame(['-', '-', '-'], [$invoice['buyerStreet'], $invoice['buyerPostalCode'], $invoice['buyerCity']]);

    $this->expectException(EracuniException::class);
    $this->expectExceptionMessage('has no city');
    $this->builder()->build($this->request('DE', TRUE, '123456789', '100.00', 0.0, address: FALSE), 'txn');
  }

  /**
   * One request, net 100 a month for the given buyer.
   */
  private function request(string $country, bool $business, string $taxId, string $total, float $rate = 25.0, ?string $productCode = NULL, ?string $kpd = NULL, bool $address = TRUE): InvoiceRequest {
    return new InvoiceRequest(
      'commerce_order',
      '7',
      '1007',
      $address
        ? new Buyer('Ana Kovač', 'Ulica 1', '31000', 'Osijek', $country, 'ana@example.com', $business, $taxId)
        : new Buyer('Ana Kovač', '', '', '', $country, 'ana@example.com', $business, $taxId),
      [new Line('Hosting, October', 1.0, '100.00', $rate, 'mj', $kpd, $productCode)],
      'EUR',
      'Stripe',
      $total,
      '2026-10-26',
    );
  }

  /**
   * A builder over the given settings.
   *
   * @param array<string, mixed> $settings
   *   The eracuni.settings values.
   */
  private function builder(array $settings = self::SETTINGS): PayloadBuilder {
    $flat = [];
    foreach ($settings as $key => $value) {
      $flat[$key] = $value;
      if (is_array($value)) {
        foreach ($value as $sub => $subValue) {
          $flat[$key . '.' . $sub] = $subValue;
        }
      }
    }

    $time = $this->createStub(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1793000000);

    return new PayloadBuilder($this->getConfigFactoryStub(['eracuni.settings' => $flat]), $time);
  }

}
