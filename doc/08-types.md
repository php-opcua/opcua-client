# Types Reference

## Core Types

### NodeId

Identifies a node in the OPC UA address space.

```php
use PhpOpcua\Client\Types\NodeId;

$nodeId = NodeId::numeric(0, 85);           // ns=0;i=85
$nodeId = NodeId::string(2, 'Temperature'); // ns=2;s=Temperature
$nodeId = NodeId::guid(1, '550e8400-e29b-41d4-a716-446655440000');
$nodeId = NodeId::opaque(1, 'DEADBEEF');
```

**Properties** (`public readonly`):

| Property | Type | Description |
|---|---|---|
| `$nodeId->namespaceIndex` | `int` | Namespace index |
| `$nodeId->identifier` | `int\|string` | The node identifier |
| `$nodeId->type` | `string` | `'numeric'`, `'string'`, `'guid'`, or `'opaque'` |

**Methods:**

| Method | Returns | Description |
|---|---|---|
| `isNumeric()` | `bool` | True if numeric identifier |
| `isString()` | `bool` | True if string identifier |
| `isGuid()` | `bool` | True if GUID identifier |
| `isOpaque()` | `bool` | True if opaque (ByteString) identifier |
| `getEncodingByte()` | `int` | Binary protocol encoding byte |
| `__toString()` | `string` | OPC UA string format (e.g. `ns=2;i=1001`) |

**String format in client methods:**

All public client methods that accept a `NodeId` parameter also accept a `NodeId|string` union type. Strings are parsed automatically using OPC UA notation. Invalid strings throw `InvalidNodeIdException`.

```php
// These are equivalent:
$client->read(NodeId::numeric(0, 2259));
$client->read('i=2259');

$client->browse(NodeId::numeric(0, 85));
$client->browse('i=85');

$client->write(NodeId::numeric(2, 1001), 42, BuiltinType::Int32);
$client->write('ns=2;i=1001', 42, BuiltinType::Int32);
```

**Parsing and serialization:**

```php
$nodeId = NodeId::parse('ns=2;i=1001');
$nodeId = NodeId::parse('i=85');          // ns=0 implied
$nodeId = NodeId::parse('ns=2;s=MyNode');

echo (string) $nodeId; // "ns=2;i=1001"
```

---

### Variant

Typed value container. Wraps any OPC UA value with its type information.

```php
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\BuiltinType;

$v = new Variant(BuiltinType::Int32, 42);
$v = new Variant(BuiltinType::String, 'Hello');
$v = new Variant(BuiltinType::Double, 3.14);
$v = new Variant(BuiltinType::Boolean, true);
$v = new Variant(BuiltinType::DateTime, new DateTimeImmutable());

// Arrays
$v = new Variant(BuiltinType::Int32, [1, 2, 3, 4, 5]);
$v = new Variant(BuiltinType::String, ['a', 'b', 'c']);
```

**Properties** (`public readonly`):

| Property | Type | Description |
|---|---|---|
| `$v->type` | `BuiltinType` | The OPC UA type |
| `$v->value` | `mixed` | The actual value (scalar or array) |
| `$v->dimensions` | `?int[]` | Multi-dimensional array dimensions, or `null` |

**Methods:**

| Method | Returns | Description |
|---|---|---|
| `isMultiDimensional()` | `bool` | True if dimensions has more than one entry |

---

### DataValue

A value with metadata. The inner `Variant` is private -- use `getValue()` to unwrap it.

```php
use PhpOpcua\Client\Types\DataValue;

$dv = new DataValue(
    value: new Variant(BuiltinType::Int32, 42),
    statusCode: 0,
    sourceTimestamp: new DateTimeImmutable(),
    serverTimestamp: new DateTimeImmutable(),
);
```

**Properties** (`public readonly`):

| Property | Type | Description |
|---|---|---|
| `$dv->statusCode` | `int` | OPC UA status code |
| `$dv->sourceTimestamp` | `?DateTimeImmutable` | When the source produced the value |
| `$dv->serverTimestamp` | `?DateTimeImmutable` | When the server received it |

**Methods:**

