<?php

declare(strict_types=1);

namespace Drupal\Tests\views_network_relationship_nodes\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Tests\relationship_nodes\Kernel\RelationshipNodesKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\views_network\Traits\NetworkViewTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests relation nodes as edges of a network view.
 *
 * @group views_network
 */
#[Group('views_network')]
#[RunTestsInSeparateProcesses]
class RelationNodesEdgeSourceTest extends RelationshipNodesKernelTestBase {

  use NetworkViewTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'views',
    'views_network',
    'views_network_relationship_nodes',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['views']);
    Role::load(RoleInterface::ANONYMOUS_ID)->grantPermission('access content')->save();
    // User 1 is created first so it does not bypass access in other tests.
    $this->createUser();
  }

  /**
   * Relations between result rows are edges, labeled with their type.
   */
  public function testRelationEdges(): void {
    $ann = $this->createPerson('Ann');
    $bob = $this->createPerson('Bob');
    $cas = $this->createPerson('Cas');
    $hidden = $this->createPerson('Hidden', FALSE);
    $this->createRelation($ann, $bob, $this->createRelationType('Colleague'));
    $this->createRelation($bob, $cas);
    $this->createRelation($ann, $cas, NULL, FALSE);
    $this->createRelation($ann, $hidden);

    $this->createNetworkView([
      'edge_sources' => ['relation_nodes' => ['enabled' => TRUE, 'settings' => ['bundles' => []]]],
    ], ['person']);

    $this->container->get('account_switcher')->switchTo(new AnonymousUserSession());
    $build = $this->renderNetwork();
    $graph = json_decode($build['#canvas_attributes']->toArray()['data-views-network'], TRUE);

    // The relation nodes are edges, not graph nodes; the unpublished relation
    // and the unpublished person are left out.
    $this->assertSame(['Ann', 'Bob', 'Cas'], array_column($graph['nodes'], 'label'));
    $this->assertSame(['Ann > Bob: Colleague', 'Bob > Cas: Person relation'], $this->edgeList($graph));
    $this->assertContains('user.node_grants:view', $build['#cache']['contexts']);
    $this->assertContains('node_list', $build['#cache']['tags']);

    // An administrator sees the unpublished relation too.
    $this->container->get('account_switcher')->switchTo($this->createUser(['bypass node access']));
    $this->assertContains('Ann > Cas: Person relation', $this->edgeList($this->getGraph()));
  }

}
