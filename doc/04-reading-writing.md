# Reading & Writing Values

## Server BuildInfo

Every OPC UA server exposes build metadata under standard well-known nodes. The client provides convenience methods to read them without memorizing NodeIds:

```php
// Individual fields
$client->getServerProductName();        // ?string  (ns=0;i=2262)
$client->getServerManufacturerName();   // ?string  (ns=0;i=2263)
$client->getServerSoftwareVersion();    // ?string  (ns=0;i=2264)
$client->getServerBuildNumber();        // ?string  (ns=0;i=2265)
$client->getServerBuildDate();          // ?DateTimeImmutable (ns=0;i=2266)

// All at once — single readMulti() call
$info = $client->getServerBuildInfo();

echo $info->productName;        // e.g. "UA-.NETStandard"
echo $info->manufacturerName;   // e.g. "OPC Foundation"
echo $info->softwareVersion;    // e.g. "1.5.374.126"
echo $info->buildNumber;        // e.g. "1.5.374.126"
echo $info->buildDate;          // DateTimeImmutable
```

`getServerBuildInfo()` returns a `BuildInfo` readonly DTO. Individual methods return `null` when the server provides no value for that field.

## Reading a Value

```php
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;

// Using string format
$dataValue = $client->read('i=2259'); // ServerStatus_State

// Or with a NodeId object
$dataValue = $client->read(NodeId::numeric(0, 2259));

if (StatusCode::isGood($dataValue->statusCode)) {
    echo "Value: " . $dataValue->getValue() . "\n";
}
```

> **Events:** Every `read()` dispatches a `NodeValueRead` event. See [Events](14-events.md).

### Metadata Cache

Attributes like DisplayName, BrowseName, DataType, and NodeClass are static — they don't change at runtime. Enable metadata caching to avoid redundant server reads:

```php
// Enable on the builder before connecting
$client = ClientBuilder::create()
    ->setReadMetadataCache(true)
    ->connect('opc.tcp://localhost:4840');

// First call: reads from server, caches the result
$name = $client->read('ns=2;i=1001', AttributeId::DisplayName);

// Second call: served from cache (no server round-trip)
$name = $client->read('ns=2;i=1001', AttributeId::DisplayName);

// Force a refresh from the server
$name = $client->read('ns=2;i=1001', AttributeId::DisplayName, refresh: true);
```

**Rules:**

- **Disabled by default** — opt-in via `setReadMetadataCache(true)`.
- **Value (attribute 13) is never cached** — always reads from the server, regardless of the setting.
- **`refresh: true`** bypasses the cache and re-reads from the server, then updates the cache.
- Uses the same PSR-16 cache backend as browse and write type detection.
- `invalidateCache($nodeId)` clears all cached metadata for that node.

### Reading a Specific Attribute

By default, `read()` targets the Value attribute (id 13). You can read any attribute:

```php
use PhpOpcua\Client\Types\AttributeId;

$displayName = $client->read(NodeId::numeric(0, 2259), AttributeId::DisplayName);
$dataType = $client->read(NodeId::numeric(0, 2259), AttributeId::DataType);
```

**Common attributes:**

| Constant | Value | Description |
|----------|-------|-------------|
| `AttributeId::NodeId` | 1 | The node's NodeId |
| `AttributeId::NodeClass` | 2 | Node class |
| `AttributeId::BrowseName` | 3 | Browse name |
| `AttributeId::DisplayName` | 4 | Display name |
| `AttributeId::Description` | 5 | Description |
| `AttributeId::Value` | 13 | The value (default) |
| `AttributeId::DataType` | 14 | Data type NodeId |
| `AttributeId::AccessLevel` | 17 | Access level bitmask |

### Reading Multiple Values

```php
// Fluent builder
$results = $client->readMulti()
    ->node('i=2259')->value()
    ->node('i=2267')->value()
    ->node('ns=2;s=Temperature')->value()
    ->execute();

foreach ($results as $dataValue) {
    if (StatusCode::isGood($dataValue->statusCode)) {
        echo $dataValue->getValue() . "\n";
    }
}

// Or with array (still works)
$results = $client->readMulti([
    ['nodeId' => 'i=2259'],
    ['nodeId' => 'i=2267'],
    ['nodeId' => 'ns=2;s=Temperature', 'attributeId' => AttributeId::Value],
]);
```

> **Tip:** The builder's `->node()` adds a node, then you pick the attribute (`->value()`, `->displayName()`, etc.). Call `->execute()` to send the request.

