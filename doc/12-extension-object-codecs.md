# ExtensionObject Codecs

## Overview

OPC UA `ExtensionObject` is a container for custom data structures defined by the server or by OPC UA companion specifications. Examples include alarm details, diagnostic structures, PLC-specific data types, and any server-defined complex type.

By default, the library returns ExtensionObjects as raw arrays with an opaque binary body:

```php
$result = $client->read($nodeId);
$value = $result->getValue();
// ['typeId' => NodeId, 'encoding' => 1, 'body' => '<binary blob>']
```

The **codec system** allows you to register custom decoders that automatically transform these binary blobs into usable PHP arrays or objects.

## Implementing a Codec

A codec implements the `ExtensionObjectCodec` interface with two methods:

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

The `decode()` method receives a `BinaryDecoder` positioned at the start of the ExtensionObject body. Use the decoder's `read*` methods to extract fields in the order defined by the type's binary encoding. The `encode()` method does the reverse.

### Available Decoder Methods

Inside `decode()` you can use all `BinaryDecoder` methods:

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

Use `ExtensionObjectRepository` to register your codec for a specific type NodeId:

```php
use Gianfriaur\OpcuaPhpClient\Repository\ExtensionObjectRepository;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

// Register by class name (instantiated automatically)
ExtensionObjectRepository::register(NodeId::numeric(2, 5001), MyPointCodec::class);

// Or register by instance (useful for codecs that need configuration)
ExtensionObjectRepository::register(NodeId::numeric(2, 5001), new MyPointCodec());
```

The `typeId` is the **binary encoding NodeId** of the ExtensionObject type — this is the NodeId that appears in the `typeId` field of the raw ExtensionObject. You can find it by reading the node without a codec first and inspecting the `typeId` value.

## Using a Registered Codec

Once registered, the codec is used automatically whenever the library encounters an ExtensionObject with that `typeId`:

```php
ExtensionObjectRepository::register(NodeId::numeric(2, 5001), MyPointCodec::class);

$client->connect('opc.tcp://localhost:4840');

$result = $client->read($pointNodeId);
$point = $result->getValue();
// ['x' => 1.0, 'y' => 2.0, 'z' => 3.0]  — decoded by MyPointCodec
```

No changes to `read()`, `readMulti()`, or any other client method are needed.

## Repository API

```php
// Register
ExtensionObjectRepository::register($typeId, MyCodec::class);

// Check if registered
ExtensionObjectRepository::has($typeId);   // bool

// Get the codec instance
ExtensionObjectRepository::get($typeId);   // ?ExtensionObjectCodec

// Unregister a specific type
ExtensionObjectRepository::unregister($typeId);

// Remove all codecs
ExtensionObjectRepository::clear();
```

## Finding the TypeId

To find the binary encoding NodeId for a type, read the node without a codec and inspect the raw result:

```php
$result = $client->read($nodeId);
$raw = $result->getValue();

echo $raw['typeId'];     // e.g. "ns=2;i=5001"
echo $raw['encoding'];   // 1 = binary, 2 = XML
echo strlen($raw['body']); // body size in bytes
```

Use this `typeId` when calling `ExtensionObjectRepository::register()`.

## Limitations

- **Binary encoding only:** Codecs are used only for ExtensionObjects with binary encoding (`0x01`). XML-encoded ExtensionObjects (`0x02`) are returned as raw XML strings.
- **Global registry:** The repository is static — codecs registered anywhere are available globally. This is by design for simplicity, but means codecs are shared across all client instances.
- **No built-in type codecs:** The library does not ship with codecs for standard OPC UA ExtensionObject types (e.g., `ServerStatusDataType`, `EUInformation`). You must implement codecs for the types you need.

## Design Note: Why BuiltinTypes Are Not Codecs

The codec system is designed exclusively for `ExtensionObject` — composite structures whose binary format is defined by the server or the OPC UA companion specifications. OPC UA `BuiltinType` values (`Int32`, `String`, `Double`, `Boolean`, `DateTime`, etc.) are **primitive types defined at the protocol level**: their binary encoding is fixed by the OPC UA specification and hardcoded in `BinaryEncoder`/`BinaryDecoder`. Making them pluggable codecs would add an indirection layer with no practical benefit, since their format never changes and cannot be extended. The two layers serve different purposes:

- **BuiltinType** — the protocol itself (fixed, spec-defined, always the same)
- **ExtensionObjectCodec** — application-level structures built on top of the protocol (variable, server-defined, user-extensible)
