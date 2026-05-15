---
eyebrow: 'Docs · Types'
lede:    'Variant is the value container. DataValue wraps it with status and timestamps. Both are read-only; the conversion rules between PHP and OPC UA types matter.'

see_also:
  - { href: './built-in-types.md', meta: '5 min' }
  - { href: './extension-objects.md', meta: '5 min' }
  - { href: '../operations/writing-values.md', meta: '7 min' }

prev: { label: 'NodeId',           href: './node-id.md' }
next: { label: 'Extension objects', href: './extension-objects.md' }
---

# DataValue and Variant

`DataValue` is what `read()` returns. It carries:

- `value` (`Variant`) — the actual value, typed
- `statusCode` (`int`) — quality / error code, `0` = `Good`
- `sourceTimestamp` (`?DateTimeImmutable`) — when the device sampled
- `serverTimestamp` (`?DateTimeImmutable`) — when the server replied

A `Variant` carries:

- `type` (`BuiltinType`) — which OPC UA primitive this is
- `value` (`mixed`) — the PHP value (scalar or array)
- `dimensions` (`?array`) — for multidimensional arrays only

## Reading values

`DataValue::getValue()` unwraps the `Variant` to its native PHP value:

<!-- @code-block language="php" label="value extraction" -->
```php
$dv = $client->read('ns=2;s=Devices/PLC/Speed');

$dv->value;            // Variant — typed wrapper
$dv->getValue();       // float — unwrapped scalar
$dv->statusCode;       // int
$dv->sourceTimestamp;  // ?DateTimeImmutable
```
<!-- @endcode-block -->

`getValue()` also handles two convenience cases:

- **Decoded ExtensionObjects** — when a value is an `ExtensionObject`
  with a registered codec, `getValue()` returns the decoded body
  rather than the wrapper. Pass the wrapper itself via
  `$dv->value->value` if you need the raw `ExtensionObject`.
- **Arrays** — array `Variant`s return PHP arrays directly.

### Bad-status reads

A `DataValue` with `BadNodeIdUnknown` or `BadAttributeIdInvalid` will
still have a `value` field — usually a `Variant` of `Null`. Trust the
status code, not the value:

<!-- @code-block language="php" label="status before value" -->
```php
use PhpOpcua\Client\Types\StatusCode;

if (! StatusCode::isGood($dv->statusCode)) {
    throw new RuntimeException(
        'Read failed: ' . StatusCode::getName($dv->statusCode)
    );
}

$value = $dv->getValue();
```
<!-- @endcode-block -->

## PHP ↔ OPC UA type mapping

The library maps between PHP types and `BuiltinType` cases as follows:

| OPC UA `BuiltinType` | PHP type on read         | Accepted on write              |
| -------------------- | ------------------------ | ------------------------------ |
| `Boolean`            | `bool`                   | `bool`, `int` (0/1)            |
| `SByte` / `Byte`     | `int`                    | `int` in `-128..127` / `0..255` |
| `Int16` / `UInt16`   | `int`                    | `int` in range                 |
| `Int32` / `UInt32`   | `int`                    | `int`                          |
| `Int64` / `UInt64`   | `int`                    | `int`                          |
| `Float` / `Double`   | `float`                  | `float`, `int`                 |
| `String`             | `string`                 | `string`                       |
| `DateTime`           | `DateTimeImmutable`      | `DateTimeImmutable`, ISO string |
| `Guid`               | `string` (canonical hex) | `string` in canonical form     |
| `ByteString`         | `string` (raw bytes)     | `string` (raw bytes)           |
| `XmlElement`         | `string` (raw XML)       | `string`                       |
| `NodeId`             | `NodeId`                 | `NodeId`, string shorthand     |
| `StatusCode`         | `int`                    | `int`                          |
| `QualifiedName`      | `QualifiedName`          | `QualifiedName`                |
| `LocalizedText`      | `LocalizedText`          | `LocalizedText`                |
| `ExtensionObject`    | `ExtensionObject` (raw or decoded) | `ExtensionObject`    |
| `DataValue`          | `DataValue`              | `DataValue`                    |
| `Variant`            | `Variant`                | `Variant`                      |

