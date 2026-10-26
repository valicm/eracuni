<?php

declare(strict_types=1);

namespace Drupal\eracuni\Invoice;

/**
 * How VAT applies to a buyer: where they are, and whether they are a business.
 *
 * Six cases and no more, decided from the buyer alone. The VAT transaction
 * type each needs is not decided here but configured (`vat_transaction_types`),
 * because which code e-računi expects for a reverse charge is an accountant's
 * answer, and one that a law can change.
 *
 * A business is treated as one only with an id that can be one: a Croatian
 * company without a valid OIB is invoiced as a consumer, retail and
 * fiscalised, and an EU company without a VAT id pays the seller's VAT.
 */
enum VatTreatment: string {

  case DomesticB2b = 'domestic_b2b';
  case DomesticB2c = 'domestic_b2c';
  case EuB2b = 'eu_b2b';
  case EuB2c = 'eu_b2c';
  case ExportB2b = 'export_b2b';
  case ExportB2c = 'export_b2c';

  /**
   * The treatment of a buyer, for a seller in the given country.
   */
  public static function of(Buyer $buyer, string $sellerCountry): self {
    $country = strtoupper($buyer->countryCode);

    if ($country === strtoupper($sellerCountry)) {
      return $buyer->business && TaxId::isOib($buyer->taxId) ? self::DomesticB2b : self::DomesticB2c;
    }

    if (in_array($country, TaxId::EU, TRUE)) {
      return $buyer->business && TaxId::euVat($buyer->taxId, $country) !== NULL ? self::EuB2b : self::EuB2c;
    }

    return $buyer->business ? self::ExportB2b : self::ExportB2c;
  }

  /**
   * Whether the invoice is a retail one: gross prices, the buyer inline.
   *
   * Every consumer invoice is, wherever the consumer is; e-računi fiscalises
   * a retail invoice paid by a card-like means on creation.
   */
  public function isRetail(): bool {
    return in_array($this, [self::DomesticB2c, self::EuB2c, self::ExportB2c], TRUE);
  }

  /**
   * Whether the treatment cannot be invoiced without a VAT transaction type.
   *
   * A domestic sale, and one to an EU consumer, is an ordinary sale at the
   * rate charged (the seller's, or the consumer's own under OSS). The others
   * are a reverse charge or an export, and an invoice that does not say which
   * is wrong whichever it was. A code configured for the ordinary ones is
   * still sent.
   */
  public function needsTransactionType(): bool {
    return in_array($this, [self::EuB2b, self::ExportB2b, self::ExportB2c], TRUE);
  }

  /**
   * The buyer's tax id as the invoice should carry it, or ''.
   */
  public function taxIdOf(Buyer $buyer): string {
    return match ($this) {
      self::DomesticB2b => (string) TaxId::oib($buyer->taxId),
      self::EuB2b => (string) TaxId::euVat($buyer->taxId, $buyer->countryCode),
      default => TaxId::normalise($buyer->taxId),
    };
  }

}
