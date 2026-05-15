---
eyebrow: 'Docs · Operations'
lede:    'OPC UA browse paths are not URLs — they are sequences of BrowseName references. translateBrowsePaths() turns "/Objects/Server/ServerStatus" into a NodeId without you knowing the namespace indices.'

see_also:
  - { href: './browsing.md',         meta: '6 min' }
  - { href: '../types/node-id.md',   meta: '5 min' }
  - { href: '../recipes/detecting-server-capabilities.md', meta: '5 min' }

prev: { label: 'Browsing',         href: './browsing.md' }
next: { label: 'Calling methods', href: './calling-methods.md' }
---

# Resolving paths

The OPC UA `TranslateBrowsePathsToNodeIds` service takes a starting
node and a list of `RelativePath` segments — each segment a
`QualifiedName` — and returns the NodeId reached by following those
references. It is the right tool when:

- You know the human-readable path of a node but not its NodeId.
- You want code that survives a server reorganising its namespace
  indices.

This library wraps it in two shapes: a thin path resolver
(`resolveNodeId()`) and a fluent multi-path builder
(`translateBrowsePaths()`).

## resolveNodeId — single path

<!-- @method name="$client->resolveNodeId(string \$path, NodeId|string|null \$startingNodeId = null, bool \$useCache = true): NodeId" returns="NodeId" visibility="public" -->

<!-- @code-block language="php" label="examples/resolve.php" -->
```php
$serverStatus = $client->resolveNodeId('/Objects/Server/ServerStatus');
// → NodeId(ns=0;i=2256) on every standard OPC UA server
```
<!-- @endcode-block -->

<!-- @params -->
<!-- @param name="$path" type="string" required -->
Slash-separated browse path. Segments are interpreted as namespace-`0`
`QualifiedName`s by default; to specify a non-zero namespace, prefix a
segment with `<ns>:` (e.g. `/2:Devices/2:PLC/2:Speed`).
<!-- @endparam -->
<!-- @param name="$startingNodeId" type="NodeId|string|null" default="null" -->
The node the path is relative to. `null` (default) starts at the
**Root** folder (`ns=0;i=84`). Pass a `NodeId` or string to start
elsewhere — useful when you have an object reference and want to
resolve a method or a property under it.
<!-- @endparam -->
<!-- @param name="$useCache" type="bool" default="true" -->
Cache the resolution result. Same PSR-16 store as `browse()`.
<!-- @endparam -->
<!-- @endparams -->

### The slash-vs-NodeId dispatch

`resolveNodeId()` is called internally by every API that accepts
`NodeId|string`. The dispatcher looks at the first character of the
string:

- Matches `/^(ns=\d+;)?[isgb]=/` → parse as a NodeId
  (`i=`, `s=`, `g=`, `b=`)
- Contains `/` and does **not** match the NodeId grammar → resolve as
  a browse path
- Anything else → raise `InvalidNodeIdException`

This is why `'ns=2;s=Devices/PLC/Speed'` is read as a string NodeId
(slash inside an `s=` identifier is part of the identifier) while
`'/Objects/Server'` is read as a browse path.

<!-- @callout variant="warning" -->
String NodeIds whose identifier starts with `/` are ambiguous and not
supported by the dispatch heuristic. The combination is rare in
practice; if you encounter one, build the `NodeId` with
`NodeId::string()` and pass the object instead of a string.
<!-- @endcallout -->

## translateBrowsePaths — multiple paths

For batched resolution, `translateBrowsePaths()` returns an array of
`BrowsePathResult` items, one per request:

<!-- @code-block language="php" label="batched path translation" -->
```php
$results = $client->translateBrowsePaths([
    [
        'startingNodeId' => 'i=85',                      // Objects
        'path'           => '/Server/ServerStatus',
    ],
    [
        'startingNodeId' => 'i=85',
        'path'           => '/2:Devices/2:PLC/2:Speed',
    ],
]);

foreach ($results as $i => $r) {
    if ($r->statusCode === 0) {
        foreach ($r->targets as $t) {
            echo "[$i] " . $t->targetId . "\n";
        }
    }
}
```
<!-- @endcode-block -->

Each `BrowsePathResult` carries a `statusCode` and a `targets` array.
A single path can yield multiple targets when the path traverses
references that fork — typically zero or one in practice.

### Fluent builder

`translateBrowsePaths()` returns a `BrowsePathsBuilder` when called
without arguments:

<!-- @code-block language="php" label="fluent path builder" -->
```php
$results = $client->translateBrowsePaths()
    ->from('i=85')
        ->path('Server', 'ServerStatus')
    ->from('i=85')
        ->path('Devices', 'PLC', 'Speed')
    ->execute();
```
<!-- @endcode-block -->

| Method                                | Effect                                          |
| ------------------------------------- | ----------------------------------------------- |
| `from(NodeId\|string)`                | Start a new request anchored at that node       |
| `path(string ...$segments)`           | Append `QualifiedName` segments (`<ns>:Name` syntax accepted) |
| `segment(QualifiedName $qname)`       | Append a single pre-built segment               |
| `execute(): BrowsePathResult[]`       | Issue the call, return the results              |

## Common usage

Path resolution shines in two patterns:

**1. Namespace-stable lookups at startup.**

<!-- @code-block language="php" label="discover IDs at startup" -->
```php
$plcNs  = 2;   // resolved from the namespace table at connect time

$ids = [
    'speed'  => $client->resolveNodeId("/{$plcNs}:Devices/{$plcNs}:PLC/{$plcNs}:Speed"),
    'mode'   => $client->resolveNodeId("/{$plcNs}:Devices/{$plcNs}:PLC/{$plcNs}:Mode"),
    'health' => $client->resolveNodeId("/{$plcNs}:Devices/{$plcNs}:PLC/{$plcNs}:Health"),
];

// Use $ids['speed'] etc. for the rest of the session — fast, cached.
```
<!-- @endcode-block -->

**2. Methods on dynamically-discovered objects.**

When you browse a folder and find an `Object` node, its callable
methods are not visible from the reference alone — you resolve them by
their `BrowseName`:

<!-- @code-block language="php" label="resolve a method under an object" -->
```php
$method = $client->resolveNodeId('/Reset', startingNodeId: $deviceObject);

$client->call($deviceObject, $method, inputArguments: []);
```
<!-- @endcode-block -->

## What to read next

- [Operations · Calling methods](./calling-methods.md) — `call()` with
  resolved method NodeIds.
- [Recipes · Detecting server capabilities](../recipes/detecting-server-capabilities.md)
  — using path resolution to introspect what a server exposes.
