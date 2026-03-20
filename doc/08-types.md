# Types Reference

## NodeId

Identifies a node in the OPC UA address space. Four identifier types:

```php
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

// Numeric (most common)
$nodeId = NodeId::numeric(0, 85);           // ns=0;i=85

// String
$nodeId = NodeId::string(2, 'Temperature'); // ns=2;s=Temperature

// GUID
$nodeId = NodeId::guid(1, '550e8400-e29b-41d4-a716-446655440000');

// Opaque (ByteString) — hex encoded
$nodeId = NodeId::opaque(1, 'DEADBEEF');
```

### Methods

```php
$nodeId->getNamespaceIndex();  // int
$nodeId->getIdentifier();     // int|string
$nodeId->getType();            // 'numeric', 'string', 'guid', 'opaque'
$nodeId->isNumeric();          // bool
$nodeId->isString();           // bool
$nodeId->isGuid();             // bool
$nodeId->isOpaque();           // bool
$nodeId->getEncodingByte();    // int (binary protocol encoding)
```

### Parsing and Serialization

```php
// Parse from OPC UA string format
$nodeId = NodeId::parse('ns=2;i=1001');
$nodeId = NodeId::parse('i=85');         // ns=0 implied
$nodeId = NodeId::parse('ns=2;s=MyNode');

// Serialize back
echo $nodeId->toString();  // "ns=2;i=1001"
echo (string) $nodeId;     // same thing (__toString)
```

### Encoding Rules

Numeric NodeIds use the most compact encoding:
- **TwoByte** (0x00): namespace=0, identifier 0-255
- **FourByte** (0x01): namespace 0-255, identifier 0-65535
- **Numeric** (0x02): full UInt16 namespace, UInt32 identifier

## Variant

Typed value container. Wraps any OPC UA value with its type:

```php
use Gianfriaur\OpcuaPhpClient\Types\Variant;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;

// Scalars
$v = new Variant(BuiltinType::Int32, 42);
$v = new Variant(BuiltinType::String, 'Hello');
$v = new Variant(BuiltinType::Double, 3.14);
$v = new Variant(BuiltinType::Boolean, true);
$v = new Variant(BuiltinType::DateTime, new DateTimeImmutable());

// Arrays
$v = new Variant(BuiltinType::Int32, [1, 2, 3, 4, 5]);
$v = new Variant(BuiltinType::String, ['a', 'b', 'c']);

$v->getType();          // BuiltinType enum
$v->getValue();         // mixed
$v->getDimensions();    // ?int[] — multi-dimensional array dimensions
$v->isMultiDimensional(); // bool
```

## DataValue

A value with metadata:

```php
use Gianfriaur\OpcuaPhpClient\Types\DataValue;

$dv = new DataValue(
    value: new Variant(BuiltinType::Int32, 42),
    statusCode: 0,
    sourceTimestamp: new DateTimeImmutable(),
    serverTimestamp: new DateTimeImmutable(),
);

$dv->getValue();             // mixed (unwrapped from Variant)
$dv->getVariant();           // ?Variant
$dv->getStatusCode();        // int
$dv->getSourceTimestamp();   // ?DateTimeImmutable
$dv->getServerTimestamp();   // ?DateTimeImmutable
```

## BuiltinType (Enum)

All 25 OPC UA built-in data types:

```php
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;

BuiltinType::Boolean;        // 1
BuiltinType::SByte;          // 2
BuiltinType::Byte;           // 3
BuiltinType::Int16;          // 4
BuiltinType::UInt16;         // 5
BuiltinType::Int32;          // 6
BuiltinType::UInt32;         // 7
BuiltinType::Int64;          // 8
BuiltinType::UInt64;         // 9
BuiltinType::Float;          // 10
BuiltinType::Double;         // 11
BuiltinType::String;         // 12
BuiltinType::DateTime;       // 13
BuiltinType::Guid;           // 14
BuiltinType::ByteString;     // 15
BuiltinType::XmlElement;     // 16
BuiltinType::NodeId;         // 17
BuiltinType::ExpandedNodeId; // 18
BuiltinType::StatusCode;     // 19
BuiltinType::QualifiedName;  // 20
BuiltinType::LocalizedText;  // 21
BuiltinType::ExtensionObject;// 22
BuiltinType::DataValue;      // 23
BuiltinType::Variant;        // 24
BuiltinType::DiagnosticInfo; // 25
```

