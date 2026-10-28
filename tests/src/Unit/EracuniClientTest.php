<?php

declare(strict_types=1);

namespace Drupal\Tests\eracuni\Unit;

use Drupal\Core\Site\Settings;
use Drupal\eracuni\Client\EracuniClient;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;

/**
 * What the client logs of a call, and what it never does.
 */
#[Group('eracuni')]
final class EracuniClientTest extends UnitTestCase {

  /**
   * The logger of the last client made, keeping what it was given.
   *
   * @var \Psr\Log\AbstractLogger&object{messages: list<array{0: string, 1: string}>}
   */
  private AbstractLogger $logger;

  /**
   * Off by default: a call logs nothing of what it sent or got.
   */
  public function testTrafficIsNotLoggedByDefault(): void {
    $this->client([])->call('SalesInvoiceList', ['dateFrom' => '2026-10-01']);

    $this->assertSame([], $this->logger->messages);
  }

  /**
   * On, the parameters and the answer are logged, the credentials never.
   */
  public function testTrafficIsLoggedWithoutCredentials(): void {
    $result = $this->client(['log_traffic' => TRUE])->call('SalesInvoiceList', ['buyerName' => 'Kupac d.o.o.']);

    $this->assertSame(['number' => '2026-1'], $result);
    $this->assertCount(2, $this->logger->messages);
    [[$sentLevel, $sent], [$answerLevel, $answer]] = $this->logger->messages;
    $this->assertSame('debug', $sentLevel);
    $this->assertStringContainsString('SalesInvoiceList sent', $sent);
    $this->assertStringContainsString('Kupac d.o.o.', $sent);
    $this->assertSame('debug', $answerLevel);
    $this->assertStringContainsString('answered HTTP 200', $answer);
    $this->assertStringContainsString('2026-1', $answer);
    foreach (['user-name', 'secret-key-value', 'token-value'] as $credential) {
      $this->assertStringNotContainsString($credential, $sent . $answer);
    }
  }

  /**
   * A long answer, a PDF in base64, is cut and says how long it was.
   */
  public function testLongAnswerIsCut(): void {
    $pdf = str_repeat('A', 40000);
    $body = (string) json_encode([
      'response' => ['status' => 'ok', 'result' => ['content' => $pdf]],
    ]);
    $this->client(['log_traffic' => TRUE], $body)->call('SalesInvoiceGetPDF', ['number' => '2026-1']);

    $answer = $this->logger->messages[1][1];
    $this->assertLessThan(17000, strlen($answer));
    $this->assertMatchesRegularExpression('/… \(\d+ bytes in all\)$/', $answer);
  }

  /**
   * A client with these settings beside the credentials, answering $body.
   *
   * @param array<string, mixed> $extra
   *   Settings beside the credentials.
   * @param string|null $body
   *   The answer; an invoice number by default.
   */
  private function client(array $extra, ?string $body = NULL): EracuniClient {
    $body ??= (string) json_encode(['response' => ['status' => 'ok', 'result' => ['number' => '2026-1']]]);
    $http = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], $body)]))]);
    $logger = new class() extends AbstractLogger {

      /**
       * Messages logged, each rendered: level, then text.
       *
       * @var list<array{0: string, 1: string}>
       */
      public array $messages = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, \Stringable|string $message, array $context = []): void {
        $this->messages[] = [(string) $level, strtr((string) $message, array_map('strval', $context))];
      }

    };
    $this->logger = $logger;

    $settings = new Settings([
      'eracuni' => [
        'username' => 'user-name',
        'secret_key' => 'secret-key-value',
        'token' => 'token-value',
      ] + $extra,
    ]);

    return new EracuniClient($http, $logger, $settings, static function (): void {});
  }

}
