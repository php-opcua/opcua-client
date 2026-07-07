# Types reference

OPC UA primitives as PHP classes, all under `PhpOpcua\Client\Types\`. The wire value objects (NodeId, Variant, DataValue, ExtensionObject, LocalizedText, QualifiedName, EndpointDescription, ReferenceDescription, StructureDefinition, StructureField, UserTokenPolicy) are `final readonly` with public readonly properties. `StatusCode` is a plain class of int constants plus static helpers (`isGood`/`isBad`/`isUncertain`/`getName`/`withDataValueInfoBits`); `AttributeId` is a plain class of int constants only; BuiltinType, NodeClass, BrowseDirection, and ConnectionState are enums; and BrowseNode is `final` but mutable (children are added via `addChild()`).

## NodeId

Identifies a node in the OPC UA address space.

```php
use PhpOpcua\Client\Types\NodeId;

// Four identifier kinds:
NodeId::numeric($namespaceIndex, $intId);        // i=123, ns=2;i=42
NodeId::string($namespaceIndex, $stringId);      // s=MyNode, ns=2;s=Sensors/Temp
NodeId::guid($namespaceIndex, $guidString);      // g=550e8400-e29b-41d4-a716-446655440000
NodeId::opaque($namespaceIndex, $hexIdentifier); // b=<hex string>, decoded via hex2bin() on the wire

// Properties (public readonly):
$nodeId->namespaceIndex;                          // int
$nodeId->identifier;                              // int | string
$nodeId->type;                                    // 'numeric' | 'string' | 'guid' | 'opaque'

// Parsing:
NodeId::parse('ns=2;s=Sensors/Temp');             // → NodeId
NodeId::parse('i=2259');                          // → NodeId::numeric(0, 2259)

// Canonical string:
$nodeId->toString();                              // 'ns=2;s=Sensors/Temp'
(string) $nodeId;                                 // same — __toString()

// Predicates:
$nodeId->isNumeric();
$nodeId->isString();
$nodeId->isGuid();
$nodeId->isOpaque();
```

**Idiom**: in application code, pass strings to client methods. `read('ns=2;s=Temp')` is preferable to `read(NodeId::string(2, 'Temp'))` — every method auto-parses.

## Variant

A typed value (OPC UA's "any" type).

```php
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\BuiltinType;

// Construct:
$v = new Variant(BuiltinType::Int32, 42);
$v = new Variant(BuiltinType::String, 'hello');
$v = new Variant(BuiltinType::Double, [1.0, 2.0, 3.0]);                          // array
$v = new Variant(BuiltinType::Double, [1.0, 2.0, 3.0, 4.0], dimensions: [2, 2]); // 2D matrix

// Properties:
$v->type;                                          // BuiltinType
$v->value;                                         // mixed
$v->dimensions;                                    // ?int[] — for multi-dim arrays
$v->isMultiDimensional();
```

## DataValue

A value with metadata: status, timestamps.

```php
use PhpOpcua\Client\Types\DataValue;

// Public readonly:
$dv->statusCode;                                   // int — 0 = Good, see StatusCode
$dv->sourceTimestamp;                              // ?DateTimeImmutable
$dv->serverTimestamp;                              // ?DateTimeImmutable
$dv->type;                                         // ?BuiltinType — derived from inner Variant; null when no Variant

// Methods:
$dv->getValue();                                   // unwrapped value (int, string, DateTime, …)
                                                   // — auto-decodes registered ExtensionObjects
$dv->getType();                                    // ?BuiltinType — same as $dv->type, symmetric with getValue()
$dv->getVariant();                                 // @deprecated — kept for back-compat; prefer $dv->type / getType() for the data type

// Construct:
DataValue::of($value, BuiltinType::Double);
DataValue::ofInt32(42);
DataValue::ofDouble(3.14);
DataValue::ofString('hello');
DataValue::ofBoolean(true);
DataValue::ofDateTime(new DateTimeImmutable());
DataValue::bad(0x80020000);                        // BadInternalError
```

`getValue()` is the canonical unwrap method. Don't replace it with property access — it handles the `ExtensionObject` auto-decode case that `->value` doesn't.

## ExtensionObject

Wrapper for structured (non-primitive) OPC UA values.

```php
use PhpOpcua\Client\Types\ExtensionObject;

// Public readonly:
$eo->typeId;                                       // NodeId
$eo->encoding;                                     // int — 0x01 = binary, 0x02 = XML, 0x00 = no body
$eo->body;                                         // raw bytes / XML string / null
$eo->value;                                        // decoded PHP value (if codec registered) — else null

// State:
$eo->isDecoded();                                  // value is populated (codec ran)
$eo->isRaw();                                      // body is populated but no codec — read it manually
```

When a codec for `$eo->typeId` is registered (either by `discoverDataTypes()` or manually via `$client->getExtensionObjectRepository()->register($typeId, $codec)`, where `$codec` is an `ExtensionObjectCodec` instance or its fully-qualified class-string), `DataValue::getValue()` returns `$eo->value` directly instead of the `ExtensionObject` wrapper.

## BuiltinType

Enum of OPC UA's 25 built-in types.

```php
use PhpOpcua\Client\Types\BuiltinType;

