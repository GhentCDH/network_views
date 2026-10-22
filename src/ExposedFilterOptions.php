<?php

declare(strict_types=1);

namespace Drupal\network_views;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\network_views\Plugin\views\style\Network;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;

/**
 * Hides exposed filter options that would give an empty network.
 *
 * With "Hide nodes without connections", an exposed filter on the field of a
 * shared links type offers values that only lead to hidden nodes: the
 * results with such a value are linked to each other through it, so a value
 * of a single result, or of none, shows nothing. With the style option "Hide
 * filter options that give no links", those values are left out. They are
 * counted on the view's results without that filter, per entity (not per
 * translation). Filters on other fields stay as they are: they would need a
 * query per option.
 */
class ExposedFilterOptions {

  /**
   * Views executed to count the values; their exposed forms stay as they are.
   */
  protected static int $counting = 0;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Removes the options of exposed filters that would give an empty network.
   *
   * @param array $form
   *   The exposed form of a view.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Its state, with the view.
   */
  public function alterForm(array &$form, FormStateInterface $form_state): void {
    $view = $form_state->get('view');
    if (static::$counting || !$view instanceof ViewExecutable) {
      return;
    }
    $style = $view->display_handler->getOption('style');
    $options = ($style['type'] ?? '') === 'network' ? ($style['options'] ?? []) : [];
    if (empty($options['hide_isolated']) || empty($options['hide_empty_filter_options'])) {
      return;
    }
    $shared_fields = static::sharedFields($options);
    if (!$shared_fields) {
      return;
    }

    $cacheability = new CacheableMetadata();
    // Values change with the content, the other filters and access.
    $entity_type_id = Network::entityTypeOfView($view, $this->entityTypeManager);
    if ($entity_type_id) {
      $cacheability->addCacheTags($this->entityTypeManager->getDefinition($entity_type_id)->getListCacheTags());
    }
    $cacheability->addCacheContexts(['url.query_args', 'user.permissions', 'languages:language_content']);

    $input = $view->getExposedInput();
    foreach ($view->display_handler->getHandlers('filter') as $filter) {
      if (!$filter instanceof FilterPluginBase || !$filter->isExposed() || !empty($filter->options['is_grouped'])) {
        continue;
      }
      $field_name = $this->fieldName($view, $filter);
      if ($field_name === NULL || !isset($shared_fields[$field_name])) {
        continue;
      }
      $identifier = $filter->options['expose']['identifier'];
      // With an exposed operator, the value is in a wrapper.
      $parents = isset($form[$identifier . '_wrapper'][$identifier]) ? [$identifier . '_wrapper', $identifier] : [$identifier];
      $options_list = NestedArray::getValue($form, array_merge($parents, ['#options']));
      if (!is_array($options_list)) {
        continue;
      }
      $counts = $this->countValues($view, $identifier, $field_name, $cacheability);
      $max = $shared_fields[$field_name];
      $selected = array_map('strval', (array) ($input[$identifier] ?? []));
      foreach ($options_list as $key => $label) {
        // Option groups stay as they are.
        if (is_array($label)) {
          continue;
        }
        // Taxonomy term filters wrap options with a hierarchy in objects.
        $value = (string) (is_object($label) && isset($label->option) ? key($label->option) : $key);
        $count = $counts[$value] ?? 0;
        if ($value === 'All' || $value === '' || in_array($value, $selected, TRUE) || ($count >= 2 && (!$max || $count <= $max))) {
          continue;
        }
        unset($options_list[$key]);
      }
      NestedArray::setValue($form, array_merge($parents, ['#options']), $options_list);
    }
    CacheableMetadata::createFromRenderArray($form)->merge($cacheability)->applyTo($form);
  }

  /**
   * Returns the fields of shared links types, with their maximum.
   *
   * @param array $options
   *   The options of the network style.
   *
   * @return int[]
   *   The maximum number of items per shared item (0: no maximum), keyed by
   *   field name: fields of the entity reference fields edge source whose
   *   edges link the results that refer to the same item.
   */
  public static function sharedFields(array $options): array {
    $source = $options['edge_sources']['entity_reference_fields'] ?? [];
    $fields = empty($source['enabled']) ? [] : ($source['settings']['fields'] ?? []);
    $shared = [];
    foreach ($options['shared_links'] ?? [] as $settings) {
      $edge_type = (string) ($settings['edge_type'] ?? '');
      if (($settings['mode'] ?? 'target') === 'target' && str_starts_with($edge_type, 'field:')) {
        $field_name = substr($edge_type, 6);
        if (in_array($field_name, $fields, TRUE)) {
          $shared[$field_name] = (int) ($settings['max'] ?? 0);
        }
      }
    }
    return $shared;
  }

  /**
   * Returns the entity field an exposed filter filters on, if it is one.
   */
  protected function fieldName(ViewExecutable $view, FilterPluginBase $filter): ?string {
    // SQL views: the Views data of a field's columns name the field.
    if (!empty($filter->definition['field_name'])) {
      return $filter->definition['field_name'];
    }
    // Search API views: an index field whose property is the entity field.
    $query = $view->getQuery();
    if (method_exists($query, 'getIndex') && ($index = $query->getIndex())) {
      $field = $index->getField($filter->realField);
      $path = $field ? $field->getPropertyPath() : '';
      return $path !== '' && !str_contains($path, ':') ? $path : NULL;
    }
    return NULL;
  }

  /**
   * Counts the results of the view per value of a field, without its filter.
   *
   * @return int[]
   *   The number of entities with the value, keyed by value.
   */
  protected function countValues(ViewExecutable $view, string $identifier, string $field_name, CacheableMetadata $cacheability): array {
    $input = $view->getExposedInput();
    unset($input[$identifier]);
    $count_view = Views::getView($view->id());
    $count_view->setDisplay($view->current_display);
    $count_view->setArguments($view->args);
    $count_view->setExposedInput($input);
    $count_view->setItemsPerPage(0);
    static::$counting++;
    try {
      $count_view->execute();
    }
    finally {
      static::$counting--;
    }
    $entities = [];
    foreach ($count_view->result as $row) {
      // ResultRow::$_entity is documented as not nullable, but it is NULL
      // when the entity could not be loaded.
      /** @var \Drupal\Core\Entity\EntityInterface|null $entity */
      $entity = $row->_entity;
      // Search API: a repeated search in the same request can return its
      // results without their entities; the item loads them.
      if (!$entity && isset($row->_item) && method_exists($row->_item, 'getOriginalObject')) {
        $entity = $row->_item->getOriginalObject()?->getValue();
      }
      if (!$entity instanceof FieldableEntityInterface || !$entity->hasField($field_name)) {
        continue;
      }
      // One entity, also when the view has a row per translation.
      foreach ($entity->get($field_name) as $item) {
        $value = $item->{$item::mainPropertyName()};
        if ($value !== NULL && $value !== '') {
          $entities[(string) $value][$entity->getEntityTypeId() . ':' . $entity->id()] = TRUE;
        }
      }
    }
    $cacheability->addCacheableDependency($count_view->storage);
    $count_view->destroy();
    return array_map('count', $entities);
  }

}
