<?php

declare(strict_types=1);

namespace Drupal\Tests\network_views\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\network_views\Traits\NetworkViewTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a network view of a Search API index.
 *
 * @group network_views
 */
#[Group('network_views')]
#[RunTestsInSeparateProcesses]
class NetworkSearchApiTest extends KernelTestBase {

  use NetworkViewTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'taxonomy',
    'views',
    'search_api',
    'search_api_db',
    'network_views',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('search_api_task');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('search_api', ['search_api_item']);
    $this->installConfig(['system', 'field', 'filter', 'node', 'taxonomy', 'views', 'search_api']);
    // User 1 is created first so the current user does not bypass access.
    $this->createUser();
    $this->setUpCurrentUser([], ['access content']);

    NodeType::create(['type' => 'initiative', 'name' => 'Initiative'])->save();
    Vocabulary::create(['vid' => 'keywords', 'name' => 'Keywords'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_keywords',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_keywords',
      'entity_type' => 'node',
      'bundle' => 'initiative',
      'label' => 'Keywords',
      'settings' => ['handler_settings' => ['target_bundles' => ['keywords' => 'keywords']]],
    ])->save();

    Server::create([
      'id' => 'database',
      'name' => 'Database',
      'backend' => 'search_api_db',
      'backend_config' => ['database' => 'default:default'],
    ])->save();
    Index::create([
      'id' => 'content',
      'name' => 'Content',
      'server' => 'database',
      'datasource_settings' => ['entity:node' => []],
      'tracker_settings' => ['default' => []],
      'field_settings' => [
        'title' => [
          'label' => 'Title',
          'datasource_id' => 'entity:node',
          'property_path' => 'title',
          'type' => 'string',
        ],
      ],
      'options' => ['index_directly' => FALSE],
    ])->save();
  }

  /**
   * Initiatives that share a keyword are linked; the keywords are no nodes.
   */
  public function testSharedKeywords(): void {
    $land = Term::create(['vid' => 'keywords', 'name' => 'Land']);
    $land->save();
    $archives = Term::create(['vid' => 'keywords', 'name' => 'Archives']);
    $archives->save();
    foreach (['Ann' => [$land], 'Bob' => [$land, $archives], 'Cas' => [$archives], 'Dirk' => []] as $title => $terms) {
      Node::create([
        'type' => 'initiative',
        'title' => $title,
        'field_keywords' => array_map(fn(Term $term) => $term->id(), $terms),
      ])->save();
    }
    Index::load('content')->indexItems();

    View::create([
      'id' => 'test_network',
      'label' => 'Test network',
      'base_table' => 'search_api_index_content',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_plugin' => 'default',
          'display_title' => 'Default',
          'position' => 0,
          'display_options' => [
            'query' => ['type' => 'search_api_query', 'options' => []],
            'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
            'row' => ['type' => 'fields'],
            'fields' => [
              'title' => [
                'id' => 'title',
                'table' => 'search_api_index_content',
                'field' => 'title',
                'plugin_id' => 'search_api_field',
              ],
            ],
            'style' => [
              'type' => 'network',
              'options' => [
                'edge_sources' => [
                  'entity_reference_fields' => [
                    'enabled' => TRUE,
                    'settings' => ['fields' => ['field_keywords'], 'node_fields' => []],
                  ],
                ],
                'shared_links' => [
                  ['edge_type' => 'field:field_keywords', 'mode' => 'target', 'label' => 'Same keyword', 'max' => 0],
                ],
                'edge_color_by' => 'shared_item',
                'hide_isolated' => TRUE,
              ],
            ],
          ],
        ],
      ],
    ])->save();

    $graph = $this->getGraph();
    $labels = array_column($graph['nodes'], 'label');
    sort($labels);
    // Dirk shares no keyword.
    $this->assertSame(['Ann', 'Bob', 'Cas'], $labels);
    $this->assertSame(['Ann > Bob: Same keyword', 'Bob > Cas: Same keyword'], $this->edgeList($graph));
    // Each keyword its own color.
    $this->assertCount(2, array_unique(array_column($graph['edges'], 'color')));
  }

}
