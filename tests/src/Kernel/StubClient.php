<?php

declare(strict_types=1);

namespace Drupal\Tests\eracuni\Kernel;

use Drupal\eracuni\Client\EracuniClientInterface;
use Drupal\eracuni\Client\EracuniException;

/**
 * Answers from a list a test fills, and remembers what it was asked.
 */
final class StubClient implements EracuniClientInterface {

  /**
   * What it was asked, in order.
   *
   * @var array<int, array{method: string, parameters: array<string, mixed>}>
   */
  public static array $calls = [];

  /**
   * What it answers next, first in first out.
   *
   * A result, or an exception to throw.
   *
   * @var array<int, array<string, mixed>|\Drupal\eracuni\Client\EracuniException>
   */
  public static array $answers = [];

  /**
   * {@inheritdoc}
   */
  public function call(string $method, array $parameters = []): array {
    self::$calls[] = ['method' => $method, 'parameters' => $parameters];
    $answer = array_shift(self::$answers) ?? throw new \LogicException('No answer queued for ' . $method);

    if ($answer instanceof EracuniException) {
      throw $answer;
    }

    return $answer;
  }

  /**
   * {@inheritdoc}
   */
  public function isConfigured(): bool {
    return TRUE;
  }

}
