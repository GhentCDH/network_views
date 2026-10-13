<?php

declare(strict_types=1);

namespace Drupal\network_views\Graph;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Template\Attribute;
use Drupal\network_views\Palette;

/**
 * Builds network graphs from graph nodes and edges, and renders them.
 *
 * This is the part of a network that does not depend on where the nodes come
 * from: the network Views style and other modules (such as an ego network on
 * an entity page) decide which entities are nodes and how they are labeled,
 * and use this service for the rest.
 */
class GraphBuilder {

  use StringTranslationTrait;

  /**
   * The default options of build() and toRenderArray().
   */
  public const DEFAULT_OPTIONS = [
    // Whether edges have a direction.
    'directed' => TRUE,
    // Whether to leave out nodes without edges (except root nodes).
    'hide_isolated' => FALSE,
    // Nodes and edges that are not available in the current content
    // language: 'show' them, show them 'fade'd, or 'hide' them.
    'language' => 'show',
    // Colors per category and per edge type, as lines of "value|color".
    'colors' => '',
    'edge_colors' => '',
    // Whether to build a legend.
    'legend' => TRUE,
    // Display options for the renderer.
    'layout' => 'cose',
    'edge_labels' => FALSE,
    'size' => 'degree',
    'link' => TRUE,
    'height' => '600px',
  ];

  public function __construct(
    protected LanguageManagerInterface $languageManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
  ) {}

  /**
   * Builds the graph data.
   *
   * @param \Drupal\network_views\Graph\GraphNode[] $nodes
   *   The nodes, keyed by entity ID, all of one entity type.
   * @param \Drupal\network_views\EdgeSource\Edge[] $edges
   *   The edges; edges that do not connect two of the nodes are left out.
   * @param array $options
   *   Options; see self::DEFAULT_OPTIONS.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cacheability of the graph.
   *
   * @return array
   *   The graph, with keys:
   *   - nodes: Node data for the renderer.
   *   - edges: Edge data for the renderer.
   *   - legend: Legend items, under the keys "nodes" and "edges".
   */
  public function build(array $nodes, array $edges, array $options, CacheableMetadata $cacheability): array {
    $options += self::DEFAULT_OPTIONS;
    $first = reset($nodes);
    if (!$first) {
      return ['nodes' => [], 'edges' => [], 'legend' => []];
    }
    $entity_type_id = $first->entity->getEntityTypeId();

    if ($options['language'] !== 'show') {
      $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
      $cacheability->addCacheContexts(['languages:' . LanguageInterface::TYPE_CONTENT]);
      foreach ($nodes as $id => $node) {
        if (!$node->root && $this->isLanguageUnavailable($node->entity, $langcode, $cacheability)) {
          if ($options['language'] === 'hide') {
            unset($nodes[$id]);
          }
          else {
            $node->faded = TRUE;
          }
        }
      }
    }

    $roots = array_filter($nodes, fn(GraphNode $node) => $node->root);
    $graph_edges = [];
    foreach ($edges as $edge) {
      if ($edge->sourceEntityType !== $entity_type_id
        || $edge->targetEntityType !== $entity_type_id
        || !isset($nodes[$edge->sourceId], $nodes[$edge->targetId])
        || (string) $edge->sourceId === (string) $edge->targetId
        || ($edge->languageUnavailable && $options['language'] === 'hide')) {
        continue;
      }
      $source = $edge->sourceId;
      $target = $edge->targetId;
      $label = $edge->typeLabel;
      // Read edges from the root: "A teacher of root" becomes "root student
      // of A" when the edge has a reverse label.
      if (isset($roots[$target]) && !isset($roots[$source]) && $edge->reverseLabel !== NULL) {
        [$source, $target, $label] = [$target, $source, $edge->reverseLabel];
      }
      $source = $this->key($entity_type_id, $source);
      $target = $this->key($entity_type_id, $target);
      $id = $edge->id;
      if (!$options['directed']) {
        $pair = [$source, $target];
        sort($pair);
        $id = $label . '|' . implode('|', $pair);
      }
      $faded = $edge->languageUnavailable && $options['language'] === 'fade';
      // Of duplicate edges, an available one wins.
      if (isset($graph_edges[$id]) && (!$graph_edges[$id]['faded'] || $faded)) {
        continue;
      }
      $graph_edges[$id] = [
        'id' => $id,
        'source' => $source,
        'target' => $target,
        'type' => $label,
        'label' => $label,
        'faded' => $faded,
      ];
    }

    if ($options['hide_isolated']) {
      $connected = [];
      foreach ($graph_edges as $edge) {
        $connected[$edge['source']] = TRUE;
        $connected[$edge['target']] = TRUE;
      }
      $nodes = array_filter($nodes, fn(GraphNode $node) => $node->root || isset($connected[$this->key($entity_type_id, $node->entity->id())]));
    }

    $graph_nodes = [];
    $categories = [];
    $data_colors = [];
    foreach ($nodes as $id => $node) {
      $cacheability->addCacheableDependency($node->entity);
      $data = [
        'id' => $this->key($entity_type_id, $id),
        'label' => $node->label,
        'category' => $node->category,
        'tooltip' => $node->tooltip,
        'root' => $node->root,
        'faded' => $node->faded,
      ];
      if ($options['link'] && $node->entity->hasLinkTemplate('canonical')) {
        $url = $node->entity->toUrl()->toString(TRUE);
        $cacheability->addCacheableDependency($url);
        $data['url'] = $url->getGeneratedUrl();
      }
      if ($node->color !== NULL && Palette::isColor($node->color)) {
        $data['color'] = $node->color;
        $data_colors[$node->category] ??= $node->color;
      }
      $categories[$node->category] = ($categories[$node->category] ?? 0) + 1;
      $graph_nodes[] = $data;
    }

    // Colors per category: from the data, the configured colors or the
    // palette, in that order.
    $category_colors = $data_colors + Palette::assign(array_keys($categories), Palette::parse($options['colors']));
    foreach ($graph_nodes as &$data) {
      $data['color'] ??= $category_colors[$data['category']];
    }
    unset($data);

    $edge_types = [];
    foreach ($graph_edges as $edge) {
      $edge_types[$edge['type']] = ($edge_types[$edge['type']] ?? 0) + 1;
    }
    $edge_overrides = Palette::parse($options['edge_colors']);
    $edge_colors = count($edge_types) === 1 && !$edge_overrides
      ? [array_key_first($edge_types) => Palette::NEUTRAL]
      : Palette::assign(array_keys($edge_types), $edge_overrides);
    foreach ($graph_edges as &$edge) {
      $edge['color'] = $edge_colors[$edge['type']];
    }
    unset($edge);

    $legend = [];
    if ($options['legend']) {
      ksort($categories, SORT_NATURAL | SORT_FLAG_CASE);
      ksort($edge_types, SORT_NATURAL | SORT_FLAG_CASE);
      // Without categories, all nodes have one color and need no legend.
      if (array_keys($categories) !== ['']) {
        foreach ($categories as $category => $count) {
          $legend['nodes'][] = ['label' => $category, 'color' => $category_colors[$category], 'count' => $count];
        }
      }
      foreach ($edge_types as $type => $count) {
        $legend['edges'][] = ['label' => $type, 'color' => $edge_colors[$type], 'count' => $count];
      }
    }

    return [
      'nodes' => $graph_nodes,
      'edges' => array_values($graph_edges),
      'legend' => $legend,
    ];
  }

