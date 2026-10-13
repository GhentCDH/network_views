<?php

declare(strict_types=1);

namespace Drupal\Tests\network_views\Traits;

use Drupal\views\Entity\View;
use Drupal\views\Views;

/**
 * Creates and renders node views with the network style.
 */
trait NetworkViewTrait {

  /**
   * Creates a view of nodes with the network style.
   *
   * @param array $style_options
   *   Options of the network style.
   * @param string[] $bundles
   *   The content types to show.
   * @param array $fields
   *   Extra Views fields, keyed by field ID.
   */
  protected function createNetworkView(array $style_options, array $bundles, array $fields = []): View {
    $view = View::create([
      'id' => 'test_network',
      'label' => 'Test network',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_plugin' => 'default',
          'display_title' => 'Default',
          'position' => 0,
          'display_options' => [
            'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
            'style' => ['type' => 'network', 'options' => $style_options],
            'row' => ['type' => 'fields'],
            'fields' => [
              'title' => [
                'id' => 'title',
                'table' => 'node_field_data',
                'field' => 'title',
                'plugin_id' => 'field',
                'entity_type' => 'node',
                'entity_field' => 'title',
                'label' => 'Title',
                'type' => 'string',
                'settings' => ['link_to_entity' => FALSE],
              ],
            ] + $fields,
            'filters' => [
              'type' => [
                'id' => 'type',
                'table' => 'node_field_data',
                'field' => 'type',
                'plugin_id' => 'bundle',
                'entity_type' => 'node',
                'entity_field' => 'type',
                'value' => array_combine($bundles, $bundles),
              ],
              'status' => [
                'id' => 'status',
                'table' => 'node_field_data',
                'field' => 'status',
                'plugin_id' => 'boolean',
                'entity_type' => 'node',
                'entity_field' => 'status',
                'value' => '1',
              ],
            ],
            'sorts' => [
              'nid' => [
                'id' => 'nid',
                'table' => 'node_field_data',
                'field' => 'nid',
                'plugin_id' => 'standard',
                'order' => 'ASC',
              ],
            ],
          ],
        ],
      ],
    ]);
    $view->save();
    return $view;
  }

  /**
   * Executes the test view and returns the render array of the style.
   */
  protected function renderNetwork(): array {
    $view = Views::getView('test_network');
    $view->setDisplay('default');
    $view->execute();
    $build = $view->style_plugin->render();
    $view->destroy();
    return $build;
  }

  /**
   * Executes the test view and returns the graph data.
   */
  protected function getGraph(): array {
    $build = $this->renderNetwork();
    $this->assertArrayHasKey('#canvas_attributes', $build);
    return json_decode($build['#canvas_attributes']->toArray()['data-network-views'], TRUE);
  }

  /**
   * Returns the edges of a graph as "source label > target label: type".
   *
   * @return string[]
   *   The sorted edges.
   */
  protected function edgeList(array $graph): array {
    $labels = array_column($graph['nodes'], 'label', 'id');
    $edges = array_map(fn(array $edge) => $labels[$edge['source']] . ' > ' . $labels[$edge['target']] . ': ' . $edge['label'], $graph['edges']);
    sort($edges);
    return $edges;
  }

}