| Method | Returns | Description |
|---|---|---|
| `getValue()` | `mixed` | Unwrapped value from the inner Variant |
| `getVariant()` | `?Variant` | The full Variant object (when you need type info) |
| `getEncodingMask()` | `int` | Bitmask for binary encoding |

> **Tip:** `getValue()` returns the raw scalar or array directly. If you need to know the OPC UA type, call `getVariant()` instead and check `->type`.

**Factory methods:**

`DataValue` provides static factory methods for creating instances with common types. Each returns a `DataValue` with status `Good`, no timestamps, and the appropriate `Variant` inside.

```php
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\StatusCode;

// Typed factories
DataValue::ofInt32(42);
DataValue::ofDouble(3.14);
DataValue::ofString('Hello');
DataValue::ofBoolean(true);
DataValue::ofFloat(1.5);
DataValue::ofUInt32(100);
DataValue::ofInt16(7);
DataValue::ofUInt16(8);
DataValue::ofInt64(123456789);
DataValue::ofUInt64(987654321);
DataValue::ofDateTime(new DateTimeImmutable());

// Generic factory — any BuiltinType
DataValue::of(42, BuiltinType::Int32);

// Bad status (no value)
DataValue::bad(StatusCode::BadNodeIdUnknown);
```

| Factory Method | Type | Description |
|---|---|---|
| `ofInt32(int $v)` | `BuiltinType::Int32` | Signed 32-bit integer |
| `ofDouble(float $v)` | `BuiltinType::Double` | 64-bit float |
| `ofString(string $v)` | `BuiltinType::String` | UTF-8 string |
| `ofBoolean(bool $v)` | `BuiltinType::Boolean` | Boolean |
| `ofFloat(float $v)` | `BuiltinType::Float` | 32-bit float |
| `ofUInt32(int $v)` | `BuiltinType::UInt32` | Unsigned 32-bit integer |
| `ofInt16(int $v)` | `BuiltinType::Int16` | Signed 16-bit integer |
| `ofUInt16(int $v)` | `BuiltinType::UInt16` | Unsigned 16-bit integer |
| `ofInt64(int $v)` | `BuiltinType::Int64` | Signed 64-bit integer |
| `ofUInt64(int $v)` | `BuiltinType::UInt64` | Unsigned 64-bit integer |
| `ofDateTime(DateTimeImmutable $v)` | `BuiltinType::DateTime` | Date/time |
| `of(mixed $v, BuiltinType $type)` | any | Generic factory for any built-in type |
| `bad(int $statusCode)` | N/A | DataValue with no value and a bad status code |

---

### BuiltinType (Enum)

All 25 OPC UA built-in data types:

```php
use PhpOpcua\Client\Types\BuiltinType;
```

| Case | Value | | Case | Value |
|---|---|---|---|---|
| `Boolean` | 1 | | `DateTime` | 13 |
| `SByte` | 2 | | `Guid` | 14 |
| `Byte` | 3 | | `ByteString` | 15 |
| `Int16` | 4 | | `XmlElement` | 16 |
| `UInt16` | 5 | | `NodeId` | 17 |
| `Int32` | 6 | | `ExpandedNodeId` | 18 |
| `UInt32` | 7 | | `StatusCode` | 19 |
| `Int64` | 8 | | `QualifiedName` | 20 |
| `UInt64` | 9 | | `LocalizedText` | 21 |
| `Float` | 10 | | `ExtensionObject` | 22 |
| `Double` | 11 | | `DataValue` | 23 |
| `String` | 12 | | `Variant` / `DiagnosticInfo` | 24 / 25 |

---

## Value Types

### QualifiedName

A name qualified by a namespace index.

```php
use PhpOpcua\Client\Types\QualifiedName;

$qn = new QualifiedName(0, 'ServerStatus');
```

**Properties** (`public readonly`):

| Property | Type |
|---|---|
| `$qn->namespaceIndex` | `int` |
| `$qn->name` | `string` |

`__toString()` returns `'ServerStatus'` for ns=0, or `'2:Temperature'` for ns=2.

---

### LocalizedText

A string with an optional locale.

```php
use PhpOpcua\Client\Types\LocalizedText;

$lt = new LocalizedText('en', 'Server Status');
```

**Properties** (`public readonly`):

| Property | Type |
|---|---|
| `$lt->locale` | `string` |
| `$lt->text` | `string` |