## ExtensionObject Codecs

OPC UA `ExtensionObject` is a container for custom structures. Without a codec, the library gives you back a raw array with an opaque binary body. Register a codec and it gets decoded automatically:

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

Full guide: [ExtensionObject Codecs](12-extension-object-codecs.md).

## NodeClass (Enum)

```php
use Gianfriaur\OpcuaPhpClient\Types\NodeClass;

NodeClass::Unspecified;    // 0
NodeClass::Object;         // 1
NodeClass::Variable;       // 2
NodeClass::Method;         // 4
NodeClass::ObjectType;     // 8
NodeClass::VariableType;   // 16
NodeClass::ReferenceType;  // 32
NodeClass::DataType;       // 64
NodeClass::View;           // 128
```

## BrowseDirection (Enum)

```php
use Gianfriaur\OpcuaPhpClient\Types\BrowseDirection;

BrowseDirection::Forward;  // 0 — children
BrowseDirection::Inverse;  // 1 — parents
BrowseDirection::Both;     // 2 — both ways
```

Used in `browse()`, `browseAll()`, `browseRecursive()`, etc.

## QualifiedName

A name qualified by a namespace index:

```php
use Gianfriaur\OpcuaPhpClient\Types\QualifiedName;

$qn = new QualifiedName(0, 'ServerStatus');

$qn->getNamespaceIndex();  // 0
$qn->getName();            // 'ServerStatus'
echo $qn;                  // 'ServerStatus' (ns=0 omitted)

$qn2 = new QualifiedName(2, 'Temperature');
echo $qn2;                 // '2:Temperature'
```

## LocalizedText

A string with an optional locale:

```php
use Gianfriaur\OpcuaPhpClient\Types\LocalizedText;

$lt = new LocalizedText('en', 'Server Status');

$lt->getLocale();  // 'en'
$lt->getText();    // 'Server Status'
echo $lt;          // 'Server Status'
```

## StatusCode

Utility for OPC UA status codes:

```php
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

StatusCode::isGood(0x00000000);      // true
StatusCode::isBad(0x80340000);       // true
StatusCode::isUncertain(0x408F0000); // true
StatusCode::getName(0x80340000);     // 'BadNodeIdUnknown'
```

### Constants

| Constant | Value |
|----------|-------|
| `StatusCode::Good` | `0x00000000` |
| `StatusCode::BadUnexpectedError` | `0x80010000` |
| `StatusCode::BadInternalError` | `0x80020000` |
| `StatusCode::BadOutOfMemory` | `0x80030000` |
| `StatusCode::BadCommunicationError` | `0x80050000` |
| `StatusCode::BadTimeout` | `0x800A0000` |
| `StatusCode::BadServiceUnsupported` | `0x800B0000` |
| `StatusCode::BadNothingToDo` | `0x800F0000` |
| `StatusCode::BadTooManyOperations` | `0x80100000` |
| `StatusCode::BadNodeIdUnknown` | `0x80340000` |
| `StatusCode::BadAttributeIdInvalid` | `0x80350000` |
| `StatusCode::BadIndexRangeInvalid` | `0x80360000` |
| `StatusCode::BadNotWritable` | `0x803B0000` |
| `StatusCode::BadNotReadable` | `0x803E0000` |
| `StatusCode::BadTypeMismatch` | `0x80740000` |
| `StatusCode::BadInvalidArgument` | `0x80AB0000` |
| `StatusCode::BadNoData` | `0x80B10000` |
| `StatusCode::BadUserAccessDenied` | `0x801F0000` |
| `StatusCode::BadSessionIdInvalid` | `0x80250000` |
| `StatusCode::BadSecureChannelIdInvalid` | `0x80220000` |
| `StatusCode::BadMethodInvalid` | `0x80750000` |
| `StatusCode::BadArgumentsMissing` | `0x80760000` |
| `StatusCode::UncertainNoCommunicationLastUsableValue` | `0x408F0000` |

