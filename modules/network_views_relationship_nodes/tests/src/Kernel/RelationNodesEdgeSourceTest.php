<?php

declare(strict_types=1);

namespace Drupal\Tests\network_views_relationship_nodes\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\network_views\Traits\NetworkViewTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests relation nodes as edges of a network view.
 *
 * Sets up a person content type with a typed person–person relation, as the
 * relationship_nodes UI does.
 *
 * @group network_views
 */
#[Group('network_views')]
#[RunTestsInSeparateProcesses]
class RelationNodesEdgeSourceTest extends KernelTestBase {

  use NetworkViewTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'field_ui',
    'filter',
    'text',
    'node',
    'taxonomy',
    'entity_events',
    'inline_entity_form',
    'relationship_nodes',
    'views',
    'network_views',
    'network_views_relationship_nodes',
  ];

  /**
   * The relation content type.
   */
  protected const RELATION_BUNDLE = 'rel_person_person';

  /**
   * The relation type vocabulary.
   */
  protected const RELATION_VOCAB = 'reltype_person_person';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'taxonomy', 'relationship_nodes', 'views']);
    Role::load(RoleInterface::ANONYMOUS_ID)->grantPermission('access content')->save();
    // User 1 is created first so the users below do not bypass access.
    $this->createUser();

    NodeType::create(['type' => 'person', 'name' => 'Person'])->save();
    $field_manager = $this->container->get('relationship_nodes.relationship_field_manager');

    $vocab = Vocabulary::create(['vid' => static::RELATION_VOCAB, 'name' => 'Relation types']);
    $vocab->setThirdPartySetting('relationship_nodes', 'enabled', TRUE);
    $vocab->setThirdPartySetting('relationship_nodes', 'referencing_type', 'entity_reference');
    $vocab->save();
    $field_manager->implementFieldUpdates($vocab);

    $type = NodeType::create(['type' => static::RELATION_BUNDLE, 'name' => 'Person relation']);
    $type->setThirdPartySetting('relationship_nodes', 'enabled', TRUE);
    $type->setThirdPartySetting('relationship_nodes', 'typed_relation', TRUE);
    $type->setThirdPartySetting('relationship_nodes', 'auto_title', FALSE);
    $type->save();
    if (!FieldConfig::loadByName('node', static::RELATION_BUNDLE, 'rn_related_entity_1')) {
      // Older versions create the relation fields only through the UI.
      $field_manager->implementFieldUpdates($type);
    }

    // Point the relation fields at their targets, as the field UI does.
    $targets = [
      'rn_related_entity_1' => 'person',
      'rn_related_entity_2' => 'person',
      'rn_relation_type' => static::RELATION_VOCAB,
    ];
    foreach ($targets as $field_name => $target) {
      /** @var \Drupal\field\Entity\FieldConfig|null $field */
      $field = FieldConfig::loadByName('node', static::RELATION_BUNDLE, $field_name);
      $this->assertNotNull($field, "Relation field $field_name was created.");
      $field->setSetting('handler_settings', ['target_bundles' => [$target => $target]])->save();
    }
  }

  /**
   * Creates a person.
   */
  protected function createPerson(string $title, bool $published = TRUE): NodeInterface {
    $node = Node::create(['type' => 'person', 'title' => $title, 'status' => $published]);
    $node->save();
    return $node;
  }

  /**
   * Creates a relation between two persons.
   */
  protected function createRelation(NodeInterface $a, NodeInterface $b, ?TermInterface $type = NULL, bool $published = TRUE): NodeInterface {
    $relation = Node::create([
      'type' => static::RELATION_BUNDLE,
      'title' => $a->label() . ' – ' . $b->label(),
      'status' => $published,
      'rn_related_entity_1' => $a->id(),
      'rn_related_entity_2' => $b->id(),
      'rn_relation_type' => $type?->id(),
    ]);
    $relation->save();
    return $relation;
  }

  /**
   * Creates a relation type term.
   */
  protected function createRelationType(string $name): TermInterface {
    $term = Term::create(['vid' => static::RELATION_VOCAB, 'name' => $name]);
    $term->save();
    return $term;
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
    $graph = json_decode($build['#canvas_attributes']->toArray()['data-network-views'], TRUE);

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
