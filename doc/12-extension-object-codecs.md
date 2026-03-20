# ExtensionObject Codecs

## The Problem

OPC UA `ExtensionObject` is a container for custom structures -- alarm details, diagnostic info, PLC-specific types, anything beyond the standard built-in types.

Without a codec, you get raw binary data:

```php
$result = $client->read($nodeId);
$value = $result->getValue();
// ['typeId' => NodeId, 'encoding' => 1, 'body' => '<binary blob>']
```

The codec system lets you register decoders that turn these blobs into PHP arrays or objects.

## Writing a Codec

Implement `ExtensionObjectCodec` with `decode()` and `encode()`:

```php
use Gianfriaur\OpcuaPhpClient\Encoding\ExtensionObjectCodec;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;

class MyPointCodec implements ExtensionObjectCodec
{
    public function decode(BinaryDecoder $decoder): object|array
    {
        return [
            'x' => $decoder->readDouble(),
            'y' => $decoder->readDouble(),
            'z' => $decoder->readDouble(),
        ];
    }

    public function encode(BinaryEncoder $encoder, mixed $value): void
    {
        $encoder->writeDouble($value['x']);
        $encoder->writeDouble($value['y']);
        $encoder->writeDouble($value['z']);
    }
}
```

The decoder is positioned at the start of the ExtensionObject body. Read fields in the exact order the type's binary encoding defines. `encode()` does the reverse.

### Available Decoder/Encoder Methods

| Method | OPC UA Type |
|--------|-------------|
| `readBoolean()` / `writeBoolean()` | Boolean |
| `readByte()` / `writeByte()` | Byte |
| `readSByte()` / `writeSByte()` | SByte |
| `readUInt16()` / `writeUInt16()` | UInt16 |
| `readInt16()` / `writeInt16()` | Int16 |
| `readUInt32()` / `writeUInt32()` | UInt32 |
| `readInt32()` / `writeInt32()` | Int32 |
| `readInt64()` / `writeInt64()` | Int64 |
| `readUInt64()` / `writeUInt64()` | UInt64 |
| `readFloat()` / `writeFloat()` | Float |
| `readDouble()` / `writeDouble()` | Double |
| `readString()` / `writeString()` | String |
| `readByteString()` / `writeByteString()` | ByteString |
| `readDateTime()` / `writeDateTime()` | DateTime |
| `readGuid()` / `writeGuid()` | Guid |
| `readNodeId()` / `writeNodeId()` | NodeId |
| `readQualifiedName()` / `writeQualifiedName()` | QualifiedName |
| `readLocalizedText()` / `writeLocalizedText()` | LocalizedText |
| `readVariant()` / `writeVariant()` | Variant |
| `readExtensionObject()` / `writeExtensionObject()` | Nested ExtensionObject |

## Registering a Codec

Create an `ExtensionObjectRepository`, register your codecs, and pass it to the `Client`:

```php
use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Repository\ExtensionObjectRepository;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

$repo = new ExtensionObjectRepository();

// By class name (instantiated on first use)
$repo->register(NodeId::numeric(2, 5001), MyPointCodec::class);

// By instance (useful when the codec needs configuration)
$repo->register(NodeId::numeric(2, 5001), new MyPointCodec());

$client = new Client(extensionObjectRepository: $repo);
```

> **Note:** Each `Client` has its own isolated repository. Codecs registered on one client do not affect another. If you don't pass a repository, the client creates an empty one internally.

You can also register codecs after creating the client:

```php
$client = new Client();
$client->getExtensionObjectRepository()->register(
    NodeId::numeric(2, 5001),
    MyPointCodec::class
);
```

## Using It

Once registered, the codec fires automatically whenever the library encounters an ExtensionObject with that `typeId`:

```php
$repo = new ExtensionObjectRepository();
$repo->register(NodeId::numeric(2, 5001), MyPointCodec::class);

$client = new Client(extensionObjectRepository: $repo);
$client->connect('opc.tcp://localhost:4840');

$result = $client->read($pointNodeId);
$point = $result->getValue();
// ['x' => 1.0, 'y' => 2.0, 'z' => 3.0]
```