## AttributeId

Constants for node attributes:

```php
use Gianfriaur\OpcuaPhpClient\Types\AttributeId;

AttributeId::NodeId;                  // 1
AttributeId::NodeClass;              // 2
AttributeId::BrowseName;             // 3
AttributeId::DisplayName;            // 4
AttributeId::Description;            // 5
AttributeId::WriteMask;              // 6
AttributeId::UserWriteMask;          // 7
AttributeId::IsAbstract;             // 8
AttributeId::Symmetric;              // 9
AttributeId::InverseName;            // 10
AttributeId::ContainsNoLoops;        // 11
AttributeId::EventNotifier;          // 12
AttributeId::Value;                  // 13 (default for read/write)
AttributeId::DataType;               // 14
AttributeId::ValueRank;              // 15
AttributeId::ArrayDimensions;        // 16
AttributeId::AccessLevel;            // 17
AttributeId::UserAccessLevel;        // 18
AttributeId::MinimumSamplingInterval;// 19
AttributeId::Historizing;            // 20
AttributeId::Executable;             // 21
AttributeId::UserExecutable;         // 22
```

## EndpointDescription

Server endpoint info (from `getEndpoints()`):

```php
$ep->getEndpointUrl();           // string
$ep->getServerCertificate();     // ?string (DER)
$ep->getSecurityMode();          // int (1=None, 2=Sign, 3=SignAndEncrypt)
$ep->getSecurityPolicyUri();     // string
$ep->getUserIdentityTokens();    // UserTokenPolicy[]
$ep->getTransportProfileUri();   // string
$ep->getSecurityLevel();         // int
```

## UserTokenPolicy

Auth method supported by an endpoint:

```php
$policy->getPolicyId();          // ?string
$policy->getTokenType();         // int (0=Anonymous, 1=Username, 2=Certificate)
$policy->getIssuedTokenType();   // ?string
$policy->getIssuerEndpointUrl(); // ?string
$policy->getSecurityPolicyUri(); // ?string
```

## ConnectionState (Enum)

```php
use Gianfriaur\OpcuaPhpClient\Types\ConnectionState;

ConnectionState::Disconnected;  // never connected or cleanly disconnected
ConnectionState::Connected;     // up and running
ConnectionState::Broken;        // connection was lost
```

Used by `Client::getConnectionState()` and `Client::isConnected()`. See [Connection & Configuration](02-connection.md#connection-state).

## ReferenceDescription

A reference between nodes (from `browse()`):

```php
$ref->getReferenceTypeId();  // NodeId
$ref->isForward();           // bool
$ref->getNodeId();           // NodeId
$ref->getBrowseName();       // QualifiedName
$ref->getDisplayName();      // LocalizedText
$ref->getNodeClass();        // NodeClass enum
$ref->getTypeDefinition();   // ?NodeId
```

## BrowseNode

Tree node from `browseRecursive()`. Wraps a `ReferenceDescription` with children:

```php
use Gianfriaur\OpcuaPhpClient\Types\BrowseNode;

$node->getReference();    // ReferenceDescription
$node->getNodeId();       // NodeId
$node->getDisplayName();  // LocalizedText
$node->getBrowseName();   // QualifiedName
$node->getNodeClass();    // NodeClass enum
$node->getChildren();     // BrowseNode[]
$node->hasChildren();     // bool
$node->addChild($child);  // void
```

See [Browsing](03-browsing.md#recursive-browse) for examples.
