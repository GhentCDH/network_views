<?php

declare(strict_types=1);

namespace Drupal\network_views\Plugin\NetworkEdgeSource;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\network_views\Attribute\NetworkEdgeSource;
use Drupal\network_views\EdgeSource\Edge;
use Drupal\network_views\EdgeSource\EdgeSourcePluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Edges from the entity reference fields of the result entities.
 *
 * Each reference from a result entity to another result entity is an edge,
 * directed from the referencing to the referenced entity.
 */
#[NetworkEdgeSource(
  id: 'entity_reference_fields',
  label: new TranslatableMarkup('Entity reference fields'),
  description: new TranslatableMarkup('A reference from one result row to another is an edge.'),
)]
class EntityReferenceFields extends EdgeSourcePluginBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_field.manager'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['fields' => []];
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(string $entity_type_id): bool {
    return !empty($this->getReferenceFields($entity_type_id));
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, string $entity_type_id): array {
    $form['fields'] = [
      '#type' => 'checkboxes',
      '#title' => new TranslatableMarkup('Fields'),
      '#description' => new TranslatableMarkup('References in these fields are edges. The field label is the edge label.'),
      '#options' => $this->getReferenceFields($entity_type_id),
      '#default_value' => $this->configuration['fields'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array $values): array {
    return ['fields' => array_values(array_filter($values['fields'] ?? []))];
  }

  /**
   * {@inheritdoc}
   */
  public function getEdges(array $entities): array {
    $edges = [];
    foreach ($entities as $entity) {
      if (!$entity instanceof FieldableEntityInterface) {
        continue;
      }
      foreach ($this->configuration['fields'] as $field_name) {
        if (!$entity->hasField($field_name)) {
          continue;
        }
        $definition = $entity->getFieldDefinition($field_name);
        $target_type = $definition->getFieldStorageDefinition()->getSetting('target_type');
        $label = (string) $definition->getLabel();
        foreach (array_filter(array_column($entity->get($field_name)->getValue(), 'target_id')) as $target_id) {
          $edges[] = new Edge(
            id: implode(':', [$field_name, $entity->id(), $target_id]),
            sourceEntityType: $entity->getEntityTypeId(),
            sourceId: $entity->id(),
            targetEntityType: $target_type,
            targetId: $target_id,
            type: 'field:' . $field_name,
            typeLabel: $label,
          );
        }
      }
    }
    return $edges;
  }

  /**
   * {@inheritdoc}
   *
   * The references of the entity, and the references to it from other
   * entities that the current user may view.
   */
  public function getEdgesOf(EntityInterface $entity): array {
    return $this->getAllEdgesOf([$entity->id() => $entity]);
  }

  /**
   * {@inheritdoc}
   *
   * The references of the entities, and the references to them from other
   * entities that the current user may view.
   */
  public function getAllEdgesOf(array $entities): array {
    $first = reset($entities);
    if (!$first) {
      return [];
    }
    $entity_type_id = $first->getEntityTypeId();
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $storages = $this->entityFieldManager->getFieldStorageDefinitions($entity_type_id);
    $ids = array_map('strval', array_keys($entities));
    $referencing_ids = [];
    foreach ($this->configuration['fields'] as $field_name) {
      if (!isset($storages[$field_name])) {
        continue;
      }
      foreach (array_chunk($ids, 500) as $chunk) {
        $referencing_ids += $storage->getQuery()
          ->accessCheck(TRUE)
          ->condition($field_name, $chunk, 'IN')
          ->execute();
      }
    }
    // The references of the given entities are found from the entities.
    $referencing_ids = array_diff($referencing_ids, $ids);

    $edges = $this->getEdges($entities);
    $given = array_flip($ids);
    foreach ($this->getEdges($storage->loadMultiple($referencing_ids)) as $edge) {
      if ($edge->targetEntityType === $entity_type_id && isset($given[(string) $edge->targetId])) {
        $edges[] = $edge;
      }
    }
    return $edges;
  }

  /**
   * {@inheritdoc}
   */
  public function getEdgeTypes(string $entity_type_id): array {
    $types = [];
    foreach ($this->getReferenceFields($entity_type_id) as $field_name => $label) {
      $types['field:' . $field_name] = $label;
    }
    return $types;
  }

  /**
   * Returns the entity reference fields of an entity type that can be edges.
   *
   * Only fields that reference the entity type itself are offered: references
   * to other entity types never end at a result row.
   *
   * @return string[]
   *   Field labels keyed by field name.
   */
  protected function getReferenceFields(string $entity_type_id): array {
    $options = [];
    $storages = $this->entityFieldManager->getFieldStorageDefinitions($entity_type_id);
    foreach ($this->entityFieldManager->getFieldMap()[$entity_type_id] ?? [] as $field_name => $info) {
      $storage = $storages[$field_name] ?? NULL;
      if (!$storage
        || !in_array($info['type'], ['entity_reference', 'entity_reference_revisions'], TRUE)
        || $storage->getSetting('target_type') !== $entity_type_id
        || $storage->isBaseField()) {
        continue;
      }
      $options[$field_name] = $this->fieldLabel($entity_type_id, $field_name, $info['bundles']) . " ($field_name)";
    }
    asort($options);
    return $options;
  }

  /**
   * Returns the label of a field in the first bundle that has it.
   */
  protected function fieldLabel(string $entity_type_id, string $field_name, array $bundles): string {
    foreach ($bundles as $bundle) {
      $definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);
      if (isset($definitions[$field_name])) {
        return (string) $definitions[$field_name]->getLabel();
      }
    }
    return $field_name;
  }

}
