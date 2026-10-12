<?php

declare(strict_types=1);

namespace Drupal\views_network\EdgeSource;

use Drupal\Core\Cache\UnchangingCacheableDependencyTrait;
use Drupal\Core\Plugin\PluginBase;

/**
 * Base class for network edge sources.
 */
abstract class EdgeSourcePluginBase extends PluginBase implements EdgeSourceInterface {

  use UnchangingCacheableDependencyTrait;

  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration(): array {
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): static {
    $this->configuration = $configuration + $this->defaultConfiguration();
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(string $entity_type_id): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array $values): array {
    return array_intersect_key($values, $this->defaultConfiguration());
  }

}