No extra steps. Read a node, get decoded data.

## Repository API

```php
$repo = new ExtensionObjectRepository();

$repo->register($typeId, MyCodec::class);    // Register a codec
$repo->has($typeId);                          // bool
$repo->get($typeId);                          // ?ExtensionObjectCodec
$repo->unregister($typeId);                   // Remove one
$repo->clear();                               // Remove all
```

## Finding the TypeId

Read the node without a codec first and inspect the raw result:

```php
$result = $client->read($nodeId);
$raw = $result->getValue();

echo $raw['typeId'];       // e.g. "ns=2;i=5001"
echo $raw['encoding'];     // 1 = binary, 2 = XML
echo strlen($raw['body']); // body size in bytes
```

Use that `typeId` when calling `$repo->register()`.

> **Tip:** The `typeId` is the **binary encoding NodeId**, not the data type's own NodeId. These are different. The binary encoding NodeId is the one that appears in the wire format.

## Automatic Discovery

Instead of writing codecs by hand, call `$client->discoverDataTypes()` after connecting. The client browses the server's DataType hierarchy, reads the `DataTypeDefinition` attribute (available on OPC UA 1.04+ servers), and registers a `DynamicCodec` for every custom structure it finds.

**Before — manual codec:**

```php
class MyPointCodec implements ExtensionObjectCodec
{
    public function decode(BinaryDecoder $decoder): array
    {
        return [
            'x' => $decoder->readDouble(),
            'y' => $decoder->readDouble(),
            'z' => $decoder->readDouble(),
        ];
    }

    public function encode(BinaryEncoder $encoder, mixed $value): void
    {
        $encoder->writeDouble($value['x']);
        $encoder->writeDouble($value['y']);
        $encoder->writeDouble($value['z']);
    }
}

$repo = new ExtensionObjectRepository();
$repo->register(NodeId::numeric(2, 5001), MyPointCodec::class);
$client = new Client(extensionObjectRepository: $repo);
$client->connect('opc.tcp://localhost:4840');
```

**After — automatic discovery:**

```php
$client = new Client();
$client->connect('opc.tcp://localhost:4840');
$client->discoverDataTypes();

$point = $client->read($pointNodeId)->getValue();
// ['x' => 1.5, 'y' => 2.5, 'z' => 3.5] — decoded automatically
```

No codec class, no registration. The library reads the structure definition from the server and builds a decoder at runtime.

### Namespace Filtering

Pass a `namespaceIndex` to limit discovery to a specific namespace. This avoids scanning the entire type hierarchy when you only care about your application's types:

```php
$client->discoverDataTypes(namespaceIndex: 2);
```

### Manual Codecs Take Priority

If you registered a codec manually before calling `discoverDataTypes()`, the manual codec is preserved. Auto-discovery never overwrites existing registrations.

> **Note:** Auto-discovery requires the server to expose `DataTypeDefinition` attributes (OPC UA 1.04+). Older servers that lack these attributes need manual codecs.

> **Tip:** Call `discoverDataTypes()` once after `connect()`. It adds a round-trip to the server but saves you from writing and maintaining codec classes for every custom type.

## Limitations

- **Binary only.** Codecs work for binary-encoded ExtensionObjects (encoding `0x01`). XML-encoded ones (encoding `0x02`) come back as raw XML strings.
- **No built-in codecs.** The library does not ship decoders for standard OPC UA ExtensionObject types like `ServerStatusDataType` or `EUInformation`. You write the codecs you need.

## Design Note: Why BuiltinTypes Are Not Codecs

The codec system is for `ExtensionObject` -- composite structures whose binary format is defined by servers or OPC UA companion specs.

`BuiltinType` values (`Int32`, `String`, `Double`, etc.) are protocol-level primitives. Their encoding is fixed by the OPC UA spec and hardcoded in `BinaryEncoder` / `BinaryDecoder`. Making them pluggable would add indirection with zero benefit since their format never changes.

Two distinct layers:

- **BuiltinType** -- the protocol itself (fixed, spec-defined)
- **ExtensionObjectCodec** -- application-level structures on top of the protocol (variable, server-defined, extensible)
