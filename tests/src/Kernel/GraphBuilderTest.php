<?php

declare(strict_types=1);

namespace Drupal\Tests\network_views\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Language\LanguageInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\network_views\EdgeSource\Edge;
use Drupal\network_views\Graph\GraphNode;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the graph builder and finding the edges of one entity.
 *
 * @group network_views
 */
#[Group('network_views')]
#[RunTestsInSeparateProcesses]
class GraphBuilderTest extends KernelTestBase {

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
    'language',
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
    $this->installConfig(['system', 'field', 'filter', 'node', 'language']);
    ConfigurableLanguage::createFromLangcode('nl')->save();
    // User 1 is created first so the current user does not bypass access.
    $this->createUser();
    $this->setUpCurrentUser([], ['access content']);

    NodeType::create(['type' => 'person', 'name' => 'Person'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_knows',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_knows',
      'entity_type' => 'node',
      'bundle' => 'person',
      'label' => 'Knows',
    ])->save();

    // Ann knows Bob; Cas knows Ann; Dirk knows Cas.
    $this->createPerson('Bob');
    $this->createPerson('Ann', ['Bob']);
    $this->createPerson('Cas', ['Ann']);
    $this->createPerson('Dirk', ['Cas']);
  }

  /**
   * Creates a person that knows other persons.
   */
  protected function createPerson(string $title, array $knows = [], array $values = []): NodeInterface {
    $node = Node::create($values + [
      'type' => 'person',
      'title' => $title,
      'field_knows' => array_map(fn($known) => $this->nodes[$known]->id(), $knows),
    ]);
    $node->save();
    return $this->nodes[$title] = $node;
  }

  /**
   * Returns graph nodes for persons.
   *
   * @return \Drupal\network_views\Graph\GraphNode[]
   *   The graph nodes, keyed by entity ID.
   */
  protected function graphNodes(array $titles, ?string $root = NULL): array {
    $nodes = [];
    foreach ($titles as $title) {
      $entity = $this->nodes[$title];
      $nodes[$entity->id()] = new GraphNode($entity, $title, root: $title === $root);
    }
    return $nodes;
  }

  /**
   * Returns an edge between two persons.
   */
  protected function edge(string $source, string $target, string $label, ?string $reverse = NULL, bool $language_unavailable = FALSE): Edge {
    return new Edge(
      id: "$source-$target",
      sourceEntityType: 'node',
      sourceId: $this->nodes[$source]->id(),
      targetEntityType: 'node',
      targetId: $this->nodes[$target]->id(),
      type: $label,
      typeLabel: $label,
      reverseLabel: $reverse,
      languageUnavailable: $language_unavailable,
    );
  }

  /**
   * Returns the edges of a graph as "source > target: label".
   *
   * @return string[]
   *   The sorted edges.
   */
  protected function edgeList(array $graph): array {
    $labels = array_column($graph['nodes'], 'label', 'id');
    $edges = array_map(fn(array $edge) => $labels[$edge['source']] . ' > ' . $labels[$edge['target']] . ': ' . $edge['label'] . ($edge['faded'] ? ' (faded)' : ''), $graph['edges']);
    sort($edges);
    return $edges;
  }

  /**
   * The edges of one entity include references to it.
   */
  public function testEdgesOf(): void {
    $config = ['entity_reference_fields' => ['enabled' => TRUE, 'settings' => ['fields' => ['field_knows']]]];
    $cacheability = new CacheableMetadata();
    $edges = $this->container->get('plugin.manager.network_views.edge_source')
      ->getEdgesOf($config, $this->nodes['Ann'], $cacheability);

    $titles = array_map(fn(NodeInterface $node) => $node->id(), $this->nodes);
    $names = array_flip($titles);
    $list = array_map(fn(Edge $edge) => $names[$edge->sourceId] . ' > ' . $names[$edge->targetId], $edges);
    sort($list);
    $this->assertSame(['Ann > Bob', 'Cas > Ann'], $list);
  }

  /**
   * Edges are read from the root, which is never hidden.
   */
  public function testRoot(): void {
    $edges = [
      $this->edge('Bob', 'Ann', 'Teacher of', 'Student of'),
      $this->edge('Cas', 'Ann', 'Friend of'),
      $this->edge('Ann', 'Dirk', 'Knows', 'Known by'),
    ];
    $graph = $this->container->get('network_views.graph_builder')
      ->build($this->graphNodes(['Ann', 'Bob', 'Cas', 'Dirk'], 'Ann'), $edges, ['hide_isolated' => TRUE], new CacheableMetadata());

    $this->assertSame(['Ann > Bob: Student of', 'Ann > Dirk: Knows', 'Cas > Ann: Friend of'], $this->edgeList($graph));
    $this->assertSame([TRUE, FALSE, FALSE, FALSE], array_column($graph['nodes'], 'root'));

    // Without edges, only the root is left.
    $graph = $this->container->get('network_views.graph_builder')
      ->build($this->graphNodes(['Ann', 'Bob'], 'Ann'), [], ['hide_isolated' => TRUE], new CacheableMetadata());
    $this->assertSame(['Ann'], array_column($graph['nodes'], 'label'));
  }

  /**
   * Nodes and edges unavailable in the current language are faded or hidden.
   */
  public function testLanguage(): void {
    // Ann and Cas have a Dutch translation, Cas an unpublished one; Bob is
    // only in English; Eva has no language.
    $this->nodes['Ann']->addTranslation('nl', ['title' => 'Ann NL'])->save();
    $this->nodes['Cas']->addTranslation('nl', ['title' => 'Cas NL', 'status' => 0])->save();
    $this->createPerson('Eva', [], ['langcode' => LanguageInterface::LANGCODE_NOT_SPECIFIED]);
    $this->config('system.site')->set('default_langcode', 'nl')->save();
    $this->container->get('language.default')->set(ConfigurableLanguage::load('nl'));
    $this->container->get('language_manager')->reset();

    $nodes = $this->graphNodes(['Ann', 'Bob', 'Cas', 'Eva']);
    $edges = [
      $this->edge('Ann', 'Bob', 'Knows'),
      $this->edge('Ann', 'Eva', 'Knows'),
      $this->edge('Eva', 'Ann', 'Mentions', NULL, TRUE),
    ];
    $builder = $this->container->get('network_views.graph_builder');

    $cacheability = new CacheableMetadata();
    $graph = $builder->build($nodes, $edges, ['language' => 'fade'], $cacheability);
    $this->assertSame(['Ann' => FALSE, 'Bob' => TRUE, 'Cas' => TRUE, 'Eva' => FALSE], array_column($graph['nodes'], 'faded', 'label'));
    $this->assertSame(['Ann > Bob: Knows', 'Ann > Eva: Knows', 'Eva > Ann: Mentions (faded)'], $this->edgeList($graph));
    $this->assertContains('languages:language_content', $cacheability->getCacheContexts());

    $graph = $builder->build($this->graphNodes(['Ann', 'Bob', 'Cas', 'Eva']), $edges, ['language' => 'hide'], new CacheableMetadata());
    $this->assertSame(['Ann', 'Eva'], array_column($graph['nodes'], 'label'));
    $this->assertSame(['Ann > Eva: Knows'], $this->edgeList($graph));

    $graph = $builder->build($this->graphNodes(['Ann', 'Bob', 'Cas', 'Eva']), $edges, [], new CacheableMetadata());
    $this->assertCount(4, $graph['nodes']);
    $this->assertCount(3, $graph['edges']);
  }

  /**
   * Edges of shared links types link the nodes that share an item.
   */
  public function testSharedLinks(): void {
    NodeType::create(['type' => 'organisation', 'name' => 'Organisation'])->save();
    foreach (['Org A' => 1, 'Org B' => 1, 'Org C' => 0] as $title => $status) {
      $this->nodes[$title] = Node::create(['type' => 'organisation', 'title' => $title, 'status' => $status]);
      $this->nodes[$title]->save();
    }
    // Ann, Bob and Cas are organised by Org A; Ann and Bob by Org B; Cas and
    // Dirk by Org C, which the user may not view. Ann knows Bob.
    $organised_by = fn(string $source, string $target, bool $language_unavailable = FALSE) => new Edge(
      id: "org:$source-$target",
      sourceEntityType: 'node',
      sourceId: $this->nodes[$source]->id(),
      targetEntityType: 'node',
      targetId: $this->nodes[$target]->id(),
      type: 'organised_by',
      typeLabel: 'Organised by',
      languageUnavailable: $language_unavailable,
    );
    $edges = [
      $organised_by('Ann', 'Org A'),
      $organised_by('Bob', 'Org A'),
      $organised_by('Cas', 'Org A'),
      $organised_by('Ann', 'Org B'),
      $organised_by('Bob', 'Org B'),
      $organised_by('Cas', 'Org C'),
      $organised_by('Dirk', 'Org C'),
      $this->edge('Ann', 'Bob', 'Knows'),
    ];
    $builder = $this->container->get('network_views.graph_builder');
    $shared = ['edge_type' => 'organised_by', 'mode' => 'target', 'label' => '', 'max' => 0];
    $persons = fn() => $this->graphNodes(['Ann', 'Bob', 'Cas', 'Dirk']);

    $cacheability = new CacheableMetadata();
    $graph = $builder->build($persons(), $edges, ['shared_links' => [$shared]], $cacheability);
    $this->assertSame([
      'Ann > Bob: Knows',
      'Ann > Cas: Shared: Organised by',
      'Bob > Ann: Shared: Organised by',
      'Bob > Cas: Shared: Organised by',
    ], $this->edgeList($graph));
    $links = array_column($graph['edges'], NULL, 'id');
    $ann_bob = $links['shared:organised_by:' . $this->nodes['Bob']->id() . ':' . $this->nodes['Ann']->id()];
    $this->assertSame(2, $ann_bob['weight']);
    $this->assertSame('Shared: Organised by: Org A, Org B', $ann_bob['tooltip']);
    $this->assertTrue($ann_bob['undirected']);
    $this->assertContains('node:' . $this->nodes['Org A']->id(), $cacheability->getCacheTags());
    // Org C may not be viewed, so Dirk has no links.
    $this->assertNotContains('Dirk', $this->linkedLabels($graph));

    // The label can be set, and items shared by too many nodes left out.
    $options = ['shared_links' => [['label' => 'Same organiser', 'max' => 2] + $shared]];
    $graph = $builder->build($persons(), $edges, $options, new CacheableMetadata());
    $this->assertSame(['Ann > Bob: Knows', 'Bob > Ann: Same organiser'], $this->edgeList($graph));
    $this->assertSame('Same organiser: Org B', $graph['edges'][1]['tooltip']);
    $this->assertSame(['Knows', 'Same organiser'], array_column($graph['legend']['edges'], 'label'));

    // With "source", the targets of edges with the same source are linked.
    $reversed = array_map(fn(Edge $edge) => new Edge($edge->id, $edge->targetEntityType, $edge->targetId, $edge->sourceEntityType, $edge->sourceId, $edge->type, 'Organises'), array_slice($edges, 0, 5));
    $graph = $builder->build($persons(), $reversed, ['shared_links' => [['mode' => 'source'] + $shared]], new CacheableMetadata());
    $this->assertSame([
      'Ann > Cas: Shared: Organises',
      'Bob > Ann: Shared: Organises',
      'Bob > Cas: Shared: Organises',
    ], $this->edgeList($graph));

    // A shared item in the graph keeps its other edges, not those of the
    // shared links type.
    $nodes = $this->graphNodes(['Ann', 'Bob', 'Org B']);
    $graph = $builder->build($nodes, [...$edges, $this->edge('Org B', 'Ann', 'Knows')], ['shared_links' => [$shared]], new CacheableMetadata());
    $this->assertSame(['Ann > Bob: Knows', 'Bob > Ann: Shared: Organised by', 'Org B > Ann: Knows'], $this->edgeList($graph));

    // Edges unavailable in the current language fade or hide the links that
    // depend on them alone.
    $edges[3] = $organised_by('Ann', 'Org B', TRUE);
    $edges[0] = $organised_by('Ann', 'Org A', TRUE);
    $graph = $builder->build($persons(), $edges, ['shared_links' => [$shared], 'language' => 'fade'], new CacheableMetadata());
    $this->assertSame([
      'Ann > Bob: Knows',
      'Ann > Cas: Shared: Organised by (faded)',
      'Bob > Ann: Shared: Organised by (faded)',
      'Bob > Cas: Shared: Organised by',
    ], $this->edgeList($graph));
    $graph = $builder->build($persons(), $edges, ['shared_links' => [$shared], 'language' => 'hide'], new CacheableMetadata());
    $this->assertSame(['Ann > Bob: Knows', 'Bob > Cas: Shared: Organised by'], $this->edgeList($graph));
  }

  /**
   * Shared items unavailable in the current language are faded or hidden.
   */
  public function testSharedLinksLanguage(): void {
    NodeType::create(['type' => 'organisation', 'name' => 'Organisation'])->save();
    $org = Node::create(['type' => 'organisation', 'title' => 'Org A']);
    $org->save();
    foreach (['Ann', 'Bob'] as $title) {
      $this->nodes[$title]->addTranslation('nl', ['title' => "$title NL"])->save();
    }
    $this->config('system.site')->set('default_langcode', 'nl')->save();
    $this->container->get('language.default')->set(ConfigurableLanguage::load('nl'));
    $this->container->get('language_manager')->reset();

    $edges = [];
    foreach (['Ann', 'Bob'] as $source) {
      $edges[] = new Edge("org:$source", 'node', $this->nodes[$source]->id(), 'node', $org->id(), 'organised_by', 'Organised by');
    }
    $options = ['shared_links' => [['edge_type' => 'organised_by', 'mode' => 'target', 'label' => '', 'max' => 0]]];
    $builder = $this->container->get('network_views.graph_builder');

    // Org A has no Dutch translation.
    $graph = $builder->build($this->graphNodes(['Ann', 'Bob']), $edges, $options + ['language' => 'fade'], new CacheableMetadata());
    $this->assertSame(['Bob > Ann: Shared: Organised by (faded)'], $this->edgeList($graph));
    $graph = $builder->build($this->graphNodes(['Ann', 'Bob']), $edges, $options + ['language' => 'hide'], new CacheableMetadata());
    $this->assertSame([], $graph['edges']);
    $graph = $builder->build($this->graphNodes(['Ann', 'Bob']), $edges, $options, new CacheableMetadata());
    $this->assertSame(['Bob > Ann: Shared: Organised by'], $this->edgeList($graph));

    // With a translation, the link is available and names it in Dutch.
    $org->addTranslation('nl', ['title' => 'Org A NL'])->save();
    $graph = $builder->build($this->graphNodes(['Ann', 'Bob']), $edges, $options + ['language' => 'hide'], new CacheableMetadata());
    $this->assertSame(['Bob > Ann: Shared: Organised by'], $this->edgeList($graph));
    $this->assertSame('Shared: Organised by: Org A NL', $graph['edges'][0]['tooltip']);
  }

  /**
   * Returns the labels of the nodes with edges.
   *
   * @return string[]
   *   The labels.
   */
  protected function linkedLabels(array $graph): array {
    $labels = array_column($graph['nodes'], 'label', 'id');
    $linked = [];
    foreach ($graph['edges'] as $edge) {
      $linked[] = $labels[$edge['source']];
      $linked[] = $labels[$edge['target']];
    }
    return array_values(array_unique($linked));
  }

}
