<?php

declare(strict_types=1);

namespace Drupal\Tests\network_views\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\network_views\Palette;
use Drupal\network_views\Plugin\views\style\Network;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the color assignment and parsing.
 *
 * @group network_views
 */
#[Group('network_views')]
#[CoversClass(Palette::class)]
class PaletteTest extends UnitTestCase {

  /**
   * Values get distinct colors that do not depend on the other values.
   */
  public function testAssign(): void {
    $all = Palette::assign(['Person', 'Organisation', 'Place']);
    $this->assertCount(3, array_unique($all));

    // Filtering a value away does not change the colors of the others, unless
    // a collision moved them.
    $two = Palette::assign(['Person', 'Place']);
    $this->assertSame($all['Person'], $two['Person']);
    $this->assertSame($all['Place'], $two['Place']);

    // The order of the values does not matter.
    $this->assertEquals($all, Palette::assign(['Place', 'Person', 'Organisation']));

    // Overrides win, and their color is not given to another value.
    $overridden = Palette::assign(['Person', 'Place'], ['Person' => $all['Place']]);
    $this->assertSame($all['Place'], $overridden['Person']);
    $this->assertNotSame($all['Place'], $overridden['Place']);

    // More values than colors reuse colors.
    $many = Palette::assign(array_map(fn($i) => "v$i", range(1, 15)));
    $this->assertCount(15, $many);
    $this->assertCount(count(Palette::COLORS), array_unique($many));
  }

  /**
   * Color lines are parsed, and invalid ones reported.
   */
  public function testParseColors(): void {
    $colors = Network::parseColors("Person|#4e79a7\r\n\nA|B|red\nbroken\nX|url(evil)\nY|rgb(1, 2, 3)", $invalid);
    $this->assertSame(['Person' => '#4e79a7', 'A|B' => 'red', 'Y' => 'rgb(1, 2, 3)'], $colors);
    $this->assertSame(['broken', 'X|url(evil)'], $invalid);
  }

}
