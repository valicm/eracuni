<?php

declare(strict_types=1);

namespace Drupal\eracuni\Client;

use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * The JSON API of e-računi: one POST, the credentials in the body.
 *
 * Credentials come from settings.php, never from configuration, because
 * configuration is exported and these must never be:
 *
 * @code
 * $settings['eracuni'] = [
 *   'username' => '…',
 *   'secret_key' => '…',
 *   'token' => '…',
 *   // The Croatian instance unless a site says otherwise.
 *   'endpoint' => 'https://e-racuni.com/WebServicesHR/API',
 *   // Logs each request's parameters and e-računi's answer, at debug level.
 *   'log_traffic' => FALSE,
 * ];
 * @endcode
 *
 * Two answers are known to mean that nothing was done and the request may be
 * sent again as it is: HTTP 429, and e-računi's guard against a second
 * request while one is running on the same account ("Another web request is
 * currently being processed"). Both are waited out here, a few times. Any
 * other failure after the request left is a transport failure, for the
 * caller to decide about.
 */
final readonly class EracuniClient implements EracuniClientInterface {

  public const string DEFAULT_ENDPOINT = 'https://e-racuni.com/WebServicesHR/API';

  /**
   * How many times a busy or rate-limited answer is waited out.
   */
  private const int ATTEMPTS = 3;

  /**
   * What e-računi says when nothing matched a list's filter.
   *
   * An error by its status and an empty list by its meaning, so it is
   * answered as an empty result rather than a refusal.
   */
  private const string NOTHING_FOUND = '#noDocumentsFound';

  /**
   * How much of a request or an answer is logged: a PDF comes as base64.
   */
  private const int LOGGED_LENGTH = 16384;

  /**
   * How a pause is taken: sleep(), or nothing at all in a test.
   *
   * @var \Closure(int): void
   */
  private \Closure $sleep;

  public function __construct(
    private ClientInterface $http,
    private LoggerInterface $logger,
    private Settings $settings,
    ?\Closure $sleep = NULL,
  ) {
    $this->sleep = $sleep ?? static function (int $seconds): void {
      sleep($seconds);
    };
  }

  /**
   * {@inheritdoc}
   */
  public function isConfigured(): bool {
    $credentials = $this->credentials();

    return $credentials['username'] !== '' && $credentials['secret_key'] !== '' && $credentials['token'] !== '';
  }

  /**
   * {@inheritdoc}
   */
  public function call(string $method, array $parameters = []): array {
    if (!$this->isConfigured()) {
      throw EracuniException::configuration('e-računi has no credentials: $settings[\'eracuni\'] is not set.');
    }

    $credentials = $this->credentials();
    $body = json_encode([
      'username' => $credentials['username'],
      'secretKey' => $credentials['secret_key'],
      'token' => $credentials['token'],
      'method' => $method,
      'parameters' => (object) $parameters,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    $logTraffic = $this->logsTraffic();
    if ($logTraffic) {
      $this->logger->debug('e-računi @method sent: @parameters', [
        '@method' => $method,
        '@parameters' => $this->clip(json_encode((object) $parameters, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)),
      ]);
    }

    $attempt = 0;
    while (TRUE) {
      $attempt++;
      try {
        $response = $this->http->request('POST', $credentials['endpoint'], [
          'body' => $body,
          'headers' => ['Content-Type' => 'application/json'],
          'http_errors' => FALSE,
          'timeout' => 60,
          // e-računi answers `Content-Encoding: 8-bit`, which curl refuses to
          // decode. The bodies are never compressed.
          'decode_content' => FALSE,
        ]);
      }
      catch (GuzzleException $e) {
        throw EracuniException::transport(sprintf('e-računi %s: %s', $method, $e->getMessage()), $e);
      }

      $status = $response->getStatusCode();
      $raw = (string) $response->getBody();
      if ($logTraffic) {
        $this->logger->debug('e-računi @method answered HTTP @status: @answer', [
          '@method' => $method,
          '@status' => $status,
          '@answer' => $this->clip($raw),
        ]);
      }

      if (($status === 429 || $this->isBusy($raw)) && $attempt < self::ATTEMPTS) {
        $this->logger->notice('e-računi was busy on @method; waiting before attempt @n.', [
          '@method' => $method,
          '@n' => $attempt + 1,
        ]);
        ($this->sleep)(5 * $attempt);
        continue;
      }

      return $this->answer($method, $status, $raw);
    }
  }

  /**
   * The result of an answer, or the reason there is none.
   *
   * Errors from e-računi come with a body even under HTTP 500, so the body is
   * read before the status is: an explicit error is a refusal whatever the
   * status, and only an answer that is not e-računi's at all is a transport
   * failure.
   *
   * @return array<string, mixed>
   *   The result.
   */
  private function answer(string $method, int $status, string $raw): array {
    try {
      $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      $decoded = NULL;
    }

    $answer = is_array($decoded) && is_array($decoded['response'] ?? NULL) ? $decoded['response'] : NULL;
    if ($answer === NULL) {
      throw EracuniException::transport(sprintf('e-računi %s: HTTP %d and no answer of its own (%s).', $method, $status, mb_substr($raw, 0, 200)));
    }

    $description = trim((string) ($answer['description'] ?? ''));
    if (($answer['status'] ?? '') === 'ok') {
      $result = $answer['result'] ?? [];
      return is_array($result) ? $result : ['value' => $result];
    }

    if (str_contains($description, self::NOTHING_FOUND)) {
      return [];
    }

    throw EracuniException::refused(sprintf('e-računi refused %s: %s', $method, $description !== '' ? $description : 'no reason given'));
  }

  /**
   * Whether the answer is e-računi's one-request-at-a-time guard.
   */
  private function isBusy(string $raw): bool {
    return str_contains($raw, 'Another web request is currently being processed')
      || str_contains($raw, 'Concurrent usage of atomic API operations');
  }

  /**
   * Whether requests and answers are logged.
   *
   * Off unless settings.php says so: an invoice carries its buyer's name,
   * address and tax id. The credentials are never logged; they travel beside
   * the parameters, not in them.
   */
  private function logsTraffic(): bool {
    $set = $this->settings->get('eracuni', []);

    return is_array($set) && !empty($set['log_traffic']);
  }

  /**
   * A logged text, cut to LOGGED_LENGTH.
   */
  private function clip(string|false $text): string {
    $text = (string) $text;
    if (strlen($text) <= self::LOGGED_LENGTH) {
      return $text;
    }

    return mb_strcut($text, 0, self::LOGGED_LENGTH) . sprintf('… (%d bytes in all)', strlen($text));
  }

  /**
   * The credentials, each a string.
   *
   * @return array{username: string, secret_key: string, token: string, endpoint: string}
   *   Credentials and the endpoint.
   */
  private function credentials(): array {
    $set = $this->settings->get('eracuni', []);
    $set = is_array($set) ? $set : [];

    return [
      'username' => (string) ($set['username'] ?? ''),
      'secret_key' => (string) ($set['secret_key'] ?? ''),
      'token' => (string) ($set['token'] ?? ''),
      'endpoint' => (string) ($set['endpoint'] ?? self::DEFAULT_ENDPOINT),
    ];
  }

}
