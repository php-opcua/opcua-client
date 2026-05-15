---
eyebrow: 'Docs · Types'
lede:    'BuiltinType is the 25-case enum that names every primitive in OPC UA. This is the reference table — what each case means, how it maps to PHP, and what range it spans.'

see_also:
  - { href: './data-value-and-variant.md', meta: '6 min' }
  - { href: '../operations/writing-values.md', meta: '7 min' }
  - { href: '../reference/enums.md',           meta: '6 min' }

prev: { label: 'Extension objects', href: './extension-objects.md' }
next: { label: 'Modules',           href: '../extensibility/modules.md' }
---

# Built-in types

`PhpOpcua\Client\Types\BuiltinType` enumerates the 25 OPC UA built-in
primitive types defined in the spec (Part 6 §5.1). Every `Variant`
carries one of these; every `DataType` node ultimately resolves to one
through its supertype chain.

This page is the field guide.

## The table

| `BuiltinType`        | OPC UA id | PHP type             | Range / format                                   |
| -------------------- | --------- | -------------------- | ------------------------------------------------ |
| `Boolean`            | 1         | `bool`               | true / false                                     |
| `SByte`              | 2         | `int`                | -128 .. 127                                      |
| `Byte`               | 3         | `int`                | 0 .. 255                                         |
| `Int16`              | 4         | `int`                | -32 768 .. 32 767                                |
| `UInt16`             | 5         | `int`                | 0 .. 65 535                                      |
| `Int32`              | 6         | `int`                | -2 147 483 648 .. 2 147 483 647                  |
| `UInt32`             | 7         | `int`                | 0 .. 4 294 967 295                               |
| `Int64`              | 8         | `int`                | -9.22e18 .. 9.22e18 (PHP `int` on LP64)          |
| `UInt64`             | 9         | `int`                | 0 .. 9.22e18 (signed-int range; see below)       |
| `Float`              | 10        | `float`              | IEEE-754 binary32 (~7 decimal digits)            |
| `Double`             | 11        | `float`              | IEEE-754 binary64 (~15-17 decimal digits)        |
| `String`             | 12        | `string`             | UTF-8, length-prefixed on the wire               |
| `DateTime`           | 13        | `DateTimeImmutable`  | UTC, microsecond resolution                      |
| `Guid`               | 14        | `string`             | Canonical 8-4-4-4-12 uppercase hex               |
| `ByteString`         | 15        | `string`             | Raw bytes, length-prefixed                       |
| `XmlElement`         | 16        | `string`             | Raw XML, length-prefixed                         |
| `NodeId`             | 17        | `NodeId`             | See [NodeId](./node-id.md)                       |
| `ExpandedNodeId`     | 18        | `NodeId` (namespace URI ignored) | Reduced to `NodeId` on read              |
| `StatusCode`         | 19        | `int`                | 32-bit OPC UA status                             |
| `QualifiedName`      | 20        | `QualifiedName`      | `(namespaceIndex, name)`                         |
| `LocalizedText`      | 21        | `LocalizedText`      | `(locale, text)`                                 |
| `ExtensionObject`    | 22        | `ExtensionObject`    | See [Extension objects](./extension-objects.md)  |
| `DataValue`          | 23        | `DataValue`          | Nested DataValue                                 |
| `Variant`            | 24        | `Variant`            | Nested Variant                                   |
| `DiagnosticInfo`     | 25        | (rarely surfaced)    | Server diagnostic detail                         |

## Integer ranges

On every platform this library supports (PHP 8.2+ on Linux, macOS,
Windows 64-bit), PHP's native `int` is 64-bit signed. That makes:

- **`SByte`, `Byte`, `Int16`, `UInt16`, `Int32`** straightforward —
  PHP `int` can hold the full range, and overflow is your problem on
  write.
- **`UInt32`** safe (up to ~4.3 billion, well within PHP `int`).
- **`Int64`** safe — PHP `int` matches the OPC UA `Int64` range
  exactly.
