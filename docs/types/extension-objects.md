---
eyebrow: 'Docs · Types'
lede:    'An ExtensionObject is OPC UA''s answer to "structured value" — anything more complex than a primitive arrives wrapped in one. Decode them with a codec, or keep them raw and route the bytes to your own decoder.'

see_also:
  - { href: '../extensibility/extension-object-codecs.md', meta: '7 min' }
  - { href: '../extensibility/type-discovery.md',           meta: '6 min' }
  - { href: './data-value-and-variant.md',                  meta: '6 min' }

prev: { label: 'DataValue and Variant', href: './data-value-and-variant.md' }
next: { label: 'Built-in types',        href: './built-in-types.md' }
---

# Extension objects

When an OPC UA server publishes a value that is more structured than a
primitive — a 3-D vector, a vendor-specific status record, an OPC UA
`Argument` description — the wire format wraps it in an
**ExtensionObject**: a tagged binary blob whose `typeId` tells the
client which decoder to use.

The library exposes ExtensionObjects through the `Types\ExtensionObject`
class. It is read-only and has two flavours:

- **Raw** — the bytes are still encoded, because no codec was
  registered for this `typeId`. `body` holds the bytes, `value` is
  null.
- **Decoded** — a codec ran and produced a structured value.
  `body` is null, `value` holds the decoded payload.

## The shape

| Property        | Type        | Meaning                                          |
| --------------- | ----------- | ------------------------------------------------ |
| `typeId`        | `NodeId`    | DataType NodeId of the structure                 |
| `encoding`      | `int`       | `0` = none, `1` = binary body, `2` = XML body    |
| `body`          | `?string`   | Encoded bytes (if raw) or `null` (if decoded)    |
| `value`         | `mixed`     | Decoded value (if codec ran) or `null` (if raw)  |

Two helper methods:

<!-- @code-block language="php" label="raw vs decoded" -->
```php
$ext = $dv->value->value;            // assuming the Variant is an ExtensionObject

if ($ext->isDecoded()) {
    $payload = $ext->value;          // structured PHP value from the codec
} elseif ($ext->isRaw()) {
    $bytes   = $ext->body;           // forward to your own decoder
}
```
<!-- @endcode-block -->

## DataValue auto-extraction

`DataValue::getValue()` does one extra step for ExtensionObjects: if
the wrapper is decoded, it returns the decoded `value` directly. You
rarely interact with `ExtensionObject` directly:

<!-- @code-block language="php" label="auto-unwrap" -->
```php
$dv = $client->read('ns=2;s=Devices/PLC/Vector3D');

// If a codec for the Vector3D type is registered:
$vector = $dv->getValue();    // ['x' => 1.0, 'y' => 2.0, 'z' => 3.0]

// If no codec is registered:
$ext = $dv->getValue();       // ExtensionObject (raw)
echo $ext->typeId;
echo bin2hex($ext->body);
```
<!-- @endcode-block -->

## Registering a codec

Codecs are per-client, not static. Build one that implements
`Encoding\ExtensionObjectCodec` and register it via the repository:

<!-- @code-block language="php" label="register a codec" -->
```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Encoding\ExtensionObjectCodec;
use PhpOpcua\Client\Types\NodeId;

class Vector3DCodec implements ExtensionObjectCodec
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

$builder = ClientBuilder::create();
$builder->getExtensionObjectRepository()
    ->register(NodeId::numeric(2, 5001), Vector3DCodec::class);
$client = $builder->connect('opc.tcp://plc.local:4840');
```
<!-- @endcode-block -->

Detail in [Extensibility · Extension object
codecs](../extensibility/extension-object-codecs.md).

## Automatic codec generation

For OPC UA 1.04+ servers that publish `DataTypeDefinition` attributes,
`discoverDataTypes()` synthesises codecs from those definitions:

<!-- @code-block language="php" label="auto-discovery" -->
```php
$discovered = $client->discoverDataTypes(namespaceIndex: 2);
echo "Discovered {$discovered} dynamic structure types.\n";

// Any read that returns an ExtensionObject of a discovered type is now
// decoded automatically. No manual codec registration required.
$value = $client->read('ns=2;s=Sensors/Pump1/Status')->getValue();
```
<!-- @endcode-block -->

See [Extensibility · Type discovery](../extensibility/type-discovery.md).

## Encoding for writes

Writing an ExtensionObject means either:

1. **Decoded path** — pass the structured PHP value to a write call
   that knows the type, with auto-detect on. The library encodes via
   the registered codec.

2. **Raw path** — build an `ExtensionObject` manually with the
   pre-encoded bytes:

<!-- @code-block language="php" label="raw write" -->
```php
use PhpOpcua\Client\Types\ExtensionObject;
use PhpOpcua\Client\Types\NodeId;

$ext = new ExtensionObject(
    typeId: NodeId::numeric(2, 5001),
    encoding: 1,                     // binary body
    body: $rawBytes,                 // produced by your own encoder
    value: null,
);

$client->write('ns=2;s=Devices/PLC/Vector3D', $ext);
```
<!-- @endcode-block -->

Raw writes are useful when the type is exotic and you have an existing
binary serializer (a `.proto`, a `Argument` builder, …) you'd rather
keep using.

## Limitations

- **Binary encoding only.** XML-encoded ExtensionObjects (encoding
  `2`) are decoded as raw bytes; the library does not ship an XML
  schema decoder.
- **No built-in codecs.** The library ships zero pre-registered codecs.
  The well-known `Argument` and `EnumValueType` structures, used by
  the spec itself, must be registered manually or discovered.
- **Repository is instance-level.** Each `Client` has its own
  `ExtensionObjectRepository`. There is no global codec table — by
  design, so two clients targeting different servers cannot bleed
  codecs into each other.

The repository surface:

<!-- @code-block language="php" label="repository API" -->
```php
$repo = $client->getExtensionObjectRepository();

$repo->register(NodeId::numeric(2, 5001), Vector3DCodec::class);
$repo->unregister(NodeId::numeric(2, 5001));
$repo->has(NodeId::numeric(2, 5001));     // bool
$repo->get(NodeId::numeric(2, 5001));     // ?ExtensionObjectCodec
$repo->clear();                           // remove all
```
<!-- @endcode-block -->
