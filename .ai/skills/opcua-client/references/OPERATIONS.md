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

Signature: `read(NodeId|string $nodeId, ?AttributeId $attr = AttributeId::Value, bool $useCache = true, bool $refresh = false): DataValue`.

- `useCache: false` — skip the cache, always go to the wire (Value attribute is never cached anyway; this matters for metadata)
- `refresh: true` — bypass cache AND update it with the fresh read
- `attr` — by default `Value`. For metadata: `AttributeId::DisplayName`, `BrowseName`, `DataType`, `NodeClass`, etc.

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

Auto-detect raises:
- `WriteTypeDetectionException` — node has no resolvable `DataType` attribute (e.g. doesn't exist)
- `WriteTypeMismatchException` — explicit `$type` parameter conflicts with detected type (`expectedType`, `givenType` on the exception)

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

// Recursive — returns BrowseNode tree
$tree = $client->browseTree('i=85', maxDepth: 5);
foreach ($tree->getChildren() as $child) { /* ... */ }

// Cross-batches of continuation points automatically
$client->browseAll('i=85');                             // forces full traversal
```

`ReferenceDescription` exposes: `referenceTypeId`, `isForward`, `nodeId`, `browseName`, `displayName`, `nodeClass`, `typeDefinition`.

## Resolve a path

```php
// Single browse path
$nodeId = $client->resolveNodeId('/Objects/MyPLC/Sensors/Temperature');
// Returns: NodeId|null

// Multiple paths via the BrowsePath service (more efficient for >1 path)
$results = $client->translateBrowsePaths()
    ->from('i=85')->path('MyPLC', 'Sensors', 'Temperature')
    ->from('i=85')->path('MyPLC', 'Setpoints', 'Target')
    ->execute();
```

`resolveNodeId()` uses BrowsePath under the hood with the `Server` namespace as default starting point.

## Call a method

```php
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\BuiltinType;

$result = $client->callMethod(
    objectId: 'ns=2;s=MyDevice',
    methodId: 'ns=2;s=MyDevice/StartProcess',
    inputs: [
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

`CallResult`: `statusCode`, `inputArgumentResults` (int[]), `outputArguments` (DataValue[]).

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
while (true) {
    $response = $client->publish();
    foreach ($response->notifications as $notif) {
        echo "{$notif['monitoredItemId']}: {$notif['dataValue']->getValue()}\n";
    }
    if ($response->moreNotifications === false) {
        sleep(1);                                      // simple pacing
    }
}
```

- `publish()` blocks until the server has notifications or the publishing interval elapses
- `PublishResult`: `subscriptionId`, `sequenceNumber`, `moreNotifications`, `notifications` (array of `['monitoredItemId' => int, 'dataValue' => DataValue]`)
- Event notifications come back in the same `notifications` array but with `'eventFields' => Variant[]` instead of `'dataValue'`

### Subscribe to events / alarms

Add an EventFilter to the monitored item config — typically created via the EventFilter builder (see `Module/Subscription/Filters/`). The client auto-deduces alarm vs data-change from the notification fields and dispatches the corresponding PSR-14 event (`DataChangeReceived`, `EventNotificationReceived`, `AlarmEventReceived`, `AlarmActivated`, `AlarmDeactivated`).

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
    maxValues: 1000,
);

foreach ($dvs as $dv) {
    echo "[{$dv->sourceTimestamp->format('H:i:s.v')}] {$dv->getValue()}\n";
}
```

### Read processed (aggregated)

```php
use PhpOpcua\Client\Module\Aggregate\AggregateFunction;

$dvs = $client->historyReadProcessed(
    'ns=2;s=Sensors/Temp',
    start: new DateTimeImmutable('-1 hour'),
    end: new DateTimeImmutable(),
    intervalMs: 60_000,                                      // 1-minute buckets
    aggregate: AggregateFunction::Average,
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

```php
$events = $client->historyReadEvents(
    'ns=2;s=Source',
    start: $start,
    end: $end,
    eventFilter: $filter,                                    // EventFilter builder
);
```

## History UPDATE (v4.4.0)

Insert / Replace / Update / Delete on historical data and event timeseries.

```php
use PhpOpcua\Client\Module\History\PerformUpdateType;

// Insert (fails if a value already exists at that timestamp)
$client->historyInsertData('ns=2;s=Sensors/Temp', [
    DataValue::of(22.1, BuiltinType::Double)->withSourceTimestamp($t1),
    DataValue::of(22.3, BuiltinType::Double)->withSourceTimestamp($t2),
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

All return `HistoryUpdateResult` (statusCode + per-operation status codes). Five new PSR-14 events: `HistoryDataUpdated`, `HistoryDataDeleted`, `HistoryEventUpdated`, `HistoryEventDeleted`.

## Aggregate (v4.4.0, client-side)

Compute aggregates on raw DataValue buffers without involving the server's aggregate service:

```php
use PhpOpcua\Client\Module\Aggregate\AggregateFunction;

$rawDvs = $client->historyReadRaw('ns=2;s=Temp', $start, $end);

$intervals = $client->aggregate(
    values: $rawDvs,
    startTime: $start,
    endTime: $end,
    intervalMs: 60_000,                                      // 60-second buckets
    function: AggregateFunction::Interpolate,                // or Minimum, Maximum, Average, Count
);
// Returns Interval[] with computed values per bucket.

// Shortcut: fetch + aggregate in one call
$intervals = $client->historyAggregate(
    'ns=2;s=Temp',
    start: $start,
    end: $end,
    intervalMs: 60_000,
    function: AggregateFunction::Average,
);
```

Supports `Interpolate`, `Minimum`, `Maximum`, `Average`, `Count`. Other Part 13 aggregates (TimeAverage, Range, Delta, Total, etc.) are in the core ROADMAP. `AggregateComputed` event dispatched after each call.

## Node management

Dynamic address-space modification (when the server supports it):

```php
use PhpOpcua\Client\Types\NodeClass;

$results = $client->addNodes([
    [
        'parentNodeId' => 'ns=2;s=MyFolder',
        'referenceTypeId' => 'i=35',                         // Organizes
        'requestedNewNodeId' => 'ns=2;s=NewVariable',
        'browseName' => 'NewVariable',
        'nodeClass' => NodeClass::Variable,
        // ... 8 node classes total: Object, Variable, Method, ObjectType,
        //     VariableType, ReferenceType, DataType, View
    ],
]);
// $results[0]->statusCode + $results[0]->addedNodeId

$statuses = $client->deleteNodes(['ns=2;s=OldVariable'], deleteTargetReferences: true);

$statuses = $client->addReferences([[
    'sourceNodeId' => 'ns=2;s=A',
    'referenceTypeId' => 'i=46',                             // HasProperty
    'isForward' => true,
    'targetNodeId' => 'ns=2;s=B',
]]);

$statuses = $client->deleteReferences([/* same shape */]);
```

`AddNodesResult[]` for `addNodes()`, `int[]` for the others.

## File Transfer (v4.4.0)

OPC UA Part 5 file transfer service set. Targets server-side `FileType` nodes:

```php
// Read a file
$handle = $client->fileOpen('ns=2;s=Files/Config', mode: FileOpenMode::Read);
$bytes = $client->fileRead($handle, length: 4096);
$client->fileClose($handle);

// Write
$handle = $client->fileOpen('ns=2;s=Files/Upload', mode: FileOpenMode::Write | FileOpenMode::EraseExisting);
$client->fileWrite($handle, $data);
$client->fileClose($handle);

// Directory operations (FileDirectoryType)
$client->fileCreateDirectory('ns=2;s=Files', name: 'NewDir');
$client->fileCreateFile('ns=2;s=Files', name: 'newfile.bin');
$client->fileDelete('ns=2;s=Files/oldfile.bin');
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

$client->getRepository()->register(new MyCustomCodec());
```
