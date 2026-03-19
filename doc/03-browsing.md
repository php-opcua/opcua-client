# Browsing the Address Space

## Basic Browse

Browse returns the references (children) of a given node:

```php
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

// Browse the Objects folder (ns=0, i=85)
$references = $client->browse(NodeId::numeric(0, 85));

foreach ($references as $ref) {
    echo sprintf(
        "%s (NodeId: ns=%d;i=%s, Class: %s)\n",
        $ref->getDisplayName(),
        $ref->getNodeId()->getNamespaceIndex(),
        $ref->getNodeId()->getIdentifier(),
        $ref->getNodeClass()->name,
    );
}
```

## Browse Parameters

```php
use Gianfriaur\OpcuaPhpClient\Types\BrowseDirection;

$references = $client->browse(
    nodeId: NodeId::numeric(0, 85),
    direction: BrowseDirection::Forward,          // Forward, Inverse, or Both
    referenceTypeId: NodeId::numeric(0, 33),     // HierarchicalReferences (default)
    includeSubtypes: true,                       // Include subtypes of reference
    nodeClassMask: 0,                            // 0=All classes
);
```

**BrowseDirection enum:**

| Case | Value | Description |
|------|-------|-------------|
| `BrowseDirection::Forward` | `0` | Forward references (children) |
| `BrowseDirection::Inverse` | `1` | Inverse references (parents) |
| `BrowseDirection::Both` | `2` | Both directions |

**Common reference type NodeIds:**
- `NodeId::numeric(0, 33)` - HierarchicalReferences
- `NodeId::numeric(0, 35)` - Organizes
- `NodeId::numeric(0, 47)` - HasComponent
- `NodeId::numeric(0, 46)` - HasProperty
- `NodeId::numeric(0, 40)` - HasTypeDefinition

**NodeClass mask (bitmask):**
- `0` - All classes
- `1` - Object
- `2` - Variable
- `4` - Method
- `8` - ObjectType
- `16` - VariableType
- `32` - ReferenceType
- `64` - DataType
- `128` - View

## Browse All (with automatic continuation)

`browseAll()` is like `browse()` but automatically follows all continuation points, returning the complete list of references in a single call:

```php
$refs = $client->browseAll(NodeId::numeric(0, 85));
// All references, even if the server paginates them
```

This is equivalent to the manual continuation loop below, but handled transparently.

## Browse with Continuation (manual)

For large result sets, the server may return a continuation point. You can handle it manually if you need fine-grained control:

```php
$result = $client->browseWithContinuation(NodeId::numeric(0, 85));

$allRefs = $result['references'];
$continuationPoint = $result['continuationPoint'];

// Fetch remaining results
while ($continuationPoint !== null) {
    $next = $client->browseNext($continuationPoint);
    $allRefs = array_merge($allRefs, $next['references']);
    $continuationPoint = $next['continuationPoint'];
}
```

## ReferenceDescription Properties

Each reference returned by browse contains:

```php
$ref->getReferenceTypeId();   // NodeId - type of relationship
$ref->isForward();            // bool - direction of reference
$ref->getNodeId();            // NodeId - target node
$ref->getBrowseName();        // QualifiedName - browse name
$ref->getDisplayName();       // LocalizedText - human-readable name
$ref->getNodeClass();         // NodeClass enum
$ref->getTypeDefinition();    // ?NodeId - type definition node
```

## Common Well-Known NodeIds

| Name | NodeId | Description |
|------|--------|-------------|
| Root | `NodeId::numeric(0, 84)` | Root of the address space |
| Objects | `NodeId::numeric(0, 85)` | Objects folder |
| Types | `NodeId::numeric(0, 86)` | Types folder |
| Views | `NodeId::numeric(0, 87)` | Views folder |
| Server | `NodeId::numeric(0, 2253)` | Server object |
| ServerStatus | `NodeId::numeric(0, 2256)` | Server status |
| ServiceLevel | `NodeId::numeric(0, 2267)` | Service level |

## Recursive Browse

`browseRecursive()` navigates the address space recursively starting from a node, building a tree of `BrowseNode` objects. It automatically handles continuation points at each level and includes **cycle detection** to prevent infinite loops on circular references.

```php
use Gianfriaur\OpcuaPhpClient\Types\BrowseNode;

// Browse 2 levels deep from the Objects folder
$tree = $client->browseRecursive(NodeId::numeric(0, 85), maxDepth: 2);

foreach ($tree as $node) {
    echo $node->getDisplayName() . "\n";
    foreach ($node->getChildren() as $child) {
        echo "  " . $child->getDisplayName() . "\n";
    }
}
```

### Configurable Default Depth

The default `maxDepth` (10) can be configured globally via `setDefaultBrowseMaxDepth()`:

```php
$client = new Client();
$client->setDefaultBrowseMaxDepth(20); // all browseRecursive() calls will use 20

$client->connect('opc.tcp://localhost:4840');

$tree = $client->browseRecursive($nodeId);                // uses 20
$tree = $client->browseRecursive($nodeId, maxDepth: 3);   // override to 3
```

### Parameters

```php
$tree = $client->browseRecursive(
    nodeId: NodeId::numeric(0, 85),
    direction: BrowseDirection::Forward,              // Forward, Inverse, or Both
    maxDepth: 3,                                      // default: 10 (configurable), use -1 for unlimited
    referenceTypeId: NodeId::numeric(0, 33),          // HierarchicalReferences
    includeSubtypes: true,
    nodeClassMask: 0,                                 // 0=All classes
);
```

