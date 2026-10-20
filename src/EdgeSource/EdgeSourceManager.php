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
   * Returns all edges of the enabled edge sources that touch the entities.
   *
   * @param array $config
   *   Edge source configuration, keyed by plugin ID.
   * @param \Drupal\Core\Entity\EntityInterface[] $entities
   *   The entities, keyed by ID, all of one entity type.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cacheability of the edge sources.
   *
   * @return \Drupal\network_views\EdgeSource\Edge[]
   *   The edges, also to and from other entities.
   *
   * @see \Drupal\network_views\EdgeSource\EdgeSourceInterface::getAllEdgesOf()
   */
  public function getAllEdgesOf(array $config, array $entities, CacheableMetadata $cacheability): array {
    $first = reset($entities);
    if (!$first) {
      return [];
    }
    $edges = [];
    foreach ($this->createEnabled($config, $first->getEntityTypeId()) as $edge_source) {
      $cacheability->addCacheableDependency($edge_source);
      array_push($edges, ...$edge_source->getAllEdgesOf($entities));
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

  /**
   * Returns the edge types and groups of the applicable edge sources.
   *
   * @param string $entity_type_id
   *   The entity type of the graph nodes.
   *
   * @return array
   *   Two arrays of labels: the edge types and the groups, keyed by key. An
   *   edge source without groups has its edge types as groups.
   */
  public function getEdgeTypesAndGroups(string $entity_type_id): array {
    $types = [];
    $groups = [];
    foreach (array_keys($this->getDefinitions()) as $plugin_id) {
      $edge_source = $this->createInstance($plugin_id);
      assert($edge_source instanceof EdgeSourceInterface);
      if (!$edge_source->isApplicable($entity_type_id)) {
        continue;
      }
      $source_types = $edge_source->getEdgeTypes($entity_type_id);
      $types += $source_types;
      $groups += $edge_source->getEdgeGroups($entity_type_id) ?: $source_types;
    }
    return [$types, $groups];
  }

  /**
   * Builds the form to show edge types as shared links.
   *
   * Shared links connect the items that share a neighbour, e.g. initiatives
   * organised by the same organisation, instead of linking each item to that
   * neighbour (a one-mode projection of the network).
   *
   * @param array $shared_links
   *   The current shared links configuration; see GraphBuilder::build().
   * @param string $entity_type_id
   *   The entity type of the graph nodes.
   * @param string $name
   *   The HTML name of the edge source configuration element (see
   *   buildConfigurationForm()), to show the edge types of an edge source
   *   only when it is enabled.
   *
   * @return array
   *   The form element. Clean the submitted values with
   *   submitSharedLinksForm().
   */
  public function buildSharedLinksForm(array $shared_links, string $entity_type_id, string $name): array {
    $current = array_column($shared_links, NULL, 'edge_type');
    $element = [
      '#type' => 'details',
      '#title' => $this->t('Shared links'),
      '#description' => $this->t('Instead of an edge to the item they share, connect the items that share it with each other: e.g. for "Organised by" (initiative to organisation), link the initiatives organised by the same organisation. The items they share do not have to be in the graph. For relation nodes, an edge goes from the first related item to the second. This is also known as a one-mode projection.'),
      '#open' => (bool) $current,
      '#tree' => TRUE,
    ];
    foreach (array_keys($this->getDefinitions()) as $plugin_id) {
      $edge_source = $this->createInstance($plugin_id);
      assert($edge_source instanceof EdgeSourceInterface);
      if (!$edge_source->isApplicable($entity_type_id) || !($types = $edge_source->getEdgeTypes($entity_type_id))) {
        continue;
      }
      $element[$plugin_id] = [
        '#type' => 'table',
        '#caption' => $edge_source->getPluginDefinition()['label'],
        '#header' => [
          $this->t('Edge type'),
          $this->t('Show as'),
          $this->t('Label of the shared links'),
          $this->t('Leave out shared items with more than'),
        ],
        '#states' => [
          'visible' => [':input[name="' . $name . '[' . $plugin_id . '][enabled]"]' => ['checked' => TRUE]],
        ],
      ];
      foreach ($types as $type => $label) {
        $config = $current[$type] ?? [];
        $element[$plugin_id][$type] = [
          'label' => ['#plain_text' => $label],
          'mode' => [
            '#type' => 'select',
            '#title' => $this->t('Show @type as', ['@type' => $label]),
            '#title_display' => 'invisible',
            '#options' => [
              '' => $this->t('Direct edges'),
              'target' => $this->t('Links between items with the same target'),
              'source' => $this->t('Links between items with the same source'),
            ],
            '#default_value' => $config['mode'] ?? '',
          ],
          'shared_label' => [
            '#type' => 'textfield',
            '#title' => $this->t('Label of the shared links of @type', ['@type' => $label]),
            '#title_display' => 'invisible',
            '#placeholder' => $this->t('Shared: @type', ['@type' => $label]),
            '#default_value' => $config['label'] ?? '',
            '#size' => 20,
          ],
          'max' => [
            '#type' => 'number',
            '#title' => $this->t('Leave out shared items of @type with more than this number of items', ['@type' => $label]),
            '#title_display' => 'invisible',
            '#description' => $this->t('items; empty for no limit'),
            '#min' => 2,
            // 0 is stored for no limit, shown as an empty field.
            '#default_value' => ($config['max'] ?? 0) ?: NULL,
            '#size' => 4,
          ],
        ];
      }
    }
    if (!Element::children($element)) {
      return [];
    }
    return $element;
  }

  /**
   * Returns the shared links configuration from submitted form values.
   *
   * @param array $values
   *   The submitted values of the element of buildSharedLinksForm().
   *
   * @return array
   *   A list of shared links settings, each with the keys "edge_type",
   *   "mode", "label" and "max"; see GraphBuilder::build().
   */
  public function submitSharedLinksForm(array $values): array {
    $shared_links = [];
    foreach ($values as $types) {
      foreach (is_array($types) ? $types : [] as $type => $row) {
        if (!is_array($row) || !in_array($row['mode'] ?? '', ['target', 'source'], TRUE)) {
          continue;
        }
        $shared_links[] = [
          'edge_type' => (string) $type,
          'mode' => $row['mode'],
          'label' => trim((string) ($row['shared_label'] ?? '')),
          'max' => (int) ($row['max'] ?? 0),
        ];
      }
    }
    return $shared_links;
  }

}
