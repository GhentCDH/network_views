<?php

declare(strict_types=1);

namespace Drupal\views_network\Plugin\views\style;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
use Drupal\views\Attribute\ViewsStyle;
use Drupal\views\Plugin\views\field\EntityField;
use Drupal\views\Plugin\views\style\StylePluginBase;
use Drupal\views_network\EdgeSource\EdgeSourceInterface;
use Drupal\views_network\EdgeSource\EdgeSourceManager;
use Drupal\views_network\Palette;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays the result rows as the nodes of a network graph.
 *
 * Each result entity is a node of the graph. The edges come from the enabled
 * edge source plugins; only edges between two result entities are shown, so
 * the filters of the view decide what is in the graph.
 *
 * @ingroup views_style_plugins
 */
#[ViewsStyle(
  id: 'network',
  title: new TranslatableMarkup('Network'),
  help: new TranslatableMarkup('Displays the result rows as a network graph.'),
  display_types: ['normal'],
  register_theme: FALSE,
)]
class Network extends StylePluginBase {

  /**
   * {@inheritdoc}
   */
  protected $usesFields = TRUE;

  /**
   * {@inheritdoc}
   */
  protected $usesGrouping = FALSE;

  /**
   * {@inheritdoc}
   */
  protected $usesRowPlugin = FALSE;

  /**
   * The edge source plugin manager.
   */
  protected EdgeSourceManager $edgeSourceManager;

  /**
   * The entity repository.
   */
  protected EntityRepositoryInterface $entityRepository;

