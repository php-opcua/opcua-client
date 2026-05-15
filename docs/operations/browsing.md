---
eyebrow: 'Docs · Operations'
lede:    'Walk the address space one level, all levels, or with continuation. browse() is the lowest-friction call; browseAll() and browseRecursive() handle paginated and tree traversals.'

see_also:
  - { href: './resolving-paths.md',   meta: '5 min' }
  - { href: '../recipes/browsing-recursively.md', meta: '4 min' }
  - { href: '../observability/caching.md', meta: '5 min' }

prev: { label: 'Writing values',   href: './writing-values.md' }
next: { label: 'Resolving paths',  href: './resolving-paths.md' }
---

# Browsing

A Browse service call asks the server: *"starting from this node,
which references match my criteria and which target nodes do they
point to?"*. The reply is a list of `ReferenceDescription` items —
each describes one outgoing reference plus the (decoded) target node.

This library exposes three browse shapes — `browse()`,
`browseAll()`, `browseRecursive()` — plus two pagination primitives.
Pick one by intent:

| You want                         | Use                       |
| -------------------------------- | ------------------------- |
| One level, first page only       | `browse()`                |
| One level, all pages             | `browseAll()`             |
| Entire subtree                   | `browseRecursive()`       |
| Manual continuation control      | `browseWithContinuation()` + `browseNext()` |

## browse()

<!-- @method name="$client->browse(NodeId|string \$nodeId, BrowseDirection \$direction = BrowseDirection::Forward, bool \$includeSubtypes = true, int \$nodeClassMask = 0, bool \$useCache = true): array" returns="ReferenceDescription[]" visibility="public" -->

<!-- @code-block language="php" label="examples/browse.php" -->
```php
foreach ($client->browse('i=85') as $ref) {
    printf(
        "%-30s %-12s %s\n",
        $ref->displayName->text,
        $ref->nodeClass->name,
        $ref->nodeId
    );
}
```
<!-- @endcode-block -->

`browse()` returns the **first page** of references. For most servers,
"a folder with a few children" fits in one page and there is nothing
more to fetch. For wide nodes (a server with thousands of devices
under one root), you will see only the first ~100–1000 entries; use
`browseAll()` or `browseWithContinuation()`.

### Filters

| Argument            | Effect                                                  |
| ------------------- | ------------------------------------------------------- |
| `$direction`        | `Forward` (default), `Inverse`, or `Both`. Inverse follows references pointing *at* the node. |
| `$includeSubtypes`  | When `true` (default), references whose `ReferenceTypeId` is a subtype of the standard reference types are included. Set `false` to follow a single specific reference type. |
| `$nodeClassMask`    | Bitmask filter on the target node's class. `0` = all. Combine `NodeClass` enum values with `\|`. |

<!-- @code-block language="php" label="filter to variables only" -->
```php
use PhpOpcua\Client\Types\NodeClass;

$variables = $client->browse(
    'ns=2;s=Devices',
    nodeClassMask: NodeClass::Variable->value
);
```
<!-- @endcode-block -->

## browseAll()

`browseAll()` follows continuation points until the server reports
done. The signature mirrors `browse()`:

<!-- @code-block language="php" label="exhaustive single-level browse" -->
```php
$all = $client->browseAll('ns=2;s=Devices');   // every immediate child
```
<!-- @endcode-block -->

Behind the scenes the client issues `Browse`, then `BrowseNext` on the
continuation point repeatedly until the server returns no further
items. Each follow-up call respects the same `$useCache` flag.

## browseRecursive()

For tree walks, `browseRecursive()` traverses children-of-children up
to a configurable depth, with built-in cycle detection:

<!-- @method name="$client->browseRecursive(NodeId|string \$nodeId, ?int \$maxDepth = null, int \$nodeClassMask = 0): BrowseNode" returns="BrowseNode" visibility="public" -->

<!-- @code-block language="php" label="full subtree" -->
```php
$tree = $client->browseRecursive('ns=2;s=Devices', maxDepth: 3);

// $tree is a BrowseNode — each node has a reference and an array of children.
walk($tree, depth: 0);

function walk(BrowseNode $node, int $depth): void
{
    echo str_repeat('  ', $depth) . $node->reference->displayName->text . "\n";
    foreach ($node->getChildren() as $child) {
        walk($child, $depth + 1);
    }
}
```
<!-- @endcode-block -->

- `$maxDepth = null` falls back to the builder-wide default
  (`setDefaultBrowseMaxDepth()`, ships at `4`). Cap it deliberately for
  large address spaces — an OPC UA server with 50 000 nodes will
  exhaust memory long before the network does.
- Cycle detection tracks NodeIds already visited and short-circuits.
- Internally, each level runs `browseAll()` and inherits its caching.

See [Recipes · Browsing recursively](../recipes/browsing-recursively.md)
for streaming alternatives.

## Manual continuation

When you need finer control — incremental loading in a UI, time-
bounded discovery — drop to the lower-level pair:

<!-- @code-block language="php" label="manual pagination" -->
```php
$page = $client->browseWithContinuation('ns=2;s=Devices');

foreach ($page->references as $ref) {
    process($ref);
}

while ($page->continuationPoint !== null) {
    $page = $client->browseNext($page->continuationPoint);
    foreach ($page->references as $ref) {
        process($ref);
    }
}
```
<!-- @endcode-block -->

`browseWithContinuation()` returns a `BrowseResultSet` with both the
current page and an optional continuation point. `browseNext()` takes
that token and returns the next page. The continuation point is opaque
and unique to the originating call — pass it back unchanged.

## Caching

By default every browse call caches its result keyed by endpoint URL,
NodeId, direction, includeSubtypes, and nodeClassMask. Cache hits fire
the `CacheHit` event, misses fire `CacheMiss`. Bypass per call with
`useCache: false`; flush all entries with `$client->flushCache()` or
invalidate one node with `$client->invalidateCache($nodeId)`.

See [Observability · Caching](../observability/caching.md).

## Browse events

| Event        | When                                                        |
| ------------ | ----------------------------------------------------------- |
| `NodeBrowsed`| After every successful browse — payload carries the node id, direction, and reference count |
| `CacheHit` / `CacheMiss` | Cache observation events                        |

## What to read next

- [Operations · Resolving paths](./resolving-paths.md) —
  `translateBrowsePaths()` / `resolveNodeId()`, which use Browse-like
  semantics to turn `/Objects/Server/…` strings into NodeIds.
- [Types · NodeId](../types/node-id.md) — the structure of the
  identifiers returned in `ReferenceDescription`.
