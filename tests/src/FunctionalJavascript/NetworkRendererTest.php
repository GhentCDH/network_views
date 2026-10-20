<?php

declare(strict_types=1);

namespace Drupal\Tests\network_views\FunctionalJavascript;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\network_views\Traits\CytoscapeTrait;
use Drupal\Tests\network_views\Traits\NetworkViewTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Cytoscape.js renderer of network views in a browser.
 *
 * Cytoscape.js is loaded from cdnjs.cloudflare.com, so the browser needs
 * internet access.
 *
 * @group network_views
 */
#[Group('network_views')]
#[RunTestsInSeparateProcesses]
class NetworkRendererTest extends WebDriverTestBase {

  use CytoscapeTrait;
  use NetworkViewTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'views', 'network_views'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

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
    $this->drupalCreateContentType(['type' => 'person', 'name' => 'Person']);
    $this->drupalCreateContentType(['type' => 'organisation', 'name' => 'Organisation']);
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

    // In the order of the circle layout: Ann knows Bob, Bob knows Cas, Ann
    // and Cas are members of Org A and Org B, Dirk has no connections. The
    // shared link between Ann and Cas is the diagonal of the circle.
    $this->addNode('Org A', 'organisation');
    $this->addNode('Org B', 'organisation');
    $this->addNode('Ann');
    $this->addNode('Bob');
    $this->addNode('Cas', 'person', ['field_member' => ['Org A', 'Org B']]);
    $this->addNode('Dirk');
    $this->nodes['Ann']->set('field_knows', [$this->nodes['Bob']->id()])
      ->set('field_member', [$this->nodes['Org A']->id(), $this->nodes['Org B']->id()])
      ->save();
    $this->nodes['Bob']->set('field_knows', [$this->nodes['Cas']->id()])->save();

    $view = $this->createNetworkView([
      'tooltip_fields' => ['title'],
      'edge_sources' => [
        'entity_reference_fields' => ['enabled' => TRUE, 'settings' => ['fields' => ['field_knows', 'field_member']]],
      ],
      'shared_links' => [
        ['edge_type' => 'field:field_member', 'mode' => 'target', 'label' => 'Same organisation', 'max' => 0],
      ],
      'layout' => 'circle',
      'height' => '400px',
    ], ['person']);
    $display = &$view->getDisplay('default');
    $display['display_options']['empty'] = [
      'area_text_custom' => [
        'id' => 'area_text_custom',
        'table' => 'views',
        'field' => 'area_text_custom',
        'plugin_id' => 'text_custom',
        'empty' => TRUE,
        'content' => 'No network to show.',
      ],
    ];
    $view->getExecutable()->newDisplay('page', 'Page', 'page_1');
    $page = &$view->getDisplay('page_1');
    $page['display_options']['path'] = 'network';
    $view->save();
    $this->container->get('router.builder')->rebuild();

