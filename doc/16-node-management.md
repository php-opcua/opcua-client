# Node Management

OPC UA servers that support dynamic address space modification allow clients to add and remove nodes and references at runtime. This library provides four methods for these operations.

> **Note:** Not all OPC UA servers support node management. Servers that do not will return `BadServiceUnsupported`. Check your server's capabilities before relying on these operations.

## Adding Nodes

```php
use PhpOpcua\Client\Types\AddNodesResult;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\QualifiedName;
use PhpOpcua\Client\Types\StatusCode;

// Add a Variable node under the Objects folder
$results = $client->addNodes([
    [
        'parentNodeId'       => 'i=85',                          // Objects folder
        'referenceTypeId'    => 'i=35',                          // Organizes
        'requestedNewNodeId' => 'ns=2;s=MyVariable',             // desired NodeId
        'browseName'         => new QualifiedName(2, 'MyVariable'),
        'nodeClass'          => NodeClass::Variable,
        'typeDefinition'     => 'i=63',                          // BaseDataVariableType
        'dataType'           => NodeId::numeric(0, 6),           // Int32
        'accessLevel'        => 3,                               // CurrentRead | CurrentWrite
    ],
]);

foreach ($results as $result) {
    if (StatusCode::isGood($result->statusCode)) {
        echo "Created: {$result->addedNodeId}\n";
    }
}
```

Each item in the array produces one `AddNodesResult` with a `statusCode` and the server-assigned `addedNodeId`.

### Supported Node Classes

All 8 OPC UA node classes are supported. The library encodes class-specific attributes automatically:

| Node Class | Extra Fields |
|---|---|
| **Object** | `eventNotifier` |
| **Variable** | `dataType`, `valueRank`, `arrayDimensions`, `accessLevel`, `userAccessLevel`, `minimumSamplingInterval`, `historizing`, `value` |
| **Method** | `executable`, `userExecutable` |
| **ObjectType** | `isAbstract` |
| **VariableType** | `dataType`, `valueRank`, `arrayDimensions`, `isAbstract`, `value` |
| **ReferenceType** | `isAbstract`, `symmetric`, `inverseName` |
| **DataType** | `isAbstract` |
| **View** | `containsNoLoops`, `eventNotifier` |

Common fields for all classes: `displayName`, `description`, `writeMask`, `userWriteMask`. If `displayName` is omitted, the browse name is used.

### Adding an Object Node

```php
$results = $client->addNodes([
    [
        'parentNodeId'       => 'i=85',
        'referenceTypeId'    => 'i=35',
        'requestedNewNodeId' => 'ns=2;s=MyFolder',
        'browseName'         => new QualifiedName(2, 'MyFolder'),
        'nodeClass'          => NodeClass::Object,
        'typeDefinition'     => 'i=61',  // FolderType
    ],
]);
```

### Adding Multiple Nodes

```php
$results = $client->addNodes([
    [
        'parentNodeId'       => 'i=85',
        'referenceTypeId'    => 'i=35',
        'requestedNewNodeId' => 'ns=2;s=Node1',
        'browseName'         => new QualifiedName(2, 'Node1'),
        'nodeClass'          => NodeClass::Variable,
        'typeDefinition'     => 'i=63',
    ],
    [
        'parentNodeId'       => 'i=85',
        'referenceTypeId'    => 'i=35',
        'requestedNewNodeId' => 'ns=2;s=Node2',
        'browseName'         => new QualifiedName(2, 'Node2'),
        'nodeClass'          => NodeClass::Variable,
        'typeDefinition'     => 'i=63',
    ],
]);

// $results[0] corresponds to Node1, $results[1] to Node2
```

## Deleting Nodes

```php
$statusCodes = $client->deleteNodes([
    ['nodeId' => 'ns=2;s=MyVariable', 'deleteTargetReferences' => true],
    ['nodeId' => 'ns=2;s=MyFolder'],  // deleteTargetReferences defaults to true
]);

foreach ($statusCodes as $code) {
    echo StatusCode::isGood($code) ? "Deleted\n" : "Failed: " . StatusCode::getName($code) . "\n";
}
```

## Adding References

```php
$statusCodes = $client->addReferences([
    [
        'sourceNodeId'    => 'ns=2;s=MyFolder',
        'referenceTypeId' => NodeId::numeric(0, 35),  // Organizes
        'isForward'       => true,
        'targetNodeId'    => 'ns=2;s=MyVariable',
        'targetNodeClass' => NodeClass::Variable,
    ],
]);
```

## Deleting References

```php
$statusCodes = $client->deleteReferences([
    [
        'sourceNodeId'       => 'ns=2;s=MyFolder',
        'referenceTypeId'    => NodeId::numeric(0, 35),
        'isForward'          => true,
        'targetNodeId'       => 'ns=2;s=MyVariable',
        'deleteBidirectional' => true,  // also remove the inverse reference
    ],
]);
```

## Full Example: Create, Use, and Clean Up

```php
use PhpOpcua\Client\Types\BuiltinType;

// 1. Create a folder and a variable
$results = $client->addNodes([
    [
        'parentNodeId'       => 'i=85',
        'referenceTypeId'    => 'i=35',
        'requestedNewNodeId' => 'ns=2;s=TempFolder',
        'browseName'         => new QualifiedName(2, 'TempFolder'),
        'nodeClass'          => NodeClass::Object,
        'typeDefinition'     => 'i=61',
    ],
    [
        'parentNodeId'       => 'ns=2;s=TempFolder',
        'referenceTypeId'    => 'i=47',  // HasComponent
        'requestedNewNodeId' => 'ns=2;s=TempValue',
        'browseName'         => new QualifiedName(2, 'TempValue'),
        'nodeClass'          => NodeClass::Variable,
        'typeDefinition'     => 'i=63',
        'dataType'           => NodeId::numeric(0, 11),  // Double
        'accessLevel'        => 3,
    ],
]);

// 2. Write and read
$client->write('ns=2;s=TempValue', 23.5, BuiltinType::Double);
$dv = $client->read('ns=2;s=TempValue');
echo $dv->getValue(); // 23.5

// 3. Clean up
$client->deleteNodes([
    ['nodeId' => 'ns=2;s=TempValue'],
    ['nodeId' => 'ns=2;s=TempFolder'],
]);
```

## Error Handling

Common status codes returned by node management operations:

| Status Code | Meaning |
|---|---|
| `0x00000000` (Good) | Operation succeeded |
| `0x800B0000` (BadServiceUnsupported) | Server does not support this service |
| `0x80340000` (BadNodeIdUnknown) | Node does not exist (delete) |
| `0x80330000` (BadNodeIdExists) | Requested NodeId already exists (add) |
| `0x80480000` (BadParentNodeIdInvalid) | Parent node not found or invalid |
| `0x80620000` (BadReferenceNotAllowed) | Reference type not valid for the target |
