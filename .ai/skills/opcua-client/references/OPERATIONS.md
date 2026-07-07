# Operations reference

Every OPC UA service set the client speaks, organised by what you're trying to accomplish.

## Read a single value

```php
$dv = $client->read('i=2259');                // Server.ServerStatus.State

$dv->getValue();                              // unwrapped variant value (int, string, DateTimeImmutable, …)
$dv->statusCode;                              // 0 (Good) or non-zero Uncertain/Bad
$dv->sourceTimestamp;                         // DateTimeImmutable on the device
$dv->serverTimestamp;                         // DateTimeImmutable on the OPC UA server
```

Signature: `read(NodeId|string $nodeId, int $attributeId = AttributeId::Value, bool $refresh = false): DataValue`.

- `attributeId` — an int; defaults to `AttributeId::Value`. For metadata pass the int constants `AttributeId::DisplayName`, `AttributeId::BrowseName`, `AttributeId::DataType`, `AttributeId::NodeClass`, etc. (`AttributeId` is a const-holder class, not an enum.)
- `refresh: true` — for metadata attributes, bypass the metadata cache AND update it with the fresh read.
- There is no `useCache` parameter: the `Value` attribute is never cached, and metadata caching is governed by the client's read-metadata-cache setting plus `$refresh`.

## Read many values (fluent builder — preferred)

```php
$results = $client->readMulti()
    ->node('i=2259')->value()
    ->node('ns=2;s=Temp')->value()
    ->node('ns=2;s=Temp')->displayName()
    ->node('ns=2;s=Temp')->dataType()
    ->execute();

foreach ($results as $i => $dv) {
    echo "$i: " . $dv->getValue() . "\n";
}
```

The builder is the idiomatic style. Each `->node()` opens a node section; chained attribute calls (`->value()`, `->displayName()`, etc.) accumulate. Results come back in the order requested.

**Auto-batching**: if your list exceeds the server's `MaxNodesPerRead`, the client transparently splits into batches. No code change needed.

Array form (legacy, still supported): `$client->readMulti([['nodeId' => 'i=2259']])`.

## Write a single value

Two flavors:

```php
// Auto-detect type via read-before-write (cached, type-safe)
$status = $client->write('ns=2;s=Setpoint', 42.5);

// Explicit type (faster, no round-trip)
use PhpOpcua\Client\Types\BuiltinType;
$status = $client->write('ns=2;s=Setpoint', 42.5, BuiltinType::Double);
```

Auto-detect (no explicit `$type`) performs a read-before-write to discover the type. It raises:
- `WriteTypeDetectionException` — when write-type auto-detection is disabled and no explicit type was given, OR when the read returns no value/Variant to detect a type from.

Passing an explicit `BuiltinType` is taken at face value: the type is used as-is with no read and no validation against the node's actual type, so there is no mismatch detection. (A `WriteTypeMismatchException` class exists in the codebase but is never thrown by `write()`.)

When the user knows the type, generate code with explicit `BuiltinType::*` — it skips the read-before-write round trip.

## Write many values

```php
$results = $client->writeMulti()
    ->node('ns=2;s=A')->value(3.14)                          // auto-detect
    ->node('ns=2;s=B')->typed('Hello', BuiltinType::String)  // explicit
    ->execute();
```

Returns `int[]` (status codes, one per write).

## Browse

```php
// One level — default Forward, HierarchicalReferences
foreach ($client->browse('i=85') as $ref) {            // i=85 = Objects folder
    echo "{$ref->displayName} ({$ref->nodeId})\n";
}

// Custom direction / reference type
use PhpOpcua\Client\Types\BrowseDirection;
$client->browse('ns=2;s=Plant', BrowseDirection::Inverse);

// Recursive — returns a BrowseNode[] (top-level nodes; each has ->getChildren())
$nodes = $client->browseRecursive('i=85', maxDepth: 5);
foreach ($nodes as $node) {
    foreach ($node->getChildren() as $child) { /* ... */ }
}

// Cross-batches of continuation points automatically
$client->browseAll('i=85');                             // forces full traversal
```

`ReferenceDescription` exposes: `referenceTypeId`, `isForward`, `nodeId`, `browseName`, `displayName`, `nodeClass`, `typeDefinition`.

## Resolve a path

```php
// Single browse path
$nodeId = $client->resolveNodeId('/Objects/MyPLC/Sensors/Temperature');
// Returns: NodeId (throws ServiceException if the path cannot be resolved or yields no targets)

// Multiple paths via the BrowsePath service (more efficient for >1 path)
$results = $client->translateBrowsePaths()
    ->from('i=85')->path('MyPLC', 'Sensors', 'Temperature')
    ->from('i=85')->path('MyPLC', 'Setpoints', 'Target')
    ->execute();
```

