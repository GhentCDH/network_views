<?php

declare(strict_types=1);

namespace Drupal\Tests\network_views\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\network_views\Traits\NetworkViewTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests ego networks as views: the "Network neighbourhood" contextual filter.
 *
 * @group network_views
 */
#[Group('network_views')]
#[RunTestsInSeparateProcesses]
class NetworkNeighbourhoodTest extends KernelTestBase {

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
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'field', 'filter', 'node', 'views']);
    // User 1 is created first so the current user does not bypass access.
    $this->createUser();
    $this->setUpCurrentUser([], ['access content']);

    NodeType::create(['type' => 'person', 'name' => 'Person'])->save();
    NodeType::create(['type' => 'organisation', 'name' => 'Organisation'])->save();
    foreach (['field_knows' => 'Knows', 'field_member' => 'Member of'] as $field_name => $label) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'cardinality' => -1,
        'settings' => ['target_type' => 'node'],
      ])->save();
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => 'person',
        'label' => $label,
      ])->save();
    }

    // Ann knows Bob, Bob knows Cas, Cas knows Dirk, Eva knows Ann, Hidden
    // (unpublished) knows Ann; Ann and Gina are members of Acme.
    $this->createNode('Acme', 'organisation');
    $this->createNode('Dirk');
    $this->createNode('Cas', ['field_knows' => ['Dirk']]);
    $this->createNode('Bob', ['field_knows' => ['Cas']]);
    $this->createNode('Ann', ['field_knows' => ['Bob'], 'field_member' => ['Acme']]);
    $this->createNode('Eva', ['field_knows' => ['Ann']]);
    $this->createNode('Hidden', ['field_knows' => ['Ann']], 0);
    $this->createNode('Gina', ['field_member' => ['Acme']]);
  }

  /**
   * Creates a node with references to other test nodes.
   */
  protected function createNode(string $title, array|string $references = [], int $status = 1): NodeInterface {
    $bundle = is_string($references) ? $references : 'person';
    $values = ['type' => $bundle, 'title' => $title, 'status' => $status];
    foreach (is_array($references) ? $references : [] as $field_name => $titles) {
      $values[$field_name] = array_map(fn($known) => $this->nodes[$known]->id(), $titles);
    }
    $node = Node::create($values);
    $node->save();
    return $this->nodes[$title] = $node;
  }

  /**
   * Creates the ego network view: persons, with the neighbourhood filter.
   *
   * @param array $style_options
   *   Options of the network style; the edges are field_knows by default.
   * @param int $depth
   *   The depth of the neighbourhood.
   */
  protected function createEgoView(array $style_options = [], int $depth = 1): void {
    $view = $this->createNetworkView($style_options + [
      'edge_sources' => [
        'entity_reference_fields' => $this->referenceEdges(['field_knows']),
      ],
      'hide_isolated' => TRUE,
    ], ['person']);
    $display = &$view->getDisplay('default');
    // No "Published" filter: the neighbourhood is access checked, so users
    // who may view unpublished content see it too.
    unset($display['display_options']['filters']['status']);
    $display['display_options']['arguments']['network_neighbourhood'] = [
      'id' => 'network_neighbourhood',
      'table' => 'node_field_data',
      'field' => 'network_neighbourhood',
      'plugin_id' => 'network_neighbourhood',
      'entity_type' => 'node',
      'depth' => $depth,
      // Without a value, no ego network (Views shows all rows by default).
      'default_action' => 'empty',
    ];
    $view->save();
  }

  /**
   * Returns the configuration of the reference fields edge source.
   */
  protected function referenceEdges(array $fields): array {
    return ['enabled' => TRUE, 'settings' => ['fields' => $fields, 'node_fields' => []]];
  }

  /**
   * Executes the ego view of a node and returns the graph.
   */
  protected function egoGraph(?string $title): ?array {
    $view = Views::getView('test_network');
    $view->setDisplay('default');
    $view->setArguments($title === NULL ? [] : [$this->nodes[$title]->id()]);
    $view->execute();
    $build = $view->style_plugin->render();
    $view->destroy();
    return isset($build['#canvas_attributes']) ? json_decode($build['#canvas_attributes']->toArray()['data-network-views'], TRUE) : NULL;
  }

  /**
   * The neighbours of a node, with the node as root.
   */
  public function testNeighbourhood(): void {
    $this->createEgoView();
    $graph = $this->egoGraph('Ann');
    // Bob (referenced) and Eva (referencing); not Cas (two steps away) or the
    // unpublished Hidden.
    $labels = array_column($graph['nodes'], 'label');
    sort($labels);
    $this->assertSame(['Ann', 'Bob', 'Eva'], $labels);
    $roots = array_map('boolval', array_column($graph['nodes'], 'root', 'label'));
    ksort($roots);
    $this->assertSame(['Ann' => TRUE, 'Bob' => FALSE, 'Eva' => FALSE], $roots);
    $this->assertSame(['Ann > Bob: Knows', 'Eva > Ann: Knows'], $this->edgeList($graph));

    // Another node, another neighbourhood.
    $labels = array_column($this->egoGraph('Cas')['nodes'], 'label');
    sort($labels);
    $this->assertSame(['Bob', 'Cas', 'Dirk'], $labels);
  }

  /**
   * With 2 steps, the neighbours of the neighbours are included.
   */
  public function testDepth(): void {
    $this->createEgoView([], 2);
    $labels = array_column($this->egoGraph('Ann')['nodes'], 'label');
    sort($labels);
    $this->assertSame(['Ann', 'Bob', 'Cas', 'Eva'], $labels);
  }

  /**
   * Nodes that share an item through shared links are neighbours.
   */
  public function testSharedLinks(): void {
    $this->createEgoView([
      'edge_sources' => [
        'entity_reference_fields' => $this->referenceEdges(['field_knows', 'field_member']),
      ],
      'shared_links' => [
        ['edge_type' => 'field:field_member', 'mode' => 'target', 'label' => 'Same organisation', 'max' => 0],
      ],
    ]);
    $graph = $this->egoGraph('Ann');
    $labels = array_column($graph['nodes'], 'label');
    sort($labels);
    $this->assertSame(['Ann', 'Bob', 'Eva', 'Gina'], $labels);
    $this->assertContains('Ann > Gina: Same organisation', $this->edgeList($graph));
  }

  /**
   * Without an entity to show, or one the user may not view, nothing.
   */
  public function testNoRoot(): void {
    $this->createEgoView();
    $this->assertSame([], $this->egoGraph(NULL)['nodes'] ?? []);
    $this->assertSame([], $this->egoGraph('Hidden')['nodes'] ?? []);
  }

  /**
   * The contextual filter exists for content entity types.
   */
  public function testViewsData(): void {
    $data = $this->container->get('views.views_data');
    $this->assertSame('network_neighbourhood', $data->get('node_field_data')['network_neighbourhood']['argument']['id']);
    $this->assertSame('tid', $data->get('taxonomy_term_field_data')['network_neighbourhood']['argument']['field']);
  }

  /**
   * The shape, size and color of the root can be configured.
   */
  public function testRootOptions(): void {
    $this->createEgoView(['root_shape' => 'diamond', 'root_size' => 2.5, 'root_color' => '#123456']);
    $graph = $this->egoGraph('Ann');
    $this->assertSame('diamond', $graph['settings']['rootShape']);
    $this->assertEquals(2.5, $graph['settings']['rootSize']);
    $colors = array_column($graph['nodes'], 'color', 'label');
    $this->assertSame('#123456', $colors['Ann']);
    $this->assertNotSame('#123456', $colors['Bob']);

    // The defaults: a star 1.6 times as large, in the color of its category.
    View::load('test_network')->delete();
    $this->createEgoView();
    $graph = $this->egoGraph('Ann');
    $this->assertSame('star', $graph['settings']['rootShape']);
    $this->assertEquals(1.6, $graph['settings']['rootSize']);
    $colors = array_column($graph['nodes'], 'color', 'label');
    $this->assertSame($colors['Bob'], $colors['Ann']);

    // The settings form offers them only with the contextual filter.
    $form = $this->styleOptionsForm();
    $this->assertArrayHasKey('root_shape', $form);
    View::load('test_network')->delete();
    $this->createNetworkView([], ['person']);
    $this->assertArrayNotHasKey('root_shape', $this->styleOptionsForm());
  }

  /**
   * Translations are published separately: one the user may view counts.
   *
   * Gil is unpublished in English, the original, and published in French.
   */
  public function testTranslations(): void {
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->container->get('language_manager')->reset();
    $gil = $this->createNode('Gil', [], 0);
    $gil->addTranslation('fr', ['title' => 'Gil FR', 'status' => 1])->save();
    $this->nodes['Ann']->set('field_knows', [$this->nodes['Bob']->id(), $gil->id()])->save();
    $this->createEgoView(['language' => 'fade']);

    // In English, Gil is a neighbour, faded, with the French title: not the
    // title of the unpublished English one.
    $graph = $this->egoGraph('Ann');
    $faded = array_map('boolval', array_column($graph['nodes'], 'faded', 'label'));
    ksort($faded);
    $this->assertSame(['Ann' => FALSE, 'Bob' => FALSE, 'Eva' => FALSE, 'Gil FR' => TRUE], $faded);
    // Gil as root.
    $labels = array_column($this->egoGraph('Gil')['nodes'], 'label');
    sort($labels);
    $this->assertSame(['Ann', 'Gil FR'], $labels);

    // In French, Gil is available.
    $this->config('system.site')->set('default_langcode', 'fr')->save();
    $this->container->get('language.default')->set(ConfigurableLanguage::load('fr'));
    $this->container->get('language_manager')->reset();
    $faded = array_map('boolval', array_column($this->egoGraph('Ann')['nodes'], 'faded', 'label'));
    $this->assertFalse($faded['Gil FR']);

    // Without a translation the user may view, Gil is not found.
    $gil = Node::load($gil->id());
    $gil->getTranslation('fr')->setUnpublished()->save();
    // Access results are kept for the rest of the request.
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $labels = array_column($this->egoGraph('Ann')['nodes'], 'label');
    sort($labels);
    $this->assertSame(['Ann', 'Bob', 'Eva'], $labels);
    $this->assertSame([], $this->egoGraph('Gil')['nodes'] ?? []);

    // Users who may view unpublished content see unpublished neighbours.
    $this->setUpCurrentUser([], ['access content', 'bypass node access']);
    $labels = array_column($this->egoGraph('Ann')['nodes'], 'label');
    sort($labels);
    $this->assertSame(['Ann', 'Bob', 'Eva', 'Gil FR', 'Hidden'], $labels);
  }

  /**
   * Builds the options form of the network style of the test view.
   */
  protected function styleOptionsForm(): array {
    $view = Views::getView('test_network');
    $view->setDisplay('default');
    $view->initStyle();
    $form = [];
    $view->style_plugin->buildOptionsForm($form, new FormState());
    return $form;
  }

}
