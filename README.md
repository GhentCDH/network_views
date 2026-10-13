# network_views

A Views style that displays the result rows as a network graph, rendered with [Cytoscape.js](https://js.cytoscape.org/).

- **Nodes** are the entities in the result of the view. The filters, contextual filters and exposed filters of the view decide what is in the graph.
- **Edges** come from *edge sources*. Only edges whose two ends are both in the result are shown, so a filtered view shows the network between the filtered entities, and entities the user may not see never appear.

## Requirements

- Drupal 10.3 or later, or Drupal 11
- Views
- For the `network_views_relationship_nodes` submodule: [`relationship_nodes`](https://github.com/GhentCDH/relationship_nodes). The tests run against its `dev` branch: the 1.0.0-beta2 release fails on Drupal 11.4 when relation fields are saved.

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

Then require and enable the module (and optionally the submodule):

```bash
composer require "drupal/network_views:dev-main"
drush en network_views
drush en network_views_relationship_nodes
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
| Directed edges | Shows arrows. Without direction, two edges of the same type between the same nodes are merged. |
| Always show edge labels | Otherwise edge labels are shown when hovering a node. |
| Edge colors | Optional `label\|color` lines per edge type. With a single edge type, edges are grey. |
| Layout, Height, Legend | Display options. |

Clicking a node opens the entity; hovering a node highlights its neighbourhood.

## Edge sources

| Plugin | Module | Edges |
|---|---|---|
| Entity reference fields | `network_views` | A reference from one result row to another, in the selected fields. Labeled with the field label. |
| Relation nodes | `network_views_relationship_nodes` | A relation node of `relationship_nodes` between two result rows, directed from `rn_related_entity_1` to `rn_related_entity_2`. Labeled with the relation type term, or the relation content type for untyped relations. Relation nodes the user may not view are left out. |

The relation nodes themselves are not graph nodes: filter them out of the view (e.g. by content type), and they become edges.

### Writing an edge source

An edge source is a plugin in `src/Plugin/NetworkEdgeSource/` with the `#[NetworkEdgeSource]` attribute, implementing `EdgeSourceInterface` (usually by extending `EdgeSourcePluginBase`). `getEdges()` receives the result entities keyed by ID and returns `Edge` objects; the style drops the edges whose ends are not both in the result. Provide a config schema `network_views.edge_source.PLUGIN_ID` of type `network_views_edge_source` for the settings, and the cacheability of the edges through the plugin's `getCacheTags()`/`getCacheContexts()`.

## Theming

The template is `network-views.html.twig`. The graph data is a JSON object in the `data-network-views` attribute of the canvas element (`nodes`, `edges` and `settings`), read by `js/network-views.js`.

## Development

Unit, kernel and functional tests are in `tests/` and in the submodule's `tests/`. The submodule test extends the kernel test base of `relationship_nodes`.

```bash
SIMPLETEST_DB=sqlite://localhost//tmp/test.sqlite SIMPLETEST_BASE_URL=http://127.0.0.1:8080 \
  vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/contrib/network_views
```

## Known limitations and ideas

- Large graphs: all result entities are loaded and the graph is embedded in the page. This has not been measured beyond small test graphs; for very large networks a JSON display and a WebGL renderer (e.g. Sigma.js) would be better.
- Edges to entities outside the result are not shown; showing them as faded "ghost" nodes would be an option.
- Node positions are computed in the browser on every page load and are not saved.
- The graph is not keyboard-accessible; the legend and the view's other displays (e.g. a table attachment) are the accessible alternative.
- `cytoscape_egonetwork` shows the ego network of one node on its page. That remains a separate module: this style shows the network between the rows of a view.
