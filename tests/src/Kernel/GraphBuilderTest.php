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
use Drupal\network_views\Palette;
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
    $ann_bob = $links['shared:organised_by:node:' . $this->nodes['Bob']->id() . ':node:' . $this->nodes['Ann']->id()];
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

  /**
   * Node colors are configured and assigned by key, not by label.
   */
  public function testNodeColorKeys(): void {
    $builder = $this->container->get('network_views.graph_builder');
    $node = fn(string $title, string $category, ?string $key) => new GraphNode($this->nodes[$title], $title, $category, colorKey: $key);
    $nodes = [
      $node('Ann', 'History', 'taxonomy_term:1'),
      $node('Bob', 'History', 'taxonomy_term:1'),
      $node('Cas', 'Philosophy', 'taxonomy_term:2'),
      $node('Dirk', 'Linguistics', 'taxonomy_term:3'),
    ];
    $colors = fn(array $graph) => array_column($graph['nodes'], 'color', 'label');

    // A color by key; the others from the palette.
    $graph = $builder->build($nodes, [], ['node_colors' => ['taxonomy_term:2' => '#000000']], new CacheableMetadata());
    $this->assertSame('#000000', $colors($graph)['Cas']);
    $this->assertSame($colors($graph)['Ann'], $colors($graph)['Bob']);
    $this->assertSame([
      ['label' => 'History', 'color' => $colors($graph)['Ann'], 'count' => 2],
      ['label' => 'Linguistics', 'color' => $colors($graph)['Dirk'], 'count' => 1],
      ['label' => 'Philosophy', 'color' => '#000000', 'count' => 1],
    ], $graph['legend']['nodes']);

    // The same keys with labels in another language get the same colors.
    $dutch = [
      $node('Ann', 'Geschiedenis', 'taxonomy_term:1'),
      $node('Bob', 'Geschiedenis', 'taxonomy_term:1'),
      $node('Cas', 'Filosofie', 'taxonomy_term:2'),
      $node('Dirk', 'Taalkunde', 'taxonomy_term:3'),
    ];
    $dutch_graph = $builder->build($dutch, [], ['node_colors' => ['taxonomy_term:2' => '#000000']], new CacheableMetadata());
    $this->assertSame(array_values($colors($graph)), array_values($colors($dutch_graph)));

    // Older settings by label still apply to categories without a color by
    // key, and categories without a key use their label.
    $this->createPerson('Eva');
    $graph = $builder->build([...$nodes, $node('Eva', 'Other', NULL)], [], [
      'node_colors' => ['taxonomy_term:2' => '#000000'],
      'colors' => "History|#111111\nPhilosophy|#222222\nOther|#333333",
    ], new CacheableMetadata());
    $this->assertSame(['Ann' => '#111111', 'Bob' => '#111111', 'Cas' => '#000000', 'Eva' => '#333333'], array_diff_key($colors($graph), ['Dirk' => TRUE]));
  }

  /**
   * Edges are colored by type, group, shared item or not at all.
   */
  public function testEdgeColorModes(): void {
    $builder = $this->container->get('network_views.graph_builder');
    $edge = function (string $source, string $target, string $type, string $label, ?string $group = NULL, ?string $group_label = NULL): Edge {
      $source_id = $this->nodes[$source]->id();
      $target_id = $this->nodes[$target]->id();
      return new Edge("$type:$source-$target", 'node', $source_id, 'node', $target_id, $type, $label, group: $group, groupLabel: $group_label);
    };
    $edges = [
      $edge('Ann', 'Bob', 'relation_type:1', 'Teacher of', 'relation_bundle:rel', 'Person relation'),
      $edge('Bob', 'Cas', 'relation_type:2', 'Friend of', 'relation_bundle:rel', 'Person relation'),
      $edge('Cas', 'Dirk', 'field:field_knows', 'Knows'),
    ];
    $nodes = fn() => $this->graphNodes(['Ann', 'Bob', 'Cas', 'Dirk']);
    $legend = fn(array $graph) => array_column($graph['legend']['edges'] ?? [], 'label');
    $colors = fn(array $graph) => array_column($graph['edges'], 'color', 'label');

    $by_type = $builder->build($nodes(), $edges, ['edge_color_map' => ['relation_type:1' => '#123456']], new CacheableMetadata());
    $this->assertSame(['Friend of', 'Knows', 'Teacher of'], $legend($by_type));
    $this->assertSame('#123456', $colors($by_type)['Teacher of']);
    $this->assertCount(3, array_unique($colors($by_type)));

    $options = ['edge_color_by' => 'group', 'edge_color_map' => ['relation_bundle:rel' => '#654321']];
    $by_group = $builder->build($nodes(), $edges, $options, new CacheableMetadata());
    $this->assertSame(['Knows', 'Person relation'], $legend($by_group));
    $this->assertSame('#654321', $colors($by_group)['Teacher of']);
    $this->assertSame('#654321', $colors($by_group)['Friend of']);

    $none = $builder->build($nodes(), $edges, ['edge_color_by' => 'none'], new CacheableMetadata());
    $this->assertSame([], $legend($none));
    $this->assertSame([Palette::NEUTRAL], array_values(array_unique($colors($none))));
  }

  /**
   * Shared links can be colored by the item they share.
   */
  public function testSharedLinkColors(): void {
    NodeType::create(['type' => 'organisation', 'name' => 'Organisation'])->save();
    foreach (['Org A', 'Org B'] as $title) {
      $this->nodes[$title] = Node::create(['type' => 'organisation', 'title' => $title]);
      $this->nodes[$title]->save();
    }
    $edges = [];
    // Ann and Bob in both, Cas in Org B, Dirk in Org A.
    $memberships = [
      ['Ann', 'Org A'], ['Bob', 'Org A'], ['Ann', 'Org B'],
      ['Bob', 'Org B'], ['Cas', 'Org B'], ['Dirk', 'Org A'],
    ];
    foreach ($memberships as [$source, $target]) {
      $source_id = $this->nodes[$source]->id();
      $target_id = $this->nodes[$target]->id();
      $edges[] = new Edge("org:$source-$target", 'node', $source_id, 'node', $target_id, 'organised_by', 'Organised by');
    }
    $options = [
      'shared_links' => [['edge_type' => 'organised_by', 'mode' => 'target', 'label' => '', 'max' => 0]],
      'edge_color_by' => 'shared_item',
      'edge_color_map' => ['node:' . $this->nodes['Org A']->id() => '#aa0000'],
    ];
    $graph = $this->container->get('network_views.graph_builder')->build($this->graphNodes(['Ann', 'Bob', 'Cas', 'Dirk']), $edges, $options, new CacheableMetadata());
    $labels = array_column($graph['nodes'], 'label', 'id');
    $colors = [];
    foreach ($graph['edges'] as $link) {
      $pair = [$labels[$link['source']], $labels[$link['target']]];
      sort($pair);
      $colors[implode('-', $pair)] = $link['color'];
    }
    // Via Org A only, via Org B only, and via both (grey).
    $this->assertSame('#aa0000', $colors['Ann-Dirk']);
    $this->assertSame($colors['Ann-Cas'], $colors['Bob-Cas']);
    $this->assertNotContains($colors['Ann-Cas'], ['#aa0000', Palette::NEUTRAL]);
    $this->assertSame(Palette::NEUTRAL, $colors['Ann-Bob']);
    $this->assertSame(['Org A', 'Org B', 'Several shared items'], array_column($graph['legend']['edges'], 'label'));
  }

  /**
   * Targets of "show as node" edges become nodes, colored by bundle.
   */
  public function testExtraNodes(): void {
    NodeType::create(['type' => 'organisation', 'name' => 'Organisation'])->save();
    $orgs = [];
    foreach (['Org A' => 1, 'Org B' => 1, 'Org C' => 0] as $title => $status) {
      $values = ['type' => 'organisation', 'title' => $title, 'status' => $status];
      $orgs[$title] = $this->nodes[$title] = Node::create($values);
      $orgs[$title]->save();
    }
    $member = function (string $source, string $target, bool $as_node = TRUE): Edge {
      $source_id = $this->nodes[$source]->id();
      $target_id = $this->nodes[$target]->id();
      return new Edge("m:$source-$target", 'node', $source_id, 'node', $target_id, 'field:field_member', 'Member of', otherEndAsNode: $as_node);
    };
    $edges = [
      $member('Ann', 'Org A'),
      $member('Bob', 'Org B'),
      $member('Cas', 'Org C'),
      $member('Dirk', 'Org A', FALSE),
    ];
    // The missing end can also be the source, e.g. of a relation.
    $edges[] = new Edge('employs', 'node', $orgs['Org B']->id(), 'node', $this->nodes['Dirk']->id(), 'employs', 'Employs', otherEndAsNode: TRUE);
    $builder = $this->container->get('network_views.graph_builder');

    $graph = $builder->build($this->graphNodes(['Ann', 'Bob', 'Cas', 'Dirk']), $edges, [], new CacheableMetadata());
    // Org A and Org B, not the unpublished Org C; Dirk's edge to Org A is
    // shown, because Org A is a node now.
    $this->assertSame(['Ann', 'Bob', 'Cas', 'Dirk', 'Org A', 'Org B'], array_column($graph['nodes'], 'label'));
    $expected = ['Ann > Org A: Member of', 'Bob > Org B: Member of', 'Dirk > Org A: Member of', 'Org B > Dirk: Employs'];
    $this->assertSame($expected, $this->edgeList($graph));
    $this->assertSame(['', 'Organisation'], array_column($graph['legend']['nodes'], 'label'));

    // In an ego network, only the targets of the root's edges.
    $graph = $builder->build($this->graphNodes(['Ann', 'Bob'], 'Ann'), $edges, ['extra_nodes' => 'root'], new CacheableMetadata());
    $this->assertSame(['Ann', 'Bob', 'Org A'], array_column($graph['nodes'], 'label'));
  }

}
