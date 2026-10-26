<?php

declare(strict_types=1);

namespace Drupal\Tests\eracuni\Kernel;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\eracuni\Invoice\Buyer;
use Drupal\eracuni\Invoice\InvoiceRequest;
use Drupal\eracuni\Invoice\Line;
use Drupal\eracuni\Source\InvoiceSourceInterface;

/**
 * A sale of net 100 at 25% to a consumer in Osijek, whatever the id.
 */
final class StubSource implements InvoiceSourceInterface {

  /**
   * The user every stub sale was made to.
   */
  public const int BUYER = 7;

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'stub';
  }

  /**
   * {@inheritdoc}
   */
  public function request(string $id): InvoiceRequest {
    return new InvoiceRequest(
      'stub',
      $id,
      '10' . str_pad($id, 2, '0', STR_PAD_LEFT),
      new Buyer('Ana Kovač', 'Ulica 1', '31000', 'Osijek', 'HR', 'ana@example.com'),
      [new Line('Hosting', 1.0, '100.00', 25.0, 'mj')],
      'EUR',
      'Stripe',
      '125.00',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function access(string $id, AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf((int) $account->id() === self::BUYER)->cachePerUser();
  }

}
