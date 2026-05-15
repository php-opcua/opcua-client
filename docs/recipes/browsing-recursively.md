---
eyebrow: 'Docs · Recipes'
lede:    'browseRecursive() walks a subtree in memory. For wide address spaces, swap it for a streaming traversal that does not blow up on a 50 000-node tree.'

see_also:
  - { href: '../operations/browsing.md',        meta: '6 min' }
  - { href: '../observability/caching.md',      meta: '5 min' }
  - { href: '../operations/resolving-paths.md', meta: '5 min' }

prev: { label: 'Handling unsupported services',     href: './service-unsupported.md' }
next: { label: 'Subscribing to data changes',       href: './subscribing-to-data-changes.md' }
---

# Browsing recursively

`browseRecursive()` is the right call when you want the whole subtree
in memory at once — a configuration screen, a one-time inventory
dump, a small tree on a tame server. For anything larger, you need a
streaming approach: walk node-by-node, process as you go, never hold
the whole tree at once.

## The simple case

<!-- @code-block language="php" label="examples/recursive-small.php" -->
```php
use PhpOpcua\Client\Types\BrowseNode;
use PhpOpcua\Client\Types\NodeClass;

$tree = $client->browseRecursive(
    'ns=2;s=Devices',
    maxDepth: 3,
    nodeClassMask: NodeClass::Object->value | NodeClass::Variable->value,
);

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

`maxDepth: 3` is a memory cap, not a feature decision. The default
(set via `setDefaultBrowseMaxDepth()`, ships at `4`) is fine for the
"I want to see what's in there" case; lower it when you only need the
top level.

`browseRecursive()` has cycle detection — references back to an
ancestor stop the recursion. Useful, because the OPC UA address space
graph is not a tree.

## Streaming traversal

When the subtree might be large, switch to a generator:

<!-- @code-block language="php" label="examples/recursive-streamed.php" -->
```php
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\ReferenceDescription;

/**
 * Yield every Variable node under $rootNodeId, depth-first, with
 * cycle detection. Memory is O(depth × children-per-level), not O(tree).
 */
function streamVariables($client, NodeId|string $rootNodeId): \Generator
{
    $stack   = [$client->resolveNodeId((string) $rootNodeId)];
    $visited = [];

    while ($stack !== []) {
        $node = array_pop($stack);
        $key  = (string) $node;

        if (isset($visited[$key])) {
            continue;
        }
        $visited[$key] = true;

        foreach ($client->browseAll($node, nodeClassMask: NodeClass::Variable->value) as $ref) {
            yield $ref;
        }

        foreach ($client->browseAll($node, nodeClassMask: NodeClass::Object->value) as $ref) {
            $stack[] = $ref->nodeId;
        }
    }
}

foreach (streamVariables($client, 'ns=2;s=Plant') as $variable) {
    processOne($variable);    // your code here
}
```
<!-- @endcode-block -->

The pattern:

- One `browseAll()` per node visited — the library handles
  continuation points internally.
- The generator yields one `ReferenceDescription` at a time. Caller
  controls memory.
- Explicit visited set short-circuits cycles. Without it, you can
  loop indefinitely if the server publishes a cyclic graph (rare but
  legal).
- Two separate browse calls per node — one for variables (yield), one
  for objects (recurse). Cuts wire traffic when you only care about
  variables.

## When the tree is *really* big

For multi-tenant plants — a server publishing hundreds of devices
each with a thousand tags — even the streaming generator is too
greedy. The right pattern is to **bound the depth** at each level
and queue rather than recurse:

<!-- @code-block language="php" label="examples/recursive-bfs.php" -->
```php
$queue   = new SplQueue();
$queue->enqueue(['node' => 'ns=2;s=Plant', 'depth' => 0]);
$visited = [];

while (! $queue->isEmpty()) {
    ['node' => $node, 'depth' => $depth] = $queue->dequeue();
    $key = (string) $node;

    if ($depth > MAX_DEPTH || isset($visited[$key])) {
        continue;
    }
    $visited[$key] = true;

    foreach ($client->browseAll($node) as $ref) {
        processOne($ref);
        if ($ref->nodeClass === NodeClass::Object) {
            $queue->enqueue(['node' => $ref->nodeId, 'depth' => $depth + 1]);
        }
    }
}
```
<!-- @endcode-block -->

Breadth-first lets you stop at a fixed depth without losing nodes at
that depth — useful for "show me the first 3 levels".

## Cache interaction

Every `browse()` / `browseAll()` call hits the PSR-16 cache by
default. A recursive walk benefits enormously: the second walk over
the same subtree (no schema change in between) is mostly cache hits.

For a one-off inventory job:

- Leave caching on (`InMemoryCache` is the default).
- If the job is in a short-lived script, the cache adds no value —
  the second walk does not happen — but it also adds negligible
  cost.

For a periodic discovery worker:

- Use a persistent cache (`FileCache`, Redis) via `setCache()`. The
  cost amortises across runs.
- Invalidate on schema deploys with `$client->invalidateCache($root)`
  or `$client->flushCache()`.

See [Observability · Caching](../observability/caching.md).

## Choosing a `nodeClassMask`

OPC UA browse returns Variables, Objects, Methods, Views, Types — by
default, all of them, including the type definitions of every node
visited. That balloons the result set.

Two common masks:

<!-- @code-block language="php" label="filter masks" -->
```php
// Just folders and devices — for tree navigation
$mask = NodeClass::Object->value;

// Just data — for inventory of every tag in a plant
$mask = NodeClass::Variable->value;

// Both — when you want the structure and the leaves
$mask = NodeClass::Object->value | NodeClass::Variable->value;
```
<!-- @endcode-block -->

Cutting `Type` and `Method` from the mask typically cuts result-set
size by 30-60% on industrial servers.

## Cycle detection in `browseRecursive`

The built-in `browseRecursive()` tracks NodeIds it has already
visited and short-circuits. The streaming patterns above replicate
that — do **not** assume the server publishes a tree. Two real-world
shapes that loop:

- An object has a `HasNotifier` reference back to its parent device.
- A type definition references itself transitively through
  `HasSubtype` and `HasComponent`.

Without cycle detection, both walk forever.

## Performance numbers (ballpark)

Real-world numbers from the library's integration suite (LAN
attached UA-.NETStandard server):

| Subtree size       | `browseRecursive()` | Streaming generator |
| ------------------ | ------------------- | ------------------- |
| 100 nodes          | ~50 ms              | ~60 ms              |
| 1 000 nodes        | ~400 ms             | ~500 ms             |
| 10 000 nodes       | ~5 s, ~40 MB RAM    | ~6 s, <2 MB RAM     |
| 100 000 nodes      | OOM at default settings | ~60 s, <2 MB RAM |

The streaming pattern is roughly the same speed (the wire traffic
dominates), but holds constant memory.

## When *not* to walk

If the address space is well-known and stable, hardcode the NodeIds
in configuration. The library is fast enough that resolving them at
startup with `resolveNodeId()` is fine; recursive discovery is for
inventories and unknown servers.
