<?php

declare(strict_types=1);

namespace Drupal\network_views\Graph;

use Drupal\Core\Entity\EntityInterface;

/**
 * An entity as a node of a graph, with how to show it.
 */
final class GraphNode {

  /**
   * Constructs a GraphNode.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity, in the language to show it in.
   * @param string $label
   *   The label.
   * @param string $category
   *   The color category, e.g. the bundle label; empty for one color.
   * @param string|null $color
   *   A color from the data that overrides the color of the category.
   * @param string $tooltip
   *   Tooltip HTML, which must be safe to output.
   * @param bool $root
   *   Whether this is the root of the graph, e.g. of an ego network. The root
   *   is shown larger, edges are read from it, and it is never hidden.
   * @param bool $faded
   *   Whether to show the node faded.
   * @param string|null $colorKey
   *   A stable key of the color category, e.g. "node:person" for a bundle or
   *   "taxonomy_term:12" for a referenced term: colors are configured and
   *   assigned by this key, the same in every language. NULL to use the
   *   category label (as older settings did).
   */
  public function __construct(
    public readonly EntityInterface $entity,
    public readonly string $label,
    public readonly string $category = '',
    public readonly ?string $color = NULL,
    public readonly string $tooltip = '',
    public readonly bool $root = FALSE,
    public bool $faded = FALSE,
    public readonly ?string $colorKey = NULL,
  ) {}

}
