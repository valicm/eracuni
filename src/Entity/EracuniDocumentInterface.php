<?php

declare(strict_types=1);

namespace Drupal\eracuni\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;

/**
 * One invoice asked of e-računi.
 */
interface EracuniDocumentInterface extends ContentEntityInterface, EntityChangedInterface {

  /**
   * Owed, and not issued yet: queued, or between attempts.
   */
  public const string PENDING = 'pending';

  /**
   * In e-računi, with a document ID.
   */
  public const string ISSUED = 'issued';

  /**
   * Refused, or not reached after every attempt. The alarm's to raise.
   */
  public const string FAILED = 'failed';

  /**
   * Pending, issued or failed.
   */
  public function getStatus(): string;

  /**
   * Whether e-računi holds it.
   */
  public function isIssued(): bool;

  /**
   * Whether e-računi holds it as a draft: unnumbered, and with no PDF.
   */
  public function isDraft(): bool;

  /**
   * The document ID e-računi gave it, or ''.
   */
  public function getDocumentId(): string;

  /**
   * The invoice number e-računi gave it, or ''.
   */
  public function getNumber(): string;

  /**
   * The order reference it is filed under, prefix included.
   */
  public function getReference(): string;

  /**
   * The `apiTransactionId` every attempt carries.
   */
  public function getTransactionId(): string;

  /**
   * How many times it has been sent.
   */
  public function getAttempts(): int;

}
