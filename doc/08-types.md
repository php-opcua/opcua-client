# Types Reference

## Core Types

### NodeId

Identifies a node in the OPC UA address space.

```php
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

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
use Gianfriaur\OpcuaPhpClient\Types\Variant;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;

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
use Gianfriaur\OpcuaPhpClient\Types\DataValue;

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

---

### BuiltinType (Enum)

All 25 OPC UA built-in data types:

```php
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
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
use Gianfriaur\OpcuaPhpClient\Types\QualifiedName;

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
use Gianfriaur\OpcuaPhpClient\Types\LocalizedText;

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
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

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
use Gianfriaur\OpcuaPhpClient\Types\BrowseDirection;
```

| Case | Value | Description |
|---|---|---|
| `Forward` | `0` | Browse children |
| `Inverse` | `1` | Browse parents |
| `Both` | `2` | Browse both directions |

---

### NodeClass (Enum)

```php
use Gianfriaur\OpcuaPhpClient\Types\NodeClass;
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
use Gianfriaur\OpcuaPhpClient\Types\ConnectionState;
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
use Gianfriaur\OpcuaPhpClient\Types\AttributeId;
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

## Type Discovery

### StructureField

Describes a single field within a structure definition. Returned as part of `StructureDefinition`.

```php
use Gianfriaur\OpcuaPhpClient\Types\StructureField;
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
use Gianfriaur\OpcuaPhpClient\Types\StructureDefinition;
```

**Properties** (`public readonly`):

| Property | Type | Description |
|---|---|---|
| `$def->defaultEncodingId` | `NodeId` | Binary encoding NodeId |
| `$def->baseDataType` | `NodeId` | Base data type NodeId |
| `$def->structureType` | `int` | `0` = Structure, `1` = StructureWithOptionalFields, `2` = Union |
| `$def->fields` | `StructureField[]` | Ordered list of fields |

---

## ExtensionObject Codecs

OPC UA `ExtensionObject` is a container for custom structures. Without a codec, the library returns raw arrays. Register a codec to get automatic decoding:

```php
use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Repository\ExtensionObjectRepository;

$repo = new ExtensionObjectRepository();
$repo->register(NodeId::numeric(2, 5001), MyPointCodec::class);

$client = new Client(extensionObjectRepository: $repo);
$client->connect('opc.tcp://localhost:4840');

$result = $client->read($pointNodeId);
// $result->getValue() => ['x' => 1.0, 'y' => 2.0, 'z' => 3.0]
```

Each `Client` has its own isolated repository. If you do not pass one, the client creates an empty one internally.

Full guide: [ExtensionObject Codecs](12-extension-object-codecs.md).
