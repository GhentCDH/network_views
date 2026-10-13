# network_views

Network graphs of Drupal entities, rendered with [Cytoscape.js](https://js.cytoscape.org/): a Views style that displays the result rows as a network, and the building blocks for other network displays, such as [`cytoscape_egonetwork`](https://github.com/GhentCDH/cytoscape_egonetwork).

- **Nodes** are the entities in the result of the view. The filters, contextual filters and exposed filters of the view decide what is in the graph.
- **Edges** come from *edge sources*. Only edges whose two ends are both in the result are shown, so a filtered view shows the network between the filtered entities, and entities the user may not see never appear.

## Requirements

- Drupal 10.3 or later, or Drupal 11
- Views

Cytoscape.js 3.30.2 is loaded from cdnjs.cloudflare.com (see `network_views.libraries.yml`).

## Installation

Add the repository to your project's `composer.json`:

```json
"repositories": [
  {
    "type": "vcs",
    "url": "https://github.com/GhentCDH/network_views"
  }
]
```

Then require and enable the module:

```bash
composer require "drupal/network_views:dev-main"
drush en network_views
```

## Usage

1. Create a view of content (or another entity type) with the **Network** format.
2. Add the fields to use as label, tooltip or color. Fields that are only used in the tooltip can be excluded from display.
3. Under **Format › Settings › Edges**, enable an edge source.
4. Set the pager to **Display all items**: with a limited pager, the graph only contains one page of the result.

### Settings

| Setting | Effect |
|---|---|
| Label | Field used as node label; default the entity label. |
| Tooltip | Fields shown when hovering a node, with their Views label and formatting. |
| Color by | Bundle, a field, or nothing. Nodes with the same value get the same color; for a multi-value field the first value counts. |
| Colors | Optional `value\|color` lines, e.g. `Person\|#4e79a7`. Other values get a color from the default palette. A value keeps its color when other values are filtered away. |
| Color from field | Optional field whose value is a CSS color, e.g. a color field on a taxonomy term added through a relationship. Editors then choose the colors in the content. |
| Node size | Fixed, or by number of connections. |
| Hide nodes without connections | Leaves out isolated nodes. |
| Not available in the current language | Show normally, show faded or hide the nodes without a translation in the current language that the visitor may view (for visitors: a published one), and the edges that their edge source marks as unavailable in it. Entities without a language are always available. |
| Directed edges | Shows arrows. Without direction, two edges of the same type between the same nodes are merged. |
| Always show edge labels | Otherwise edge labels are shown when hovering a node. |
| Edge colors | Optional `label\|color` lines per edge type. With a single edge type, edges are grey. |
| Layout, Height, Legend | Display options. |

Clicking a node opens the entity; hovering a node highlights its neighbourhood.

## Edge sources

| Plugin | Module | Edges |
|---|---|---|
| Entity reference fields | `network_views` | A reference from one result row to another, in the selected fields. Labeled with the field label. |
| Relation nodes | `relationship_nodes_networks`, a submodule of [`relationship_nodes`](https://github.com/GhentCDH/relationship_nodes) | A relation node between two entities, labeled with its relation type. |

### Writing an edge source

An edge source is a plugin in `src/Plugin/NetworkEdgeSource/` with the `#[NetworkEdgeSource]` attribute, implementing `EdgeSourceInterface` (usually by extending `EdgeSourcePluginBase`).

- `getEdges()` receives entities keyed by ID and returns `Edge` objects; edges whose ends are not both in the graph are dropped.
- `getEdgesOf()` returns the edges that touch one entity, including those from entities that are not known yet (e.g. references *to* it). It is used to find neighbours, as in an ego network.
- An `Edge` can carry a `reverseLabel` (e.g. "Student of" for "Teacher of"), used when the graph is read from the root node, and a `languageUnavailable` flag for edges that are not available in the current language.
- Provide a config schema `network_views.edge_source.PLUGIN_ID` of type `network_views_edge_source` for the settings, and the cacheability of the edges through the plugin's `getCacheTags()`/`getCacheContexts()`.

## Using the graph in other modules

The `network_views.graph_builder` service builds and renders a graph from `GraphNode` objects (an entity with its label, color category, tooltip, and whether it is the root or faded) and `Edge` objects; it handles language, colors, the legend and the render array. `plugin.manager.network_views.edge_source` creates the configured edge sources, finds the edges between or of entities, and provides the edge source settings form. See `cytoscape_egonetwork` for an example.

## Theming

The template is `network-views.html.twig`. The graph data is a JSON object in the `data-network-views` attribute of the canvas element (`nodes`, `edges` and `settings`), read by `js/network-views.js`.

## Development

Unit, kernel and functional tests are in `tests/`.

```bash
SIMPLETEST_DB=sqlite://localhost//tmp/test.sqlite SIMPLETEST_BASE_URL=http://127.0.0.1:8080 \
  vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/contrib/network_views
```

## Known limitations and ideas

- Large graphs: all result entities are loaded and the graph is embedded in the page. This has not been measured beyond small test graphs; for very large networks a JSON display and a WebGL renderer (e.g. Sigma.js) would be better.
- Edges to entities outside the result are not shown; showing them as faded "ghost" nodes would be an option.
- Node positions are computed in the browser on every page load and are not saved.
- The graph is not keyboard-accessible; the legend and the view's other displays (e.g. a table attachment) are the accessible alternative.
