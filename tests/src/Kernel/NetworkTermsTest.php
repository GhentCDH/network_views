<?php

declare(strict_types=1);

namespace Drupal\Tests\network_views\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\network_views\Traits\NetworkViewTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests terms in network views: as nodes, as colors, as shared items.
 *
 * @group network_views
 */
#[Group('network_views')]
#[RunTestsInSeparateProcesses]
class NetworkTermsTest extends KernelTestBase {

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
    'network_views',
  ];

  /**
   * The test terms, keyed by name.
   *
   * @var \Drupal\taxonomy\TermInterface[]
   */
  protected array $terms = [];

  /**
   * The test persons, keyed by title.
   *
   * @var \Drupal\node\NodeInterface[]
   */
  protected array $persons = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'field', 'filter', 'node', 'taxonomy', 'views']);
    // User 1 is created first so the current user does not bypass access.
    $this->createUser();
    $this->setUpCurrentUser([], ['access content']);

    NodeType::create(['type' => 'person', 'name' => 'Person'])->save();
    Vocabulary::create(['vid' => 'topics', 'name' => 'Topics'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_topics',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_topics',
      'entity_type' => 'node',
      'bundle' => 'person',
      'label' => 'Topics',
      'settings' => ['handler_settings' => ['target_bundles' => ['topics' => 'topics']]],
    ])->save();

    // History has the child Archives; Secret is unpublished.
    $this->createTerm('History');
    $this->createTerm('Archives', ['parent' => $this->terms['History']->id()]);
    $this->createTerm('Philosophy');
    $this->createTerm('Secret', ['status' => 0]);
    // Ann: History, Archives; Bob: Archives; Cas: Philosophy, Secret.
    $this->createPerson('Ann', ['History', 'Archives']);
    $this->createPerson('Bob', ['Archives']);
    $this->createPerson('Cas', ['Philosophy', 'Secret']);
  }

  /**
   * Creates a topic term.
   */
  protected function createTerm(string $name, array $values = []): TermInterface {
    $term = Term::create($values + ['vid' => 'topics', 'name' => $name]);
    $term->save();
    return $this->terms[$name] = $term;
  }

  /**
   * Creates a person with topics.
   */
  protected function createPerson(string $title, array $topics): NodeInterface {
    $node = Node::create([
      'type' => 'person',
      'title' => $title,
      'field_topics' => array_map(fn($name) => $this->terms[$name]->id(), $topics),
    ]);
    $node->save();
    return $this->persons[$title] = $node;
  }

  /**
   * Edge source configuration with the topics field.
   */
  protected function topicEdges(bool $as_nodes): array {
    return [
      'entity_reference_fields' => [
        'enabled' => TRUE,
        'settings' => ['fields' => ['field_topics'], 'node_fields' => $as_nodes ? ['field_topics'] : []],
      ],
    ];
  }

  /**
   * Referenced terms can be shown as nodes, colored by vocabulary.
   */
  public function testTermsAsNodes(): void {
    $this->createNetworkView([
      'edge_sources' => $this->topicEdges(TRUE),
      'node_colors' => ['taxonomy_term:topics' => '#123456'],
    ], ['person']);
    $graph = $this->getGraph();

    // The terms the persons refer to, except the unpublished Secret.
    $labels = array_column($graph['nodes'], 'label');
    sort($labels);
    $this->assertSame(['Ann', 'Archives', 'Bob', 'Cas', 'History', 'Philosophy'], $labels);
    $this->assertSame([
      'Ann > Archives: Topics',
      'Ann > History: Topics',
      'Bob > Archives: Topics',
      'Cas > Philosophy: Topics',
    ], $this->edgeList($graph));
    $colors = array_column($graph['nodes'], 'color', 'label');
    $this->assertSame('#123456', $colors['History']);
    $legend = $this->renderNetwork()['#legend']['nodes'];
    $this->assertSame([['Person', 3], ['Topics', 3]], array_map(fn($item) => [$item['label'], $item['count']], $legend));
    $this->assertStringEndsWith('/taxonomy/term/' . $this->terms['History']->id(), array_column($graph['nodes'], 'url', 'label')['History']);

    // Without "show as node", references to terms are not edges.
    View::load('test_network')->delete();
    $this->createNetworkView(['edge_sources' => $this->topicEdges(FALSE)], ['person']);
    $graph = $this->getGraph();
    $this->assertSame(['Ann', 'Bob', 'Cas'], array_column($graph['nodes'], 'label'));
    $this->assertSame([], $graph['edges']);
  }

  /**
   * Persons can be linked by a shared term, without the term as a node.
   */
  public function testSharedTerms(): void {
    $this->createNetworkView([
      'edge_sources' => $this->topicEdges(FALSE),
      'shared_links' => [['edge_type' => 'field:field_topics', 'mode' => 'target', 'label' => 'Same topic', 'max' => 0]],
    ], ['person']);
    $graph = $this->getGraph();
    $this->assertSame(['Ann > Bob: Same topic'], $this->edgeList($graph));
    $this->assertSame('Same topic: Archives', $graph['edges'][0]['tooltip']);
  }

  /**
   * Nodes can be colored by the term a field refers to.
   */
  public function testColorByReferencedTerm(): void {
    $topics = [
      'id' => 'field_topics',
      'table' => 'node__field_topics',
      'field' => 'field_topics',
      'plugin_id' => 'field',
      'entity_type' => 'node',
      'entity_field' => 'field_topics',
      'type' => 'entity_reference_label',
      'settings' => ['link' => FALSE],
      'exclude' => TRUE,
    ];
    $archives = 'taxonomy_term:' . $this->terms['Archives']->id();
    $this->createNetworkView([
      'color_field' => 'field_topics',
      'node_colors' => [$archives => '#00aa00'],
    ], ['person'], ['field_topics' => $topics]);
    $graph = $this->getGraph();
    // The first topic counts: Ann History, Bob Archives, Cas Philosophy.
    $colors = array_column($graph['nodes'], 'color', 'label');
    $this->assertSame('#00aa00', $colors['Bob']);
    $this->assertCount(3, array_unique($colors));
    $this->assertSame(['Archives', 'History', 'Philosophy'], array_column($this->renderNetwork()['#legend']['nodes'], 'label'));
  }

  /**
   * In a view of terms, the term hierarchy is an edge source.
   */
  public function testTermHierarchy(): void {
    $manager = $this->container->get('plugin.manager.network_views.edge_source');
    $this->assertTrue($manager->createInstance('term_parents')->isApplicable('taxonomy_term'));
    $this->assertFalse($manager->createInstance('term_parents')->isApplicable('node'));

    View::create([
      'id' => 'test_terms',
      'label' => 'Terms',
      'base_table' => 'taxonomy_term_field_data',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_plugin' => 'default',
          'display_title' => 'Default',
          'position' => 0,
          'display_options' => [
            'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
            'style' => [
              'type' => 'network',
              'options' => ['edge_sources' => ['term_parents' => ['enabled' => TRUE, 'settings' => []]]],
            ],
            'row' => ['type' => 'fields'],
            'fields' => [
              'name' => [
                'id' => 'name',
                'table' => 'taxonomy_term_field_data',
                'field' => 'name',
                'plugin_id' => 'field',
                'entity_type' => 'taxonomy_term',
                'entity_field' => 'name',
                'type' => 'string',
                'settings' => ['link_to_entity' => FALSE],
              ],
            ],
            'filters' => [
              'status' => [
                'id' => 'status',
                'table' => 'taxonomy_term_field_data',
                'field' => 'status',
                'plugin_id' => 'boolean',
                'entity_type' => 'taxonomy_term',
                'entity_field' => 'status',
                'value' => '1',
              ],
            ],
          ],
        ],
      ],
    ])->save();
    $view = Views::getView('test_terms');
    $view->setDisplay('default');
    $view->execute();
    $build = $view->style_plugin->render();
    $graph = json_decode($build['#canvas_attributes']->toArray()['data-network-views'], TRUE);
    $this->assertSame(['Archives > History: Parent term'], $this->edgeList($graph));

    // The edges of one term include its children.
    $config = ['term_parents' => ['enabled' => TRUE, 'settings' => []]];
    $edges = $manager->getEdgesOf($config, $this->terms['History'], new CacheableMetadata());
    $this->assertSame([(string) $this->terms['Archives']->id()], array_map(fn($edge) => (string) $edge->sourceId, $edges));
  }

}
