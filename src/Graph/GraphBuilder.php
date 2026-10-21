<?php

declare(strict_types=1);

namespace Drupal\network_views\Graph;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
    // Colors of node categories, keyed by color key (GraphNode::$colorKey,
    // e.g. "node:person" or "taxonomy_term:12").
    'node_colors' => [],
    // How edges are colored: 'type', 'group', 'shared_item' or 'none'; see
    // colorEdges().
    'edge_color_by' => 'type',
    // Colors of edge types, groups or shared items, keyed by their key (e.g.
    // "field:field_knows", "relation_type:12" or "taxonomy_term:5").
    'edge_color_map' => [],
    // Older settings: colors by label, as lines of "label|color"; used for
    // categories without a color by key.
    'colors' => '',
    'edge_colors' => '',
    // Which targets of "show as node" edges become nodes: those of 'all'
    // nodes, or only those of 'root' nodes (ego networks).
    'extra_nodes' => 'all',
    // Edge types shown as shared links: a list of arrays with the keys
    // "edge_type" (Edge::$type), "mode" ('target' links the sources of edges
    // with the same target, 'source' the targets of edges with the same
    // source), "label" (of the links; empty for "Shared: <type label>") and
    // "max" (leave out shared items with more items in the graph; 0 for no
    // limit).
    'shared_links' => [],
    // Whether to build a legend.
    'legend' => TRUE,
    // Display options for the renderer.
    'layout' => 'cose',
    'edge_labels' => FALSE,
    'size' => 'degree',
    'link' => TRUE,
    'height' => '600px',
    // The root of an ego network: its shape, its size as a factor of the
    // size of other nodes, and its color (empty: the color of its category).
    'root_shape' => 'star',
    'root_size' => 1.6,
    'root_color' => '',
  ];

  /**
   * The shapes a root can have.
   */
  public const ROOT_SHAPES = ['star', 'diamond', 'triangle', 'hexagon', 'ellipse'];

  /**
   * The maximum number of shared items named in the tooltip of a link.
   */
  protected const MAX_TOOLTIP_ITEMS = 10;

  public function __construct(
    protected LanguageManagerInterface $languageManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityRepositoryInterface $entityRepository,
  ) {}

  /**
   * Builds the graph data.
   *
   * @param \Drupal\network_views\Graph\GraphNode[] $nodes
   *   The nodes, keyed by entity ID, all of one entity type.
   * @param \Drupal\network_views\EdgeSource\Edge[] $edges
   *   The edges; edges that do not connect two of the nodes are left out,
   *   except edges of shared links types, which may lead to the shared items
   *   outside the graph.
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
    // Nodes by graph key ("node:12"): a graph can hold several entity types.
    $by_key = [];
    foreach ($nodes as $node) {
      $by_key[$this->entityKey($node->entity)] = $node;
    }
    if (!$by_key) {
      return ['nodes' => [], 'edges' => [], 'legend' => []];
    }
    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
    foreach ($by_key as $key => $node) {
      $access = $node->entity->access('view', NULL, TRUE);
      $cacheability->addCacheableDependency($access);
      if ($access->isAllowed()) {
        continue;
      }
      // The user may not view this translation (e.g. it is unpublished): the
      // label of one the user may view instead, and no tooltip of this one.
      $viewable = $this->viewableTranslation($node->entity, $cacheability, $langcode);
      if (!$viewable) {
        unset($by_key[$key]);
      }
      else {
        $by_key[$key] = new GraphNode(
          entity: $viewable,
          label: (string) $viewable->label(),
          category: $node->category,
          color: $node->color,
          root: $node->root,
          faded: $node->faded,
          colorKey: $node->colorKey,
        );
      }
    }
    if ($options['language'] !== 'show') {
      $cacheability->addCacheContexts(['languages:' . LanguageInterface::TYPE_CONTENT]);
      foreach ($by_key as $key => $node) {
        if (!$node->root && $this->isLanguageUnavailable($node->entity, $langcode, $cacheability)) {
          if ($options['language'] === 'hide') {
            unset($by_key[$key]);
          }
          else {
            $node->faded = TRUE;
          }
        }
      }
    }
    $by_key += $this->extraNodes($by_key, $edges, $options, $langcode, $cacheability);

    $roots = array_filter($by_key, fn(GraphNode $node) => $node->root);
    $shared_links = array_column($options['shared_links'] ?: [], NULL, 'edge_type');
    $shared_edges = [];
    $graph_edges = [];
    foreach ($edges as $edge) {
      if (isset($shared_links[$edge->type])) {
        $shared_edges[] = $edge;
        continue;
      }
      $source = $this->key($edge->sourceEntityType, $edge->sourceId);
      $target = $this->key($edge->targetEntityType, $edge->targetId);
      if (!isset($by_key[$source], $by_key[$target])
        || $source === $target
        || ($edge->languageUnavailable && $options['language'] === 'hide')) {
        continue;
      }
      $label = $edge->typeLabel;
      // Read edges from the root: "A teacher of root" becomes "root student
      // of A" when the edge has a reverse label.
      if (isset($roots[$target]) && !isset($roots[$source]) && $edge->reverseLabel !== NULL) {
        [$source, $target, $label] = [$target, $source, $edge->reverseLabel];
      }
      $id = $edge->id;
      if (!$options['directed']) {
        $pair = [$source, $target];
        sort($pair);
        $id = $edge->type . '|' . $label . '|' . implode('|', $pair);
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
        // Color keys and legend labels of the edge, per coloring mode.
        '_type_key' => $edge->type,
        '_type_label' => $edge->typeLabel,
        '_group_key' => $edge->group ?? $edge->type,
        '_group_label' => $edge->groupLabel ?? $edge->typeLabel,
      ];
    }

    $graph_edges += $this->sharedLinks($by_key, $shared_edges, $shared_links, $options['language'], $cacheability);

    if ($options['hide_isolated']) {
      $connected = [];
      foreach ($graph_edges as $edge) {
        $connected[$edge['source']] = TRUE;
        $connected[$edge['target']] = TRUE;
      }
      $by_key = array_filter($by_key, fn(GraphNode $node, string $key) => $node->root || isset($connected[$key]), ARRAY_FILTER_USE_BOTH);
    }

    [$graph_nodes, $node_legend] = $this->colorNodes($by_key, $options, $cacheability);
    [$graph_edges, $edge_legend] = $this->colorEdges($graph_edges, $options);

    $legend = [];
    if ($options['legend']) {
      if ($node_legend) {
        $legend['nodes'] = $node_legend;
      }
      if ($edge_legend) {
        $legend['edges'] = $edge_legend;
      }
    }

    return [
      'nodes' => $graph_nodes,
      'edges' => array_values($graph_edges),
      'legend' => $legend,
    ];
  }

  /**
   * Returns the graph nodes for the missing ends of "show as node" edges.
   *
   * The end of an edge with Edge::$otherEndAsNode that is not a node yet,
   * when its other end is one, is added if the current user may view one of
   * its translations: preferably in the current language, colored by bundle.
   * With the "root" extra_nodes option (ego networks), only ends of edges of
   * a root are added.
   *
   * @param \Drupal\network_views\Graph\GraphNode[] $nodes
   *   The nodes, keyed by graph key.
   * @param \Drupal\network_views\EdgeSource\Edge[] $edges
   *   The edges.
   * @param array $options
   *   The options.
   * @param string $langcode
   *   The current content language.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cacheability of the added entities.
   *
   * @return \Drupal\network_views\Graph\GraphNode[]
   *   The extra nodes, keyed by graph key.
   */
  protected function extraNodes(array $nodes, array $edges, array $options, string $langcode, CacheableMetadata $cacheability): array {
    $targets = [];
    foreach ($edges as $edge) {
      if (!$edge->otherEndAsNode) {
        continue;
      }
      $source = $this->key($edge->sourceEntityType, $edge->sourceId);
      $target = $this->key($edge->targetEntityType, $edge->targetId);
      // The node end, and the missing end.
      [$known, $type, $id] = match (TRUE) {
        isset($nodes[$source]) && !isset($nodes[$target]) => [$nodes[$source], $edge->targetEntityType, $edge->targetId],
        isset($nodes[$target]) && !isset($nodes[$source]) => [$nodes[$target], $edge->sourceEntityType, $edge->sourceId],
        default => [NULL, NULL, NULL],
      };
      if (!$known || ($options['extra_nodes'] === 'root' && !$known->root)) {
        continue;
      }
      $targets[$type][$id] = TRUE;
    }
    $extra = [];
    foreach ($targets as $entity_type_id => $ids) {
      foreach ($this->entityTypeManager->getStorage($entity_type_id)->loadMultiple(array_keys($ids)) as $entity) {
        $entity = $this->viewableTranslation($entity, $cacheability, $langcode);
        if (!$entity) {
          continue;
        }
        $faded = FALSE;
        if ($options['language'] !== 'show' && $this->isLanguageUnavailable($entity, $langcode, $cacheability)) {
          if ($options['language'] === 'hide') {
            continue;
          }
          $faded = TRUE;
        }
        $extra[$this->entityKey($entity)] = new GraphNode(
          entity: $entity,
          label: (string) $entity->label(),
          category: $this->bundleLabel($entity),
          faded: $faded,
          colorKey: $this->bundleKey($entity),
        );
      }
    }
    return $extra;
  }

  /**
   * Returns the node data for the renderer and the node legend.
   *
   * Colors per category, keyed by the category's color key: from the data
   * (a color field), the configured colors (by key, or by label as in older
   * settings) or the palette. Palette colors are assigned by key, so they
   * are the same in every language.
   *
   * @param \Drupal\network_views\Graph\GraphNode[] $nodes
   *   The nodes, keyed by graph key.
   * @param array $options
   *   The options.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cacheability of the nodes.
   *
   * @return array
   *   The node data and the legend items.
   */
  protected function colorNodes(array $nodes, array $options, CacheableMetadata $cacheability): array {
    $graph_nodes = [];
    $categories = [];
    $data_colors = [];
    foreach ($nodes as $key => $node) {
      $cacheability->addCacheableDependency($node->entity);
      $color_key = $node->colorKey ?? $node->category;
      $data = [
        'id' => $key,
        'label' => $node->label,
        'category' => $node->category,
        'tooltip' => $node->tooltip,
        'root' => $node->root,
        'faded' => $node->faded,
        '_color_key' => $color_key,
      ];
      if ($options['link'] && $node->entity->hasLinkTemplate('canonical')) {
        $url = $node->entity->toUrl()->toString(TRUE);
        $cacheability->addCacheableDependency($url);
        $data['url'] = $url->getGeneratedUrl();
      }
      if ($node->color !== NULL && Palette::isColor($node->color)) {
        $data['color'] = $node->color;
        $data_colors[$color_key] ??= $node->color;
      }
      $categories[$color_key] ??= ['label' => $node->category, 'count' => 0];
      $categories[$color_key]['count']++;
      $graph_nodes[] = $data;
    }

    $colors = $data_colors + Palette::assign(array_keys($categories), $this->configuredColors($categories, $options['node_colors'], $options['colors']));
    $root_color = is_string($options['root_color']) && Palette::isColor($options['root_color']) ? $options['root_color'] : NULL;
    foreach ($graph_nodes as &$data) {
      $data['color'] ??= $colors[$data['_color_key']];
      if ($data['root'] && $root_color) {
        $data['color'] = $root_color;
      }
      unset($data['_color_key']);
    }
    unset($data);

    // Without categories, all nodes have one color and need no legend.
    $legend = [];
    if (array_keys($categories) !== ['']) {
      uasort($categories, fn(array $a, array $b) => strnatcasecmp($a['label'], $b['label']));
      foreach ($categories as $color_key => $category) {
        $legend[] = ['label' => $category['label'], 'color' => $colors[$color_key], 'count' => $category['count']];
      }
    }
    return [$graph_nodes, $legend];
  }

  /**
   * Colors the edges and returns their legend.
   *
   * By the edge_color_by option: 'type' (the edge type, e.g. a reference
   * field or a relation type), 'group' (e.g. a relation content type),
   * 'shared_item' (shared links by the item they share, grey when they share
   * several; other edges by type) or 'none' (one color, no legend).
   *
   * @param array $graph_edges
   *   The edge data, keyed by ID.
   * @param array $options
   *   The options.
   *
   * @return array
   *   The edge data and the legend items.
   */
  protected function colorEdges(array $graph_edges, array $options): array {
    $mode = $options['edge_color_by'];
    $categories = [];
    foreach ($graph_edges as &$edge) {
      [$key, $label] = match (TRUE) {
        $mode === 'none' => ['', ''],
        $mode === 'group' => [$edge['_group_key'], $edge['_group_label']],
        $mode === 'shared_item' && isset($edge['_shared_items']) => count($edge['_shared_items']) === 1
          ? [key($edge['_shared_items']), reset($edge['_shared_items'])]
          : ['_several', (string) $this->t('Several shared items')],
        default => [$edge['_type_key'], $edge['_type_label']],
      };
      $edge['_color_key'] = $key;
      $categories[$key] ??= ['label' => $label, 'count' => 0];
      $categories[$key]['count']++;
    }
    unset($edge);

    $configured = $this->configuredColors($categories, $options['edge_color_map'], $options['edge_colors']);
    $colors = Palette::assign(array_keys($categories), ['' => Palette::NEUTRAL, '_several' => Palette::NEUTRAL] + $configured);
    // A single kind of edge is grey, unless a color is configured for it.
    if (count($categories) === 1 && !$configured) {
      $colors = [array_key_first($categories) => Palette::NEUTRAL];
    }
    foreach ($graph_edges as &$edge) {
      $edge['color'] = $colors[$edge['_color_key']];
      unset($edge['_color_key'], $edge['_type_key'], $edge['_type_label'], $edge['_group_key'], $edge['_group_label'], $edge['_shared_items']);
    }
    unset($edge);

    $legend = [];
    if ($mode !== 'none') {
      uasort($categories, fn(array $a, array $b) => strnatcasecmp($a['label'], $b['label']));
      foreach ($categories as $key => $category) {
        $legend[] = ['label' => $category['label'], 'color' => $colors[$key], 'count' => $category['count']];
      }
    }
    return [$graph_edges, $legend];
  }

  /**
   * Returns the configured colors of categories, keyed by color key.
   *
   * @param array $categories
   *   The categories, keyed by color key, each with a "label".
   * @param array $by_key
   *   Configured colors keyed by color key.
   * @param string $by_label
   *   Configured colors as lines of "label|color", as older settings did;
   *   used for categories without a color by key.
   *
   * @return string[]
   *   The colors that are valid CSS colors, keyed by color key.
   */
  protected function configuredColors(array $categories, array $by_key, string $by_label): array {
    $labels = Palette::parse($by_label);
    $colors = [];
    foreach ($categories as $key => $category) {
      $color = $by_key[$key] ?? $labels[$category['label']] ?? NULL;
      if (is_string($color) && $color !== '' && Palette::isColor($color)) {
        $colors[$key] = $color;
      }
    }
    return $colors;
  }

  /**
   * Returns the shared links between nodes.
   *
   * Nodes that are the source (or target) of edges of a shared links type
   * with the same target (or source), the shared item, are linked once per
   * pair, whatever the number of items they share. Shared items that the
   * current user may not view are left out.
   *
   * @param \Drupal\network_views\Graph\GraphNode[] $nodes
   *   The nodes, keyed by graph key.
   * @param \Drupal\network_views\EdgeSource\Edge[] $edges
   *   The edges of shared links types.
   * @param array $shared_links
   *   The shared links settings, keyed by edge type.
   * @param string $language
   *   The language option: 'show', 'fade' or 'hide'.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cacheability of the shared items.
   *
   * @return array
   *   Edge data for the renderer, keyed by ID.
   */
  protected function sharedLinks(array $nodes, array $edges, array $shared_links, string $language, CacheableMetadata $cacheability): array {
    // For each edge type and shared item: its nodes, and whether their edge
    // to it is available in the current language.
    $groups = [];
    $type_labels = [];
    $group_info = [];
    foreach ($edges as $edge) {
      if ($edge->languageUnavailable && $language === 'hide') {
        continue;
      }
      [$member_type, $member, $shared_type, $shared] = $shared_links[$edge->type]['mode'] === 'source'
        ? [$edge->targetEntityType, $edge->targetId, $edge->sourceEntityType, $edge->sourceId]
        : [$edge->sourceEntityType, $edge->sourceId, $edge->targetEntityType, $edge->targetId];
      $member = $this->key($member_type, $member);
      if (!isset($nodes[$member])) {
        continue;
      }
      $type_labels[$edge->type] ??= $edge->typeLabel;
      $group_info[$edge->type] ??= [$edge->group ?? $edge->type, $edge->groupLabel ?? $edge->typeLabel];
      $groups[$edge->type][$shared_type][$shared][$member] = !empty($groups[$edge->type][$shared_type][$shared][$member]) || !$edge->languageUnavailable;
    }

    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
    $links = [];
    foreach ($groups as $type => $groups_of_type) {
      $settings = $shared_links[$type];
      $label = ($settings['label'] ?? '') !== '' ? $settings['label'] : (string) $this->t('Shared: @type', ['@type' => $type_labels[$type]]);
      $max = (int) ($settings['max'] ?? 0);
      foreach ($groups_of_type as $shared_type => $members_per_item) {
        // A shared item links at least two nodes, and at most "max".
        $members_per_item = array_filter($members_per_item, fn(array $members) => count($members) > 1 && (!$max || count($members) <= $max));
        if (!$members_per_item) {
          continue;
        }
        foreach ($this->entityTypeManager->getStorage($shared_type)->loadMultiple(array_keys($members_per_item)) as $shared_id => $shared) {
          $shared = $this->viewableTranslation($shared, $cacheability, $langcode);
          if (!$shared) {
            continue;
          }
          $cacheability->addCacheableDependency($shared);
          $unavailable = $language !== 'show' && $this->isLanguageUnavailable($shared, $langcode, $cacheability);
          if ($unavailable && $language === 'hide') {
            continue;
          }
          $shared_label = (string) $shared->label();
          $members = $members_per_item[$shared_id];
          $keys = array_map('strval', array_keys($members));
          sort($keys, SORT_NATURAL);
          foreach ($keys as $i => $a) {
            foreach (array_slice($keys, $i + 1) as $b) {
              $id = "shared:$type:$a:$b";
              $links[$id] ??= [
                'source' => $a,
                'target' => $b,
                'label' => $label,
                'type' => $type,
                'items' => [],
                'available' => FALSE,
              ];
              $links[$id]['items'][$this->key($shared_type, $shared_id)] = $shared_label;
              $links[$id]['available'] = $links[$id]['available'] || ($members[$a] && $members[$b] && !$unavailable);
            }
          }
        }
      }
    }

    $graph_edges = [];
    foreach ($links as $id => $link) {
      $items = array_values($link['items']);
      natcasesort($items);
      $named = array_slice($items, 0, static::MAX_TOOLTIP_ITEMS);
      $tooltip = Html::escape($link['label']) . ': ' . Html::escape(implode(', ', $named));
      if (count($items) > count($named)) {
        $tooltip .= ' ' . $this->formatPlural(count($items) - count($named), 'and 1 more', 'and @count more');
      }
      $graph_edges[$id] = [
        'id' => $id,
        'source' => $link['source'],
        'target' => $link['target'],
        'type' => $link['label'],
        'label' => $link['label'],
        'faded' => !$link['available'] && $language === 'fade',
        'weight' => count($items),
        'tooltip' => $tooltip,
        'undirected' => TRUE,
        // Shared links are colored by their own type; by group, as the group
        // of the edges they are built from; or by the items they share.
        '_type_key' => 'shared:' . $link['type'],
        '_type_label' => $link['label'],
        '_group_key' => 'shared:' . $group_info[$link['type']][0],
        '_group_label' => (string) $this->t('Shared: @type', ['@type' => $group_info[$link['type']][1]]),
        '_shared_items' => $link['items'],
      ];
    }
    return $graph_edges;
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
        'rootShape' => in_array($options['root_shape'], static::ROOT_SHAPES, TRUE) ? $options['root_shape'] : 'star',
        'rootSize' => max(1.0, min(4.0, (float) $options['root_size'])),
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
   * Returns a translation of an entity the current user may view.
   *
   * Translations can be published separately: an item whose original is
   * unpublished can have a published translation. The translation in the
   * language comes first, then the original, then the other translations.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cacheability of the access checks.
   * @param string|null $langcode
   *   The language code; the current content language by default.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The translation, or NULL when the user may view none.
   */
  public function viewableTranslation(EntityInterface $entity, CacheableMetadata $cacheability, ?string $langcode = NULL): ?EntityInterface {
    $langcode ??= $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
    $candidates = [$entity];
    if ($entity instanceof TranslatableInterface && !$entity->language()->isLocked()) {
      // The translation in the language, the original, then the others.
      $candidates = [];
      if ($entity->hasTranslation($langcode)) {
        $candidates[$langcode] = $entity->getTranslation($langcode);
      }
      $original = $entity->getUntranslated();
      $candidates += [$original->language()->getId() => $original];
      foreach (array_keys($entity->getTranslationLanguages()) as $translation_langcode) {
        $candidates += [$translation_langcode => $entity->getTranslation($translation_langcode)];
      }
    }
    foreach ($candidates as $candidate) {
      $access = $candidate->access('view', NULL, TRUE);
      $cacheability->addCacheableDependency($access);
      if ($access->isAllowed()) {
        return $candidate;
      }
    }
    return NULL;
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

  /**
   * Returns the graph ID of an entity object.
   */
  public function entityKey(EntityInterface $entity): string {
    return $this->key($entity->getEntityTypeId(), $entity->id());
  }

  /**
   * Returns the color key of the bundle of an entity, e.g. "node:person".
   */
  public function bundleKey(EntityInterface $entity): string {
    return $entity->getEntityTypeId() . ':' . $entity->bundle();
  }

}
