<?php

declare(strict_types=1);

namespace Drupal\views_network_relationship_nodes\Plugin\NetworkEdgeSource;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\relationship_nodes\RelationBundle\BundleInfoService;
use Drupal\relationship_nodes\RelationField\FieldNameResolver;
use Drupal\views_network\Attribute\NetworkEdgeSource;
use Drupal\views_network\EdgeSource\Edge;
use Drupal\views_network\EdgeSource\EdgeSourcePluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Edges from the relation nodes of relationship_nodes.
 *
 * A relation node between two result nodes is an edge, directed from the node
 * in its first related entity field to the node in the second. The edge label
 * is the relation type term, or the relation content type for untyped
 * relations.
 */
#[NetworkEdgeSource(
  id: 'relation_nodes',
  label: new TranslatableMarkup('Relation nodes'),
  description: new TranslatableMarkup('A relation node between two result rows is an edge.'),
  weight: -10,
)]
class RelationNodes extends EdgeSourcePluginBase implements ContainerFactoryPluginInterface {

  /**
   * The number of nodes per relation query.
   */
  protected const CHUNK_SIZE = 500;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityRepositoryInterface $entityRepository,
    protected BundleInfoService $bundleInfoService,
    protected FieldNameResolver $fieldNameResolver,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity.repository'),
      $container->get('relationship_nodes.bundle_info_service'),
      $container->get('relationship_nodes.field_name_resolver'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['bundles' => []];
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(string $entity_type_id): bool {
    return $entity_type_id === 'node' && !empty($this->relationBundles());
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, string $entity_type_id): array {
    $form['bundles'] = [
      '#type' => 'checkboxes',
      '#title' => new TranslatableMarkup('Relation types'),
      '#description' => new TranslatableMarkup('Leave empty to use all relation types.'),
      '#options' => array_map(fn($bundle) => $bundle->label(), $this->relationBundles()),
      '#default_value' => $this->configuration['bundles'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array $values): array {
    return ['bundles' => array_values(array_filter($values['bundles'] ?? []))];
  }

  /**
   * {@inheritdoc}
   */
  public function getEdges(array $entities): array {
    $bundles = $this->configuration['bundles'] ?: array_keys($this->relationBundles());
    if (!$bundles || !$entities) {
      return [];
    }
    [$field_1, $field_2] = array_values($this->fieldNameResolver->getRelatedEntityFields());
    $type_field = $this->fieldNameResolver->getRelationTypeField();
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = array_keys($entities);

    $edges = [];
    foreach (array_chunk($ids, static::CHUNK_SIZE) as $chunk) {
      $relation_ids = $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', $bundles, 'IN')
        ->condition($field_1, $chunk, 'IN')
        ->condition($field_2, $ids, 'IN')
        ->execute();
      foreach ($storage->loadMultiple($relation_ids) as $relation) {
        if (!$relation instanceof NodeInterface || !$relation->access('view')) {
          continue;
        }
        [$type, $label] = $this->relationType($relation, $type_field);
        foreach ($relation->get($field_1) as $source) {
          foreach ($relation->get($field_2) as $target) {
            if ($source->target_id === NULL || $target->target_id === NULL) {
              continue;
            }
            $edges[] = new Edge(
              id: implode(':', ['relation', $relation->id(), $source->target_id, $target->target_id]),
              sourceEntityType: 'node',
              sourceId: $source->target_id,
              targetEntityType: 'node',
              targetId: $target->target_id,
              type: $type,
              typeLabel: $label,
            );
          }
        }
      }
    }
    return $edges;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return ['user.permissions', 'user.node_grants:view'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return ['node_list', 'taxonomy_term_list'];
  }

  /**
   * Returns the type key and label of a relation.
   *
   * @return string[]
   *   The type key and the label: the relation type term if it has one,
   *   otherwise the relation content type.
   */
  protected function relationType(NodeInterface $relation, string $type_field): array {
    if ($relation->hasField($type_field) && ($term = $relation->get($type_field)->entity)) {
      $term = $this->entityRepository->getTranslationFromContext($term);
      return ['relation_type:' . $term->id(), (string) $term->label()];
    }
    $bundle = $this->relationBundles()[$relation->bundle()] ?? NULL;
    return ['relation_bundle:' . $relation->bundle(), (string) ($bundle?->label() ?? $relation->bundle())];
  }

  /**
   * Returns the relation content types.
   *
   * @return \Drupal\node\NodeTypeInterface[]
   *   The relation content types, keyed by ID.
   */
  protected function relationBundles(): array {
    return $this->bundleInfoService->getAllRelationBundles('node_type');
  }

}
