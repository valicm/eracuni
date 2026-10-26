<?php

declare(strict_types=1);

namespace Drupal\eracuni\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\eracuni\Entity\EracuniDocumentInterface;
use Drupal\eracuni\Invoice\InvoiceRequest;

/**
 * An invoice's `SalesInvoiceCreate` parameters, about to be sent.
 *
 * Built and checked: a site adds a field the module does not model, or
 * changes one it does. Nothing here is checked again, so a price changed
 * here is the site's to make add up. The transaction id is the module's and
 * is put back as it was.
 */
final class PayloadEvent extends Event {

  /**
   * Builds the event.
   *
   * @param \Drupal\eracuni\Entity\EracuniDocumentInterface $document
   *   The document being issued.
   * @param \Drupal\eracuni\Invoice\InvoiceRequest $request
   *   What its source said to invoice.
   * @param array<string, mixed> $parameters
   *   The parameters: `apiTransactionId`, `sendIssuedInvoiceByEmail` and
   *   `SalesInvoice`, its lines under `Items`.
   */
  public function __construct(
    public readonly EracuniDocumentInterface $document,
    public readonly InvoiceRequest $request,
    public array $parameters,
  ) {}

}
