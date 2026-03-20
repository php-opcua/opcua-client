# ExtensionObject Codecs

## The problem

OPC UA `ExtensionObject` is a container for custom structures — alarm details, diagnostic info, PLC-specific types, anything the server defines beyond the standard types.

Without a codec, the library hands you a raw array with a binary blob:

```php
$result = $client->read($nodeId);
$value = $result->getValue();
// ['typeId' => NodeId, 'encoding' => 1, 'body' => '<binary blob>']
```

The codec system lets you register decoders that turn these blobs into actual PHP arrays or objects.

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

The decoder is positioned at the start of the ExtensionObject body. Read fields in the order the type's binary encoding defines them. `encode()` does the reverse.

### Available Decoder Methods

Everything `BinaryDecoder` offers:

| Method | OPC UA Type |
|--------|-------------|
| `readBoolean()` | Boolean |
| `readByte()` / `readSByte()` | Byte / SByte |
| `readUInt16()` / `readInt16()` | UInt16 / Int16 |
| `readUInt32()` / `readInt32()` | UInt32 / Int32 |
| `readInt64()` / `readUInt64()` | Int64 / UInt64 |
| `readFloat()` / `readDouble()` | Float / Double |
| `readString()` | String |
| `readByteString()` | ByteString |
| `readDateTime()` | DateTime |
| `readGuid()` | Guid |
| `readNodeId()` | NodeId |
| `readQualifiedName()` | QualifiedName |
| `readLocalizedText()` | LocalizedText |
| `readVariant()` | Variant |
| `readExtensionObject()` | Nested ExtensionObject |

## Registering a Codec

Create an `ExtensionObjectRepository`, register your codecs, and pass it to the `Client`:

```php
use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Repository\ExtensionObjectRepository;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

$repo = new ExtensionObjectRepository();

// By class name (instantiated automatically)
$repo->register(NodeId::numeric(2, 5001), MyPointCodec::class);

// By instance (useful when the codec needs configuration)
$repo->register(NodeId::numeric(2, 5001), new MyPointCodec());

$client = new Client(extensionObjectRepository: $repo);
```

The `typeId` is the **binary encoding NodeId** — the one that shows up in the `typeId` field of the raw ExtensionObject. To find it, read the node without a codec first and look at the `typeId` value.

Each `Client` has its own isolated repository — codecs registered on one client don't affect another. If you don't pass a repository, the client creates an empty one.

## Using It

Once registered, the codec kicks in automatically whenever the library encounters an ExtensionObject with that `typeId`:

```php
$repo = new ExtensionObjectRepository();
$repo->register(NodeId::numeric(2, 5001), MyPointCodec::class);

$client = new Client(extensionObjectRepository: $repo);
$client->connect('opc.tcp://localhost:4840');

$result = $client->read($pointNodeId);
$point = $result->getValue();
// ['x' => 1.0, 'y' => 2.0, 'z' => 3.0] — decoded by MyPointCodec
```

You can also register codecs after creating the client via `getExtensionObjectRepository()`:

```php
$client = new Client();
$client->getExtensionObjectRepository()->register(NodeId::numeric(2, 5001), MyPointCodec::class);
```

## Repository API

```php
$repo = new ExtensionObjectRepository();

// Register
$repo->register($typeId, MyCodec::class);

// Check
$repo->has($typeId);   // bool

// Get the instance
$repo->get($typeId);   // ?ExtensionObjectCodec

// Remove one
$repo->unregister($typeId);

// Remove all
$repo->clear();
```

## Finding the TypeId

Read the node without a codec and look at what comes back:

```php
$result = $client->read($nodeId);
$raw = $result->getValue();

echo $raw['typeId'];     // e.g. "ns=2;i=5001"
echo $raw['encoding'];   // 1 = binary, 2 = XML
echo strlen($raw['body']); // body size in bytes
```

Use that `typeId` when registering with `$repo->register()`.

## Limitations

- **Binary only** — codecs work for binary-encoded ExtensionObjects (`0x01`). XML-encoded ones (`0x02`) come back as raw XML strings.
- **No built-in codecs** — the library doesn't ship decoders for standard OPC UA ExtensionObject types (like `ServerStatusDataType` or `EUInformation`). You write the codecs you need.

## Why BuiltinTypes aren't codecs

The codec system is for `ExtensionObject` — composite structures whose binary format comes from the server or OPC UA companion specs. `BuiltinType` values (`Int32`, `String`, `Double`, etc.) are protocol-level primitives: their encoding is fixed by the spec and hardcoded in `BinaryEncoder`/`BinaryDecoder`. Making them pluggable would add an abstraction layer with zero practical benefit since their format never changes. Two different layers:

- **BuiltinType** — the protocol itself (fixed, spec-defined)
- **ExtensionObjectCodec** — application-level structures on top of the protocol (variable, server-defined, extensible)
