<?php

declare(strict_types=1);

namespace Drupal\eracuni\Invoice;

/**
 * Who the invoice is made out to.
 *
 * Whether the buyer is a business is said by the source, not inferred here:
 * a site knows who its customers are (a team's own setting, an account type)
 * better than an organisation field that a person may fill in for no reason.
 */
final readonly class Buyer {

  /**
   * Builds a buyer.
   *
   * @param string $name
   *   The person's or the company's name, as the invoice prints it.
   * @param string $street
   *   Street and number.
   * @param string $postalCode
   *   Postal code.
   * @param string $city
   *   City.
   * @param string $countryCode
   *   ISO 3166-1 alpha-2.
   * @param string $email
   *   Where e-računi mails the invoice, if it does.
   * @param bool $business
   *   Whether the buyer buys as a business.
   * @param string $taxId
   *   An OIB or an EU VAT id, as entered; checked by `TaxId`.
   * @param string $phone
   *   A telephone number, if any.
   */
  public function __construct(
    public string $name,
    public string $street,
    public string $postalCode,
    public string $city,
    public string $countryCode,
    public string $email,
    public bool $business = FALSE,
    public string $taxId = '',
    public string $phone = '',
  ) {}

}
