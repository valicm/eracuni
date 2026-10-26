<?php

declare(strict_types=1);

namespace Drupal\eracuni\Client;

/**
 * One call to e-računi's API.
 */
interface EracuniClientInterface {

  /**
   * Calls a method and returns e-računi's answer.
   *
   * @param string $method
   *   An API method, `SalesInvoiceCreate` say.
   * @param array<string, mixed> $parameters
   *   Its parameters.
   *
   * @return array<string, mixed>
   *   The answer's `result`, whatever shape the method gives it.
   *
   * @throws \Drupal\eracuni\Client\EracuniException
   *   Refused, not reached, or not configured.
   */
  public function call(string $method, array $parameters = []): array;

  /**
   * Whether credentials are set at all.
   */
  public function isConfigured(): bool;

}
