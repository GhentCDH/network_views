<?php

declare(strict_types=1);

namespace Drupal\network_views\EdgeSource;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\network_views\Attribute\NetworkEdgeSource;

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
    $this->alterInfo('network_views_edge_source_info');
    $this->setCacheBackend($cache_backend, 'network_views_edge_source_plugins');
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