`resolveNodeId()` uses the TranslateBrowsePaths service under the hood; the default starting point is the Root node (`ns=0;i=84`) unless `$startingNodeId` is supplied.

## Call a method

```php
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\BuiltinType;

$result = $client->call(
    objectId: 'ns=2;s=MyDevice',
    methodId: 'ns=2;s=MyDevice/StartProcess',
    inputArguments: [
        new Variant(BuiltinType::String, 'recipe-001'),
        new Variant(BuiltinType::Int32, 42),
    ],
);

if ($result->statusCode === 0) {
    foreach ($result->outputArguments as $arg) {
        echo $arg->getValue() . "\n";
    }
}
```

`CallResult` (final readonly): `statusCode` (int), `inputArgumentResults` (int[]), `outputArguments` (Variant[]).

## Subscribe to data changes

```php
$sub = $client->createSubscription(
    publishingInterval: 500.0,
    lifetimeCount: 2400,
    maxKeepAliveCount: 10,
);
// $sub->subscriptionId

$items = $client->createMonitoredItems($sub->subscriptionId)
    ->add('ns=2;s=Sensors/Temp')->samplingInterval(500.0)->queueSize(10)
    ->add('ns=2;s=Sensors/Pressure')
    ->execute();
// $items[0]->monitoredItemId

// Publish loop
use PhpOpcua\Client\Module\Subscription\DataChangeNotification;
use PhpOpcua\Client\Module\Subscription\EventNotification;

while (true) {
    $response = $client->publish();
    foreach ($response->notifications as $notif) {
        if ($notif instanceof DataChangeNotification) {
            echo "{$notif->clientHandle}: {$notif->dataValue->getValue()}\n";
        } elseif ($notif instanceof EventNotification) {
            echo "{$notif->clientHandle}: " . count($notif->eventFields) . " event fields\n";
        }
    }
    if ($response->moreNotifications === false) {
        sleep(1);                                      // simple pacing
    }
}
```

- `publish()` blocks until the server has notifications or the publishing interval elapses
- `PublishResult` (final readonly): `subscriptionId` (int), `sequenceNumber` (int), `moreNotifications` (bool), `notifications` (`array<int, DataChangeNotification|EventNotification>`), `availableSequenceNumbers` (int[])
- Discriminate notification objects with `instanceof`. A `DataChangeNotification` has `->clientHandle` (int) and `->dataValue` (DataValue) — there is no `monitoredItemId`. An `EventNotification` has `->clientHandle` (int) and `->eventFields` (Variant[]) instead of a `dataValue`

### Subscribe to events / alarms

Subscribe to events by calling `$client->createEventMonitoredItem($subscriptionId, $nodeId, $selectFields)`, where `$selectFields` is a `string[]` of event-field BrowseNames (default `['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity']`). The event filter is built internally from these field names — there is no public `EventFilter` builder class and no `Module/Subscription/Filters/` directory. The client auto-deduces alarm vs data-change from the notification fields and dispatches the corresponding PSR-14 event (`DataChangeReceived`, `EventNotificationReceived`, `AlarmEventReceived`, `AlarmActivated`, `AlarmDeactivated`).

### Transfer & republish

```php
$transferResult = $client->transferSubscriptions([$sub->subscriptionId]);
// Take ownership of an existing subscription from another client.

$retransmitted = $client->republish($sub->subscriptionId, $sequenceNumber);
// Force the server to re-send a specific notification message.
```

## History

### Read raw data points

```php
$dvs = $client->historyReadRaw(
    'ns=2;s=Sensors/Temp',
    startTime: new DateTimeImmutable('-1 hour'),
    endTime: new DateTimeImmutable(),
    numValuesPerNode: 1000,
);

foreach ($dvs as $dv) {
    echo "[{$dv->sourceTimestamp->format('H:i:s.v')}] {$dv->getValue()}\n";
}
```

### Read processed (aggregated)

```php
use PhpOpcua\Client\Types\NodeId;

// The server-side aggregate is identified by the OPC UA NodeId of the aggregate
// function, e.g. the standard "Average" function node ns=0;i=2341.
$aggregateNodeId = NodeId::numeric(0, 2341);

$dvs = $client->historyReadProcessed(
    'ns=2;s=Sensors/Temp',
    startTime: new DateTimeImmutable('-1 hour'),
    endTime: new DateTimeImmutable(),
    processingInterval: 60_000.0,                            // 1-minute buckets (ms)
    aggregateType: $aggregateNodeId,
);
```

### Read at specific timestamps

