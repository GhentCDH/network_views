<?php

declare(strict_types=1);

namespace Drupal\Tests\network_views\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\network_views\Traits\NetworkViewTrait;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the options form of the network style and the rendered page.
 *
 * @group network_views
 */
#[Group('network_views')]
#[RunTestsInSeparateProcesses]
class NetworkStyleUiTest extends BrowserTestBase {

  use NetworkViewTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'views', 'views_ui', 'network_views'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Configures the style in the Views UI and checks the page.
   */
  public function testOptionsForm(): void {
    $this->drupalCreateContentType(['type' => 'person', 'name' => 'Person']);
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
    $bob = Node::create(['type' => 'person', 'title' => 'Bob']);
    $bob->save();
    Node::create(['type' => 'person', 'title' => 'Ann', 'field_knows' => [$bob->id()]])->save();

    $view = $this->createNetworkView([], ['person']);
    $view->getExecutable()->newDisplay('page', 'Page', 'page_1');
    $display = &$view->getDisplay('page_1');
    $display['display_options']['path'] = 'network';
    $view->save();

    $this->drupalLogin($this->drupalCreateUser(['administer views', 'access content']));
    $this->drupalGet('admin/structure/views/nojs/display/test_network/default/style_options');
    $this->assertSession()->pageTextContains('Entity reference fields');
    $this->assertSession()->pageTextContains('Knows (field_knows)');

    // Invalid values are refused.
    $this->submitForm([
      'style_options[height]' => '600',
      'style_options[node_colors][other]' => 'node:1|url(x)',
    ], 'Apply');
    $this->assertSession()->pageTextContains('The height must be a number with a unit');
    $this->assertSession()->pageTextContains('Invalid lines: node:1|url(x)');

    $this->submitForm([
      'style_options[height]' => '400px',
      'style_options[node_colors][bundles][node:person][color]' => '#123456',
      'style_options[node_colors][other]' => '',
      'style_options[tooltip_fields][title]' => 'title',
      'style_options[edge_sources][entity_reference_fields][enabled]' => 1,
      'style_options[edge_sources][entity_reference_fields][settings][fields][field_knows]' => 'field_knows',
      'style_options[layout]' => 'circle',
    ], 'Apply');
    $this->submitForm([], 'Save');

    $options = View::load('test_network')->getDisplay('default')['display_options']['style']['options'];
    $this->assertSame(['title'], $options['tooltip_fields']);
    $this->assertSame([
      'entity_reference_fields' => ['enabled' => TRUE, 'settings' => ['fields' => ['field_knows'], 'node_fields' => []]],
    ], $options['edge_sources']);
    $this->assertSame(['node:person' => '#123456'], $options['node_colors']);

    $this->drupalGet('network');
    $canvas = $this->assertSession()->elementExists('css', '.network-views__canvas');
    $this->assertSame('height: 400px', $canvas->getAttribute('style'));
    $graph = json_decode($canvas->getAttribute('data-network-views'), TRUE);
    $this->assertSame(['Ann > Bob: Knows'], $this->edgeList($graph));
    $this->assertSame('circle', $graph['settings']['layout']);
    $this->assertSame(['#123456'], array_unique(array_column($graph['nodes'], 'color')));
    $this->assertSession()->elementTextContains('css', '.network-views__legend', 'Person (2)');

    // References can be shown as links between the persons that know the
    // same person.
    Node::create(['type' => 'person', 'title' => 'Cas', 'field_knows' => [$bob->id()]])->save();
    $this->drupalGet('admin/structure/views/nojs/display/test_network/default/style_options');
    $this->assertSession()->pageTextContains('Shared links');
    $this->submitForm([
      'style_options[shared_links][entity_reference_fields][field:field_knows][mode]' => 'target',
      'style_options[shared_links][entity_reference_fields][field:field_knows][shared_label]' => 'Know the same',
    ], 'Apply');
    $this->submitForm([], 'Save');
    $options = View::load('test_network')->getDisplay('default')['display_options']['style']['options'];
    $this->assertSame([
      ['edge_type' => 'field:field_knows', 'mode' => 'target', 'label' => 'Know the same', 'max' => 0],
    ], $options['shared_links']);

    $this->drupalGet('network');
    $graph = json_decode($this->assertSession()->elementExists('css', '.network-views__canvas')->getAttribute('data-network-views'), TRUE);
    $this->assertSame(['Ann > Cas: Know the same'], $this->edgeList($graph));
    $this->assertSame('Know the same: Bob', $graph['edges'][0]['tooltip']);

    // Colors are set per bundle and per edge type, by machine name.
    $this->drupalGet('admin/structure/views/nojs/display/test_network/default/style_options');
    $this->assertSession()->fieldExists('style_options[node_colors][bundles][node:person][color]');
    $this->submitForm([
      'style_options[node_colors][bundles][node:person][color]' => 'not a color',
    ], 'Apply');
    $this->assertSession()->pageTextContains('not a color is not a color');
    $this->submitForm([
      'style_options[node_colors][bundles][node:person][color]' => '#aa0000',
      'style_options[node_colors][other]' => '',
      'style_options[edge_color_by]' => 'type',
      'style_options[edge_color_map][types][shared:field:field_knows][color]' => '#00aa00',
    ], 'Apply');
    // Regression test: a saved "no limit" (0) of the shared links was refused
    // when the form was submitted again.
    $this->assertSession()->statusMessageNotExists('error');
    $this->submitForm([], 'Save');
    $options = View::load('test_network')->getDisplay('default')['display_options']['style']['options'];
    $this->assertSame(['node:person' => '#aa0000'], $options['node_colors']);
    $this->assertSame(['shared:field:field_knows' => '#00aa00'], $options['edge_color_map']);
    $this->drupalGet('network');
    $graph = json_decode($this->assertSession()->elementExists('css', '.network-views__canvas')->getAttribute('data-network-views'), TRUE);
    $this->assertSame(['#aa0000'], array_values(array_unique(array_column($graph['nodes'], 'color'))));
    $this->assertSame('#00aa00', $graph['edges'][0]['color']);
  }

}
