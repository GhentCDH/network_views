/**
 * @file
 * Renders network views with Cytoscape.js.
 */
((Drupal, once, cytoscape) => {
  const instances = new WeakMap();

  // Small graphs are not zoomed in further than this when they are fitted.
  const MAX_FIT_ZOOM = 1.25;

  /**
   * Returns the Cytoscape layout options for a layout name.
   */
  function layoutOptions(name, directed) {
    const shared = { name, animate: false, padding: 30, fit: true };
    switch (name) {
      case 'concentric':
        return {
          ...shared,
          // The root (of an ego network) in the center, then by degree.
          concentric: (node) => node.degree() + (node.data('root') ? 1000 : 0),
          levelWidth: () => 2,
          minNodeSpacing: 20,
        };
      case 'breadthfirst':
        return { ...shared, directed, spacingFactor: 1.2, roots: '[?root]' };
      case 'circle':
      case 'grid':
        return shared;
      default:
        return {
          ...shared,
          name: 'cose',
          // Labels count as part of the nodes, so that they do not overlap.
          nodeDimensionsIncludeLabels: true,
          nodeRepulsion: () => 40000,
          idealEdgeLength: () => 120,
          componentSpacing: 60,
          randomize: false,
        };
    }
  }

  /**
   * Returns the Cytoscape stylesheet.
   */
  function stylesheet(settings, textColor) {
    const size =
      settings.size === 'degree'
        ? (node) => 16 + 6 * Math.sqrt(node.degree())
        : () => 22;
    return [
      {
        selector: 'node',
        style: {
          'background-color': 'data(color)',
          label: 'data(label)',
          width: size,
          height: size,
          color: textColor,
          'font-size': 10,
          'text-valign': 'bottom',
          'text-margin-y': 4,
          'text-wrap': 'ellipsis',
          'text-max-width': 120,
          'min-zoomed-font-size': 7,
        },
      },
      {
        selector: 'edge',
        style: {
          width: 1.5,
          'line-color': 'data(color)',
          'target-arrow-color': 'data(color)',
          'target-arrow-shape': settings.directed ? 'triangle' : 'none',
          'arrow-scale': 0.8,
          'curve-style': 'bezier',
          label: settings.edgeLabels ? 'data(label)' : '',
          color: textColor,
          'font-size': 8,
          'text-rotation': 'autorotate',
          'text-background-color': '#ffffff',
          'text-background-opacity': 0.7,
          'min-zoomed-font-size': 7,
        },
      },
      {
        // The root of an ego network.
        selector: 'node[?root]',
        style: {
          shape: 'star',
          width: (node) => 1.6 * size(node),
          height: (node) => 1.6 * size(node),
          'font-weight': 'bold',
        },
      },
      {
        // Not available in the current language.
        selector: 'node[?faded]',
        style: {
          opacity: 0.45,
          'border-width': 1.5,
          'border-style': 'dashed',
          'border-color': textColor,
        },
      },
      {
        selector: 'edge[?faded]',
        style: { opacity: 0.45, 'line-style': 'dashed' },
      },
      {
        selector: 'edge.highlighted',
        style: { label: 'data(label)', width: 2.5 },
      },
      {
        selector: 'node.highlighted',
        style: { 'border-width': 2, 'border-color': textColor },
      },
      {
        selector: '.dimmed',
        style: { opacity: 0.15 },
      },
    ];
  }

  /**
   * Shows the tooltip of a node next to it.
   */
  function showTooltip(tooltip, node) {
    const html = node.data('tooltip');
    if (!html) {
      return;
    }
    tooltip.innerHTML = html;
    const position = node.renderedPosition();
    const offset = node.renderedOuterWidth() / 2 + 8;
    tooltip.style.left = `${position.x + offset}px`;
    tooltip.style.top = `${position.y}px`;
    tooltip.hidden = false;
  }

  /**
   * Creates the graph for a canvas element.
   */
  function init(canvas) {
    let graph;
    try {
      graph = JSON.parse(canvas.dataset.networkViews);
    } catch (e) {
      return;
    }
    const { settings } = graph;
    const textColor = getComputedStyle(canvas).color;

    const cy = cytoscape({
      container: canvas,
      elements: {
        nodes: graph.nodes.map((data) => ({ data })),
        edges: graph.edges.map((data) => ({ data })),
      },
      style: stylesheet(settings, textColor),
      layout: layoutOptions(settings.layout, settings.directed),
      minZoom: 0.1,
      maxZoom: 4,
      wheelSensitivity: 0.3,
    });
    instances.set(canvas, cy);
    if (cy.zoom() > MAX_FIT_ZOOM) {
      cy.zoom(MAX_FIT_ZOOM);
      cy.center();
    }

    const tooltip = document.createElement('div');
    tooltip.className = 'network-views__tooltip';
    tooltip.setAttribute('role', 'tooltip');
    tooltip.hidden = true;
    canvas.parentNode.insertBefore(tooltip, canvas.nextSibling);

    cy.on('mouseover', 'node', (event) => {
      const node = event.target;
      const neighbourhood = node.closedNeighborhood();
      cy.elements().not(neighbourhood).addClass('dimmed');
      neighbourhood.addClass('highlighted');
      showTooltip(tooltip, node);
      if (settings.link && node.data('url')) {
        canvas.style.cursor = 'pointer';
      }
    });
    cy.on('mouseout', 'node', () => {
      cy.elements().removeClass('dimmed highlighted');
      tooltip.hidden = true;
      canvas.style.cursor = '';
    });
    cy.on('pan zoom', () => {
      tooltip.hidden = true;
    });
    if (settings.link) {
      cy.on('tap', 'node', (event) => {
        const url = event.target.data('url');
        if (url) {
          window.location.href = url;
        }
      });
    }
  }

  /**
   * Renders network views.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.networkViews = {
    attach(context) {
      once('network-views', '[data-network-views]', context).forEach(init);
    },
    detach(context, settings, trigger) {
      if (trigger !== 'unload') {
        return;
      }
      once
        .remove('network-views', '[data-network-views]', context)
        .forEach((canvas) => {
          instances.get(canvas)?.destroy();
          instances.delete(canvas);
        });
    },
  };
})(Drupal, once, cytoscape);