```php
$dvs = $client->historyReadAtTime('ns=2;s=Temp', timestamps: [
    new DateTimeImmutable('-1 hour'),
    new DateTimeImmutable('-30 minutes'),
]);
```

### Read history of events

There is no `historyReadEvents()` method in this version. Historical events can only be written (Insert / Replace / Update / Delete — see "History UPDATE" below); they cannot be read back through a dedicated history-read helper. To react to events as they occur, use a live Event subscription instead: add an event monitored item via `createEventMonitoredItem(...)` and handle the resulting `EventNotification` objects from `publish()`.

## History UPDATE (v4.4.0)

Insert / Replace / Update / Delete on historical data and event timeseries.

```php
use PhpOpcua\Client\Module\History\PerformUpdateType;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\BuiltinType;

// Insert (fails if a value already exists at that timestamp).
// DataValue's constructor takes the Variant, statusCode, sourceTimestamp, serverTimestamp.
$client->historyInsertData('ns=2;s=Sensors/Temp', [
    new DataValue(new Variant(BuiltinType::Double, 22.1), 0, $t1),
    new DataValue(new Variant(BuiltinType::Double, 22.3), 0, $t2),
]);

// Replace (fails if no value exists)
$client->historyReplaceData('ns=2;s=Sensors/Temp', [...]);

// Update (insert-or-replace)
$client->historyUpdateData('ns=2;s=Sensors/Temp', [...]);

// Delete a range
$client->historyDeleteRawModified(
    'ns=2;s=Sensors/Temp',
    startTime: $t1,
    endTime: $t2,
    isDeleteModified: false,                                 // false = delete actual values; true = delete modified-history overlay
);

// Delete specific timestamps
$client->historyDeleteAtTime('ns=2;s=Sensors/Temp', timestamps: [$t1, $t2]);

// Event flavours — Insert / Replace / Update / Delete
$client->historyInsertEvent('ns=2;s=AlarmSource', selectFields, eventList);
$client->historyDeleteEvent('ns=2;s=AlarmSource', eventIds);
```

Each returns per-operation status codes as an `int[]` (one entry per DataValue / timestamp / event), except `historyDeleteRawModified`, which returns a single overall status `int`. (Internally these are decoded from a `HistoryUpdateResult` DTO, but the public methods return only the unwrapped status codes.) Five new PSR-14 events: `HistoryDataUpdated`, `HistoryDataDeleted`, `HistoryEventUpdated`, `HistoryEventDeleted`.

## Aggregate (v4.4.0, client-side)

Compute aggregates on raw DataValue buffers without involving the server's aggregate service:

```php
use PhpOpcua\Client\Module\Aggregate\AggregateFunction;

$rawDvs = $client->historyReadRaw('ns=2;s=Temp', $start, $end);

$values = $client->aggregate(
    rawValues: $rawDvs,
    startTime: $start,
    endTime: $end,
    processingIntervalMs: 60_000.0,                          // 60-second buckets (float)
    function: AggregateFunction::Interpolate,                // or Minimum, Maximum, Average, Count
);
// Returns DataValue[] — one computed DataValue per bucket.

// Shortcut: fetch + aggregate in one call
$values = $client->historyAggregate(
    'ns=2;s=Temp',
    startTime: $start,
    endTime: $end,
    processingIntervalMs: 60_000.0,
    function: AggregateFunction::Average,
);
// Returns DataValue[].
```

Supports `Interpolate`, `Minimum`, `Maximum`, `Average`, `Count`. Other Part 13 aggregates (TimeAverage, Range, Delta, Total, etc.) are in the core ROADMAP. `AggregateComputed` event dispatched after each call.

## Node management

Dynamic address-space modification (when the server supports it):

