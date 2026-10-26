<?php

declare(strict_types=1);

namespace Drupal\eracuni\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\eracuni\Entity\EracuniDocumentInterface;

/**
 * A document, issued or late.
 */
final class DocumentEvent extends Event {

  public function __construct(public readonly EracuniDocumentInterface $document) {}

}
