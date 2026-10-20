<?php

declare(strict_types=1);

namespace Drupal\network_views\Plugin\NetworkEdgeSource;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\network_views\Attribute\NetworkEdgeSource;
use Drupal\network_views\EdgeSource\Edge;
use Drupal\network_views\EdgeSource\EdgeSourcePluginBase;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Edges from the term hierarchy: from each term to its parent terms.
 */
#[NetworkEdgeSource(
  id: 'term_parents',
  label: new TranslatableMarkup('Term hierarchy'),
  description: new TranslatableMarkup('A term and its parent term are connected, directed from the term to its parent.'),
)]
class TermParents extends EdgeSourcePluginBase implements ContainerFactoryPluginInterface {

  /**
   * The type key of the edges.
   */
  public const TYPE = 'term_parent';

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(string $entity_type_id): bool {
    return $entity_type_id === 'taxonomy_term';
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, string $entity_type_id): array {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getEdges(array $entities): array {
    $edges = [];
    foreach ($entities as $term) {
      if (!$term instanceof TermInterface) {
        continue;
      }
      foreach (array_filter(array_column($term->get('parent')->getValue(), 'target_id')) as $parent_id) {
        $edges[] = $this->edge($term->id(), $parent_id);
      }
    }
    return $edges;
  }

  /**
   * {@inheritdoc}
   *
   * The parents of the term, and its children that the user may view.
   */
  public function getEdgesOf(EntityInterface $entity): array {
    return $this->getAllEdgesOf([$entity->id() => $entity]);
  }

  /**
   * {@inheritdoc}
   */
  public function getAllEdgesOf(array $entities): array {
    $ids = array_map('strval', array_keys($entities));
    if (!$ids) {
      return [];
    }
    $edges = $this->getEdges($entities);
    $children = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(TRUE)
      ->condition('parent', $ids, 'IN')
      ->execute();
    $given = array_flip($ids);
    foreach ($this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple(array_diff($children, $ids)) as $child) {
      foreach (array_column($child->get('parent')->getValue(), 'target_id') as $parent_id) {
        if (isset($given[(string) $parent_id])) {
          $edges[] = $this->edge($child->id(), $parent_id);
        }
      }
    }
    return $edges;
  }

  /**
   * {@inheritdoc}
   */
  public function getEdgeTypes(string $entity_type_id): array {
    return $entity_type_id === 'taxonomy_term' ? [static::TYPE => (string) new TranslatableMarkup('Parent term')] : [];
  }

  /**
   * Returns the edge from a term to its parent.
   */
  protected function edge(int|string $term_id, int|string $parent_id): Edge {
    return new Edge(
      id: implode(':', [static::TYPE, $term_id, $parent_id]),
      sourceEntityType: 'taxonomy_term',
      sourceId: $term_id,
      targetEntityType: 'taxonomy_term',
      targetId: $parent_id,
      type: static::TYPE,
      typeLabel: (string) new TranslatableMarkup('Parent term'),
    );
  }

}
