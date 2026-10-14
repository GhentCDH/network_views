<?php

declare(strict_types=1);

namespace Drupal\network_views\Plugin\views\style;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Attribute\ViewsStyle;
use Drupal\views\Plugin\views\field\EntityField;
use Drupal\views\Plugin\views\style\StylePluginBase;
use Drupal\network_views\EdgeSource\EdgeSourceManager;
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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->edgeSourceManager = $container->get('plugin.manager.network_views.edge_source');
    $instance->entityRepository = $container->get('entity.repository');
    $instance->graphBuilder = $container->get('network_views.graph_builder');
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
      $nodes[$entity->id()] = new GraphNode(
        entity: $entity,
        label: $this->options['label_field'] ? $this->plainText($index, $this->options['label_field']) : (string) $entity->label(),
        category: $this->category($index, $entity),
        color: $color !== '' ? $color : NULL,
        tooltip: $this->tooltip($index),
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
   * Returns the color category of a row.
   */
  protected function category(int $index, EntityInterface $entity): string {
    return match ($this->options['color_field']) {
      '' => '',
      '_bundle' => $this->graphBuilder->bundleLabel($entity),
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
