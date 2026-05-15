---
eyebrow: 'Docs · Operations'
lede:    'Write one tag or many. The library detects the right OPC UA type for you by default; pass an explicit BuiltinType when you need to override that decision.'

see_also:
  - { href: './reading-attributes.md', meta: '7 min' }
  - { href: '../types/built-in-types.md', meta: '6 min' }
  - { href: '../recipes/writing-typed-arrays.md', meta: '4 min' }

prev: { label: 'Reading attributes', href: './reading-attributes.md' }
next: { label: 'Browsing',           href: './browsing.md' }
---

# Writing values

`write()` updates a node's `Value` attribute. Attribute-level writes
(e.g. changing a `DisplayName`) are theoretically possible via the OPC
UA Write service but rare in practice and not exposed on this library's
typed API — for those, drop down to a custom module.

## Single value

<!-- @method name="$client->write(NodeId|string \$nodeId, mixed \$value, ?BuiltinType \$type = null): int" returns="int (StatusCode)" visibility="public" -->

<!-- @params -->
<!-- @param name="$nodeId" type="NodeId|string" required -->
The target node.
<!-- @endparam -->
<!-- @param name="$value" type="mixed" required -->
The PHP value to write. Accepts `bool`, `int`, `float`, `string`,
`DateTimeImmutable`, `NodeId`, `Variant`, `ExtensionObject`, and arrays
of any of the above.
<!-- @endparam -->
<!-- @param name="$type" type="?BuiltinType" default="null" -->
Override the OPC UA type used on the wire. When `null`, the library
auto-detects the type — see "Type detection" below.
<!-- @endparam -->
<!-- @endparams -->

<!-- @code-block language="php" label="basic write" -->
```php
use PhpOpcua\Client\Types\StatusCode;

$status = $client->write('ns=2;s=Devices/PLC/Setpoint', 42.5);

if (! StatusCode::isGood($status)) {
    throw new RuntimeException(
        'Write rejected: ' . StatusCode::getName($status)
    );
}
```
<!-- @endcode-block -->

The return value is the per-item `StatusCode` (an `int`). A `Good`
status (`0`) means the server accepted the write. Common bad statuses:

| StatusCode                   | Meaning                                           |
| ---------------------------- | ------------------------------------------------- |
| `BadNodeIdUnknown`           | The node does not exist on this server            |
| `BadUserAccessDenied`        | Session is not authorised to write                |
| `BadTypeMismatch`            | The value does not match the node's `DataType`    |
| `BadOutOfRange`              | Value is outside the declared engineering range   |
| `BadWriteNotSupported`       | The attribute is read-only on this server         |

## Type detection

By default, the builder enables write-type auto-detection
(`setAutoDetectWriteType(true)`). When `$type` is omitted, the client:

<!-- @steps -->
- **Checks the metadata cache.**

  If the node's `DataType` is already cached, use it directly — no
  server round-trip.

- **Otherwise, reads the node's `DataType` attribute.**

  Dispatches `WriteTypeDetecting`, issues a Read, and caches the
  result. The detection adds one round-trip on the first write to each
  node.

- **Maps the OPC UA `DataType` NodeId to a `BuiltinType`.**

  Standard built-ins (`Boolean`, `Int32`, `Double`, `String`, …) map
  directly. Custom DataTypes that resolve to a built-in via their
  supertype chain also map.

- **Encodes the PHP value as that built-in.**

  Dispatches `WriteTypeDetected` with the chosen type and a
  `fromCache` flag, then sends the WriteRequest.
<!-- @endsteps -->

Disable detection when you want absolute control:

<!-- @code-block language="php" label="disable auto-detect" -->
```php
$client = ClientBuilder::create()
    ->setAutoDetectWriteType(false)
    ->connect('opc.tcp://plc.local:4840');

// Now $type is mandatory.
$client->write('ns=2;s=Tag', 42, BuiltinType::Int32);
```
<!-- @endcode-block -->

### Detection failures

`WriteTypeDetectionException` is raised when:

- The `DataType` read returns a bad status.
- The DataType cannot be mapped to a `BuiltinType` (a structure without
  a registered codec, an unknown abstract type).

`WriteTypeMismatchException` is reserved for cases where an explicit
`$type` passed by the caller would clash with a known DataType. The
library does not currently raise it during normal write paths — the
class exists for custom validation code and future static-analysis
hooks.

## Multiple writes — writeMulti

<!-- @code-block language="php" label="writeMulti — array form" -->
```php
use PhpOpcua\Client\Types\BuiltinType;

$statuses = $client->writeMulti([
    ['nodeId' => 'ns=2;s=Setpoint', 'value' => 42.5],
    ['nodeId' => 'ns=2;s=Mode',     'value' => 1, 'type' => BuiltinType::Int32],
    ['nodeId' => 'ns=2;s=Label',    'value' => 'auto'],
]);

foreach ($statuses as $i => $status) {
    if (! StatusCode::isGood($status)) {
        // Inspect the i-th entry
    }
}
```
<!-- @endcode-block -->

The return is an `int[]` parallel to the request array.

### Fluent builder

<!-- @code-block language="php" label="writeMulti — fluent form" -->
```php
$statuses = $client->writeMulti()
    ->node('ns=2;s=Setpoint')->value(42.5)
    ->node('ns=2;s=Mode')->int32(1)
    ->node('ns=2;s=Label')->string('auto')
    ->execute();
```
<!-- @endcode-block -->

The builder exposes one method per `BuiltinType` (`boolean`, `byte`,
`int16`, `uint16`, `int32`, `uint32`, `int64`, `uint64`, `float`,
`double`, `string`, …). Each fixes the wire-type for that entry and
skips auto-detection.

| Builder method        | OPC UA type        |
| --------------------- | ------------------ |
| `value(mixed)`        | auto-detected      |
| `typed(mixed, BuiltinType)` | explicit       |
| `boolean(bool)`       | `Boolean`          |
| `int32(int)` / `uint32(int)` | `Int32` / `UInt32` |
| `double(float)`       | `Double`           |
| `string(string)`      | `String`           |
| `byteString(string)`  | `ByteString` (raw bytes) |
| `dateTime(DateTimeImmutable)` | `DateTime` |
| `localizedText(LocalizedText)` | `LocalizedText` |
| `nodeId(NodeId)`      | `NodeId`           |

## Writing arrays

Arrays use the corresponding `Variant` array form. The library detects
"array of T" automatically when auto-detect is on; with explicit types,
the same `BuiltinType` applies to every element.

<!-- @code-block language="php" label="array write" -->
```php
$client->write('ns=2;s=Setpoints', [10.0, 20.0, 30.0]);
// Auto-detected as Variant<Double[]> if the node's DataType is Double.

$client->write('ns=2;s=Setpoints', [10.0, 20.0, 30.0], BuiltinType::Double);
// Explicit — works even when auto-detect is off.
```
<!-- @endcode-block -->

For mixed-type arrays, use `Variant` directly or write multiple
individual values. See [Recipes · Writing typed
arrays](../recipes/writing-typed-arrays.md).

## Write events

The write path dispatches three PSR-14 events:

- `WriteTypeDetecting` — before detection starts (carries the node)
- `WriteTypeDetected` — after detection (carries `detectedType` and
  `fromCache`)
- `NodeValueWritten` — on success (carries the value and the
  status code)
- `NodeValueWriteFailed` — on a bad status code (carries the failure
  details)

Wire a dispatcher to instrument write traffic — see [Observability ·
Events](../observability/events.md).