```php
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\QualifiedName;
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\BuiltinType;

$results = $client->addNodes([
    [
        'parentNodeId' => 'ns=2;s=MyFolder',
        'referenceTypeId' => 'i=35',                         // Organizes
        'requestedNewNodeId' => 'ns=2;s=NewVariable',
        'browseName' => new QualifiedName(2, 'NewVariable'), // QualifiedName object, NOT a string
        'nodeClass' => NodeClass::Variable,
        'typeDefinition' => 'i=63',                          // BaseDataVariableType (REQUIRED)
        // For a Variable, typically also:
        'dataType' => NodeId::numeric(0, 12),                // String datatype; must be a NodeId instance (NOT a NodeId-string)
        'value' => new Variant(BuiltinType::String, 'initial'),
        // 8 node classes total: Object, Variable, Method, ObjectType,
        //   VariableType, ReferenceType, DataType, View
    ],
]);
// parentNodeId/referenceTypeId/requestedNewNodeId/typeDefinition accept a NodeId or
// NodeId-string (coerced via fromArray()); browseName and dataType must be instances
// (QualifiedName and NodeId respectively) — fromArray() does NOT coerce dataType.
// $results[0]->statusCode + $results[0]->addedNodeId

$statuses = $client->deleteNodes([
    ['nodeId' => 'ns=2;s=OldVariable', 'deleteTargetReferences' => true],
]);

$statuses = $client->addReferences([[
    'sourceNodeId' => 'ns=2;s=A',
    'referenceTypeId' => 'i=46',                             // HasProperty
    'isForward' => true,
    'targetNodeId' => 'ns=2;s=B',
    'targetNodeClass' => NodeClass::Variable,                // required
    // 'targetServerUri' => null,                            // optional
]]);

// deleteReferences uses a slightly different shape: no targetNodeClass; an optional
// 'deleteBidirectional' => bool instead of 'targetServerUri'.
$statuses = $client->deleteReferences([[
    'sourceNodeId' => 'ns=2;s=A',
    'referenceTypeId' => 'i=46',
    'isForward' => true,
    'targetNodeId' => 'ns=2;s=B',
    // 'deleteBidirectional' => true,                        // optional
]]);
```

`AddNodesResult[]` for `addNodes()`, `int[]` for the others.

## File Transfer (v4.4.0)

OPC UA Part 5 file transfer service set. Targets server-side `FileType` nodes:

```php
use PhpOpcua\Client\Module\FileTransfer\OpenFileMode;

// Read a file (every call takes the file node id; openFile returns the handle)
$handle = $client->openFile('ns=2;s=Files/Config', OpenFileMode::Read);
$bytes  = $client->readFile('ns=2;s=Files/Config', $handle, 4096);
$client->closeFile('ns=2;s=Files/Config', $handle);

// Write — combine modes via int values or OpenFileMode::toByte() (enum cases cannot be OR'd directly)
$mode   = OpenFileMode::toByte(OpenFileMode::Write, OpenFileMode::EraseExisting);
$handle = $client->openFile('ns=2;s=Files/Upload', $mode);
$client->writeFile('ns=2;s=Files/Upload', $handle, $data);
$client->closeFile('ns=2;s=Files/Upload', $handle);

// Directory operations (FileDirectoryType)
$newDirNode = $client->createDirectory('ns=2;s=Files', 'NewDir');            // returns NodeId
$created    = $client->createFileInDirectory('ns=2;s=Files', 'newfile.bin'); // returns CreateFileResult($fileNodeId, $fileHandle)
$client->deleteFileSystemObject('ns=2;s=Files', 'ns=2;s=Files/oldfile.bin'); // directory node + target node
```

See the module README in `src/Module/FileTransfer/` for the full surface.

## Server info

```php
$build = $client->getServerBuildInfo();
echo "{$build->productName} {$build->softwareVersion} (build {$build->buildNumber})\n";
echo "Manufacturer: {$build->manufacturerName}\n";
echo "Built: {$build->buildDate?->format('Y-m-d')}\n";

// Individual accessors (one readMulti() call each)
$client->getServerProductName();
$client->getServerSoftwareVersion();
$client->getServerBuildNumber();
$client->getServerBuildDate();
$client->getServerManufacturerName();
```

## Endpoint discovery

```php
$endpoints = $client->getEndpoints('opc.tcp://localhost:4840');
foreach ($endpoints as $ep) {
    echo "{$ep->endpointUrl} — {$ep->securityPolicyUri} / {$ep->securityMode}\n";
    foreach ($ep->userIdentityTokens as $tok) {
        echo "  - {$tok->tokenType} (policy: {$tok->policyId})\n";
    }
}
```

Useful for: discovering security policies a server supports before configuring the client. Often called once during onboarding, cached.

## Type discovery (custom structures)

```php
$client->discoverDataTypes();                            // walks the server's DataType hierarchy

// Now reads on nodes with custom Structure DataTypes return typed PHP objects
// via the auto-registered codec (no manual ExtensionObjectCodec needed for spec-compliant types).
```

OPC UA 1.04+ servers expose their custom Structure definitions via the `DataTypeDefinition` attribute. `TypeDiscoveryModule` reads them, registers codecs in the per-client `ExtensionObjectRepository`. Result is cached by default — subsequent connects skip the round-trip.

For older servers or non-standard types, register codecs manually:

```php
use PhpOpcua\Client\Encoding\ExtensionObjectCodec;
use PhpOpcua\Client\Types\NodeId;

// $encodingId is the OPC UA type NodeId the codec is keyed by.
$encodingId = NodeId::numeric(2, 1234);
// Second arg may be a codec instance or a class-string<ExtensionObjectCodec>.
$client->getExtensionObjectRepository()->register($encodingId, new MyCustomCodec());
```
