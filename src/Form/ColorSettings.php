<?php

declare(strict_types=1);

namespace Drupal\network_views\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\network_views\Palette;

/**
 * Form elements for colors keyed by machine names and IDs.
 *
 * Colors are configured per key (e.g. "node:person", "taxonomy_term:12",
 * "relation_type:5"), never per label: labels differ per language and need
 * not be unique. Used by the network Views style and the ego network
 * formatter of cytoscape_egonetwork.
 */
final class ColorSettings {

  /**
   * The maximum number of rows in a color table.
   */
  public const MAX_ROWS = 100;

  /**
   * Builds a table with a color field per key.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $caption
   *   The caption of the table.
   * @param string[] $rows
   *   The labels of the keys, keyed by key.
   * @param string[] $current
   *   The current colors, keyed by key.
   * @param array $states
   *   Optional #states of the table.
   *
   * @return array
   *   The table element; its values are cleaned with ::submit().
   */
  public static function table(TranslatableMarkup|string $caption, array $rows, array $current, array $states = []): array {
    $table = [
      '#type' => 'table',
      '#caption' => $caption,
      '#header' => [new TranslatableMarkup('Value'), new TranslatableMarkup('Color')],
      '#empty' => new TranslatableMarkup('Nothing to configure.'),
    ];
    if ($states) {
      $table['#states'] = $states;
    }
    foreach (array_slice($rows, 0, static::MAX_ROWS, TRUE) as $key => $label) {
      $table[$key] = [
        'label' => ['#plain_text' => $label . ' (' . $key . ')'],
        'color' => [
          '#type' => 'textfield',
          '#title' => new TranslatableMarkup('Color of @label', ['@label' => $label]),
          '#title_display' => 'invisible',
          '#default_value' => $current[$key] ?? '',
          '#placeholder' => new TranslatableMarkup('Palette'),
          '#size' => 9,
          '#maxlength' => 30,
          '#element_validate' => [[static::class, 'validateColor']],
        ],
      ];
    }
    return $table;
  }

  /**
   * Builds a textarea for colors of other keys, as lines of "key|color".
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $title
   *   The title.
   * @param string[] $current
   *   The current colors, keyed by key.
   * @param string[] $covered
   *   The keys that the tables of the form already show.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $description
   *   The description.
   * @param array $states
   *   Optional #states.
   *
   * @return array
   *   The textarea element; its value is cleaned with ::submit().
   */
  public static function textarea(TranslatableMarkup|string $title, array $current, array $covered, TranslatableMarkup|string $description, array $states = []): array {
    $lines = [];
    foreach (array_diff_key($current, array_flip($covered)) as $key => $color) {
      $lines[] = "$key|$color";
    }
    $element = [
      '#type' => 'textarea',
      '#title' => $title,
      '#description' => $description,
      '#default_value' => implode("\n", $lines),
      '#rows' => 2,
      '#element_validate' => [[static::class, 'validateLines']],
    ];
    if ($states) {
      $element['#states'] = $states;
    }
    return $element;
  }

  /**
   * Returns the colors from the submitted values of tables and textareas.
   *
   * @param array $values
   *   Submitted values: tables (arrays of rows with "color") and textareas
   *   (strings of "key|color" lines), in any order; later ones win.
   *
   * @return string[]
   *   The colors, keyed by key.
   */
  public static function submit(array $values): array {
    $colors = [];
    foreach ($values as $value) {
      if (is_string($value)) {
        $colors = Palette::parse($value) + $colors;
        continue;
      }
      foreach (is_array($value) ? $value : [] as $key => $row) {
        $color = trim((string) ($row['color'] ?? ''));
        if ($color !== '') {
          $colors[(string) $key] = $color;
        }
      }
    }
    return $colors;
  }

  /**
   * Element validation: a color field is empty or a CSS color.
   */
  public static function validateColor(array $element, FormStateInterface $form_state): void {
    $value = trim((string) $element['#value']);
    if ($value !== '' && !Palette::isColor($value)) {
      $form_state->setError($element, new TranslatableMarkup('%value is not a color. Use e.g. <code>#4e79a7</code>.', ['%value' => $value]));
    }
  }

  /**
   * Element validation: lines of "key|color".
   */
  public static function validateLines(array $element, FormStateInterface $form_state): void {
    Palette::parse((string) $element['#value'], $invalid);
    if ($invalid) {
      $form_state->setError($element, new TranslatableMarkup('Invalid lines: %lines. Use <code>key|color</code> with a color such as <code>#4e79a7</code>.', ['%lines' => implode(', ', $invalid)]));
    }
  }

}
