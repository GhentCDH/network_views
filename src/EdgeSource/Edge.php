<?php

declare(strict_types=1);

namespace Drupal\network_views\EdgeSource;

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
   * @param string|null $reverseLabel
   *   Optional label of the edge read from target to source, e.g. "Student
   *   of" for "Teacher of". Used when the graph is oriented from a node that
   *   is the target of the edge, as in an ego network.
   * @param bool $languageUnavailable
   *   Whether the edge is not available in the current language, e.g.
   *   because something it depends on has no translation in that language.
   *   Depending on the settings, such edges are hidden or shown faded.
   * @param string|null $group
   *   A machine key of a coarser kind of edge, e.g. the relation content
   *   type of relations whose type is their relation type term; edges can be
   *   colored by group. NULL for the same as the type.
   * @param string|null $groupLabel
   *   The human-readable label of the group.
   * @param bool $otherEndAsNode
   *   Whether the end that is not a node of the graph may be added as a node,
   *   when the other end is one: e.g. a referenced term ("show as node"), or
   *   the other side of a relation.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $sourceEntityType,
    public readonly int|string $sourceId,
    public readonly string $targetEntityType,
    public readonly int|string $targetId,
    public readonly string $type,
    public readonly string $typeLabel,
    public readonly ?string $reverseLabel = NULL,
    public readonly bool $languageUnavailable = FALSE,
    public readonly ?string $group = NULL,
    public readonly ?string $groupLabel = NULL,
    public readonly bool $otherEndAsNode = FALSE,
  ) {}

}
