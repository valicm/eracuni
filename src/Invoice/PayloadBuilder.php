<?php

declare(strict_types=1);

namespace Drupal\eracuni\Invoice;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\eracuni\Client\EracuniException;

/**
 * An invoice request as e-računi's `SalesInvoiceCreate` takes it.
 *
 * Two shapes, by the buyer's treatment. A consumer's invoice is `Retail`:
 * gross prices (`price`, `vatPercentage`), the buyer's name and address on
 * it, in the configured business unit, which e-računi fiscalises when it is
 * paid by a card-like means. A business's is `Gross`: net prices
 * (`netPrice`), VAT added at the rate given, the buyer's tax id on it.
 *
 * Nothing leaves without adding up: the lines' gross total has to match what
 * was charged to within rounding, or the request is refused here, where it
 * costs nothing, rather than issued and corrected with a credit note.
 */
final readonly class PayloadBuilder {

  public function __construct(
    private ConfigFactoryInterface $configFactory,
    private TimeInterface $time,
  ) {}

  /**
   * The `SalesInvoiceCreate` parameters for a request.
   *
   * @param \Drupal\eracuni\Invoice\InvoiceRequest $request
   *   What to invoice.
   * @param string $transactionId
   *   The document's `apiTransactionId`, the same on every attempt.
   *
   * @return array{parameters: array<string, mixed>, treatment: \Drupal\eracuni\Invoice\VatTreatment, warnings: string[]}
   *   The parameters, the treatment decided, and what deserves a look.
   *
   * @throws \Drupal\eracuni\Client\EracuniException
   *   A treatment with no code mapped, or lines that do not add up.
   */
  public function build(InvoiceRequest $request, string $transactionId): array {
    $settings = $this->configFactory->get('eracuni.settings');
    $treatment = VatTreatment::of($request->buyer, (string) ($settings->get('seller_country') ?: 'HR'));
    $code = trim((string) ($settings->get('vat_transaction_types.' . $treatment->value) ?? ''));

    if ($code === '' && $treatment->needsTransactionType()) {
      throw EracuniException::configuration(sprintf('No VAT transaction type is mapped for %s (eracuni.settings: vat_transaction_types.%s); %s was not invoiced.', $treatment->value, $treatment->value, $request->reference));
    }
    if ($request->lines === []) {
      throw EracuniException::data(sprintf('%s has no lines to invoice.', $request->reference));
    }

    $defaultKpd = trim((string) ($settings->get('default_kpd') ?? ''));
    $describeProducts = (bool) $settings->get('describe_products');
    $warnings = [];
    $items = [];
    $total = 0.0;

    foreach (array_values($request->lines) as $n => $line) {
      $productCode = trim((string) $line->productCode);
      $item = [];
      if ($productCode !== '') {
        $item['productCode'] = $productCode;
      }
      // A catalogue product prints its own name. One that does not allow
      // changing it refuses any description at all, its own name too.
      if ($productCode === '' || $describeProducts) {
        $item['description'] = $line->description;
      }
      $item += [
        'quantity' => round($line->quantity, 4),
        'unit' => $line->unit,
      ];

      if ($treatment->isRetail()) {
        $item['price'] = round((float) $line->netUnitPrice * (1 + $line->vatRate / 100), 2);
        $item['vatPercentage'] = round($line->vatRate, 2);
        $total += round($item['price'] * $line->quantity, 2);
      }
      else {
        $item['netPrice'] = round((float) $line->netUnitPrice, 4);
        $item['vatPercentage'] = round($line->vatRate, 2);
        $total += $line->grossTotal();
      }

      if ($code !== '') {
        $item['vatTransactionType'] = $code;
      }

      // A product's own KPD code is e-računi's to add, and the line's wins
      // over it: the site's default is for a line with neither.
      $kpd = $line->kpd ?? ($productCode === '' ? $defaultKpd : '');
      if ($kpd !== '') {
        // As e-računi keeps it, the section letter first: L64.99.09. A code
        // it does not know is refused, and so is a Gross invoice with a line
        // without one.
        $item['classificationCode'] = $kpd;
      }
      elseif ($productCode === '') {
        $warnings[] = sprintf('Line %d (%s) has no KPD code.', $n + 1, $line->description);
      }

      if (!$treatment->needsTransactionType() && $line->vatRate === 0.0) {
        $warnings[] = sprintf('Line %d (%s) is at 0%% VAT on an ordinary sale.', $n + 1, $line->description);
      }

      $items[] = $item;
    }

    // Per-piece rounding drifts with the quantity, not with the number of
    // lines.
    $tolerance = 0.01 + 0.005 * array_sum(array_map(static fn (Line $line): float => $line->quantity, $request->lines));
    if (abs(round($total, 2) - round((float) $request->expectedTotal, 2)) > $tolerance) {
      throw EracuniException::data(sprintf('%s: the lines come to %.2f, %s was charged.', $request->reference, $total, $request->expectedTotal));
    }

    $date = $request->date ?? date('Y-m-d', $this->time->getRequestTime());
    $invoice = [
      'status' => (string) ($settings->get('status') ?: 'IssuedInvoice'),
      'type' => $treatment->isRetail() ? 'Retail' : 'Gross',
      'date' => $date,
      'dateOfSupplyFrom' => $request->supplyDate ?? $date,
      'documentCurrency' => $request->currency,
      'documentLanguage' => (string) ($settings->get('document_language') ?: 'Croatian'),
      'methodOfPayment' => $request->methodOfPayment,
      'orderReference' => $this->reference($request),
      'buyerName' => $request->buyer->name,
      'buyerStreet' => $request->buyer->street !== '' ? $request->buyer->street : '-',
      'buyerPostalCode' => $request->buyer->postalCode,
      'buyerCity' => $request->buyer->city,
      'buyerCountry' => strtoupper($request->buyer->countryCode),
      'buyerEMail' => $request->buyer->email,
      'Items' => $items,
    ];

    $businessUnit = trim((string) ($settings->get('business_unit') ?? ''));
    if ($businessUnit !== '') {
      $invoice['businessUnit'] = $businessUnit;
    }
    if ($request->dueDate !== NULL) {
      $invoice['paymentDueDate'] = $request->dueDate;
    }
    if ($request->buyer->phone !== '') {
      $invoice['buyerPhone'] = $request->buyer->phone;
    }
    $taxId = $treatment->taxIdOf($request->buyer);
    if ($taxId !== '' && !$treatment->isRetail()) {
      $invoice['buyerTaxNumber'] = $taxId;
    }

    return [
      'parameters' => [
        'apiTransactionId' => $transactionId,
        'sendIssuedInvoiceByEmail' => (bool) $settings->get('send_by_email'),
        'SalesInvoice' => $invoice,
      ],
      'treatment' => $treatment,
      'warnings' => $warnings,
    ];
  }

  /**
   * The reference e-računi files the invoice under.
   *
   * The site's prefix and the request's own.
   */
  public function reference(InvoiceRequest $request): string {
    return trim((string) ($this->configFactory->get('eracuni.settings')->get('reference_prefix') ?? '')) . $request->reference;
  }

}
