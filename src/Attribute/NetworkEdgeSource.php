<?php

declare(strict_types=1);

namespace Drupal\views_network\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a network edge source plugin.
 *
 * An edge source finds the edges between the entities in the result of a view
 * with the network style.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class NetworkEdgeSource extends Plugin {

  /**
   * Constructs a NetworkEdgeSource attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The label shown in the style options.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   A short description shown in the style options.
   * @param int $weight
   *   The order of the edge source in the style options.
   * @param class-string|null $deriver
   *   The deriver class.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?TranslatableMarkup $description = NULL,
    public readonly int $weight = 0,
    public readonly ?string $deriver = NULL,
  ) {}

}
