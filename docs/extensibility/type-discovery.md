---
eyebrow: 'Docs · Extensibility'
lede:    'discoverDataTypes() reads the DataTypeDefinition attribute of every type in a namespace and synthesises a codec from each. One call, no hand-written codecs.'

see_also:
  - { href: './extension-object-codecs.md',   meta: '7 min' }
  - { href: '../types/extension-objects.md',  meta: '5 min' }
  - { href: '../observability/caching.md',    meta: '5 min' }

prev: { label: 'Extension object codecs', href: './extension-object-codecs.md' }
next: { label: 'Wire serialization',      href: './wire-serialization.md' }
---

# Type discovery

OPC UA 1.04 introduced the `DataTypeDefinition` attribute on
`DataType` nodes — a `StructureDefinition` or `EnumDefinition` ExtensionObject
describing the field layout of a custom structure. Servers that
populate this attribute let clients synthesise codecs at runtime, no
hand-written code required.

`discoverDataTypes()` is the call.

## The call

<!-- @method name="$client->discoverDataTypes(?int \$namespaceIndex = null, bool \$useCache = true): int" returns="int (count discovered)" visibility="public" -->

<!-- @code-block language="php" label="discover everything" -->
```php
$count = $client->discoverDataTypes();

echo "Discovered {$count} structure types.\n";

// Subsequent reads/writes of those types decode/encode automatically.
$value = $client->read('ns=2;s=Sensors/Pump1/Status')->getValue();
```
<!-- @endcode-block -->

<!-- @params -->
<!-- @param name="$namespaceIndex" type="?int" default="null" -->
Filter to a single namespace. `null` (the default) scans every
non-zero namespace. Use the filter when you only care about a vendor's
extensions and want to skip the rest.
<!-- @endparam -->
<!-- @param name="$useCache" type="bool" default="true" -->
Cache the discovered `StructureDefinition[]` keyed by NodeId. On a
warm cache, `discoverDataTypes()` replays the registrations without
any server round-trip — useful in worker fleets and on short-lived
PHP processes where the cache is shared.
<!-- @endparam -->
<!-- @endparams -->

The return is the count of types successfully registered. Failures
(types whose `DataTypeDefinition` is missing or malformed) are skipped
silently — they remain decodable as raw `ExtensionObject`.

## What it actually does

<!-- @steps -->
- **Browses every namespace** (or only `$namespaceIndex`) for nodes
  with `NodeClass::DataType` that are subtypes of `Structure`
  (`i=22`).

- **Reads `DataTypeDefinition`** on each.

  Parses it as `StructureDefinition` (`fields`, `baseDataType`,
  `structureType`) or `EnumDefinition`.

- **Synthesises a `DynamicCodec`** per structure.

  The codec interprets the fields in declaration order, recursively
  decoding nested structures whose codecs were discovered in the same
  pass.

- **Registers the codec** in the client's
  `ExtensionObjectRepository`.

  Existing manually-registered codecs are **never overwritten** —
  hand-written codecs always win.
<!-- @endsteps -->

## What it does not cover

- **XML-encoded structures.** Discovery emits binary codecs only. A
  server that publishes structures with `encoding = 2` (XML body)
  remains opaque.
- **Enums.** Enum values arrive as `Int32` on the wire; the library
  uses the enum's `EnumValues` to surface the name string on `getValue()`
  when a discovery pass has recorded the enum mapping. The numeric
  value is always preserved.
- **Optional and union fields.** Discovery handles
  `StructureType::Structure` and `StructureWithOptionalFields`. Unions
  (`StructureType::Union`, `UnionWithSubtypedValues`) are read into a
  generic shape — refine with a hand-written codec if you need typed
  union dispatch.
- **Pre-1.04 servers.** No `DataTypeDefinition` published means
  discovery can find the type but cannot decode it. Hand-write a codec
  per [Extension object codecs](./extension-object-codecs.md).

## Caching across processes

The discovery pass is the most expensive operation the library issues
during normal use — every `DataType` node is browsed and read. A cold
discovery against a vendor with 200 custom types is 200+ round-trips.

To amortise:

1. Configure a persistent PSR-16 cache (FileCache, Redis-backed,
   Laravel Cache) on the builder via `setCache()`.
2. Call `discoverDataTypes()` once per server URL. The cache key
   includes the endpoint hash; replays do not hit the server.
3. Flush selectively on schema deploy:
   `$client->invalidateCache(/* known DataType NodeId */)`.

The cached representation goes through the v4.3.0 cache codec — see
[Security · Cache path hardening](../security/cache-path-hardening.md)
and [Observability · Caching](../observability/caching.md).

## Inspecting what was discovered

The client does not surface a list of registered codecs directly. Two
indirect paths:

- **Through the repository:**
  `$client->getExtensionObjectRepository()->has(NodeId::numeric(2, 5001))`
  returns `true` if a codec — discovered or hand-written — exists for
  that type.
- **Through events:** `DataTypesDiscovered` is dispatched after every
  successful discovery pass, with the array of discovered NodeIds.
  Wire a listener.

## When to call it

- **On startup, after `connect()`.** A long-running worker process can
  pay the discovery cost once.
- **Lazily, on first read failure.** A worker that fails to decode an
  unknown ExtensionObject can call `discoverDataTypes($ns)` for the
  namespace in question and retry. Costly the first time, free
  thereafter.
- **Never, if your servers do not publish `DataTypeDefinition`.** The
  call is a no-op against pre-1.04 servers — it costs round-trips and
  registers nothing. Use hand-written codecs.

## Limitations to remember

- Discovery is **per-client**. Two clients targeting the same server
  each pay the cost — share a PSR-16 cache to make them share the
  result.
- Discovery does not currently re-run on `reconnect()`. A server that
  publishes new types mid-session needs an explicit second call.
- Discovery does not respect type-versioning. If a server changes a
  structure's field shape between two discovery passes, the cache
  holds the old shape until invalidated; live reads will decode
  against the stale codec and may produce garbage.

If the address space is dynamic enough that these limitations bite,
disable caching (`useCache: false`) and re-discover on demand.