### DataValue Properties

```php
$dataValue->getValue();           // mixed -- unwrapped value (extracts from Variant)
$dataValue->variant;              // ?Variant -- typed variant
$dataValue->statusCode;           // int -- OPC UA status code
$dataValue->sourceTimestamp;      // ?DateTimeImmutable
$dataValue->serverTimestamp;      // ?DateTimeImmutable
```

## Writing a Value

```php
use PhpOpcua\Client\Types\BuiltinType;

$statusCode = $client->write(
    'ns=2;i=1234',  // or NodeId::numeric(2, 1234)
    42,
    BuiltinType::Int32
);

if (StatusCode::isGood($statusCode)) {
    echo "Write successful\n";
} else {
    echo "Write failed: " . StatusCode::getName($statusCode) . "\n";
}
// Events: dispatches NodeValueWritten on success, NodeValueWriteFailed otherwise
```

### Auto-Detect Write Type

By default, the client automatically detects the node's type before writing. You can omit the `BuiltinType` parameter:

```php
// Auto-detect type (reads the node first, caches the type)
$client->write('ns=2;i=1234', 42);

// Explicit type (validated against the node when auto-detect is on)
$client->write('ns=2;i=1234', 42, BuiltinType::Int32);
```

The detected type is cached (PSR-16) so subsequent writes to the same node skip the read.

**Behavior:**

| Auto-detect | `$type` passed | What happens |
|---|---|---|
| ON (default) | No | Reads node, caches type, writes |
| ON | Yes | Uses the type directly, no read |
| OFF | No | Throws `WriteTypeDetectionException` |
| OFF | Yes | Uses the type directly, no read |

**Disable auto-detect:**

```php
$client = ClientBuilder::create()
    ->setAutoDetectWriteType(false)
    ->connect('opc.tcp://localhost:4840');
```

**Exceptions:**

- `WriteTypeDetectionException` — node has no readable value, or auto-detect is off and no type provided

**Events:**

- `WriteTypeDetecting` — dispatched before the type detection starts
- `WriteTypeDetected` — dispatched after the type is determined (with `$detectedType` and `$fromCache`)

**Cache invalidation:**

```php
$client->invalidateCache($nodeId); // clears cached write type (and browse cache)
$client->flushCache();             // clears everything
```

### Writing Multiple Values

```php
// Fluent builder — auto-detect type
$results = $client->writeMulti()
    ->node('ns=2;i=1001')->value(3.14)
    ->node('ns=2;i=1002')->value('Hello')
    ->node('ns=2;i=1003')->value(true)
    ->execute();

// Fluent builder — explicit type
$results = $client->writeMulti()
    ->node('ns=2;i=1001')->typed(3.14, BuiltinType::Double)
    ->node('ns=2;i=1002')->typed('Hello', BuiltinType::String)
    ->node('ns=2;i=1003')->typed(true, BuiltinType::Boolean)
    ->execute();

foreach ($results as $i => $statusCode) {
    echo "Item $i: " . StatusCode::getName($statusCode) . "\n";
}

// Or with array (still works)
$results = $client->writeMulti([
    [
        'nodeId' => 'ns=2;i=1001',
        'value' => 3.14,
        'type' => BuiltinType::Double,
    ],
    [
        'nodeId' => 'ns=2;i=1002',
        'value' => 'Hello',
        'type' => BuiltinType::String,
    ],
    [
        'nodeId' => 'ns=2;i=1003',
        'value' => true,
        'type' => BuiltinType::Boolean,
    ],
]);
```

> **Tip:** The write builder uses `->node()` to pick the target, then `->value($val, $type)` to set what to write. Call `->execute()` to send.

### Writing to a Specific Attribute

By default, `write()` targets the Value attribute (id 13):

```php
$results = $client->writeMulti([
    [
        'nodeId' => NodeId::numeric(2, 1001),
        'value' => 100,
        'type' => BuiltinType::Int32,
        'attributeId' => 13,
    ],
]);
```

### Writing Arrays

```php
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\DataValue;

// Using Variant directly
$variant = new Variant(BuiltinType::Int32, [1, 2, 3, 4, 5]);
$dataValue = new DataValue($variant);

// Or through writeMulti
$results = $client->writeMulti([
    [
        'nodeId' => NodeId::numeric(2, 2001),
        'value' => [10, 20, 30],
        'type' => BuiltinType::Int32,
    ],
]);
```

