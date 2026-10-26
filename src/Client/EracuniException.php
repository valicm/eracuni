<?php

declare(strict_types=1);

namespace Drupal\eracuni\Client;

/**
 * Something went wrong between this site and e-računi, and what kind of wrong.
 *
 * The kind decides what may happen next, which is the whole reason there is
 * more than one. A refusal is e-računi saying no, with a reason, and nothing
 * was created. A transport failure may have created the invoice before the
 * answer was lost; it is safe to ask again only because every create carries
 * an `apiTransactionId`, which e-računi answers with the invoice it already
 * made. A configuration or data failure is ours, and asking again changes
 * nothing until somebody fixes it.
 */
final class EracuniException extends \RuntimeException {

  public const string REFUSED = 'refused';
  public const string TRANSPORT = 'transport';
  public const string CONFIGURATION = 'configuration';
  public const string DATA = 'data';

  private function __construct(string $message, public readonly string $kind, ?\Throwable $previous = NULL) {
    parent::__construct($message, 0, $previous);
  }

  /**
   * An explicit error from e-računi: nothing was created.
   */
  public static function refused(string $message): self {
    return new self($message, self::REFUSED);
  }

  /**
   * The request may or may not have arrived; the answer did not.
   */
  public static function transport(string $message, ?\Throwable $previous = NULL): self {
    return new self($message, self::TRANSPORT, $previous);
  }

  /**
   * Credentials, a business unit or a code map is missing.
   */
  public static function configuration(string $message): self {
    return new self($message, self::CONFIGURATION);
  }

  /**
   * The invoice as built does not add up to what was charged.
   */
  public static function data(string $message): self {
    return new self($message, self::DATA);
  }

  /**
   * Whether trying again, unchanged, can succeed.
   */
  public function isRetryable(): bool {
    return $this->kind === self::TRANSPORT;
  }

}
