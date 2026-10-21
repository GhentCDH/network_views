<?php

declare(strict_types=1);

namespace Drupal\network_views\Graph;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\network_views\EdgeSource\Edge;
use Drupal\network_views\EdgeSource\EdgeSourceManager;

/**
 * Finds the neighbourhood of an entity in a network: its ego network.
 *
 * The neighbours are the entities of the same type connected to it through
 * the configured edge sources, and, for edge types shown as shared links,
 * the entities that share an item with it (e.g. the other initiatives of
 * its organiser). With a depth of 2, the neighbours of the neighbours too.
 * Only entities the current user may view are followed.
 */
class Neighbourhood {

  public function __construct(
    protected EdgeSourceManager $edgeSourceManager,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns the IDs of an entity and its neighbours.
   *
   * @param \Drupal\Core\Entity\EntityInterface $root
   *   The entity.
   * @param array $edge_sources
   *   Edge source configuration, keyed by plugin ID.
   * @param array $shared_links
   *   Shared links settings; see GraphBuilder::DEFAULT_OPTIONS.
   * @param int $depth
   *   The number of steps from the entity: 1 or 2.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cacheability of the edges and access checks.
   *
   * @return string[]
   *   The IDs of the entity and its neighbours, all of the entity's type.
   */
  public function find(EntityInterface $root, array $edge_sources, array $shared_links, int $depth, CacheableMetadata $cacheability): array {
    $entity_type_id = $root->getEntityTypeId();
    // Any entity of the type can become a neighbour.
    $cacheability->addCacheTags($root->getEntityType()->getListCacheTags());
    $shared_modes = array_column($shared_links, 'mode', 'edge_type');
    $known = [(string) $root->id() => TRUE];
    $frontier = [$root->id() => $root];

    for ($step = 0; $step < max(1, $depth) && $frontier; $step++) {
      $found = [];
      // The items the frontier shares, by entity type and edge type.
      $hubs = [];
      foreach ($this->edgeSourceManager->getAllEdgesOf($edge_sources, $frontier, $cacheability) as $edge) {
        if (isset($shared_modes[$edge->type])) {
          [$member_type, $member, $shared_type, $shared] = static::sharedEnds($edge, $shared_modes[$edge->type]);
          if ($member_type === $entity_type_id && isset($frontier[$member])) {
            $hubs[$shared_type][$edge->type][(string) $shared] = TRUE;
          }
          continue;
        }
        $ends = [
          [$edge->sourceEntityType, $edge->sourceId, $edge->targetEntityType, $edge->targetId],
          [$edge->targetEntityType, $edge->targetId, $edge->sourceEntityType, $edge->sourceId],
        ];
        foreach ($ends as [$from_type, $from, $to_type, $to]) {
          if ($from_type === $entity_type_id && $to_type === $entity_type_id && isset($frontier[$from]) && !isset($known[(string) $to])) {
            $found[(string) $to] = TRUE;
          }
        }
      }
      foreach ($hubs as $shared_type => $items_per_type) {
        // The IDs are the keys: array_replace() keeps numeric keys.
        $ids = array_keys(array_replace(...array_values($items_per_type)));
        $items = $this->viewable($this->entityTypeManager->getStorage($shared_type)->loadMultiple($ids), $cacheability);
        foreach ($this->edgeSourceManager->getAllEdgesOf($edge_sources, $items, $cacheability) as $edge) {
          if (!isset($shared_modes[$edge->type])) {
            continue;
          }
          [$member_type, $member, $edge_shared_type, $shared] = static::sharedEnds($edge, $shared_modes[$edge->type]);
          if ($member_type === $entity_type_id
            && $edge_shared_type === $shared_type
            && isset($items_per_type[$edge->type][(string) $shared])
            && !isset($known[(string) $member])) {
            $found[(string) $member] = TRUE;
          }
        }
      }
      $frontier = $this->viewable($this->entityTypeManager->getStorage($entity_type_id)->loadMultiple(array_keys($found)), $cacheability);
      foreach ($frontier as $id => $entity) {
        $known[(string) $id] = TRUE;
      }
    }
    return array_map('strval', array_keys($known));
  }

  /**
   * Returns the ends of an edge of a shared links type.
   *
   * @param \Drupal\network_views\EdgeSource\Edge $edge
   *   The edge.
   * @param string $mode
   *   The shared links mode: 'target' when the sources of edges with the same
   *   target are linked, 'source' when the targets of edges with the same
   *   source are.
   *
   * @return array
   *   The entity type and ID of the linked end, then of the shared item.
   */
  public static function sharedEnds(Edge $edge, string $mode): array {
    return $mode === 'source'
      ? [$edge->targetEntityType, $edge->targetId, $edge->sourceEntityType, $edge->sourceId]
      : [$edge->sourceEntityType, $edge->sourceId, $edge->targetEntityType, $edge->targetId];
  }

  /**
   * Returns the entities the current user may view.
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $entities
   *   The entities, keyed by ID.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cacheability of the access checks.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   The viewable entities, keyed by ID.
   */
  protected function viewable(array $entities, CacheableMetadata $cacheability): array {
    return array_filter($entities, function (EntityInterface $entity) use ($cacheability) {
      $access = $entity->access('view', NULL, TRUE);
      $cacheability->addCacheableDependency($access);
      return $access->isAllowed();
    });
  }

}
