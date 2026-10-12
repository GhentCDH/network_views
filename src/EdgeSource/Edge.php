<?php

declare(strict_types=1);

namespace Drupal\views_network\EdgeSource;

/**
 * An edge between two entities of a network view.
 */
final class Edge {

  /**
   * Constructs an Edge.
   *
   * @param string $id
   *   An ID that is unique in the graph, e.g. 'field_knows:12:34'. Edges with
   *   the same ID are shown once.
   * @param string $sourceEntityType
   *   The entity type of the source entity.
   * @param int|string $sourceId
   *   The ID of the source entity.
   * @param string $targetEntityType
   *   The entity type of the target entity.
   * @param int|string $targetId
   *   The ID of the target entity.
   * @param string $type
   *   A machine key of the kind of edge, e.g. 'field:field_knows'. Edges of
   *   the same type get the same color and legend entry.
   * @param string $typeLabel
   *   The human-readable label of the type, also used as edge label.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $sourceEntityType,
    public readonly int|string $sourceId,
    public readonly string $targetEntityType,
    public readonly int|string $targetId,
    public readonly string $type,
    public readonly string $typeLabel,
  ) {}

}
