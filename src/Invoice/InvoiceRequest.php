<?php

declare(strict_types=1);

namespace Drupal\eracuni\Invoice;

/**
 * Everything one invoice needs, said once, by whatever sold something.
 *
 * A source — a Commerce order, a subscription of a site's own — turns what it
 * sold into one of these, and from here on nothing knows which it was. Its
 * transaction id is not here but on the document (`EracuniDocument`), fixed
 * when the sale was first recorded, so every attempt carries the same one.
 */
final readonly class InvoiceRequest {

  /**
   * Builds a request.
   *
   * @param string $sourceType
   *   The source's id, `commerce_order` say.
   * @param string $sourceId
   *   The sold thing's id within it.
   * @param string $reference
   *   What the buyer knows it by, the order number; unique within the site.
   * @param \Drupal\eracuni\Invoice\Buyer $buyer
   *   Who it is made out to.
   * @param \Drupal\eracuni\Invoice\Line[] $lines
   *   What was sold.
   * @param string $currency
   *   ISO 4217.
   * @param string $methodOfPayment
   *   e-računi's own name for how it was paid: `Stripe`, `BankTransfer`.
   * @param string $expectedTotal
   *   What was charged, VAT included, a decimal string. An invoice whose lines
   *   come to something else is refused before it is sent.
   * @param string|null $date
   *   The invoice date, Y-m-d; today when NULL.
   * @param string|null $supplyDate
   *   When the supply began, Y-m-d; the invoice date when NULL.
   * @param string|null $dueDate
   *   When it must be paid, Y-m-d; e-računi's default when NULL.
   */
  public function __construct(
    public string $sourceType,
    public string $sourceId,
    public string $reference,
    public Buyer $buyer,
    public array $lines,
    public string $currency,
    public string $methodOfPayment,
    public string $expectedTotal,
    public ?string $date = NULL,
    public ?string $supplyDate = NULL,
    public ?string $dueDate = NULL,
  ) {}

}