BuiltinType::Boolean;        // = 1
BuiltinType::SByte;          // = 2
BuiltinType::Byte;           // = 3
BuiltinType::Int16;          // = 4
BuiltinType::UInt16;         // = 5
BuiltinType::Int32;          // = 6
BuiltinType::UInt32;         // = 7
BuiltinType::Int64;          // = 8
BuiltinType::UInt64;         // = 9
BuiltinType::Float;          // = 10
BuiltinType::Double;         // = 11
BuiltinType::String;         // = 12
BuiltinType::DateTime;       // = 13
BuiltinType::Guid;           // = 14
BuiltinType::ByteString;     // = 15
BuiltinType::XmlElement;     // = 16
BuiltinType::NodeId;         // = 17
BuiltinType::ExpandedNodeId; // = 18
BuiltinType::StatusCode;     // = 19
BuiltinType::QualifiedName;  // = 20
BuiltinType::LocalizedText;  // = 21
BuiltinType::ExtensionObject;// = 22
BuiltinType::DataValue;      // = 23
BuiltinType::Variant;        // = 24
BuiltinType::DiagnosticInfo; // = 25
```

## Auxiliary types

| Type | Purpose | Key fields |
| --- | --- | --- |
| `NodeClass` | enum | `Object`, `Variable`, `Method`, `ObjectType`, `VariableType`, `ReferenceType`, `DataType`, `View` |
| `BrowseDirection` | enum | `Forward`, `Inverse`, `Both` |
| `AttributeId` | class of int consts | `NodeId` (=1), `NodeClass`, `BrowseName`, `DisplayName`, `Description`, `WriteMask`, `UserWriteMask`, `IsAbstract`, `Symmetric`, `InverseName`, `ContainsNoLoops`, `EventNotifier`, `Value` (=13), `DataType`, `ValueRank`, `ArrayDimensions`, `AccessLevel`, `UserAccessLevel`, `MinimumSamplingInterval`, `Historizing`, `Executable`, `UserExecutable`, `DataTypeDefinition`. Plain int constants used directly, e.g. `read('i=2259', AttributeId::Value)`. |
| `ReferenceDescription` | browse result | `referenceTypeId`, `isForward`, `nodeId`, `browseName`, `displayName`, `nodeClass`, `typeDefinition` |
| `BrowseNode` | tree node (mutable) | `reference` (ReferenceDescription); methods `getReference()`, `getChildren()`, `hasChildren()`, `addChild()` |
| `EndpointDescription` | discovery result | `endpointUrl`, `serverCertificate`, `securityMode`, `securityPolicyUri`, `userIdentityTokens`, `transportProfileUri`, `securityLevel` |
| `UserTokenPolicy` | discovery result | `policyId`, `tokenType` (int: 0=Anonymous, 1=UserName, 2=Certificate, 3=IssuedToken), `issuedTokenType`, `issuerEndpointUrl`, `securityPolicyUri` |
| `LocalizedText` | i18n string | `locale`, `text`. `__toString()` returns text. |
| `QualifiedName` | namespaced name | `namespaceIndex`, `name`. `__toString()` returns `'namespaceIndex:name'` (e.g. `2:Temperature`), or just `'name'` when `namespaceIndex` is 0. |
| `StatusCode` | static helpers | `StatusCode::isGood($code)`, `isUncertain($code)`, `isBad($code)`, `withDataValueInfoBits()` |
| `ConnectionState` | enum | `Disconnected`, `Connected`, `Broken` |

## StatusCode interpretation

OPC UA status codes are 32-bit unsigned integers. Severity bits (top 2 bits):

- `0x00000000-0x3FFFFFFF` — Good (severity 0)
- `0x40000000-0x7FFFFFFF` — Uncertain (severity 1) — the value MIGHT be reliable
- `0x80000000-0xBFFFFFFF` — Bad (severity 2) — value NOT reliable
- `0xC0000000-0xFFFFFFFF` — reserved

Don't compare against `=== 0` — many "Good" reads have additional info bits set (e.g. `StatusCode::InfoTypeDataValue = 0x00000400` is OR'd in alongside DataValue info bits). Always use the predicates:

```php
use PhpOpcua\Client\Types\StatusCode;

if (StatusCode::isGood($dv->statusCode)) { /* trustworthy */ }
if (StatusCode::isUncertain($dv->statusCode)) { /* log + maybe accept */ }
if (StatusCode::isBad($dv->statusCode)) { /* discard */ }
```

Common codes you'll see (these are the `StatusCode` constants defined in the library; use `StatusCode::getName($code)` for display):
- `0x00000000` — `StatusCode::Good`
- `0x80020000` — `StatusCode::BadInternalError`
- `0x80050000` — `StatusCode::BadCommunicationError`
- `0x80340000` — `StatusCode::BadNodeIdUnknown`
- `0x80350000` — `StatusCode::BadAttributeIdInvalid`
- `0x80360000` — `StatusCode::BadIndexRangeInvalid`
- `0x80740000` — `StatusCode::BadTypeMismatch`
- `0x80AB0000` — `StatusCode::BadInvalidArgument`
- `0x801F0000` — `StatusCode::BadUserAccessDenied`
- `0x80250000` — `StatusCode::BadSessionIdInvalid`
