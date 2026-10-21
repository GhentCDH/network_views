# Changelog

All notable changes to `network_views`.

## 1.0.0-beta2 - 2026-10-21

- A click on a node dispatches a cancelable `networkviews:nodeclick` event, so a theme can show the entity elsewhere (e.g. in a side panel) instead of opening its page.

## 1.0.0-beta1 - 2026-10-21

First release. The network Views style, the graph builder for other modules (such as `cytoscape_egonetwork`) and edge sources:

- Views style "Network": result rows as nodes, edges from edge sources; labels, tooltips, layouts, legend, language handling (show, fade or hide what is not available in the current language).
- Edge sources: entity reference fields (also to other entity types or content types, whose referenced items can be shown as nodes), the term hierarchy (in term views), and, in `relationship_nodes_networks`, relation nodes.
- Shared links (one-mode projection) per edge type: items that share a neighbour are linked, also when the shared items are not in the graph.
- Ego networks as views: the contextual filter "Network neighbourhood" limits a network view to an entity (e.g. the content in the URL) and its neighbours, 1 or 2 steps deep, also through shared links; the network format shows the entity as the root.
- Access per translation: an item whose original is unpublished but that has a published translation is a node (with the label of a translation the visitor may view, faded or hidden in other languages by the language setting); the label of a translation the visitor may not view is never shown.
- A network view depends on the modules of its edge sources: uninstalling such a module (e.g. `relationship_nodes_networks`) lists and removes the view, instead of leaving a network without those edges.
- Views of a Search API index whose datasources are of one entity type (e.g. content) can be networks too.
- The view's result count (e.g. a "Result summary" header) counts the nodes that are shown, not those hidden without connections, in another language or without access (when all rows are loaded, without a pager).
- Colors keyed by machine name and ID, the same in every language: nodes by bundle, by the item a reference field refers to, or from a color field; edges by type, group or shared item. Older `label|color` lines keep working as a fallback.
