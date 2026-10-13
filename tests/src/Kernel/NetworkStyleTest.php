<?php

declare(strict_types=1);

namespace Drupal\Tests\network_views\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\network_views\Traits\NetworkViewTrait;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the network style with entity reference edges.
 *
 * @group network_views
 */
#[Group('network_views')]
#[RunTestsInSeparateProcesses]
class NetworkStyleTest extends KernelTestBase {

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
    'views',
    'network_views',
  ];

  /**
   * The test nodes, keyed by title.
   *
   * @var \Drupal\node\NodeInterface[]
   */
  protected array $nodes = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'field', 'filter', 'node', 'views']);
    $this->setUpCurrentUser([], ['access content']);

    NodeType::create(['type' => 'person', 'name' => 'Person'])->save();
    NodeType::create(['type' => 'place', 'name' => 'Place'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_knows',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'node'],
    ])->save();
    foreach (['person', 'place'] as $bundle) {
      FieldConfig::create([
        'field_name' => 'field_knows',
        'entity_type' => 'node',
        'bundle' => $bundle,
        'label' => 'Knows',
      ])->save();
    }

    // Ann knows Bob and the place Gent; Bob knows Cas and Ann; Dirk knows
    // nobody.
    $this->createNode('Gent', 'place');
    $this->createNode('Cas');
    $this->createNode('Dirk');
    $this->createNode('Bob', 'person', ['Cas']);
    $this->createNode('Ann', 'person', ['Bob', 'Gent']);
    $this->nodes['Bob']->set('field_knows', [$this->nodes['Cas']->id(), $this->nodes['Ann']->id()])->save();
  }

  /**
   * Creates a node that knows other nodes.
   */
  protected function createNode(string $title, string $bundle = 'person', array $knows = []): NodeInterface {
    $node = Node::create([
      'type' => $bundle,
      'title' => $title,
      'field_knows' => array_map(fn($known) => $this->nodes[$known]->id(), $knows),
    ]);
    $node->save();
    return $this->nodes[$title] = $node;
  }

  /**
   * Returns style options with the field_knows edges.
   */
  protected function options(array $options = []): array {
    return $options + [
      'edge_sources' => [
        'entity_reference_fields' => ['enabled' => TRUE, 'settings' => ['fields' => ['field_knows']]],
      ],
    ];
  }

  /**
   * Only references between result rows are edges.
   */
  public function testEdgesBetweenResultRows(): void {
    $this->createNetworkView($this->options(), ['person']);
    $graph = $this->getGraph();

    $this->assertSame(['Cas', 'Dirk', 'Bob', 'Ann'], array_column($graph['nodes'], 'label'));
    $this->assertSame(['Ann > Bob: Knows', 'Bob > Ann: Knows', 'Bob > Cas: Knows'], $this->edgeList($graph));
    $this->assertSame('node:' . $this->nodes['Ann']->id(), $graph['nodes'][3]['id']);
    $this->assertStringEndsWith('/node/' . $this->nodes['Ann']->id(), $graph['nodes'][3]['url']);

    // With the places in the result, the reference to Gent is an edge too.
    Views::getView('test_network')->storage->delete();
    $this->createNetworkView($this->options(), ['person', 'place']);
    $this->assertContains('Ann > Gent: Knows', $this->edgeList($this->getGraph()));
  }

  /**
   * Undirected edges are merged, and isolated nodes can be hidden.
   */
  public function testUndirectedAndIsolated(): void {
    $this->createNetworkView($this->options(['directed' => FALSE, 'hide_isolated' => TRUE]), ['person']);
    $graph = $this->getGraph();

    $this->assertSame(['Cas', 'Bob', 'Ann'], array_column($graph['nodes'], 'label'));
    $this->assertCount(2, $graph['edges']);
    $this->assertFalse($graph['settings']['directed']);
  }

  /**
   * No edge source means no edges, and the style still renders.
   */
  public function testNoEdgeSource(): void {
    $this->createNetworkView([], ['person']);
    $graph = $this->getGraph();
    $this->assertCount(4, $graph['nodes']);
    $this->assertSame([], $graph['edges']);
  }

  /**
   * Nodes are colored by bundle or field, with overrides, and get a legend.
   */
  public function testColorsAndLegend(): void {
    $this->createNetworkView($this->options(['colors' => "Place|#000000"]), ['person', 'place']);
    $build = $this->renderNetwork();
    $graph = json_decode($build['#canvas_attributes']->toArray()['data-network-views'], TRUE);

    $colors = array_column($graph['nodes'], 'color', 'label');
    $this->assertSame('#000000', $colors['Gent']);
    $this->assertNotSame('#000000', $colors['Ann']);
    $this->assertSame($colors['Ann'], $colors['Bob']);
    $this->assertSame([
      ['label' => 'Person', 'color' => $colors['Ann'], 'count' => 4],
      ['label' => 'Place', 'color' => '#000000', 'count' => 1],
    ], $build['#legend']['nodes']);
    // One edge type gets the neutral color.
    $this->assertSame([['label' => 'Knows', 'color' => '#9a9a9a', 'count' => 4]], $build['#legend']['edges']);

    // The title as color field and as tooltip.
    Views::getView('test_network')->storage->delete();
    $this->createNetworkView($this->options([
      'color_field' => 'title',
      'tooltip_fields' => ['title'],
      'label_field' => 'title',
    ]), ['person']);
    $graph = $this->getGraph();
    $this->assertCount(4, array_unique(array_column($graph['nodes'], 'color')));
    $this->assertSame('Ann', $graph['nodes'][3]['category']);
    $this->assertStringContainsString('<span class="network-views__tooltip-label">Title:</span> Ann', $graph['nodes'][3]['tooltip']);
  }

  /**
   * The whole view renders to HTML with the graph data.
   */
  public function testRenderedOutput(): void {
    $this->createNetworkView($this->options(), ['person']);
    $view = Views::getView('test_network');
    $build = $view->preview('default');
    $html = (string) $this->container->get('renderer')->renderRoot($build);

    $this->assertStringContainsString('class="network-views"', $html);
    $this->assertStringContainsString('data-network-views="', $html);
    $this->assertStringContainsString('style="height: 600px"', $html);
    $this->assertStringContainsString('network-views__legend', $html);
    $this->assertContains('network_views/network', $build['#attached']['library']);
  }

}
