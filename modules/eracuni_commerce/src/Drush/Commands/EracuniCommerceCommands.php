<?php

declare(strict_types=1);

namespace Drupal\eracuni_commerce\Drush\Commands;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\eracuni\Issuer;
use Drupal\eracuni_commerce\CommerceOrderSource;
use Drush\Attributes\Command;
use Drush\Attributes\Option;
use Drush\Attributes\Usage;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Orders that should have an invoice and have no document at all.
 */
final class EracuniCommerceCommands extends DrushCommands {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Issuer $issuer,
    private readonly CommerceOrderSource $source,
    private readonly TimeInterface $time,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get(Issuer::class),
      $container->get(CommerceOrderSource::class),
      $container->get('datetime.time'),
    );
  }

  /**
   * Records as owed every recent order of an invoiced gateway that has none.
   *
   * For orders placed before the module was enabled, or while it was not
   * listening. Forty-eight hours back by default: Croatian law gives two days
   * to fiscalise an invoice that was not, and an older one is a question
   * for the accountant, not for a command.
   *
   * @param array{hours: int, dry-run: bool} $options
   *   The options.
   */
  #[Command(name: 'eracuni:commerce:backfill')]
  #[Option(name: 'hours', description: 'How far back to look, in hours.')]
  #[Option(name: 'dry-run', description: 'List them without recording anything.')]
  #[Usage(name: 'drush eracuni:commerce:backfill --dry-run', description: 'What the last 48 hours are missing.')]
  public function backfill(array $options = ['hours' => 48, 'dry-run' => FALSE]): int {
    $since = $this->time->getRequestTime() - (int) $options['hours'] * 3600;
    $storage = $this->entityTypeManager->getStorage('commerce_order');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('state', ['draft', 'canceled'], 'NOT IN')
      ->condition('placed', $since, '>=')
      ->execute();

    $owed = 0;
    foreach ($storage->loadMultiple($ids) as $order) {
      if (!$order instanceof OrderInterface || !$this->isOwed($order)) {
        continue;
      }

      // Said rather than left out, so the list accounts for every order
      // that looks owed an invoice.
      $declined = $this->source->declined($order);
      if ($declined !== NULL) {
        $this->io()->text(sprintf('%s not invoiced: %s', $order->getOrderNumber() ?: $order->id(), $declined));
        continue;
      }

      $owed++;
      $this->io()->text(sprintf('%s placed %s', $order->getOrderNumber() ?: $order->id(), date('Y-m-d H:i', (int) $order->getPlacedTime())));
      if (!$options['dry-run']) {
        $this->issuer->owe(CommerceOrderSource::ID, (string) $order->id(), (string) ($order->getOrderNumber() ?: $order->id()), (int) $order->getPlacedTime());
      }
    }

    $this->io()->text(sprintf('%d order(s) %s.', $owed, $options['dry-run'] ? 'would be recorded' : 'recorded and queued'));

    return self::EXIT_SUCCESS;
  }

  /**
   * Whether an order should have an invoice by now and has no document.
   */
  private function isOwed(OrderInterface $order): bool {
    $gateway = $this->source->gatewaySettings($order);
    $total = $order->getTotalPrice();
    if ($gateway === NULL || $total === NULL || $total->isZero()) {
      return FALSE;
    }
    if ($gateway['issue_on'] === 'paid' && !$order->isPaid()) {
      return FALSE;
    }

    return $this->issuer->find(CommerceOrderSource::ID, (string) $order->id()) === NULL;
  }

}
