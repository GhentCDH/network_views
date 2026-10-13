<?php

declare(strict_types=1);

namespace Drupal\network_views\EdgeSource;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Finds the edges between the entities of a network view.
 *
 * The configuration of an edge source is stored in the options of the view's
 * network style, under edge_sources.PLUGIN_ID.settings.
 */
interface EdgeSourceInterface extends PluginInspectionInterface, ConfigurableInterface, CacheableDependencyInterface {

  /**
   * Whether the edge source can be used for views of an entity type.
   *
   * @param string $entity_type_id
   *   The base entity type of the view.
   */
  public function isApplicable(string $entity_type_id): bool;

  /**
   * Builds the settings form of the edge source.
   *
   * @param array $form
   *   The form element for the settings.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the style options form.
   * @param string $entity_type_id
   *   The base entity type of the view.
   *
   * @return array
   *   The form element.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, string $entity_type_id): array;

  /**
   * Returns the settings to store from the submitted settings form values.
   *
   * @param array $values
   *   The submitted values of the settings form.
   *
   * @return array
   *   The configuration of the edge source.
   */
  public function submitConfigurationForm(array $values): array;

  /**
   * Returns the edges that start or end at the given entities.
   *
   * The network style keeps only the edges of which both ends are in the
   * result of the view, so an edge source may return edges to other entities.
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $entities
   *   The entities of the result rows, keyed by entity ID, all of the base
   *   entity type of the view.
   *
   * @return \Drupal\network_views\EdgeSource\Edge[]
   *   The edges.
   */
  public function getEdges(array $entities): array;

}
