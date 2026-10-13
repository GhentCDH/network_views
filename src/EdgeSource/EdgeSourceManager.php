<?php

declare(strict_types=1);

namespace Drupal\network_views\EdgeSource;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\network_views\Attribute\NetworkEdgeSource;

/**
 * Plugin manager for network edge sources.
 *
 * Besides discovery, it handles edge source configuration as stored by the
 * network Views style and other users: an array keyed by plugin ID, each with
 * "enabled" and "settings".
 */
class EdgeSourceManager extends DefaultPluginManager {

  use StringTranslationTrait;

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

  /**
   * Creates the enabled edge sources of a configuration.
   *
   * @param array $config
   *   Edge source configuration, keyed by plugin ID.
   * @param string $entity_type_id
   *   The entity type of the graph nodes; edge sources that do not apply to
   *   it are left out.
   *
   * @return \Drupal\network_views\EdgeSource\EdgeSourceInterface[]
   *   The edge sources, keyed by plugin ID.
   */
  public function createEnabled(array $config, string $entity_type_id): array {
    $edge_sources = [];
    foreach ($config as $plugin_id => $options) {
      if (empty($options['enabled']) || !$this->hasDefinition($plugin_id)) {
        continue;
      }
      $edge_source = $this->createInstance($plugin_id, $options['settings'] ?? []);
      assert($edge_source instanceof EdgeSourceInterface);
      if ($edge_source->isApplicable($entity_type_id)) {
        $edge_sources[$plugin_id] = $edge_source;
      }
    }
    return $edge_sources;
  }

  /**
   * Returns the edges of the enabled edge sources between entities.
   *
   * @param array $config
   *   Edge source configuration, keyed by plugin ID.
   * @param \Drupal\Core\Entity\EntityInterface[] $entities
   *   The entities, keyed by ID, all of one entity type.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cacheability of the edge sources.
   *
   * @return \Drupal\network_views\EdgeSource\Edge[]
   *   The edges; they may also lead to other entities.
   */
  public function getEdges(array $config, array $entities, CacheableMetadata $cacheability): array {
    $first = reset($entities);
    if (!$first) {
      return [];
    }
    $edges = [];
    foreach ($this->createEnabled($config, $first->getEntityTypeId()) as $edge_source) {
      $cacheability->addCacheableDependency($edge_source);
      array_push($edges, ...$edge_source->getEdges($entities));
    }
    return $edges;
  }

  /**
   * Returns the edges of the enabled edge sources that touch one entity.
   *
   * @param array $config
   *   Edge source configuration, keyed by plugin ID.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cacheability of the edge sources.
   *
   * @return \Drupal\network_views\EdgeSource\Edge[]
   *   The edges.
   */
  public function getEdgesOf(array $config, EntityInterface $entity, CacheableMetadata $cacheability): array {
    $edges = [];
    foreach ($this->createEnabled($config, $entity->getEntityTypeId()) as $edge_source) {
      $cacheability->addCacheableDependency($edge_source);
      array_push($edges, ...$edge_source->getEdgesOf($entity));
    }
    return $edges;
  }

  /**
   * Builds the settings form for an edge source configuration.
   *
   * @param array $config
   *   The current configuration, keyed by plugin ID.
   * @param string $entity_type_id
   *   The entity type of the graph nodes.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $name
   *   The HTML name of the element, e.g. "style_options[edge_sources]", used
   *   to show the settings of an edge source only when it is enabled.
   *
   * @return array
   *   The form element. Clean the submitted values with
   *   submitConfigurationForm().
   */
  public function buildConfigurationForm(array $config, string $entity_type_id, FormStateInterface $form_state, string $name): array {
    $element = ['#type' => 'container', '#tree' => TRUE];
    foreach (array_keys($this->getDefinitions()) as $plugin_id) {
      $edge_source = $this->createInstance($plugin_id, $config[$plugin_id]['settings'] ?? []);
      assert($edge_source instanceof EdgeSourceInterface);
      if (!$edge_source->isApplicable($entity_type_id)) {
        continue;
      }
      $definition = $edge_source->getPluginDefinition();
      $element[$plugin_id] = [
        '#type' => 'fieldset',
        '#title' => $definition['label'],
      ];
      $element[$plugin_id]['enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enabled'),
        '#description' => $definition['description'] ?? NULL,
        '#default_value' => !empty($config[$plugin_id]['enabled']),
      ];
      $element[$plugin_id]['settings'] = $edge_source->buildConfigurationForm([
        '#type' => 'container',
        '#states' => [
          'visible' => [':input[name="' . $name . '[' . $plugin_id . '][enabled]"]' => ['checked' => TRUE]],
        ],
      ], $form_state, $entity_type_id);
    }
    if (!Element::children($element)) {
      $element['#markup'] = $this->t('No edge source is available for this entity type.');
    }
    return $element;
  }

  /**
   * Returns the configuration to store from submitted settings form values.
   *
   * @param array $values
   *   The submitted values of the element of buildConfigurationForm().
   *
   * @return array
   *   The configuration, keyed by plugin ID.
   */
  public function submitConfigurationForm(array $values): array {
    $config = [];
    foreach ($values as $plugin_id => $plugin_values) {
      if (!is_array($plugin_values) || !$this->hasDefinition($plugin_id)) {
        continue;
      }
      $edge_source = $this->createInstance($plugin_id);
      assert($edge_source instanceof EdgeSourceInterface);
      $config[$plugin_id] = [
        'enabled' => !empty($plugin_values['enabled']),
        'settings' => $edge_source->submitConfigurationForm($plugin_values['settings'] ?? []),
      ];
    }
    return $config;
  }

}
