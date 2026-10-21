# Changelog

All notable changes to `network_views`.

## Unreleased

First release. The network Views style, the graph builder for other modules (such as `cytoscape_egonetwork`) and edge sources:

- Views style "Network": result rows as nodes, edges from edge sources; labels, tooltips, layouts, legend, language handling (show, fade or hide what is not available in the current language).
- Edge sources: entity reference fields (also to other entity types or content types, whose referenced items can be shown as nodes), the term hierarchy (in term views), and, in `relationship_nodes_networks`, relation nodes.
- Shared links (one-mode projection) per edge type: items that share a neighbour are linked, also when the shared items are not in the graph.
- Ego networks as views: the contextual filter "Network neighbourhood" limits a network view to an entity (e.g. the content in the URL) and its neighbours, 1 or 2 steps deep, also through shared links; the network format shows the entity as the root.
- Colors keyed by machine name and ID, the same in every language: nodes by bundle, by the item a reference field refers to, or from a color field; edges by type, group or shared item. Older `label|color` lines keep working as a fallback.
