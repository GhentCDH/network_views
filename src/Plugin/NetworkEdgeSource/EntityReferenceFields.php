<?php

declare(strict_types=1);

namespace Drupal\views_network\Plugin\NetworkEdgeSource;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views_network\Attribute\NetworkEdgeSource;
use Drupal\views_network\EdgeSource\Edge;
use Drupal\views_network\EdgeSource\EdgeSourcePluginBase;
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
        foreach ($entity->get($field_name) as $item) {
          if ($item->target_id === NULL) {
            continue;
          }
          $edges[] = new Edge(
            id: implode(':', [$field_name, $entity->id(), $item->target_id]),
            sourceEntityType: $entity->getEntityTypeId(),
            sourceId: $entity->id(),
            targetEntityType: $target_type,
            targetId: $item->target_id,
            type: 'field:' . $field_name,
            typeLabel: $label,
          );
        }
      }
    }
    return $edges;
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
