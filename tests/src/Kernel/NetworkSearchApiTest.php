<?php

declare(strict_types=1);

namespace Drupal\Tests\network_views\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\network_views\Traits\NetworkViewTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Entity\View;
use Drupal\views\Views;
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
    'language',
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
        'field_keywords' => [
          'label' => 'Keywords',
          'datasource_id' => 'entity:node',
          'property_path' => 'field_keywords',
          'type' => 'integer',
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

    $this->createKeywordView();

    $graph = $this->getGraph();
    $labels = array_column($graph['nodes'], 'label');
    sort($labels);
    // Dirk shares no keyword.
    $this->assertSame(['Ann', 'Bob', 'Cas'], $labels);
    $this->assertSame(['Ann > Bob: Same keyword', 'Bob > Cas: Same keyword'], $this->edgeList($graph));
    // Each keyword its own color.
    $this->assertCount(2, array_unique(array_column($graph['edges'], 'color')));
  }

  /**
   * Exposed filter options that give no links can be hidden.
   *
   * Land and Archives are shared by two initiatives; Solo belongs to one,
   * Unused to none; Eva, in two languages (two index items), is the only
   * initiative with Twice.
   */
  public function testHideEmptyFilterOptions(): void {
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('nl')->save();
    $this->container->get('language_manager')->reset();
    $terms = [];
    foreach (['Land', 'Archives', 'Solo', 'Twice', 'Unused'] as $name) {
      $terms[$name] = Term::create(['vid' => 'keywords', 'name' => $name]);
      $terms[$name]->save();
    }
    $initiatives = [
      'Ann' => ['Land'],
      'Bob' => ['Land', 'Archives'],
      'Cas' => ['Archives'],
      'Dirk' => ['Solo'],
      'Eva' => ['Twice'],
    ];
    foreach ($initiatives as $title => $names) {
      $node = Node::create([
        'type' => 'initiative',
        'title' => $title,
        'field_keywords' => array_map(fn($name) => $terms[$name]->id(), $names),
      ]);
      $node->save();
      if ($title === 'Eva') {
        $node->addTranslation('nl', ['title' => 'Eva NL'])->save();
      }
    }
    Index::load('content')->indexItems();
    $names = array_flip(array_map(fn(Term $term) => $term->id(), $terms));
    $options = function (array $input = []) use ($names): array {
      $view = Views::getView('test_network');
      $view->setDisplay('default');
      $view->setExposedInput($input);
      $view->build();
      $options = array_map(fn($key) => $names[$key] ?? (string) $key, array_keys($view->exposed_widgets['keyword']['#options']));
      $view->destroy();
      sort($options);
      return $options;
    };
    $filter = [
      'keyword' => [
        'id' => 'keyword',
        'table' => 'search_api_index_content',
        'field' => 'field_keywords',
        'plugin_id' => 'search_api_term',
        'vid' => 'keywords',
        'type' => 'select',
        'operator' => 'or',
        'value' => [],
        'exposed' => TRUE,
        'expose' => [
          'operator_id' => 'keyword_op',
          'label' => 'Keyword',
          'identifier' => 'keyword',
          'multiple' => FALSE,
          'remember_roles' => ['authenticated' => 'authenticated'],
          'reduce' => FALSE,
        ],
      ],
    ];

    $this->createKeywordView(['hide_empty_filter_options' => TRUE], $filter);
    $view = Views::getView('test_network');
    $view->setDisplay('default');
    $view->execute();
    $this->assertCount(6, $view->result, 'An index item per translation.');
    $this->assertSame(['All', 'Archives', 'Land'], $options());
    // The selected value stays.
    $this->assertSame(['All', 'Archives', 'Land', 'Solo'], $options(['keyword' => $terms['Solo']->id()]));

    // Without the option, or without hiding isolated nodes, all options.
    $all = ['All', 'Archives', 'Land', 'Solo', 'Twice', 'Unused'];
    $this->createKeywordView([], $filter);
    $this->assertSame($all, $options());
    $this->createKeywordView(['hide_empty_filter_options' => TRUE, 'hide_isolated' => FALSE], $filter);
    $this->assertSame($all, $options());
  }

  /**
   * Creates the test view of the index: initiatives linked by their keywords.
   *
   * @param array $style_options
   *   Options of the network style, added to those of the shared keywords.
   * @param array $filters
   *   Views filters, keyed by ID.
   */
  protected function createKeywordView(array $style_options = [], array $filters = []): void {
    View::load('test_network')?->delete();
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
            'filters' => $filters,
            'style' => [
              'type' => 'network',
              'options' => $style_options + [
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
  }

}
