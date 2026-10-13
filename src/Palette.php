<?php

declare(strict_types=1);

namespace Drupal\network_views;

/**
 * Assigns colors from a categorical palette to values.
 */
final class Palette {

  /**
   * The color of edges when there is only one type.
   */
  public const NEUTRAL = '#9a9a9a';

  /**
   * The default palette (Tableau 10).
   */
  public const COLORS = [
    '#4e79a7', '#f28e2b', '#e15759', '#76b7b2', '#59a14f',
    '#edc948', '#b07aa1', '#ff9da7', '#9c755f', '#bab0ac',
  ];

  /**
   * Assigns a color to each value.
   *
   * A value keeps its color when other values are filtered away: each value
   * gets the palette color at the position of its hash, or the next free one.
   * Only when there are more values than colors are colors reused.
   *
   * @param string[] $values
   *   The values.
   * @param string[] $overrides
   *   Fixed colors keyed by value.
   *
   * @return string[]
   *   Colors keyed by value.
   */
  public static function assign(array $values, array $overrides = []): array {
    $result = [];
    $taken = [];
    foreach ($values as $value) {
      if (isset($overrides[$value])) {
        $result[$value] = $overrides[$value];
        $taken[$overrides[$value]] = TRUE;
      }
    }
    $count = count(self::COLORS);
    $rest = array_diff(array_map('strval', $values), array_keys($result));
    sort($rest);
    foreach ($rest as $value) {
      $start = crc32($value) % $count;
      $color = self::COLORS[$start];
      for ($i = 0; $i < $count; $i++) {
        $candidate = self::COLORS[($start + $i) % $count];
        if (!isset($taken[$candidate])) {
          $color = $candidate;
          break;
        }
      }
      $result[$value] = $color;
      $taken[$color] = TRUE;
    }
    return $result;
  }

  /**
   * Parses lines of "value|color".
   *
   * @param string $text
   *   The text to parse.
   * @param string[]|null $invalid
   *   Returns the invalid lines.
   *
   * @return string[]
   *   Colors keyed by value.
   *
   * @param-out string[] $invalid
   */
  public static function parse(string $text, ?array &$invalid = NULL): array {
    $colors = [];
    $invalid = [];
    foreach (preg_split('/\R/', $text) as $line) {
      if (trim($line) === '') {
        continue;
      }
      $parts = explode('|', $line);
      $color = trim((string) array_pop($parts));
      $value = trim(implode('|', $parts));
      if ($value === '' || !self::isColor($color)) {
        $invalid[] = trim($line);
        continue;
      }
      $colors[$value] = $color;
    }
    return $colors;
  }

  /**
   * Whether a string is a CSS color that is safe to output.
   */
  public static function isColor(string $color): bool {
    return (bool) preg_match('/^(#[0-9a-f]{3,8}|[a-z]{3,20}|(rgb|hsl)a?\([0-9.,%\s]+\))$/i', $color);
  }

}
