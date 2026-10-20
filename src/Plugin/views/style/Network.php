<?php

declare(strict_types=1);

namespace Drupal\network_views\Plugin\views\style;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Attribute\ViewsStyle;
use Drupal\views\Plugin\views\field\EntityField;
use Drupal\views\Plugin\views\style\StylePluginBase;
use Drupal\network_views\EdgeSource\EdgeSourceManager;
use Drupal\network_views\Form\ColorSettings;
use Drupal\network_views\Graph\GraphBuilder;
use Drupal\network_views\Graph\GraphNode;
use Drupal\network_views\Palette;
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
   * The graph builder.
   */
  protected GraphBuilder $graphBuilder;

  /**
   * The entity field manager.
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The bundle info service.
   */
  protected EntityTypeBundleInfoInterface $bundleInfo;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->edgeSourceManager = $container->get('plugin.manager.network_views.edge_source');
    $instance->entityRepository = $container->get('entity.repository');
    $instance->graphBuilder = $container->get('network_views.graph_builder');
    $instance->entityFieldManager = $container->get('entity_field.manager');
    $instance->entityTypeManager = $container->get('entity_type.manager');
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
    $options['node_colors'] = ['default' => []];
    $options['edge_color_by'] = ['default' => 'type'];
    $options['edge_color_map'] = ['default' => []];
    // Older settings: colors by label.
    $options['colors'] = ['default' => ''];
    $options['edge_colors'] = ['default' => ''];
    $options['edge_sources'] = ['default' => []];
    $options['shared_links'] = ['default' => []];
    $options['directed'] = ['default' => TRUE];
    $options['edge_labels'] = ['default' => FALSE];
    $options['hide_isolated'] = ['default' => FALSE];
    $options['language'] = ['default' => 'show'];
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
    $reference_fields = $this->referenceViewsFields();
    $color_options = ['' => $this->t('- Nothing (one color) -'), '_bundle' => $this->t('- Bundle -')]
      + array_intersect_key($fields, $reference_fields);
    // A field of older settings that is not a reference field.
    if (!isset($color_options[$this->options['color_field']]) && isset($fields[$this->options['color_field']])) {
      $color_options[$this->options['color_field']] = $this->t('@field (by its text)', ['@field' => $fields[$this->options['color_field']]]);
    }
    $form['color_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Color by'),
      '#description' => $this->t('Nodes with the same bundle, or referring to the same item (e.g. the same term), get the same color, in every language. With a multi-value field, the first value counts. Nodes added with "Show the referenced items as nodes" are colored by bundle.'),
      '#options' => $color_options,
      '#default_value' => $this->options['color_field'],
      '#fieldset' => 'nodes',
    ];
    $form['node_colors'] = $this->nodeColorsElement($reference_fields) + ['#fieldset' => 'nodes'];
    $form['color_value_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Color from field'),
      '#description' => $this->t('Optional. A field whose value is a CSS color (e.g. <code>#e15759</code>), such as a color field on a taxonomy term added through a relationship. It overrides the colors above for the rows that have a value.'),
      '#options' => ['' => $this->t('- None -')] + $fields,
      '#default_value' => $this->options['color_value_field'],
      '#fieldset' => 'nodes',
    ];
    if ($this->options['colors'] !== '') {
      $form['colors'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Colors by label (older settings)'),
        '#description' => $this->t('One <code>label|color</code> per line, used for values without a color above. Labels differ per language: set the colors above instead, and empty this field.'),
        '#default_value' => $this->options['colors'],
        '#rows' => 3,
        '#fieldset' => 'nodes',
      ];
    }
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
    $form['language'] = [
      '#type' => 'select',
      '#title' => $this->t('Not available in the current language'),
      '#description' => $this->t('Nodes without a translation in the current language that the visitor may view, and edges that their edge source marks as unavailable in it.'),
      '#options' => [
        'show' => $this->t('Show normally'),
        'fade' => $this->t('Show faded'),
        'hide' => $this->t('Hide'),
      ],
      '#default_value' => $this->options['language'],
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
      '#description' => $this->t('Only edges between two result rows are shown, and shared links between result rows.'),
      '#open' => TRUE,
    ];
    $form['edge_sources'] = $this->edgeSourceManager->buildConfigurationForm(
      $this->options['edge_sources'],
      (string) $entity_type_id,
      $form_state,
      'style_options[edge_sources]',
    ) + ['#fieldset' => 'edges'];
    $shared_links = $this->edgeSourceManager->buildSharedLinksForm(
      $this->options['shared_links'],
      (string) $entity_type_id,
      'style_options[edge_sources]',
    );
    if ($shared_links) {
      $form['shared_links'] = $shared_links + ['#fieldset' => 'edges'];
    }
    $form['directed'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Directed edges'),
      '#description' => $this->t('Show arrows. Without direction, two edges of the same type between the same nodes are shown once. Shared links have no direction.'),
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
    $form['edge_color_by'] = [
      '#type' => 'select',
      '#title' => $this->t('Color edges by'),
      '#options' => [
        'type' => $this->t('Type (the reference field, or the relation type)'),
        'group' => $this->t('Group (for relation nodes: the relation content type)'),
        'shared_item' => $this->t('Shared item (shared links: each shared item its own color, grey when several)'),
        'none' => $this->t('Nothing (one color)'),
      ],
      '#default_value' => $this->options['edge_color_by'],
      '#fieldset' => 'edges',
    ];
    $form['edge_color_map'] = $this->edgeColorsElement() + ['#fieldset' => 'edges'];
    if ($this->options['edge_colors'] !== '') {
      $form['edge_colors'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Edge colors by label (older settings)'),
        '#description' => $this->t('One <code>label|color</code> per line, used for edges without a color above. Labels differ per language: set the colors above instead, and empty this field.'),
        '#default_value' => $this->options['edge_colors'],
        '#rows' => 3,
        '#fieldset' => 'edges',
      ];
    }

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
      if (!isset($form[$key])) {
        continue;
      }
      Palette::parse((string) $form_state->getValue(['style_options', $key]), $invalid);
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
    $options['edge_sources'] = $this->edgeSourceManager->submitConfigurationForm($options['edge_sources'] ?? []);
    $options['shared_links'] = $this->edgeSourceManager->submitSharedLinksForm($options['shared_links'] ?? []);
    $options['node_colors'] = ColorSettings::submit($options['node_colors'] ?? []);
    $options['edge_color_map'] = ColorSettings::submit($options['edge_color_map'] ?? []);
    $options['colors'] ??= '';
    $options['edge_colors'] ??= '';
  }

  /**
   * Returns the definition of the field of a Views field, in a bundle.
   */
  protected function fieldDefinitionOf(EntityField $handler): ?FieldDefinitionInterface {
    $entity_type_id = $handler->getEntityType();
    $field_name = $handler->definition['field_name'] ?? NULL;
    if (!$field_name) {
      return NULL;
    }
    foreach ($this->entityFieldManager->getFieldMap()[$entity_type_id][$field_name]['bundles'] ?? [] as $bundle) {
      $definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);
      if (isset($definitions[$field_name])) {
        return $definitions[$field_name];
      }
    }
    return NULL;
  }

  /**
   * Returns the Views fields that are entity reference fields.
   *
   * @return string[]
   *   The target entity types, keyed by Views field ID.
   */
  protected function referenceViewsFields(): array {
    $fields = [];
    foreach ($this->displayHandler->getHandlers('field') as $id => $handler) {
      $definition = $handler instanceof EntityField ? $this->fieldDefinitionOf($handler) : NULL;
      if ($definition && in_array($definition->getType(), ['entity_reference', 'entity_reference_revisions'], TRUE)) {
        $fields[$id] = (string) $definition->getFieldStorageDefinition()->getSetting('target_type');
      }
    }
    return $fields;
  }

  /**
   * Builds the color fields of node categories.
   *
   * @param string[] $reference_fields
   *   The target entity types of the reference Views fields, keyed by ID.
   */
  protected function nodeColorsElement(array $reference_fields): array {
    $current = $this->options['node_colors'];
    $element = [
      '#type' => 'details',
      '#title' => $this->t('Colors of the nodes'),
      '#description' => $this->t('Optional. Values without a color get one from the default palette, the same in every language.'),
      '#open' => (bool) $current,
      '#tree' => TRUE,
    ];
    $base = (string) $this->baseEntityTypeId();
    $bundle_rows = $this->bundleRows([$base, ...$this->extraNodeEntityTypes()]);
    $element['bundles'] = ColorSettings::table($this->t('Bundles'), $bundle_rows, $current);
    $covered = array_keys($bundle_rows);
    foreach ($reference_fields as $field_id => $target_type) {
      if ($target_type !== 'taxonomy_term') {
        continue;
      }
      $rows = $this->termRows($field_id);
      $covered = array_merge($covered, array_keys($rows));
      $element['terms_' . $field_id] = ColorSettings::table(
        $this->t('Terms of @field', ['@field' => $this->displayHandler->getHandler('field', $field_id)->adminLabel()]),
        $rows,
        $current,
        ['visible' => [':input[name="style_options[color_field]"]' => ['value' => $field_id]]],
      );
    }
    $element['other'] = ColorSettings::textarea(
      $this->t('Other values'),
      $current,
      $covered,
      $this->t('One <code>key|color</code> per line, for referenced items not listed above, e.g. <code>node:12|#4e79a7</code> (entity type and ID).'),
    );
    return $element;
  }

  /**
   * Builds the color fields of edge types and groups.
   */
  protected function edgeColorsElement(): array {
    $current = $this->options['edge_color_map'];
    [$types, $groups] = $this->edgeSourceManager->getEdgeTypesAndGroups((string) $this->baseEntityTypeId());
    foreach ($this->options['shared_links'] as $shared) {
      $type_label = $types[$shared['edge_type']] ?? $shared['edge_type'];
      $types['shared:' . $shared['edge_type']] = $shared['label'] !== '' ? $shared['label'] : (string) $this->t('Shared: @type', ['@type' => $type_label]);
    }
    $by = ':input[name="style_options[edge_color_by]"]';
    $element = [
      '#type' => 'details',
      '#title' => $this->t('Colors of the edges'),
      '#description' => $this->t('Optional. Edges without a color get one from the default palette, the same in every language; with a single kind of edge, edges are grey.'),
      '#open' => (bool) $current,
      '#tree' => TRUE,
      '#states' => ['invisible' => [$by => ['value' => 'none']]],
    ];
    $types_visible = ['visible' => [[$by => ['value' => 'type']], 'or', [$by => ['value' => 'shared_item']]]];
    $element['types'] = ColorSettings::table($this->t('Types'), $types, $current, $types_visible);
    $element['groups'] = ColorSettings::table($this->t('Groups'), $groups, $current, ['visible' => [$by => ['value' => 'group']]]);
    $element['other'] = ColorSettings::textarea(
      $this->t('Shared items'),
      $current,
      array_merge(array_keys($types), array_keys($groups)),
      $this->t('With "Shared item": one <code>key|color</code> per line, e.g. <code>node:12|#4e79a7</code> (entity type and ID of the shared item).'),
      ['visible' => [$by => ['value' => 'shared_item']]],
    );
    return $element;
  }

  /**
   * Returns the entity types of items shown as nodes by edge sources.
   *
   * @return string[]
   *   The entity types the "show as node" reference fields refer to.
   */
  protected function extraNodeEntityTypes(): array {
    $base = (string) $this->baseEntityTypeId();
    $storages = $this->entityFieldManager->getFieldStorageDefinitions($base);
    $types = [];
    foreach ($this->options['edge_sources']['entity_reference_fields']['settings']['node_fields'] ?? [] as $field_name) {
      if (isset($storages[$field_name])) {
        $types[] = (string) $storages[$field_name]->getSetting('target_type');
      }
    }
    return array_values(array_unique(array_filter($types)));
  }

  /**
   * Returns the bundles of entity types as color rows.
   *
   * @param string[] $entity_type_ids
   *   The entity types.
   *
   * @return string[]
   *   Labels keyed by color key ("entity_type:bundle").
   */
  protected function bundleRows(array $entity_type_ids): array {
    $rows = [];
    foreach (array_unique($entity_type_ids) as $entity_type_id) {
      foreach ($this->bundleInfo->getBundleInfo($entity_type_id) as $bundle => $info) {
        $rows["$entity_type_id:$bundle"] = (string) $info['label'];
      }
    }
    return $rows;
  }

  /**
   * Returns the terms a reference Views field can refer to, as color rows.
   *
   * @return string[]
   *   Term names keyed by color key ("taxonomy_term:ID"); at most
   *   ColorSettings::MAX_ROWS.
   */
  protected function termRows(string $field_id): array {
    // The handlers of the display: in the options form, the view's fields
    // are not initialized yet.
    $handler = $this->displayHandler->getHandler('field', $field_id);
    if (!$handler instanceof EntityField) {
      return [];
    }
    $vocabularies = array_keys($this->fieldDefinitionOf($handler)?->getSetting('handler_settings')['target_bundles'] ?? []);
    if (!$vocabularies) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('vid', $vocabularies, 'IN')
      ->sort('name')
      ->range(0, ColorSettings::MAX_ROWS)
      ->execute();
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $term) {
      $rows['taxonomy_term:' . $term->id()] = (string) $this->entityRepository->getTranslationFromContext($term)->label();
    }
    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    if (!$this->baseEntityTypeId()) {
      return [];
    }

    // One graph node per entity; relationships can repeat an entity.
    $nodes = [];
    foreach ($this->view->result as $index => $row) {
      // ResultRow::$_entity is documented as not nullable, but it is NULL
      // when the entity could not be loaded.
      /** @var \Drupal\Core\Entity\EntityInterface|null $entity */
      $entity = $row->_entity;
      if (!$entity || isset($nodes[$entity->id()])) {
        continue;
      }
      $entity = $this->entityRepository->getTranslationFromContext($entity);
      $color = $this->options['color_value_field'] ? $this->plainText($index, $this->options['color_value_field']) : NULL;
      [$category, $color_key] = $this->category($index, $entity);
      $nodes[$entity->id()] = new GraphNode(
        entity: $entity,
        label: $this->options['label_field'] ? $this->plainText($index, $this->options['label_field']) : (string) $entity->label(),
        category: $category,
        color: $color !== '' ? $color : NULL,
        tooltip: $this->tooltip($index),
        colorKey: $color_key,
      );
    }

    $cacheability = new CacheableMetadata();
    $entities = array_map(fn(GraphNode $node) => $node->entity, $nodes);
    // Shared links also need the edges to shared items outside the result.
    $edges = $this->options['shared_links']
      ? $this->edgeSourceManager->getAllEdgesOf($this->options['edge_sources'], $entities, $cacheability)
      : $this->edgeSourceManager->getEdges($this->options['edge_sources'], $entities, $cacheability);
    $graph = $this->graphBuilder->build($nodes, $edges, $this->options, $cacheability);
    return $this->graphBuilder->toRenderArray($graph, $this->options, $cacheability);
  }

  /**
   * Returns the color category of a row: its label and color key.
   *
   * @return array
   *   The label (for the legend) and the color key, or NULL to use the label.
   */
  protected function category(int $index, EntityInterface $entity): array {
    $field_id = $this->options['color_field'];
    return match ($field_id) {
      '' => ['', NULL],
      '_bundle' => [$this->graphBuilder->bundleLabel($entity), $this->graphBuilder->bundleKey($entity)],
      // The referenced item is the key; older settings with another kind of
      // field use the text.
      default => [$this->plainText($index, $field_id, TRUE), $this->referencedKey($index, $field_id)],
    };
  }

  /**
   * Returns the graph key of the first item a reference field refers to.
   */
  protected function referencedKey(int $index, string $field_id): ?string {
    $handler = $this->view->field[$field_id] ?? NULL;
    if (!$handler instanceof EntityField) {
      return NULL;
    }
    $items = $handler->getItems($this->view->result[$index]);
    $raw = $items ? (reset($items)['raw'] ?? NULL) : NULL;
    $target = $raw instanceof EntityReferenceItem ? $raw->entity : NULL;
    return $target instanceof EntityInterface ? $this->graphBuilder->entityKey($target) : NULL;
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
      $html .= '<div class="network-views__tooltip-field">'
        . ($label !== '' ? '<span class="network-views__tooltip-label">' . Html::escape($label) . ':</span> ' : '')
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

}
