---
eyebrow: 'Docs · Recipes'
lede:    'Array writes work; the auto-detect path handles "array of T" when the node''s DataType is T[]. For anything stranger — multidimensional, mixed-type, sparse — drop to Variant.'

see_also:
  - { href: '../operations/writing-values.md',     meta: '7 min' }
  - { href: '../types/data-value-and-variant.md',  meta: '6 min' }
  - { href: '../types/built-in-types.md',          meta: '5 min' }

prev: { label: 'Subscribing to data changes',  href: './subscribing-to-data-changes.md' }
next: { label: 'Detecting server capabilities', href: './detecting-server-capabilities.md' }
---

# Writing typed arrays

OPC UA distinguishes between a scalar variable, a one-dimensional
array, and a multidimensional array — they have the same `DataType`
but different `ValueRank` and `ArrayDimensions`. Writing each shape
correctly is mostly mechanical; the gotchas are at the type-detection
seam.

## The simple case — 1-D, matching DataType

When the node's `DataType` is `Double` and you write `[1.0, 2.0,
3.0]`, auto-detect does the right thing:

<!-- @code-block language="php" label="examples/write-double-array.php" -->
```php
$client->write('ns=2;s=Setpoints', [10.0, 20.0, 30.0]);
// Auto-detected as Variant<Double[]>
```
<!-- @endcode-block -->

This works because the metadata read returns `DataType: Double`,
`ValueRank: 1` (1-D array). The library sees the PHP array, queries
the node's type, and encodes the values as a `Double` array.

## The first gotcha — element type ambiguity

`[1, 2, 3]` is an array of `int`. PHP `int` maps to `Int32` by
default. If the node's DataType is `Double`, the auto-detect path
notices the mismatch and either:

- **Casts to `Double` silently** when the cast is lossless.
- **Raises `WriteTypeMismatchException`** when it isn't (rare, but
  e.g. an unsigned target with a negative value).

To be explicit, pass the element type:

<!-- @code-block language="php" label="explicit element type" -->
```php
use PhpOpcua\Client\Types\BuiltinType;

$client->write('ns=2;s=Setpoints', [1, 2, 3], BuiltinType::Double);
// Encoded as Variant<Double[]> = [1.0, 2.0, 3.0]
```
<!-- @endcode-block -->

The explicit type wins. The library does **not** revalidate against
the server's `DataType` — if you tell it `Float`, it writes `Float`,
even if the server then rejects with `BadTypeMismatch`.

## Building an explicit Variant

For tighter control, build a `Variant` and pass it directly:

<!-- @code-block language="php" label="explicit Variant array" -->
```php
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\BuiltinType;

$client->write('ns=2;s=Setpoints', new Variant(
    type: BuiltinType::Double,
    value: [10.0, 20.0, 30.0],
));
```
<!-- @endcode-block -->

The `Variant` carries the type tag explicitly. The library passes it
through unchanged.

## Multidimensional arrays

OPC UA arrays can be multidimensional. The wire format is row-major
ordering plus an `ArrayDimensions` field. In PHP, that means a flat
array plus a `dimensions` argument on the `Variant`:

<!-- @code-block language="php" label="2-D array write" -->
```php
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\BuiltinType;

$matrix = [
    [1.0, 2.0, 3.0],
    [4.0, 5.0, 6.0],
];

// Flatten row-major; pass dimensions explicitly.
$flat = array_merge(...$matrix);

$client->write('ns=2;s=Matrix', new Variant(
    type: BuiltinType::Double,
    value: $flat,
    dimensions: [2, 3],   // 2 rows, 3 cols
));
```
<!-- @endcode-block -->

Auto-detect handles 1-D arrays. For ≥ 2-D, you must build the
`Variant` yourself — there is no shortcut.

## Sparse arrays and nulls

OPC UA distinguishes between:

- **Empty array** — `Variant<Double[]>` with zero elements
- **Null array** — `Variant<Null>` (no elements, no type tag)

PHP `[]` writes as an empty array; PHP `null` writes as a null
`Variant`. The server may treat them differently — read the variable
description if it matters.

For per-element nulls in an array, the spec defines `Variant`-typed
arrays where each element is a `Variant` (which itself can be `Null`):

<!-- @code-block language="php" label="array of nullable values" -->
```php
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\BuiltinType;

$mixed = [
    new Variant(BuiltinType::Double, 1.5),
    new Variant(BuiltinType::Null, null),     // "no value"
    new Variant(BuiltinType::Double, 3.5),
];

$client->write('ns=2;s=NullableArray', new Variant(
    type: BuiltinType::Variant,
    value: $mixed,
));
```
<!-- @endcode-block -->

This shape is rare in industrial servers (most do not use the
`Variant` element type), but it is the spec-supported way to carry
sparse arrays.

## Writing structured arrays — ExtensionObject

For an array of structures (`Vector3D[]`, a custom DTO[]), encode
each element with its codec and bundle them:

<!-- @code-block language="php" label="array of ExtensionObjects" -->
```php
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\ExtensionObject;
use PhpOpcua\Client\Types\NodeId;

$vectors = [
    new ExtensionObject(NodeId::numeric(2, 5001), encoding: 1, body: null, value: ['x' => 1.0, 'y' => 2.0, 'z' => 3.0]),
    new ExtensionObject(NodeId::numeric(2, 5001), encoding: 1, body: null, value: ['x' => 4.0, 'y' => 5.0, 'z' => 6.0]),
];

$client->write('ns=2;s=Trajectory', new Variant(
    type: BuiltinType::ExtensionObject,
    value: $vectors,
));
```
<!-- @endcode-block -->

The codec registered for `ns=2;i=5001` (see [Extensibility ·
Extension object codecs](../extensibility/extension-object-codecs.md))
handles the per-element encoding.

## Per-element status on writeMulti

`writeMulti()` returns an `int[]` of status codes — one per write
operation. For arrays, the entire array succeeds or fails as a unit:

<!-- @code-block language="php" label="writeMulti returns" -->
```php
$statuses = $client->writeMulti([
    ['nodeId' => 'ns=2;s=Setpoints',  'value' => [10.0, 20.0, 30.0]],
    ['nodeId' => 'ns=2;s=Mode',       'value' => 1, 'type' => BuiltinType::Int32],
    ['nodeId' => 'ns=2;s=Labels',     'value' => ['auto', 'manual']],
]);

// $statuses[0] is the status of the entire Setpoints array write.
// You don't get per-element status — that's the OPC UA protocol shape.
```
<!-- @endcode-block -->

If a single element is out-of-range, the whole array write fails with
`BadOutOfRange` and **no** elements are written. There is no
"partial write" semantics in the Write service.

## Pitfalls to remember

- **`array_values()` before writing.** PHP arrays are ordered maps;
  non-zero-based or non-contiguous keys produce arrays the wire
  format does not expect. `array_values($arr)` is a cheap safety net.
- **Boolean arrays use 1 byte per element, not 1 bit.** Don't write
  `[true, false, true]` to a node whose DataType is `BitField`; the
  spec has separate encodings.
- **Mixed numeric types collapse.** PHP `[1, 2.5, 3]` is array of
  `float` after the cast. The library auto-detects `Double`. Pass
  homogeneous arrays.
