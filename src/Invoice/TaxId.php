<?php

declare(strict_types=1);

namespace Drupal\eracuni\Invoice;

/**
 * Croatian OIBs and EU VAT ids, as far as their own form can say.
 *
 * Form only: whether a VAT id is registered is VIES's question, and Commerce
 * asks it where a site wants it asked. What is decided here is only whether a
 * value can be one at all, because a reverse-charge invoice to a malformed id
 * is a 25% sale reported as a 0% one.
 */
final class TaxId {

  /**
   * The member states, by ISO code.
   */
  public const array EU = [
    'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU',
    'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
  ];

  /**
   * Letters and digits only, upper case: "hr 123-456" is HR123456.
   */
  public static function normalise(string $value): string {
    return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
  }

  /**
   * Whether a value is a valid OIB, with or without its HR prefix.
   *
   * ISO 7064 MOD 11,10: eleven digits, the last a check digit.
   */
  public static function isOib(string $value): bool {
    $digits = self::oib($value);
    if ($digits === NULL) {
      return FALSE;
    }

    $sum = 10;
    for ($i = 0; $i < 10; $i++) {
      $sum = ($sum + (int) $digits[$i]) % 10;
      $sum = (($sum === 0 ? 10 : $sum) * 2) % 11;
    }

    return (11 - $sum) % 10 === (int) $digits[10];
  }

  /**
   * The eleven digits of an OIB, or NULL if the value is not shaped like one.
   */
  public static function oib(string $value): ?string {
    $value = self::normalise($value);
    if (str_starts_with($value, 'HR')) {
      $value = substr($value, 2);
    }

    return preg_match('/^\d{11}$/', $value) === 1 ? $value : NULL;
  }

  /**
   * An EU VAT id with its country's prefix, or NULL.
   *
   * Greece's prefix is EL, not GR. A number given without its prefix is
   * given one, since the country is known.
   */
  public static function euVat(string $value, string $countryCode): ?string {
    $country = strtoupper($countryCode);
    if (!in_array($country, self::EU, TRUE)) {
      return NULL;
    }

    $prefix = $country === 'GR' ? 'EL' : $country;
    $value = self::normalise($value);
    if ($value !== '' && !str_starts_with($value, $prefix) && preg_match('/^\d/', $value) === 1) {
      $value = $prefix . $value;
    }

    return preg_match('/^' . $prefix . '[A-Z0-9]{2,13}$/', $value) === 1 ? $value : NULL;
  }

}
