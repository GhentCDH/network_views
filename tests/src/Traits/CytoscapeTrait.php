<?php

declare(strict_types=1);

namespace Drupal\Tests\network_views\Traits;

/**
 * Inspects and operates a rendered network graph in WebDriver tests.
 *
 * The graph is read from its Cytoscape.js instance, which Cytoscape keeps on
 * the container element (the "_cyreg" property of Cytoscape 3.x), so that the
 * renderer needs no hook for tests. Mouse events are dispatched at the
 * rendered positions of nodes and edges. The helpers use the first graph on
 * the page.
 */
trait CytoscapeTrait {

  /**
   * Waits until Cytoscape has rendered the graph.
   */
  protected function waitForGraph(): void {
    $ready = $this->getSession()->wait(20000, "typeof cytoscape === 'function' && !!document.querySelector('[data-network-views]')?._cyreg?.cy");
    $state = $this->getSession()->evaluateScript("JSON.stringify({cytoscape: typeof cytoscape, canvas: !!document.querySelector('[data-network-views]'), errors: sessionStorage.getItem('js_testing_log_test.errors'), url: location.href})");
    $this->assertTrue($ready, 'Cytoscape.js was loaded (from cdnjs.cloudflare.com) and rendered the graph: ' . $state);
  }

  /**
   * Evaluates a JavaScript expression with the Cytoscape instance.
   *
   * @param string $expression
   *   The expression; "container" is the graph container and "cy" its
   *   Cytoscape instance.
   */
  protected function evaluateGraph(string $expression): mixed {
    return $this->getSession()->evaluateScript("(() => { const container = document.querySelector('[data-network-views]'); const cy = container._cyreg.cy; return $expression; })()");
  }

  /**
   * Dispatches a mouse event at a node or edge of the graph.
   *
   * @param string $type
   *   The event type, e.g. "mousemove".
   * @param string|null $element
   *   The ID of a node or edge, or NULL for an empty corner of the graph.
   */
  protected function mouseOnGraph(string $type, ?string $element = NULL): void {
    $this->getSession()->executeScript('(' . <<<'JS'
      (type, id) => {
        const container = document.querySelector('[data-network-views]');
        const cy = container._cyreg.cy;
        container.scrollIntoView();
        let position = { x: 5, y: 5 };
        if (id !== null) {
          const element = cy.getElementById(id);
          position = element.isNode() ? element.renderedPosition() : element.renderedMidpoint();
        }
        const rect = container.getBoundingClientRect();
        const x = rect.left + container.clientLeft + position.x;
        const y = rect.top + container.clientTop + position.y;
        const target = document.elementFromPoint(x, y);
        target.dispatchEvent(new MouseEvent(type, {
          bubbles: true,
          cancelable: true,
          view: window,
          clientX: x,
          clientY: y,
          button: 0,
          buttons: type === 'mousedown' ? 1 : 0,
        }));
      }
      JS . ')(' . json_encode($type) . ', ' . json_encode($element) . ')');
  }

  /**
   * Asserts that no JavaScript errors or warnings were logged.
   *
   * Errors fail the test anyway (see WebDriverTestBase), but only when it
   * ends; Cytoscape.js reports invalid styles as console warnings. It always
   * warns about the custom wheel sensitivity, which the renderer sets on
   * purpose.
   */
  protected function assertNoJavascriptErrors(): void {
    foreach (['errors', 'warnings'] as $type) {
      $logged = $this->getSession()->evaluateScript("JSON.parse(sessionStorage.getItem('js_testing_log_test.$type') || '[]')");
      $logged = array_filter($logged, fn(string $message) => !str_contains($message, 'custom wheel sensitivity'));
      $this->assertSame([], array_values($logged), "No JavaScript $type.");
    }
  }

}