  /**
   * The bundle info service.
   */
  protected EntityTypeBundleInfoInterface $bundleInfo;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->edgeSourceManager = $container->get('plugin.manager.views_network.edge_source');
    $instance->entityRepository = $container->get('entity.repository');
    $instance->bundleInfo = $container->get('entity_type.bundle.info');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['label_field'] = ['default' => ''];
    $options['tooltip_fields'] = ['default' => []];
    $options['color_field'] = ['default' => '_bundle'];
    $options['color_value_field'] = ['default' => ''];
    $options['colors'] = ['default' => ''];
    $options['edge_colors'] = ['default' => ''];
    $options['edge_sources'] = ['default' => []];
    $options['directed'] = ['default' => TRUE];
    $options['edge_labels'] = ['default' => FALSE];
    $options['hide_isolated'] = ['default' => FALSE];
    $options['size'] = ['default' => 'degree'];
    $options['link'] = ['default' => TRUE];
    $options['layout'] = ['default' => 'cose'];
    $options['height'] = ['default' => '600px'];
    $options['legend'] = ['default' => TRUE];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);
    $fields = $this->displayHandler->getFieldLabels(TRUE);
    $entity_type_id = $this->baseEntityTypeId();

    $pager = $this->displayHandler->getOption('pager');
    if (!empty($pager['options']['items_per_page'])) {
      $form['pager_warning'] = [
        '#theme' => 'status_messages',
        '#message_list' => ['warning' => [$this->t('This display shows at most @count items, so the graph only contains part of the result. Display all items to show the whole network.', ['@count' => $pager['options']['items_per_page']])]],
        '#weight' => -100,
      ];
    }

    $form['nodes'] = [
      '#type' => 'details',
      '#title' => $this->t('Nodes'),
      '#open' => TRUE,
    ];
    $form['label_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Label'),
      '#options' => ['' => $this->t('- Entity label -')] + $fields,
      '#default_value' => $this->options['label_field'],
      '#fieldset' => 'nodes',
    ];
    $form['tooltip_fields'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Tooltip'),
      '#description' => $this->t('Fields shown when hovering a node, with their Views label and formatting. Exclude fields from display to use them only here.'),
      '#options' => $fields,
      '#default_value' => $this->options['tooltip_fields'],
      '#fieldset' => 'nodes',
    ];
    $form['color_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Color by'),
      '#description' => $this->t('Nodes with the same value get the same color. With a multi-value field, the first value counts.'),
      '#options' => ['' => $this->t('- Nothing (one color) -'), '_bundle' => $this->t('- Bundle -')] + $fields,
      '#default_value' => $this->options['color_field'],
      '#fieldset' => 'nodes',
    ];
    $form['colors'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Colors'),
      '#description' => $this->t('Optional. One <code>value|color</code> per line, e.g. <code>Person|#4e79a7</code>. The value is the text of the "Color by" field (for a bundle: its label). Other values get a color from the default palette.'),
      '#default_value' => $this->options['colors'],
      '#rows' => 4,
      '#fieldset' => 'nodes',
    ];
    $form['color_value_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Color from field'),
      '#description' => $this->t('Optional. A field whose value is a CSS color (e.g. <code>#e15759</code>), such as a color field on a taxonomy term added through a relationship. It overrides the colors above for the rows that have a value.'),
      '#options' => ['' => $this->t('- None -')] + $fields,
      '#default_value' => $this->options['color_value_field'],
      '#fieldset' => 'nodes',
    ];
    $form['size'] = [
      '#type' => 'radios',
      '#title' => $this->t('Node size'),
      '#options' => [
        'fixed' => $this->t('Fixed'),
        'degree' => $this->t('By number of connections'),
      ],
      '#default_value' => $this->options['size'],
      '#fieldset' => 'nodes',
    ];
    $form['hide_isolated'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide nodes without connections'),
      '#default_value' => $this->options['hide_isolated'],
      '#fieldset' => 'nodes',
    ];
    $form['link'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Open the entity when a node is clicked'),
      '#default_value' => $this->options['link'],
      '#fieldset' => 'nodes',
    ];

    $form['edges'] = [
      '#type' => 'details',
      '#title' => $this->t('Edges'),
      '#description' => $this->t('Only edges between two result rows are shown.'),
      '#open' => TRUE,
    ];
    $form['edge_sources'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#fieldset' => 'edges',
    ];
    foreach ($this->getEdgeSources(FALSE) as $plugin_id => $edge_source) {
      if (!$entity_type_id || !$edge_source->isApplicable($entity_type_id)) {
        continue;
      }
      $definition = $edge_source->getPluginDefinition();
      $enabled_selector = ':input[name="style_options[edge_sources][' . $plugin_id . '][enabled]"]';
      $form['edge_sources'][$plugin_id] = [
        '#type' => 'fieldset',
        '#title' => $definition['label'],
      ];
      $form['edge_sources'][$plugin_id]['enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enabled'),
        '#description' => $definition['description'] ?? NULL,
        '#default_value' => !empty($this->options['edge_sources'][$plugin_id]['enabled']),
      ];
      $form['edge_sources'][$plugin_id]['settings'] = $edge_source->buildConfigurationForm([
        '#type' => 'container',
        '#states' => ['visible' => [$enabled_selector => ['checked' => TRUE]]],
      ], $form_state, $entity_type_id);
    }
    if (count(Element::children($form['edge_sources'])) === 0) {
      $form['edge_sources']['#markup'] = $this->t('No edge source is available for this view.');
    }
    $form['directed'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Directed edges'),
      '#description' => $this->t('Show arrows. Without direction, two edges of the same type between the same nodes are shown once.'),
      '#default_value' => $this->options['directed'],
      '#fieldset' => 'edges',
    ];
    $form['edge_labels'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Always show edge labels'),
      '#description' => $this->t('Otherwise the labels are shown when hovering a node.'),
      '#default_value' => $this->options['edge_labels'],
      '#fieldset' => 'edges',
    ];
    $form['edge_colors'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Edge colors'),
      '#description' => $this->t('Optional. One <code>label|color</code> per line, with the label of an edge type as shown in the legend.'),
      '#default_value' => $this->options['edge_colors'],
      '#rows' => 3,
      '#fieldset' => 'edges',
    ];

    $form['display'] = [
      '#type' => 'details',
      '#title' => $this->t('Display'),
      '#open' => TRUE,
    ];
    $form['layout'] = [
      '#type' => 'select',
      '#title' => $this->t('Layout'),
      '#options' => [
        'cose' => $this->t('Force-directed'),
        'concentric' => $this->t('Concentric (most connected in the center)'),
        'breadthfirst' => $this->t('Hierarchical'),
        'circle' => $this->t('Circle'),
        'grid' => $this->t('Grid'),
      ],
      '#default_value' => $this->options['layout'],
      '#fieldset' => 'display',
    ];
    $form['height'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Height'),
      '#description' => $this->t('A CSS length, e.g. <code>600px</code> or <code>80vh</code>.'),
      '#default_value' => $this->options['height'],
      '#size' => 10,
      '#fieldset' => 'display',
    ];
    $form['legend'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show a legend'),
      '#default_value' => $this->options['legend'],
      '#fieldset' => 'display',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateOptionsForm(&$form, FormStateInterface $form_state) {
    parent::validateOptionsForm($form, $form_state);
    $height = $form_state->getValue(['style_options', 'height']);
    if (!preg_match('/^\d+(\.\d+)?(px|em|rem|vh|%)$/', (string) $height)) {
      $form_state->setError($form['height'], $this->t('The height must be a number with a unit (px, em, rem, vh or %).'));
    }
    foreach (['colors', 'edge_colors'] as $key) {
      static::parseColors((string) $form_state->getValue(['style_options', $key]), $invalid);
      if ($invalid) {
        $form_state->setError($form[$key], $this->t('Invalid lines: %lines. Use <code>value|color</code> with a color such as <code>#4e79a7</code>.', ['%lines' => implode(', ', $invalid)]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitOptionsForm(&$form, FormStateInterface $form_state) {
    parent::submitOptionsForm($form, $form_state);
    $options = &$form_state->getValue('style_options');
    $options['tooltip_fields'] = array_values(array_filter($options['tooltip_fields'] ?? []));
    $edge_sources = [];
    foreach ($options['edge_sources'] ?? [] as $plugin_id => $values) {
      if (!is_array($values) || !$this->edgeSourceManager->hasDefinition($plugin_id)) {
        continue;
      }
      $edge_source = $this->edgeSourceManager->createInstance($plugin_id);
      $edge_sources[$plugin_id] = [
        'enabled' => !empty($values['enabled']),
        'settings' => $edge_source->submitConfigurationForm($values['settings'] ?? []),
      ];
    }
    $options['edge_sources'] = $edge_sources;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $entity_type_id = $this->baseEntityTypeId();
    if (!$entity_type_id) {
      return [];
    }
    $cacheability = new CacheableMetadata();

    // One graph node per entity; relationships can repeat an entity.
    $entities = [];
    $rows = [];
    foreach ($this->view->result as $index => $row) {
      $entity = $row->_entity ?? NULL;
      if ($entity instanceof EntityInterface && !isset($entities[$entity->id()])) {
        $entities[$entity->id()] = $this->entityRepository->getTranslationFromContext($entity);
        $rows[$entity->id()] = $index;
      }
    }

    $edges = $this->buildEdges($entity_type_id, $entities, $cacheability);
    if ($this->options['hide_isolated']) {
      $connected = [];
      foreach ($edges as $edge) {
        $connected[$edge['source']] = TRUE;
        $connected[$edge['target']] = TRUE;
      }
      $entities = array_filter($entities, fn(EntityInterface $entity) => isset($connected[$this->key($entity_type_id, $entity->id())]));
    }

    $nodes = [];
    $categories = [];
    $data_colors = [];
    foreach ($entities as $id => $entity) {
      $index = $rows[$id];
      $category = $this->category($index, $entity);
      $node = [
        'id' => $this->key($entity_type_id, $id),
        'label' => $this->options['label_field'] ? $this->plainText($index, $this->options['label_field']) : (string) $entity->label(),
        'category' => $category,
        'tooltip' => $this->tooltip($index),
      ];
      if ($this->options['link'] && $entity->hasLinkTemplate('canonical')) {
        $url = $entity->toUrl()->toString(TRUE);
        $cacheability->addCacheableDependency($url);
        $node['url'] = $url->getGeneratedUrl();
      }
      if ($this->options['color_value_field']) {
        $color = $this->plainText($index, $this->options['color_value_field']);
        if (static::isColor($color)) {
          $node['color'] = $color;
          $data_colors[$category] ??= $color;
        }
      }
      $categories[$category] = ($categories[$category] ?? 0) + 1;
      $nodes[] = $node;
    }

    // Colors per category: from the data, the configured colors or the
    // palette, in that order.
    $category_colors = $data_colors + Palette::assign(array_keys($categories), static::parseColors($this->options['colors']));
    foreach ($nodes as &$node) {
      $node['color'] ??= $category_colors[$node['category']];
    }
    unset($node);

    $edge_types = [];
    foreach ($edges as $edge) {
      $edge_types[$edge['type']] = ($edge_types[$edge['type']] ?? 0) + 1;
    }
    $edge_overrides = static::parseColors($this->options['edge_colors']);
    $edge_colors = count($edge_types) === 1 && !$edge_overrides
      ? [array_key_first($edge_types) => Palette::NEUTRAL]
      : Palette::assign(array_keys($edge_types), $edge_overrides);
    foreach ($edges as &$edge) {
      $edge['color'] = $edge_colors[$edge['type']];
    }
    unset($edge);

    $graph = [
      'nodes' => $nodes,
      'edges' => $edges,
      'settings' => [
        'layout' => $this->options['layout'],
        'directed' => (bool) $this->options['directed'],
        'edgeLabels' => (bool) $this->options['edge_labels'],
        'size' => $this->options['size'],
        'link' => (bool) $this->options['link'],
      ],
    ];

    $legend = [];
    if ($this->options['legend']) {
      ksort($categories, SORT_NATURAL | SORT_FLAG_CASE);
      ksort($edge_types, SORT_NATURAL | SORT_FLAG_CASE);
      if ($this->options['color_field'] && count($categories) > 0) {
        foreach ($categories as $category => $count) {
          $legend['nodes'][] = ['label' => $category, 'color' => $category_colors[$category], 'count' => $count];
        }
      }
      foreach ($edge_types as $type => $count) {
        $legend['edges'][] = ['label' => $type, 'color' => $edge_colors[$type], 'count' => $count];
      }
    }

    $build = [
      '#theme' => 'views_network',
      '#attributes' => ['class' => ['views-network']],
      '#canvas_attributes' => new Attribute([
        'class' => ['views-network__canvas'],
        'style' => 'height: ' . $this->options['height'],
        'data-views-network' => json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'role' => 'img',
        'aria-label' => $this->t('Network of @nodes items and @edges connections', [
          '@nodes' => count($nodes),
          '@edges' => count($edges),
        ]),
      ]),
      '#legend' => $legend,
      '#attached' => ['library' => ['views_network/network']],
    ];
    $cacheability->applyTo($build);
    return $build;
  }

  /**
   * Builds the edges between the result entities.
   *
   * @param string $entity_type_id
   *   The base entity type of the view.
   * @param \Drupal\Core\Entity\EntityInterface[] $entities
   *   The result entities, keyed by ID.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cacheability of the edge sources.
   *
   * @return array
   *   Edge data for the graph, keyed by edge ID.
   */
  protected function buildEdges(string $entity_type_id, array $entities, CacheableMetadata $cacheability): array {
    $edges = [];
    foreach ($this->getEdgeSources() as $edge_source) {
      if (!$edge_source->isApplicable($entity_type_id)) {
        continue;
      }
      $cacheability->addCacheableDependency($edge_source);
      foreach ($edge_source->getEdges($entities) as $edge) {
        if ($edge->sourceEntityType !== $entity_type_id
          || $edge->targetEntityType !== $entity_type_id
          || !isset($entities[$edge->sourceId], $entities[$edge->targetId])
          || (string) $edge->sourceId === (string) $edge->targetId) {
          continue;
        }
        $source = $this->key($entity_type_id, $edge->sourceId);
        $target = $this->key($entity_type_id, $edge->targetId);
        $id = $edge->id;
        if (!$this->options['directed']) {
          $pair = [$source, $target];
          sort($pair);
          $id = $edge->type . '|' . implode('|', $pair);
        }
        $edges[$id] ??= [
          'id' => $id,
          'source' => $source,
          'target' => $target,
          'type' => $edge->typeLabel,
          'label' => $edge->typeLabel,
        ];
      }
    }
    return array_values($edges);
  }

  /**
   * Returns the enabled edge source plugins, or all of them.
   *
   * @return \Drupal\views_network\EdgeSource\EdgeSourceInterface[]
   *   The edge sources, keyed by plugin ID.
   */
  protected function getEdgeSources(bool $enabled_only = TRUE): array {
    $edge_sources = [];
    foreach (array_keys($this->edgeSourceManager->getDefinitions()) as $plugin_id) {
      $options = $this->options['edge_sources'][$plugin_id] ?? [];
      if ($enabled_only && empty($options['enabled'])) {
        continue;
      }
      $edge_source = $this->edgeSourceManager->createInstance($plugin_id, $options['settings'] ?? []);
      assert($edge_source instanceof EdgeSourceInterface);
      $edge_sources[$plugin_id] = $edge_source;
    }
    return $edge_sources;
  }

  /**
   * Returns the color category of a row.
   */
  protected function category(int $index, EntityInterface $entity): string {
    return match ($this->options['color_field']) {
      '' => '',
      '_bundle' => (string) ($this->bundleInfo->getBundleInfo($entity->getEntityTypeId())[$entity->bundle()]['label'] ?? $entity->bundle()),
      default => $this->plainText($index, $this->options['color_field'], TRUE),
    };
  }

  /**
   * Returns the tooltip HTML of a row.
   */
  protected function tooltip(int $index): string {
    $html = '';
    foreach ($this->options['tooltip_fields'] as $field_id) {
      $handler = $this->view->field[$field_id] ?? NULL;
      $value = (string) $this->getField($index, $field_id);
      if (!$handler || trim(strip_tags($value, '<img>')) === '') {
        continue;
      }
      $label = $handler->label();
      $html .= '<div class="views-network__tooltip-field">'
        . ($label !== '' ? '<span class="views-network__tooltip-label">' . Html::escape($label) . ':</span> ' : '')
        . $value . '</div>';
    }
    return $html;
  }

  /**
   * Returns the text of a field of a row, without markup.
   *
   * @param int $index
   *   The row index.
   * @param string $field_id
   *   The Views field ID.
   * @param bool $first_value
   *   Whether to return only the first value of a multi-value entity field.
   */
  protected function plainText(int $index, string $field_id, bool $first_value = FALSE): string {
    $handler = $this->view->field[$field_id] ?? NULL;
    if ($first_value && $handler instanceof EntityField) {
      $items = $handler->getItems($this->view->result[$index]);
      $value = '';
      if ($items) {
        $first = reset($items)['rendered'];
        $renderer = $this->getRenderer();
        $value = $renderer->hasRenderContext() ? $renderer->render($first) : $renderer->renderInIsolation($first);
      }
    }
    else {
      $value = $this->getField($index, $field_id);
    }
    return trim(Html::decodeEntities(strip_tags((string) $value)));
  }

  /**
   * Returns the base entity type of the view, if it has one.
   */
  protected function baseEntityTypeId(): ?string {
    $entity_type = $this->view->getBaseEntityType();
    return $entity_type ? $entity_type->id() : NULL;
  }

  /**
   * Returns the graph ID of an entity.
   */
  protected function key(string $entity_type_id, int|string $id): string {
    return $entity_type_id . ':' . $id;
  }

  /**
   * Parses lines of "value|color".
   *
   * @param string $text
   *   The text to parse.
   * @param string[]|null $invalid
   *   Returns the invalid lines.
   *
   * @return string[]
   *   Colors keyed by value.
   */
  public static function parseColors(string $text, ?array &$invalid = NULL): array {
    $colors = [];
    $invalid = [];
    foreach (preg_split('/\R/', $text) as $line) {
      if (trim($line) === '') {
        continue;
      }
      $parts = explode('|', $line);
      $color = trim((string) array_pop($parts));
      $value = trim(implode('|', $parts));
      if ($value === '' || !static::isColor($color)) {
        $invalid[] = trim($line);
        continue;
      }
      $colors[$value] = $color;
    }
    return $colors;
  }

  /**
   * Whether a string is a CSS color that is safe to output.
   */
  public static function isColor(string $color): bool {
    return (bool) preg_match('/^(#[0-9a-f]{3,8}|[a-z]{3,20}|(rgb|hsl)a?\([0-9.,%\s]+\))$/i', $color);
  }

}
