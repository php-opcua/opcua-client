---
eyebrow: 'Docs · Extensibility'
lede:    'A codec decodes a server-defined structure to a PHP value (and vice versa). Write one when the structure is not auto-discoverable; rely on automatic type discovery when it is.'

see_also:
  - { href: '../types/extension-objects.md', meta: '5 min' }
  - { href: './type-discovery.md',           meta: '6 min' }
  - { href: '../operations/reading-attributes.md', meta: '7 min' }

prev: { label: 'Replacing modules', href: './replacing-modules.md' }
next: { label: 'Type discovery',    href: './type-discovery.md' }
---

# Extension object codecs

OPC UA structures arrive as `ExtensionObject` instances on the wire —
a `typeId` (the DataType `NodeId`) plus a binary `body`. A **codec**
translates those bytes to and from a PHP value. The library ships
zero codecs out of the box; you register the ones your address space
needs, or use automatic discovery to synthesise them at runtime.

This page is about hand-written codecs. For automatic generation, see
[Type discovery](./type-discovery.md).

## When to hand-write

Reach for a hand-written codec when:

- The DataType is published without a `DataTypeDefinition` attribute
  (older OPC UA versions, some vendor servers). Automatic discovery
  cannot reach it.
- The structure shape is well-known (`Argument`, `EnumValueType`, a
  custom DTO you control on both sides).
- You want a codec that returns a PHP class instance, not a generic
  array. Discovery synthesises array-shaped codecs only.

## The interface

<!-- @code-block language="php" label="ExtensionObjectCodec" -->
```php
namespace PhpOpcua\Client\Encoding;

interface ExtensionObjectCodec
{
    public function decode(BinaryDecoder $decoder): object|array;

    public function encode(BinaryEncoder $encoder, mixed $value): void;
}
```
<!-- @endcode-block -->

`decode()` reads from a `BinaryDecoder` positioned at the start of the
structure body. `encode()` writes to a `BinaryEncoder` — the
ExtensionObject envelope is wrapped around it by the library.

## A worked example — Vector3D

A server publishes a `Vector3D` structure as DataType `ns=2;i=5001`,
encoded as three IEEE-754 doubles in field-declaration order.

<!-- @code-block language="php" label="examples/Vector3DCodec.php" -->
```php
namespace App\Opcua;

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Encoding\ExtensionObjectCodec;

final class Vector3DCodec implements ExtensionObjectCodec
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
```
<!-- @endcode-block -->

Register the codec on the per-client repository:

<!-- @code-block language="php" label="registration" -->
```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Types\NodeId;

$builder = ClientBuilder::create();
$builder->getExtensionObjectRepository()
    ->register(NodeId::numeric(2, 5001), Vector3DCodec::class);
$client = $builder->connect('opc.tcp://plc.local:4840');

$vector = $client->read('ns=2;s=Devices/Robot/TipPosition')->getValue();
// → ['x' => 1.0, 'y' => 2.0, 'z' => 3.0]
```
<!-- @endcode-block -->

After registration, every `read()` whose value's `ExtensionObject.typeId`
matches `ns=2;i=5001` returns the decoded PHP array. Auto-detected
writes encode the array back through the codec.

## Returning class instances

`decode()` may return an object instead of an array. Useful for
strong typing and IDE autocompletion:

<!-- @code-block language="php" label="object-returning codec" -->
```php
final readonly class Vector3D
{
    public function __construct(
        public float $x,
        public float $y,
        public float $z,
    ) {}
}

final class Vector3DCodec implements ExtensionObjectCodec
{
    public function decode(BinaryDecoder $decoder): Vector3D
    {
        return new Vector3D(
            $decoder->readDouble(),
            $decoder->readDouble(),
            $decoder->readDouble(),
        );
    }

    public function encode(BinaryEncoder $encoder, mixed $value): void
    {
        assert($value instanceof Vector3D);
        $encoder->writeDouble($value->x);
        $encoder->writeDouble($value->y);
        $encoder->writeDouble($value->z);
    }
}
```
<!-- @endcode-block -->

