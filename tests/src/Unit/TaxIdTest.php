<?php

declare(strict_types=1);

namespace Drupal\Tests\eracuni\Unit;

use Drupal\eracuni\Invoice\Buyer;
use Drupal\eracuni\Invoice\TaxId;
use Drupal\eracuni\Invoice\VatTreatment;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tax ids by their form, and the treatment a buyer gets from theirs.
 */
#[Group('eracuni')]
final class TaxIdTest extends UnitTestCase {

  /**
   * An OIB's last digit checks the other ten; its HR prefix is optional.
   */
  public function testOibChecksItsCheckDigit(): void {
    $this->assertTrue(TaxId::isOib('12345678903'));
    $this->assertTrue(TaxId::isOib('HR 98765432106'));
    $this->assertFalse(TaxId::isOib('12345678904'), 'A wrong check digit.');
    $this->assertFalse(TaxId::isOib('1234567890'), 'Ten digits.');
    $this->assertSame('12345678903', TaxId::oib('hr12345678903'));
  }

  /**
   * An EU VAT id carries its country's prefix, EL for Greece.
   *
   * And it gets one when it is given without.
   */
  public function testEuVatIdsCarryTheirPrefix(): void {
    $this->assertSame('DE123456789', TaxId::euVat('de 123 456 789', 'DE'));
    $this->assertSame('DE123456789', TaxId::euVat('123456789', 'DE'));
    $this->assertSame('EL123456789', TaxId::euVat('123456789', 'GR'));
    $this->assertNull(TaxId::euVat('FR12345678901', 'DE'), "Another country's id.");
    $this->assertNull(TaxId::euVat('123456789', 'US'), 'Not in the EU.');
  }

  /**
   * Six treatments, from where the buyer is and whether their id can be one.
   */
  public function testTreatmentFollowsTheBuyer(): void {
    $buyer = static fn (string $country, bool $business, string $taxId = ''): Buyer => new Buyer('Kupac', 'Ulica 1', '31000', 'Osijek', $country, 'kupac@example.com', $business, $taxId);

    $this->assertSame(VatTreatment::DomesticB2b, VatTreatment::of($buyer('HR', TRUE, '12345678903'), 'HR'));
    $this->assertSame(VatTreatment::DomesticB2c, VatTreatment::of($buyer('HR', TRUE, '12345678904'), 'HR'), 'A company with a bad OIB is invoiced as a consumer.');
    $this->assertSame(VatTreatment::DomesticB2c, VatTreatment::of($buyer('HR', FALSE), 'HR'));
    $this->assertSame(VatTreatment::EuB2b, VatTreatment::of($buyer('DE', TRUE, 'DE123456789'), 'HR'));
    $this->assertSame(VatTreatment::EuB2c, VatTreatment::of($buyer('DE', TRUE), 'HR'), 'An EU company without a VAT id pays VAT.');
    $this->assertSame(VatTreatment::ExportB2b, VatTreatment::of($buyer('US', TRUE), 'HR'));
    $this->assertSame(VatTreatment::ExportB2c, VatTreatment::of($buyer('US', FALSE), 'HR'));

    $this->assertTrue(VatTreatment::EuB2c->isRetail());
    $this->assertFalse(VatTreatment::EuB2c->needsTransactionType());
    $this->assertTrue(VatTreatment::EuB2b->needsTransactionType());
    $this->assertFalse(VatTreatment::DomesticB2b->isRetail());
  }

}
