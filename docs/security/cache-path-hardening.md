---
eyebrow: 'Docs · Security'
lede:    'Since v4.3.0 the client never calls unserialize() on cache values. Everything stored in the PSR-16 backend goes through a JSON codec gated by a type allowlist — the gadget-chain surface is zero by construction.'

see_also:
  - { href: '../observability/caching.md',    meta: '5 min' }
  - { href: '../recipes/upgrading-to-v4.3.md', meta: '4 min' }
  - { href: '../extensibility/wire-serialization.md', meta: '6 min' }

prev: { label: 'Trust store',     href: './trust-store.md' }
next: { label: 'Types overview',  href: '../types/overview.md' }
---

# Cache path hardening

<!-- @version-badge type="added" version="v4.3.0" --> Cache storage is
gated by `Cache\CacheCodecInterface`. The default implementation,
`Cache\WireCacheCodec`, is JSON-only and validates every decoded type
against an explicit allowlist.

## The threat

PSR-16 cache backends are infrastructure. Redis, Memcached, file caches
mounted on shared volumes — none of them authenticate the writer. If a
caller other than the client can write to the cache, classic
`unserialize()`-based PHP cache code is an object-injection sink: a
crafted payload triggers `__wakeup` / `__destruct` chains in any class
the autoloader can reach.

Pre-v4.3.0, this library cached browse and resolve results with
`serialize()` / `unserialize()` exactly like every other PHP cache
library, with `allowed_classes` filtering to limit the surface. The
filtering helped, but the threat model still depended on every class
in the autoload graph being free of dangerous magic methods — a
property no application can guarantee.

## The fix

In v4.3.0, every cache code path was converted to:

- **Encode** through `Cache\WireCacheCodec::encode()` → JSON, with each
  typed payload wrapped in `{"__t": "<type-id>", …}`.
- **Decode** through `Cache\WireCacheCodec::decode()` → JSON parse,
  then look up the `__t` discriminator in `Wire\WireTypeRegistry`. The
  registry maps a small, hand-listed set of type IDs to constructor
  closures. Unknown IDs raise `Exception\CacheCorruptedException` —
  the client catches it internally and treats the entry as a miss.

There is **no call to `unserialize()` anywhere on the cache path**.
The registry installs only the types this library actually caches
(`StructureDefinition`, `StructureField`, plus the core value
objects); an attacker would need to forge a JSON payload that
deserialises into one of those types, with the field shape the
constructor expects, and trigger interesting behaviour from there.
That is a much smaller attack surface than a generic PHP gadget chain.

## What is cached, exactly

| Source                       | Cached?                                  |
| ---------------------------- | ---------------------------------------- |
| Browse results               | Yes — `ReferenceDescription[]`           |
| `browseAll` / `browseRecursive` | Yes — composed from the above         |
| `resolveNodeId` results      | Yes — single `NodeId`                    |
| `getEndpoints` results       | Yes — `EndpointDescription[]`            |
| `discoverDataTypes` results  | Yes — `StructureDefinition[]` keyed by NodeId |
| Read metadata (when enabled) | Yes — `DataValue` for non-`Value` attributes |
| **Read Value (attribute 13)**| **Never cached**                         |
| Write results                | Never cached                             |
| Method call results          | Never cached                             |

`Value` is deliberately uncached — PLC tag values change too fast to
benefit, and caching them risks serving stale process data.

## Configuring a custom codec

If you must use a different on-disk format (legacy migration, a
shared cache schema across multiple languages), implement
`Cache\CacheCodecInterface` and install it on the builder:

<!-- @code-block language="php" label="custom codec" -->
```php
use PhpOpcua\Client\Cache\CacheCodecInterface;
use PhpOpcua\Client\ClientBuilder;

class MyCacheCodec implements CacheCodecInterface
{
    public function encode(mixed $value): string
    {
        // Your representation. JSON, MessagePack, anything that is
        // NOT a generic-class serializer.
    }

    public function decode(string $payload): mixed
    {
        // Must enforce its own type allowlist.
        // Throw Exception\CacheCorruptedException on rejection.
    }
}

$client = ClientBuilder::create()
    ->setCacheCodec(new MyCacheCodec())
    ->connect('opc.tcp://plc.local:4840');
```
<!-- @endcode-block -->

`setCacheCodec(null)` reverts to the default `WireCacheCodec`.

<!-- @callout variant="danger" title="Footgun" -->
Do not call `unserialize($payload)` without `allowed_classes => false`
inside a custom codec. The whole point of the codec layer is to keep
`unserialize()` off the cache path. If your codec needs object
recovery, do it explicitly, by class, with a constructor you control.
<!-- @endcallout -->

## Upgrading from < v4.3.0

The new codec cannot decode pre-v4.3.0 cache entries — they were
`serialize()`-blobs the registry has no schema for. When the client
encounters one, `CacheCorruptedException` fires and the entry is
treated as a miss; the client refetches from the server.

This means upgrade has a transient cold-cache window — the first
request that would have hit an old entry pays for a round-trip. To
skip the window, flush persistent caches at deploy time:

<!-- @code-block language="bash" label="redis flush at deploy" -->
```bash
# Flush only the OPC UA keyspace, if you've kept it isolated.
redis-cli --scan --pattern 'opcua:*' | xargs -L 100 redis-cli DEL
```
<!-- @endcode-block -->

See [Recipes · Upgrading to v4.3](../recipes/upgrading-to-v4.3.md).

## Performance

The codec adds one JSON encode/decode per cache write/read.
Microbenchmarks against an in-memory cache show a 2-3× slowdown vs
`serialize()`; against any disk-backed or network-backed PSR-16
implementation, the codec cost is dominated by I/O and not visible.

If cache codec time ever shows up in a profile, the answer is "use a
faster backend", not "skip the codec".

## What this does not protect against

- **A compromised application process.** If the attacker can write to
  the cache *through the application*, they can do many worse things.
- **A compromised server.** Server-controlled responses are never
  cached, and `Value` attributes are never cached — but a server that
  lies on Read responses is outside the cache codec's threat model.
- **Disk-resident credentials.** Trust-store DER files, certificate
  keys — those are in the file system, not the cache. Standard secret-
  management discipline applies.