## Automatic Batching

OPC UA servers can limit how many nodes you read or write per request. The client handles this transparently.

### How It Works

After `connect()`, the client reads the server's `MaxNodesPerRead` and `MaxNodesPerWrite` limits. When `readMulti()` or `writeMulti()` exceeds that limit, the request is split automatically and results are merged in order.

```php
$client = ClientBuilder::create()
    ->connect('opc.tcp://localhost:4840');

// Server says MaxNodesPerRead = 100
// This is split into 10 requests of 100 each
$results = $client->readMulti($items1000);
// $results contains all 1000 DataValues, in order
```

You can check the discovered limits:

```php
$client->getServerMaxNodesPerRead();  // e.g. 100, or null
$client->getServerMaxNodesPerWrite(); // e.g. 100, or null
```

### Setting a Manual Batch Size

Override the server limit or set one when the server does not report any:

```php
$client = ClientBuilder::create()
    ->setBatchSize(50)
    ->connect('opc.tcp://localhost:4840');
```

**Priority order:** your `setBatchSize(N)` (N > 0) beats the server-reported limit, which beats no batching.

### Disabling Batching

Skip both batching and the server limits discovery on connect:

```php
$client = ClientBuilder::create()
    ->setBatchSize(0)
    ->connect('opc.tcp://localhost:4840');
```

> **Tip:** Use this if you know the server has no limits and want to save the extra read on connect.

### Batching Summary

| `getBatchSize()` | Server reports | Discovery on connect | Effective batch size |
|------------------|----------------|----------------------|----------------------|
| `null` (default) | 100 | Yes | 100 |
| `null` (default) | 0 (no limit) | Yes | No batching |
| `null` (default) | Not supported | Yes | No batching |
| `50` | 100 | Yes | 50 |
| `50` | 0 | Yes | 50 |
| `0` (disabled) | Any | Skipped | No batching |

> **Note:** Batching only applies to `readMulti()` and `writeMulti()`. Single `read()` and `write()` calls always go as individual requests.

## Supported Data Types

| BuiltinType | PHP Type | Example |
|-------------|----------|---------|
| `Boolean` | `bool` | `true` |
| `SByte` | `int` | `-128` to `127` |
| `Byte` | `int` | `0` to `255` |
| `Int16` | `int` | `-32768` to `32767` |
| `UInt16` | `int` | `0` to `65535` |
| `Int32` | `int` | `-2^31` to `2^31-1` |
| `UInt32` | `int` | `0` to `2^32-1` |
| `Int64` | `int` | `-2^63` to `2^63-1` |
| `UInt64` | `int` | `0` to `2^64-1` |
| `Float` | `float` | `3.14` |
| `Double` | `float` | `3.141592653589793` |
| `String` | `string` | `'Hello'` |
| `DateTime` | `DateTimeImmutable` | `new DateTimeImmutable()` |
| `Guid` | `string` | `'550e8400-e29b-41d4-a716-446655440000'` |
| `ByteString` | `string` | Binary data |
| `NodeId` | `NodeId` | `NodeId::numeric(0, 85)` |
| `QualifiedName` | `QualifiedName` | `new QualifiedName(0, 'Name')` |
| `LocalizedText` | `LocalizedText` | `new LocalizedText('en', 'Text')` |

## Status Codes

```php
use PhpOpcua\Client\Types\StatusCode;

$statusCode = $dataValue->statusCode;

StatusCode::isGood($statusCode);      // true if 0x0XXXXXXX
StatusCode::isBad($statusCode);       // true if 0x8XXXXXXX
StatusCode::isUncertain($statusCode); // true if 0x4XXXXXXX
StatusCode::getName($statusCode);     // e.g. "BadNodeIdUnknown"
```

**Common status codes:**

| Constant | Value | Meaning |
|----------|-------|---------|
| `StatusCode::Good` | `0x00000000` | Success |
| `StatusCode::BadNodeIdUnknown` | `0x80340000` | Node does not exist |
| `StatusCode::BadTypeMismatch` | `0x80740000` | Value type mismatch |
| `StatusCode::BadNotWritable` | `0x803B0000` | Node is read-only |
| `StatusCode::BadNotReadable` | `0x803E0000` | Node is not readable |
| `StatusCode::BadUserAccessDenied` | `0x801F0000` | Access denied |
| `StatusCode::BadTimeout` | `0x800A0000` | Operation timed out |