    $this->drupalLogin($this->drupalCreateUser(['access content']));
  }

  /**
   * Creates a node.
   *
   * @param string $title
   *   The title.
   * @param string $bundle
   *   The content type.
   * @param array $references
   *   Titles of the referenced nodes, keyed by field name.
   */
  protected function addNode(string $title, string $bundle = 'person', array $references = []): NodeInterface {
    $values = ['type' => $bundle, 'title' => $title];
    foreach ($references as $field_name => $titles) {
      $values[$field_name] = array_map(fn($referenced) => $this->nodes[$referenced]->id(), $titles);
    }
    $node = Node::create($values);
    $node->save();
    return $this->nodes[$title] = $node;
  }

  /**
   * Renders the graph, highlights on hover, shows tooltips and links.
   */
  public function testRenderer(): void {
    $this->drupalGet('network');
    $this->waitForGraph();
    $assert = $this->assertSession();

    // The graph has the nodes and edges of the data attribute.
    $data = json_decode($assert->elementExists('css', '[data-network-views]')->getAttribute('data-network-views'), TRUE);
    $this->assertCount(4, $data['nodes']);
    $this->assertCount(3, $data['edges']);
    $this->assertSame(4, $this->evaluateGraph('cy.nodes().length'));
    $this->assertSame(3, $this->evaluateGraph('cy.edges().length'));
    $this->assertSame(['Ann', 'Bob', 'Cas', 'Dirk'], $this->evaluateGraph('cy.nodes().map((node) => node.data("label"))'));
    $assert->elementTextContains('css', '.network-views__legend', 'Person (4)');
    $assert->elementTextContains('css', '.network-views__legend', 'Knows (2)');
    $assert->elementTextContains('css', '.network-views__legend', 'Same organisation (1)');

    // References are arrows; the shared link has none and is thicker, as Ann
    // and Cas share two organisations.
    $ann = $this->key('Ann');
    $bob = $this->key('Bob');
    $cas = $this->key('Cas');
    $dirk = $this->key('Dirk');
    $shared = 'shared:field:field_member:' . $ann . ':' . $cas;
    $knows = 'field_knows:' . $this->nodes['Ann']->id() . ':' . $this->nodes['Bob']->id();
    $this->assertSame('triangle', $this->evaluateGraph("cy.getElementById('$knows').style('target-arrow-shape')"));
    $this->assertSame('none', $this->evaluateGraph("cy.getElementById('$shared').style('target-arrow-shape')"));
    $this->assertEquals(1.5, $this->evaluateGraph("cy.getElementById('$knows').numericStyle('width')"));
    $this->assertEquals(2.5, $this->evaluateGraph("cy.getElementById('$shared').numericStyle('width')"));

    // Hovering a node shows its tooltip and highlights its neighbourhood.
    $tooltip = $assert->elementExists('css', '.network-views__tooltip');
    $this->assertFalse($tooltip->isVisible());
    $this->mouseOnGraph('mousemove', $bob);
    $this->assertTrue($tooltip->isVisible());
    $this->assertSame('Title: Bob', $tooltip->getText());
    $this->assertSame([$ann, $bob, $cas], $this->evaluateGraph("cy.nodes('.highlighted').map((node) => node.id())"));
    $this->assertSame([$dirk], $this->evaluateGraph("cy.nodes('.dimmed').map((node) => node.id())"));
    $this->assertSame([$shared], $this->evaluateGraph("cy.edges('.dimmed').map((edge) => edge.id())"));
    $this->assertSame('pointer', $this->evaluateGraph('container.style.cursor'));

    // Leaving the node restores the graph.
    $this->mouseOnGraph('mousemove');
    $this->assertFalse($tooltip->isVisible());
    $this->assertSame(0, $this->evaluateGraph("cy.elements('.dimmed, .highlighted').length"));
    $this->assertSame('', $this->evaluateGraph('container.style.cursor'));

    // Hovering the shared link names the shared items.
    $this->mouseOnGraph('mousemove', $shared);
    $this->assertTrue($tooltip->isVisible());
    $this->assertSame('Same organisation: Org A, Org B', $tooltip->getText());
    $this->assertTrue($this->evaluateGraph("cy.getElementById('$shared').hasClass('highlighted')"));
    $this->mouseOnGraph('mousemove');
    $this->assertFalse($tooltip->isVisible());

    $this->assertNoJavascriptErrors();

    // Clicking a node opens the entity.
    $this->mouseOnGraph('mousedown', $ann);
    $this->mouseOnGraph('mouseup', $ann);
    $path = $this->nodes['Ann']->toUrl()->toString();
    $this->assertTrue($this->getSession()->wait(10000, 'window.location.pathname === ' . json_encode($path)));
    $assert->addressEquals($path);
    $assert->pageTextContains('Ann');

    // Without result, there is no graph but the empty text.
    foreach (['Ann', 'Bob', 'Cas', 'Dirk'] as $title) {
      $this->nodes[$title]->setUnpublished()->save();
    }
    $this->drupalGet('network');
    $assert->pageTextContains('No network to show.');
    $assert->elementNotExists('css', '[data-network-views]');
    $assert->elementNotExists('css', '.network-views__legend');
    $this->assertNoJavascriptErrors();
  }

  /**
   * Returns the graph ID of a test node.
   */
  protected function key(string $title): string {
    return 'node:' . $this->nodes[$title]->id();
  }

}
