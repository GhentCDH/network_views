<?php

declare(strict_types=1);

namespace Drupal\network_views\Plugin\views\argument;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\network_views\Graph\GraphBuilder;
use Drupal\network_views\Graph\Neighbourhood;
use Drupal\views\Attribute\ViewsArgument;
use Drupal\views\Plugin\views\argument\ArgumentPluginBase;
use Drupal\views\Plugin\views\query\Sql;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Contextual filter: an entity and its neighbours in the network.
 *
 * Limits the result to the entity of the argument (e.g. the content ID from
 * the URL) and its neighbours, found with the edge sources and shared links
 * of the view's network format: an ego network. The network format shows the
 * entity as the root.
 */
#[ViewsArgument(id: 'network_neighbourhood')]
class NetworkNeighbourhood extends ArgumentPluginBase {

  /**
   * The neighbourhood service.
   */
  protected Neighbourhood $neighbourhood;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The graph builder.
   */
  protected GraphBuilder $graphBuilder;

  /**
   * The cacheability of the neighbourhood of the last query.
   */
  protected ?CacheableMetadata $neighbourhoodCacheability = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->neighbourhood = $container->get('network_views.neighbourhood');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->graphBuilder = $container->get('network_views.graph_builder');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['depth'] = ['default' => 1];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);
    $form['depth'] = [
      '#type' => 'select',
      '#title' => $this->t('Depth'),
      '#description' => $this->t('The neighbours are found with the edge sources and shared links of the network format of this view. With 2 steps, also the neighbours of the neighbours. Use the network format and "Hide nodes without connections" to show the ego network.'),
      '#options' => [1 => $this->t('1 step: the neighbours'), 2 => $this->t('2 steps: also their neighbours')],
      '#default_value' => $this->options['depth'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function query($group_by = FALSE) {
    $this->ensureMyTable();
    $ids = $this->neighbourhoodIds();
    // No root: an empty result.
    if ($this->query instanceof Sql) {
      $this->query->addWhere("0", "$this->tableAlias.$this->realField", $ids ?: [0], 'IN');
    }
  }

  /**
   * Returns the IDs of the root entity and its neighbours.
   *
   * @return string[]
   *   The IDs, or none when the argument is not an entity the user may view.
   */
  public function neighbourhoodIds(): array {
    $root = $this->rootEntity();
    $this->neighbourhoodCacheability = new CacheableMetadata();
    if (!$root) {
      return [];
    }
    $style = $this->displayHandler->getOption('style');
    $options = ($style['type'] ?? '') === 'network' ? ($style['options'] ?? []) : [];
    return $this->neighbourhood->find(
      $root,
      $options['edge_sources'] ?? [],
      $options['shared_links'] ?? [],
      (int) $this->options['depth'],
      $this->neighbourhoodCacheability,
    );
  }

  /**
   * Returns the entity of the argument in a language the user may view it.
   */
  public function rootEntity(): ?EntityInterface {
    $entity_type_id = $this->getEntityType();
    if ($this->argument === NULL || $this->argument === '' || !$entity_type_id) {
      return NULL;
    }
    $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($this->argument);
    // A translation the user may view: translations can be published
    // separately.
    return $entity ? $this->graphBuilder->viewableTranslation($entity, new CacheableMetadata()) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function title() {
    $root = $this->rootEntity();
    return $root ? $root->label() : parent::title();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    $tags = parent::getCacheTags();
    $entity_type_id = $this->getEntityType();
    if ($entity_type_id) {
      // Any entity of the type can become a neighbour.
      $tags = array_merge($tags, $this->entityTypeManager->getDefinition($entity_type_id)->getListCacheTags());
    }
    return $tags;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    // The neighbours depend on what the user may view.
    return array_merge(parent::getCacheContexts(), ['user.permissions', 'user.node_grants:view']);
  }

  /**
   * Returns a label for the plugin in the Views UI.
   */
  public function adminSummary() {
    return (string) new TranslatableMarkup('@summary (@depth)', [
      '@summary' => parent::adminSummary(),
      '@depth' => $this->formatPlural((int) $this->options['depth'], '1 step', '@count steps'),
    ]);
  }

}
