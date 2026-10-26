<?php

declare(strict_types=1);

namespace Drupal\eracuni\Invoice;

/**
 * One line of an invoice, net of VAT.
 *
 * Net and a rate rather than a gross price, because that is how VAT is
 * charged: the gross a retail invoice prints is worked out from these, in one
 * place, so a site cannot hand over a gross that its own net and rate do not
 * add up to.
 *
 * A line may name a product in e-računi's catalogue. e-računi then prints
 * the product's name and unit, and its KPD code unless the line names one,
 * and refuses a price, a VAT rate or a description the product does not
 * allow an invoice to change: the price is always this line's, so the
 * product has to allow that.
 */
final readonly class Line {

  /**
   * Builds a line.
   *
   * @param string $description
   *   What was sold, as the invoice prints it.
   * @param float $quantity
   *   How many.
   * @param string $netUnitPrice
   *   One unit's price without VAT, a decimal string.
   * @param float $vatRate
   *   The rate charged on it, in percent: 25, not 0.25.
   * @param string $unit
   *   The unit e-računi prints: kom, mj, h.
   * @param string|null $kpd
   *   Its KPD code, or NULL for the product's or the site's default.
   * @param string|null $productCode
   *   The product in e-računi's catalogue it is, or NULL for none.
   */
  public function __construct(
    public string $description,
    public float $quantity,
    public string $netUnitPrice,
    public float $vatRate,
    public string $unit = 'kom',
    public ?string $kpd = NULL,
    public ?string $productCode = NULL,
  ) {}

  /**
   * The line's net total, rounded to the cent.
   */
  public function netTotal(): float {
    return round($this->quantity * (float) $this->netUnitPrice, 2);
  }

  /**
   * The line's total with its VAT, rounded to the cent.
   */
  public function grossTotal(): float {
    return round($this->netTotal() * (1 + $this->vatRate / 100), 2);
  }

}