`__toString()` returns the text.

---

### StatusCode

Utility class for OPC UA status codes. All methods are static.

```php
use PhpOpcua\Client\Types\StatusCode;

StatusCode::isGood(0x00000000);      // true
StatusCode::isBad(0x80340000);       // true
StatusCode::isUncertain(0x408F0000); // true
StatusCode::getName(0x80340000);     // 'BadNodeIdUnknown'
```

**Static methods:**

| Method | Returns | Description |
|---|---|---|
| `isGood(int $code)` | `bool` | Top 2 bits are `00` |
| `isBad(int $code)` | `bool` | Top 2 bits are `10` |
| `isUncertain(int $code)` | `bool` | Top 2 bits are `01` |
| `getName(int $code)` | `string` | Human-readable name, or hex fallback |

**Constants:**

| Constant | Value |
|---|---|
| `StatusCode::Good` | `0x00000000` |
| `StatusCode::BadUnexpectedError` | `0x80010000` |
| `StatusCode::BadInternalError` | `0x80020000` |
| `StatusCode::BadOutOfMemory` | `0x80030000` |
| `StatusCode::BadCommunicationError` | `0x80050000` |
| `StatusCode::BadTimeout` | `0x800A0000` |
| `StatusCode::BadServiceUnsupported` | `0x800B0000` |
| `StatusCode::BadNothingToDo` | `0x800F0000` |
| `StatusCode::BadTooManyOperations` | `0x80100000` |
| `StatusCode::BadUserAccessDenied` | `0x801F0000` |
| `StatusCode::BadSecureChannelIdInvalid` | `0x80220000` |
| `StatusCode::BadSessionIdInvalid` | `0x80250000` |
| `StatusCode::BadNodeIdUnknown` | `0x80340000` |
| `StatusCode::BadAttributeIdInvalid` | `0x80350000` |
| `StatusCode::BadIndexRangeInvalid` | `0x80360000` |
| `StatusCode::BadNotWritable` | `0x803B0000` |
| `StatusCode::BadNotReadable` | `0x803E0000` |
| `StatusCode::BadTypeMismatch` | `0x80740000` |
| `StatusCode::BadMethodInvalid` | `0x80750000` |
| `StatusCode::BadArgumentsMissing` | `0x80760000` |
| `StatusCode::BadInvalidArgument` | `0x80AB0000` |
| `StatusCode::BadNoData` | `0x80B10000` |
| `StatusCode::UncertainNoCommunicationLastUsableValue` | `0x408F0000` |

---

## Browse Types

### ReferenceDescription

A reference between nodes, returned by `browse()`.

**Properties** (`public readonly`):

| Property | Type | Description |
|---|---|---|
| `$ref->referenceTypeId` | `NodeId` | Type of reference |
| `$ref->isForward` | `bool` | Direction of the reference |
| `$ref->nodeId` | `NodeId` | Target node |
| `$ref->browseName` | `QualifiedName` | Browse name of the target |
| `$ref->displayName` | `LocalizedText` | Display name of the target |
| `$ref->nodeClass` | `NodeClass` | Node class of the target |
| `$ref->typeDefinition` | `?NodeId` | Type definition, if available |

---

### BrowseNode

Tree node from `browseRecursive()`. Wraps a `ReferenceDescription` with children.

**Properties** (`public readonly`):

| Property | Type |
|---|---|
| `$node->reference` | `ReferenceDescription` |

Access the underlying reference data through `$node->reference->nodeId`, `$node->reference->displayName`, etc.

**Methods:**

| Method | Returns | Description |
|---|---|---|
| `getChildren()` | `BrowseNode[]` | Child nodes |
| `hasChildren()` | `bool` | True if this node has children |
| `addChild(BrowseNode $child)` | `void` | Add a child node |