- **`UInt64`** **half-safe.** PHP `int` is signed 64-bit. Values from
  `0` to `2^63 - 1` round-trip cleanly; values above (the upper half
  of the `UInt64` range) become negative in PHP and the wire encoding
  reinterprets them as the high range — which is correct on the wire,
  but you cannot compare them in PHP with `>` or `<` and get the right
  answer.

If you need full `UInt64` arithmetic in PHP, use `bcmath` or `gmp` on
the application side — the wire layer cannot fix the type mismatch.

## Float vs Double

`Float` is IEEE-754 binary32 — 32 bits, ~7 decimal digits of
precision. PHP `float` is binary64 internally. The library passes the
PHP value through; rounding to single precision happens on the wire.

A `Float` value of `0.1` reads back as `0.10000000149011612` — that is
the closest binary32 to `0.1`. Compare floats from the wire with a
tolerance (`abs($a - $b) < 1e-6`), never `===`.

`Double` round-trips losslessly.

## String, ByteString, XmlElement — all "binary"

All three are length-prefixed byte sequences on the wire. They differ
in *intent*, not encoding:

- **`String`** — declared UTF-8. The library does not currently
  validate UTF-8 on read; the bytes are returned as-is, and PHP's
  string semantics treat them as opaque. If the server publishes
  invalid UTF-8, you'll see the raw bytes.
- **`ByteString`** — arbitrary binary. No encoding assumptions.
- **`XmlElement`** — XML serialised as bytes. The library does not
  parse it; you receive the raw string.

If you need a length-prefixed string of arbitrary bytes, use
`ByteString`, not `String` — the type tag is the only thing telling
servers (and your downstream code) which to expect.

## DateTime

OPC UA `DateTime` is 100-nanosecond ticks since 1601-01-01 UTC
(Windows `FILETIME`). PHP `DateTimeImmutable` resolves to
microseconds. The library truncates to microseconds on read and
zero-pads on write — sub-microsecond precision from the device is
lost.

The minimum representable value (`0`) decodes to `1601-01-01 00:00:00
UTC`. Several servers use this as a sentinel for "no timestamp"; treat
it as null in your application logic if you observe it.

## GUID

OPC UA `Guid` is a 16-byte UUID. The PHP representation is the
canonical 8-4-4-4-12 uppercase hex string with hyphens
(`72962B91-FA75-4AE6-8D28-B404DC7DAE63`). Pass lowercase on write —
the library normalises before encoding.

## Variant cases that surface less often

Some `BuiltinType` cases are rare in everyday code but you may see
them in nested DataValue / Variant structures returned by complex
services:

- **`ExpandedNodeId`** — `NodeId` plus an explicit namespace URI and
  server index. This library reduces it to a plain `NodeId` on read;
  the URI and server index are dropped.
- **`DiagnosticInfo`** — server-side diagnostic detail attached to a
  bad status code. Rarely populated by production servers; surfaced
  in the library as a nested decoded record.

## Array forms

Every `BuiltinType` has an array form. The wire format flags it
through `Variant`'s `arrayDimensions` field; on the PHP side, a
`Variant` is constructed with `value: [/* php array */]` and
optionally `dimensions: [d1, d2, …]` for multidimensional arrays.

See [Recipes · Writing typed arrays](../recipes/writing-typed-arrays.md).

## Auto-detection rules

When write auto-detection is on and you do not pass `$type` to
`write()`, the library reads the node's `DataType` attribute and maps
it through the supertype chain to a `BuiltinType`. The mapping rules:

| If `DataType` resolves to                   | `BuiltinType` chosen |
| ------------------------------------------- | -------------------- |
| One of the 25 built-in DataTypes            | The corresponding case |
| An `Enumeration`                            | `Int32`              |
| A subtype of `String` (e.g. `LocaleId`)     | `String`             |
| A subtype of `Integer`                      | The integer subtype's bit width and signedness |
| A subtype of `Number` that is not an integer | `Double` (lossy if the spec demanded `Float`) |
| A `Structure` with a registered codec       | `ExtensionObject`    |
| A `Structure` without a codec               | `WriteTypeDetectionException` |

If you write a Float-typed node, pass `BuiltinType::Float` explicitly
or live with the lossy mapping above.