`Float` is IEEE-754 single precision (32 bits). PHP's `float` is
internally double precision; round-trips through `Float` lose
precision below ~7 decimal digits.

`Int64` / `UInt64` use PHP's native `int` type, which is 64-bit on
LP64 systems (every supported platform). Values outside the signed
range will overflow into negative on the wire — be deliberate with
`UInt64` values above `2^63 - 1`.

## Building Variants explicitly

When the wire type matters and auto-detect is off, build a `Variant`:

<!-- @code-block language="php" label="explicit Variant" -->
```php
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\BuiltinType;

$v = new Variant(
    type: BuiltinType::Int32,
    value: 42,
);

$client->write('ns=2;s=Tag', $v);

// For arrays:
$arr = new Variant(
    type: BuiltinType::Double,
    value: [1.0, 2.0, 3.0],
);

// For 2D arrays:
$mat = new Variant(
    type: BuiltinType::Double,
    value: [1.0, 2.0, 3.0, 4.0],
    dimensions: [2, 2],
);
```
<!-- @endcode-block -->

`dimensions` is required for arrays with `valueRank > 1`. A 1-D array
leaves it `null` and the count is derived from the array size.

## Building DataValues

`DataValue` ships factory methods for the common write/test cases:

<!-- @code-block language="php" label="DataValue factories" -->
```php
use PhpOpcua\Client\Types\DataValue;

DataValue::ofBool(true);
DataValue::ofInt(42);
DataValue::ofDouble(3.14);
DataValue::ofString('hello');
DataValue::ofVariant(new Variant(BuiltinType::Int32, [1, 2, 3]));

// With explicit status:
DataValue::ofVariant($v, statusCode: 0x80000000);

// All factories accept optional timestamps:
DataValue::ofDouble(42.0, sourceTimestamp: new DateTimeImmutable());
```
<!-- @endcode-block -->

These are primarily useful in tests (see [Testing ·
Handlers](../testing/handlers.md)) but compose anywhere a `DataValue`
is needed.

## Timestamps

OPC UA encodes timestamps as 100-nanosecond ticks since 1601-01-01 UTC
(Windows `FILETIME` format). PHP's `DateTimeImmutable` resolves to
microseconds; the conversion truncates to the nearest microsecond on
read and zero-pads on write. The library transparently handles both
directions.

`DateTimeImmutable` is the only representation exposed; the raw 64-bit
tick value is not surfaced.

## Equality

`Variant` and `DataValue` are value objects. PHP's `==` does structural
equality:

<!-- @code-block language="php" label="value equality" -->
```php
$a = DataValue::ofInt(42);
$b = DataValue::ofInt(42);

$a == $b;     // true
$a === $b;    // false (different objects)
```
<!-- @endcode-block -->

The `statusCode` and timestamps participate in equality. To compare
only the underlying value, compare `$a->getValue() === $b->getValue()`.

## Common pitfalls

- **`getValue()` on a Null Variant.** Returns `null`. Always check
  `$dv->value->type !== BuiltinType::Null` before treating the value
  as present.
- **Float comparisons.** OPC UA `Float` (single precision) → PHP
  `float` (double precision) round-trips lose precision. Compare with
  a tolerance, never `===`.
- **DateTime in non-UTC contexts.** The library returns
  `DateTimeImmutable` in UTC. Reformat with `setTimezone()` for
  display, do not pass non-UTC instances back to `write()`.
- **String identifiers in NodeId.** A `Variant<NodeId>` whose
  identifier is `"path/with/slash"` round-trips correctly through
  the API — but printing it to logs loses the namespace prefix unless
  you cast the `NodeId` to string yourself.