See [Browsing](03-browsing.md#recursive-browse) for tree traversal examples.

---

### BrowseDirection (Enum)

```php
use PhpOpcua\Client\Types\BrowseDirection;
```

| Case | Value | Description |
|---|---|---|
| `Forward` | `0` | Browse children |
| `Inverse` | `1` | Browse parents |
| `Both` | `2` | Browse both directions |

---

### NodeClass (Enum)

```php
use PhpOpcua\Client\Types\NodeClass;
```

| Case | Value |
|---|---|
| `Unspecified` | `0` |
| `Object` | `1` |
| `Variable` | `2` |
| `Method` | `4` |
| `ObjectType` | `8` |
| `VariableType` | `16` |
| `ReferenceType` | `32` |
| `DataType` | `64` |
| `View` | `128` |

---

## Server Types

### EndpointDescription

Server endpoint info, returned by `getEndpoints()`.

**Properties** (`public readonly`):

| Property | Type | Description |
|---|---|---|
| `$ep->endpointUrl` | `string` | Endpoint URL |
| `$ep->serverCertificate` | `?string` | DER-encoded certificate |
| `$ep->securityMode` | `int` | `1` = None, `2` = Sign, `3` = SignAndEncrypt |
| `$ep->securityPolicyUri` | `string` | Security policy URI |
| `$ep->userIdentityTokens` | `UserTokenPolicy[]` | Supported auth methods |
| `$ep->transportProfileUri` | `string` | Transport profile URI |
| `$ep->securityLevel` | `int` | Relative security ranking |

---

### UserTokenPolicy

Authentication method supported by an endpoint.

**Properties** (`public readonly`):

| Property | Type | Description |
|---|---|---|
| `$policy->policyId` | `?string` | Policy identifier |
| `$policy->tokenType` | `int` | `0` = Anonymous, `1` = Username, `2` = Certificate |
| `$policy->issuedTokenType` | `?string` | Issued token type URI |
| `$policy->issuerEndpointUrl` | `?string` | Issuer endpoint URL |
| `$policy->securityPolicyUri` | `?string` | Security policy for this token |

---

### ConnectionState (Enum)

```php
use PhpOpcua\Client\Types\ConnectionState;
```

| Case | Description |
|---|---|
| `Disconnected` | Never connected, or cleanly disconnected |
| `Connected` | Active connection |
| `Broken` | Connection was lost |

Used by `$client->getConnectionState()` and `$client->isConnected()`. See [Connection & Configuration](02-connection.md#connection-state).

---

### AttributeId

Constants for OPC UA node attributes.

```php
use PhpOpcua\Client\Types\AttributeId;
```

| Constant | Value | | Constant | Value |
|---|---|---|---|---|
| `NodeId` | 1 | | `EventNotifier` | 12 |
| `NodeClass` | 2 | | `Value` | 13 |
| `BrowseName` | 3 | | `DataType` | 14 |
| `DisplayName` | 4 | | `ValueRank` | 15 |
| `Description` | 5 | | `ArrayDimensions` | 16 |
| `WriteMask` | 6 | | `AccessLevel` | 17 |
| `UserWriteMask` | 7 | | `UserAccessLevel` | 18 |
| `IsAbstract` | 8 | | `MinimumSamplingInterval` | 19 |
| `Symmetric` | 9 | | `Historizing` | 20 |
| `InverseName` | 10 | | `Executable` | 21 |
| `ContainsNoLoops` | 11 | | `UserExecutable` | 22 |

> **Note:** `AttributeId::Value` (13) is the default for `read()` and `write()`.

---

## Result DTOs

All result DTOs use `public readonly` properties.

> **v5.0.0 Note:** Module-specific DTOs have moved from `Types\` to their module's namespace. Shared types (`NodeId`, `DataValue`, `Variant`, `StatusCode`, etc.) remain in `Types\`. Update your `use` imports accordingly:
>
> | Class | Old Location | New Location |
> |---|---|---|
> | `CallResult` | `PhpOpcua\Client\Types\CallResult` | `PhpOpcua\Client\Module\ReadWrite\CallResult` |
> | `BrowseResultSet` | `PhpOpcua\Client\Types\BrowseResultSet` | `PhpOpcua\Client\Module\Browse\BrowseResultSet` |
> | `BrowsePathResult` | `PhpOpcua\Client\Types\BrowsePathResult` | `PhpOpcua\Client\Module\TranslateBrowsePath\BrowsePathResult` |
> | `SubscriptionResult` | `PhpOpcua\Client\Types\SubscriptionResult` | `PhpOpcua\Client\Module\Subscription\SubscriptionResult` |
> | `MonitoredItemResult` | `PhpOpcua\Client\Types\MonitoredItemResult` | `PhpOpcua\Client\Module\Subscription\MonitoredItemResult` |
> | `PublishResult` | `PhpOpcua\Client\Types\PublishResult` | `PhpOpcua\Client\Module\Subscription\PublishResult` |
> | `TransferResult` | `PhpOpcua\Client\Types\TransferResult` | `PhpOpcua\Client\Module\Subscription\TransferResult` |
> | `AddNodesResult` | `PhpOpcua\Client\Types\AddNodesResult` | `PhpOpcua\Client\Module\NodeManagement\AddNodesResult` |
> | `BuildInfo` | `PhpOpcua\Client\Types\BuildInfo` | `PhpOpcua\Client\Module\ServerInfo\BuildInfo` |

### BrowseResultSet

Returned by `browseWithContinuation()` and `browseNext()`.

| Property | Type | Description |
|---|---|---|
| `$result->references` | `ReferenceDescription[]` | Browse results |
| `$result->continuationPoint` | `?string` | `null` when there are no more results |

See [Browsing](03-browsing.md#browse-with-continuation-manual).

---

### BrowsePathResult

Returned by `translateBrowsePaths()`, one per path.

| Property | Type | Description |
|---|---|---|
| `$result->statusCode` | `int` | Status of the path resolution |
| `$result->targets` | `BrowsePathTarget[]` | Resolved targets |

---

### BrowsePathTarget

A single target resolved from a browse path.

| Property | Type | Description |
|---|---|---|
| `$target->targetId` | `NodeId` | The resolved NodeId |
| `$target->remainingPathIndex` | `int` | `0xFFFFFFFF` if fully resolved |

---

### CallResult

Returned by `call()`. See [Method Calling](05-method-call.md).

| Property | Type | Description |
|---|---|---|
| `$result->statusCode` | `int` | Method execution status |
| `$result->inputArgumentResults` | `int[]` | Per-argument validation status codes |
| `$result->outputArguments` | `Variant[]` | Output values |

---

### SubscriptionResult

Returned by `createSubscription()`. See [Subscriptions](06-subscriptions.md).

| Property | Type | Description |
|---|---|---|
| `$result->subscriptionId` | `int` | Server-assigned subscription ID |
| `$result->revisedPublishingInterval` | `float` | Actual interval in ms |
| `$result->revisedLifetimeCount` | `int` | Actual lifetime count |
| `$result->revisedMaxKeepAliveCount` | `int` | Actual keep-alive count |

---

### MonitoredItemResult

Returned per item by `createMonitoredItems()` and `createEventMonitoredItem()`. See [Subscriptions](06-subscriptions.md#monitoring-data-changes).

| Property | Type | Description |
|---|---|---|
| `$result->monitoredItemId` | `int` | Server-assigned item ID |
| `$result->statusCode` | `int` | Creation status |
| `$result->revisedSamplingInterval` | `float` | Actual sampling interval in ms |
| `$result->revisedQueueSize` | `int` | Actual queue size |

---

### PublishResult

Returned by `publish()`. See [Subscriptions](06-subscriptions.md#receiving-notifications).

| Property | Type | Description |
|---|---|---|
| `$result->subscriptionId` | `int` | Which subscription this belongs to |
| `$result->sequenceNumber` | `int` | Sequence number (for acknowledgment) |
| `$result->moreNotifications` | `bool` | True if more notifications are waiting |
| `$result->notifications` | `array` | Notification entries |

Each entry in `notifications` is an associative array. For data changes: `type`, `clientHandle`, `dataValue`. For events: `type`, `clientHandle`, `eventFields`.

---

### TransferResult

Returned per subscription by `transferSubscriptions()`. See [Subscriptions](06-subscriptions.md#transferring-subscriptions).

| Property | Type | Description |
|---|---|---|
| `$result->statusCode` | `int` | Transfer status for this subscription |
| `$result->availableSequenceNumbers` | `int[]` | Sequence numbers available for republishing |

---

### AddNodesResult

Returned per node by `addNodes()`. See [Node Management](16-node-management.md).

```php
use PhpOpcua\Client\Types\AddNodesResult;
```

| Property | Type | Description |
|---|---|---|
| `$result->statusCode` | `int` | OPC UA status code for this node creation |
| `$result->addedNodeId` | `NodeId` | The server-assigned NodeId of the new node |

---

### BuildInfo

Returned by `getServerBuildInfo()`. Contains metadata about the connected server's software.

```php
use PhpOpcua\Client\Types\BuildInfo;
```

| Property | Type | Description |
|---|---|---|
| `$info->productName` | `?string` | Server product name (ns=0;i=2262) |
| `$info->manufacturerName` | `?string` | Server manufacturer (ns=0;i=2263) |
| `$info->softwareVersion` | `?string` | Software version string (ns=0;i=2264) |
| `$info->buildNumber` | `?string` | Build number (ns=0;i=2265) |
| `$info->buildDate` | `?DateTimeImmutable` | Build date (ns=0;i=2266) |

---

## Type Discovery

### StructureField

Describes a single field within a structure definition. Returned as part of `StructureDefinition`.

```php
use PhpOpcua\Client\Types\StructureField;
```

**Properties** (`public readonly`):

| Property | Type | Description |
|---|---|---|
| `$field->name` | `string` | Field name |
| `$field->dataType` | `NodeId` | OPC UA data type of the field |
| `$field->valueRank` | `int` | `-1` = scalar, `1` = array |
| `$field->builtinType` | `BuiltinType` | Resolved built-in type for encoding |
| `$field->isOptional` | `bool` | Whether the field is optional |

---

### StructureDefinition

Describes the layout of a custom structure type. Used by `discoverDataTypes()` to build dynamic codecs.

```php
use PhpOpcua\Client\Types\StructureDefinition;
```

**Properties** (`public readonly`):

| Property | Type | Description |
|---|---|---|
| `$def->defaultEncodingId` | `NodeId` | Binary encoding NodeId |
| `$def->baseDataType` | `NodeId` | Base data type NodeId |
| `$def->structureType` | `int` | `0` = Structure, `1` = StructureWithOptionalFields, `2` = Union |
| `$def->fields` | `StructureField[]` | Ordered list of fields |

---

## ExtensionObject

Represents an OPC UA ExtensionObject — a typed container for custom binary or XML structures.

```php
use PhpOpcua\Client\Types\ExtensionObject;
```

**Properties** (`public readonly`):

| Property | Type | Description |
|---|---|---|
| `$ext->typeId` | `NodeId` | Encoding NodeId identifying the type |
| `$ext->encoding` | `int` | `0x01` = binary, `0x02` = XML, `0x00` = no body |
| `$ext->body` | `?string` | Raw body bytes. Null when decoded via codec. |
| `$ext->value` | `mixed` | Decoded value from codec. Null when raw. |

**Methods:**

| Method | Returns | Description |
|---|---|---|
| `isDecoded()` | `bool` | True if decoded by a registered codec |
| `isRaw()` | `bool` | True if no codec was available (raw body) |

**Behavior with `DataValue::getValue()`:**

- **With codec registered:** `getValue()` auto-extracts `$ext->value` — returns the decoded data directly (e.g. `['x' => 1.0, 'y' => 2.0]`)
- **Without codec:** `getValue()` returns the `ExtensionObject` DTO — access `->typeId`, `->body`, etc.

```php
$value = $client->read($pointNodeId)->getValue();

if ($value instanceof ExtensionObject) {
    echo $value->typeId;   // raw, no codec
    echo $value->body;     // binary blob
} else {
    echo $value['x'];      // decoded via codec
}
```

---

## ExtensionObject Codecs

Register codecs to automatically decode ExtensionObjects. Full guide: [ExtensionObject Codecs](12-extension-object-codecs.md).

```php
$repo = new ExtensionObjectRepository();
$repo->register(NodeId::numeric(2, 5001), MyPointCodec::class);

$client = ClientBuilder::create($repo)
    ->connect('opc.tcp://localhost:4840');

$result = $client->read($pointNodeId);
// $result->getValue() => ['x' => 1.0, 'y' => 2.0, 'z' => 3.0]
```

Each `Client` has its own isolated repository. If you do not pass one, the builder creates an empty one internally.
