<?php

declare(strict_types=1);

namespace Drupal\Tests\eracuni\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\eracuni\Client\EracuniClientInterface;
use Drupal\KernelTests\KernelTestBase;

/**
 * The module over a stub e-računi, with one stub source and private files.
 */
abstract class EracuniKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = ['system', 'user', 'field', 'file', 'options', 'eracuni'];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);

    $container->register(EracuniClientInterface::class, StubClient::class);
    $container->register('eracuni_test.source', StubSource::class)
      ->addTag('eracuni.source');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('eracuni_document');
    $this->installConfig(['eracuni']);
    $this->config('eracuni.settings')
      ->set('reference_prefix', 'test-')
      ->set('default_kpd', 'J63.11.10')
      ->save();
    StubClient::$calls = [];
    StubClient::$answers = [];
  }

}