For codecs returning objects, also implement `Wire\WireSerializable`
on the class if the values cross an IPC boundary — see [Wire
serialization](./wire-serialization.md).

## BinaryDecoder / BinaryEncoder surface

These are the only types a codec sees. The methods follow OPC UA Part
6 §5.2:

| Decoder method                      | Encoder method                       | Encodes / decodes                  |
| ----------------------------------- | ------------------------------------ | ---------------------------------- |
| `readBoolean()`                     | `writeBoolean(bool)`                 | 1 byte                              |
| `readSByte()` / `readByte()`        | `writeSByte(int)` / `writeByte(int)` | 1 byte                              |
| `readInt16()` / `readUInt16()`      | `writeInt16(int)` / `writeUInt16(int)` | 2 bytes LE                        |
| `readInt32()` / `readUInt32()`      | `writeInt32(int)` / `writeUInt32(int)` | 4 bytes LE                        |
| `readInt64()` / `readUInt64()`      | `writeInt64(int)` / `writeUInt64(int)` | 8 bytes LE                        |
| `readFloat()` / `readDouble()`      | `writeFloat(float)` / `writeDouble(float)` | IEEE-754                       |
| `readString()`                      | `writeString(?string)`               | int32 length + UTF-8 bytes          |
| `readByteString()`                  | `writeByteString(?string)`           | int32 length + raw bytes            |
| `readDateTime()`                    | `writeDateTime(?DateTimeImmutable)`  | FILETIME ticks                      |
| `readGuid()`                        | `writeGuid(string)`                  | 16-byte UUID                        |
| `readNodeId()`                      | `writeNodeId(NodeId)`                | Compact NodeId encoding             |
| `readQualifiedName()`               | `writeQualifiedName(QualifiedName)`  | ns + string                         |
| `readLocalizedText()`               | `writeLocalizedText(LocalizedText)`  | locale-mask + text                  |
| `readVariant()`                     | `writeVariant(Variant)`              | Recursive Variant encoding          |
| `readExtensionObject()`             | `writeExtensionObject(ExtensionObject)` | Nested ExtensionObject           |

For arrays, prefix-encode the length as `int32` (-1 for null arrays)
and then write/read N elements in order. The library does not provide
generic array helpers — write the loop.

## Common pitfalls

- **Reading past the body.** `BinaryDecoder` tracks a buffer offset.
  Reading more bytes than the structure declared throws
  `EncodingException("Buffer underflow: …")`. Match the server's field
  count exactly.
- **Encoding length-prefixed nulls.** `writeString(null)` emits
  `int32(-1)`; `writeString("")` emits `int32(0)`. They are different
  on the wire and decoded as `null` vs `""` respectively.
- **Endianness.** OPC UA is little-endian, always. The encoder/decoder
  handle that for you — do not byte-swap manually.
- **Optional fields.** OPC UA structures with optional fields use a
  bitmask. Read the mask first (`UInt32` or whatever the type
  declares), then conditionally read each optional field. Discovery
  handles this; hand-written codecs must implement it.

## Sharing codecs across clients

`ExtensionObjectRepository` is **per-client**. Two `Client` instances
get independent repositories. This is deliberate — different servers
may use the same DataType NodeId for different structures.

For a shared registry across many clients (a Laravel container, a
Symfony service), build a small factory that creates the
`ClientBuilder` and registers the codecs in one place.

## Finding the type id

The DataType NodeId is published by the server. The shortest path:

<!-- @code-block language="text" label="manual discovery" -->
```text
1. Browse the variable in question and note its DataType NodeId.
2. Browse that DataType and inspect its DataTypeDefinition attribute,
   if present — the field shape is in there.
3. If absent, fall back to vendor docs or wire capture.
```
<!-- @endcode-block -->

Once you have the field shape, the codec writes itself.