  /**
   * Returns the render array of a graph.
   *
   * @param array $graph
   *   A graph from build().
   * @param array $options
   *   Options; see self::DEFAULT_OPTIONS.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   The cacheability of the graph, applied to the render array.
   */
  public function toRenderArray(array $graph, array $options, CacheableMetadata $cacheability): array {
    $options += self::DEFAULT_OPTIONS;
    $data = [
      'nodes' => $graph['nodes'],
      'edges' => $graph['edges'],
      'settings' => [
        'layout' => $options['layout'],
        'directed' => (bool) $options['directed'],
        'edgeLabels' => (bool) $options['edge_labels'],
        'size' => $options['size'],
        'link' => (bool) $options['link'],
      ],
    ];
    $build = [
      '#theme' => 'network_views',
      '#attributes' => ['class' => ['network-views']],
      '#canvas_attributes' => new Attribute([
        'class' => ['network-views__canvas'],
        'style' => 'height: ' . $options['height'],
        'data-network-views' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'role' => 'img',
        'aria-label' => $this->t('Network of @nodes items and @edges connections', [
          '@nodes' => count($graph['nodes']),
          '@edges' => count($graph['edges']),
        ]),
      ]),
      '#legend' => $graph['legend'],
      '#attached' => ['library' => ['network_views/network']],
    ];
    $cacheability->applyTo($build);
    return $build;
  }

  /**
   * Whether an entity is not available in a language.
   *
   * An entity is available when it has a translation in the language that the
   * current user may view (for visitors: a published one). Entities in a
   * locked language (not specified, not applicable) are always available.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string $langcode
   *   The language code.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cacheability of the access checks.
   */
  public function isLanguageUnavailable(EntityInterface $entity, string $langcode, CacheableMetadata $cacheability): bool {
    if (!$entity instanceof TranslatableInterface || $entity->language()->isLocked()) {
      return FALSE;
    }
    if (!$entity->hasTranslation($langcode)) {
      return TRUE;
    }
    $access = $entity->getTranslation($langcode)->access('view', NULL, TRUE);
    $cacheability->addCacheableDependency($access);
    return !$access->isAllowed();
  }

  /**
   * Returns the label of the bundle of an entity, e.g. as color category.
   */
  public function bundleLabel(EntityInterface $entity): string {
    return (string) ($this->bundleInfo->getBundleInfo($entity->getEntityTypeId())[$entity->bundle()]['label'] ?? $entity->bundle());
  }

  /**
   * Returns the graph ID of an entity.
   */
  protected function key(string $entity_type_id, int|string $id): string {
    return $entity_type_id . ':' . $id;
  }

}
