<?php

declare(strict_types=1);

namespace Drupal\views_network\EdgeSource;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\views_network\Attribute\NetworkEdgeSource;

/**
 * Plugin manager for network edge sources.
 */
class EdgeSourceManager extends DefaultPluginManager {

  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/NetworkEdgeSource',
      $namespaces,
      $module_handler,
      EdgeSourceInterface::class,
      NetworkEdgeSource::class,
    );
    $this->alterInfo('views_network_edge_source_info');
    $this->setCacheBackend($cache_backend, 'views_network_edge_source_plugins');
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinitions(): array {
    $definitions = parent::getDefinitions();
    uasort($definitions, fn(array $a, array $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));
    return $definitions;
  }

}