| Parameter | Default | Description |
|-----------|---------|-------------|
| `nodeId` | (required) | Starting node |
| `direction` | `BrowseDirection::Forward` | Browse direction enum |
| `maxDepth` | `null` (uses configured default: 10) | Maximum recursion depth. Use `-1` for unlimited (capped at 256) |
| `referenceTypeId` | `null` | Filter by reference type |
| `includeSubtypes` | `true` | Include subtypes of the reference type |
| `nodeClassMask` | `0` | Filter by node class (bitmask, 0 = all) |

### Depth Limits

| `maxDepth` | Behavior |
|------------|----------|
| `null` (default) | Uses configured default (10, or value set via `setDefaultBrowseMaxDepth()`) |
| `1` | Only direct children, no recursion |
| `-1` | Unlimited depth (capped at 256 internally) |
| Any value > 256 | Capped at 256 |

> **Disclaimer:** Setting `maxDepth` to a high value (or `-1`) can have serious consequences:
>
> - **Performance:** Each level generates one browse request per node. A server with thousands of nodes will result in thousands of sequential HTTP-like round-trips, which can take minutes or longer.
> - **Memory:** The entire tree is built in memory. Large address spaces can consume significant RAM.
> - **Server load:** Massive recursive browsing can overwhelm the server, especially on resource-constrained PLCs or embedded devices.
> - **Circular references:** OPC UA address spaces can contain circular references (e.g., TypeDefinition nodes pointing back to each other). The built-in cycle detection prevents infinite loops, but the traversal may still visit a very large number of nodes before all cycles are resolved.
>
> For production use, always set `maxDepth` to the minimum value required for your use case. Start with a small value and increase only if needed.

### Cycle Detection

The method tracks all visited NodeIds during traversal. When a node is encountered that has already been visited, it is included in the tree as a leaf (without children) to avoid infinite recursion. This is consistent with how other OPC UA libraries (open62541, node-opcua, OPC Foundation .NET SDK) handle recursive browsing.

### BrowseNode

Each node in the tree is a `BrowseNode` that wraps a `ReferenceDescription` and holds its children:

```php
$node->getReference();    // ReferenceDescription - the original reference
$node->getNodeId();       // NodeId
$node->getDisplayName();  // LocalizedText
$node->getBrowseName();   // QualifiedName
$node->getNodeClass();    // NodeClass enum
$node->getChildren();     // BrowseNode[] - child nodes
$node->hasChildren();     // bool
```

### Full Tree Print Example

```php
function printTree(array $nodes, int $indent = 0): void
{
    foreach ($nodes as $node) {
        echo str_repeat('  ', $indent) . $node->getDisplayName() . "\n";
        printTree($node->getChildren(), $indent + 1);
    }
}

$tree = $client->browseRecursive(NodeId::numeric(0, 85), maxDepth: 3);
printTree($tree);
```

### Configuration Methods

| Method | Description |
|--------|-------------|
| `setDefaultBrowseMaxDepth(int)` | Set the default maxDepth for all `browseRecursive()` calls. Default: 10. Use -1 for unlimited. |
| `getDefaultBrowseMaxDepth(): int` | Get the current default maxDepth. |

## Path Resolution

Instead of navigating the address space manually, you can resolve a human-readable path to a NodeId using `resolveNodeId()`:

```php
// Resolve a path to a NodeId
$nodeId = $client->resolveNodeId('/Objects/Server/ServerStatus/State');

// Then use it for read/write/subscribe
$dataValue = $client->read($nodeId);
```

This uses the OPC UA `TranslateBrowsePathsToNodeIds` service internally — a single request, much faster than browsing node by node.

### Path Format

- Segments are separated by `/`
- Leading `/` is optional (`/Objects/Server` and `Objects/Server` are equivalent)
- Default starting node is Root (`ns=0;i=84`)
- For non-zero namespaces, use `ns:Name` format: `"Objects/2:MyPLC/2:Temperature"`

```php
// Simple path
$nodeId = $client->resolveNodeId('/Objects/Server');

// Path with namespaced segments
$nodeId = $client->resolveNodeId('/Objects/2:MyPLC/2:Temperature');

// Custom starting node (start from Objects instead of Root)
$nodeId = $client->resolveNodeId('Server', NodeId::numeric(0, 85));
```

### translateBrowsePaths (Advanced)

For full control over the OPC UA `TranslateBrowsePathsToNodeIds` service, use `translateBrowsePaths()`:

```php
use Gianfriaur\OpcuaPhpClient\Types\QualifiedName;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

$results = $client->translateBrowsePaths([
    [
        'startingNodeId' => NodeId::numeric(0, 85), // Objects
        'relativePath' => [
            ['targetName' => new QualifiedName(0, 'Server')],
            ['targetName' => new QualifiedName(0, 'ServerStatus')],
        ],
    ],
]);

if (StatusCode::isGood($results[0]['statusCode'])) {
    $targetNodeId = $results[0]['targets'][0]['targetId'];
}
```

Each element in the relative path supports:

| Field | Default | Description |
|-------|---------|-------------|
| `targetName` | (required) | `QualifiedName` of the target node |
| `referenceTypeId` | HierarchicalReferences | Reference type to follow |
| `isInverse` | `false` | Follow inverse references |
| `includeSubtypes` | `true` | Include subtypes of the reference type |

Multiple paths can be resolved in a single request for efficiency.
