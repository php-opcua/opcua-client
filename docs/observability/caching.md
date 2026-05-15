---
eyebrow: 'Docs · Observability'
lede:    'A PSR-16 cache backs browse, resolve, endpoint, and type-discovery results. Values pass through a codec gated by an allowlist — the v4.3.0 hardening that replaced unserialize().'

see_also:
  - { href: '../security/cache-path-hardening.md', meta: '5 min' }
  - { href: '../operations/browsing.md',           meta: '6 min' }
  - { href: '../recipes/upgrading-to-v4.3.md',     meta: '4 min' }

prev: { label: 'Event reference', href: './event-reference.md' }
next: { label: 'MockClient',      href: '../testing/mock-client.md' }
---

# Caching

The client caches everything it can safely cache. By default that
means **`InMemoryCache` with a 300-second TTL**. Plug in any PSR-16
`CacheInterface` and the same hit/miss logic moves to your backend.

## What's cached

| Operation                          | Cached?  | Key shape                                            |
| ---------------------------------- | -------- | ---------------------------------------------------- |
| `browse()` / `browseAll()`         | Yes      | `opcua:{endpoint_hash}:browse:{nodeId}:{direction}:{includeSubtypes}:{nodeClassMask}` |
| `browseRecursive()`                | Indirect | Each internal `browseAll()` call hits its own cache  |
| `browseNext()`                     | No       | Continuation points are session-specific             |
| `resolveNodeId()` / `translateBrowsePaths()` | Yes | `opcua:{endpoint_hash}:resolve:{startingNodeId}:{path_hash}` |
| `getEndpoints()`                   | Yes      | `opcua:{endpoint_hash}:endpoints:{url_hash}`         |
| `discoverDataTypes()`              | Yes      | `opcua:{endpoint_hash}:dataTypes:{namespaceIndex\|all}` |
| `read()` for non-Value attributes  | Opt-in   | `opcua:{endpoint_hash}:metadata:{nodeId}:{attributeId}` |
| `read()` for Value (attribute 13)  | **Never**| —                                                     |
| `write()` / `call()` / `historyRead*` | Never  | —                                                     |

The endpoint hash means two clients targeting different servers never
share a cached entry. Bypass per call with `useCache: false` where the
flag is supported; flush all entries with `$client->flushCache()`;
invalidate one node across all operations with
`$client->invalidateCache($nodeId)`.

## Configuring the cache

`InMemoryCache` is the default, scoped to the `Client` instance.
Replace it with any PSR-16 implementation:

<!-- @code-block language="php" label="examples/caching.php" -->
```php
use PhpOpcua\Client\Cache\FileCache;
use PhpOpcua\Client\Cache\InMemoryCache;
use PhpOpcua\Client\ClientBuilder;

// In-memory, 600 s TTL — process-local, lost on restart
$client = ClientBuilder::create()
    ->setCache(new InMemoryCache(defaultTtl: 600))
    ->connect('opc.tcp://plc.local:4840');

// File-based — survives restart, shared across processes on the same host
$client = ClientBuilder::create()
    ->setCache(new FileCache('/var/cache/opcua', defaultTtl: 1800))
    ->connect('opc.tcp://plc.local:4840');

// Laravel cache (Redis, etc.)
$client = ClientBuilder::create()
    ->setCache(app('cache')->store('redis'))
    ->connect('opc.tcp://plc.local:4840');

// Disable caching entirely
$client = ClientBuilder::create()
    ->setCache(null)
    ->connect('opc.tcp://plc.local:4840');
```
<!-- @endcode-block -->

`setCache(null)` is the right setting for short-lived scripts where
the cache adds no value and the in-memory store would just inflate
the working set.

## The metadata cache

Off by default. Turn it on when you read non-Value attributes
(`DisplayName`, `DataType`, `BrowseName`, …) repeatedly:

<!-- @code-block language="php" label="metadata cache" -->
```php
$client = ClientBuilder::create()
    ->setReadMetadataCache(true)
    ->connect('opc.tcp://plc.local:4840');

$client->read('ns=2;s=Tag', AttributeId::DataType);   // server hit
$client->read('ns=2;s=Tag', AttributeId::DataType);   // cache hit
$client->read('ns=2;s=Tag', AttributeId::DataType, refresh: true); // server hit
```
<!-- @endcode-block -->

`Value` (attribute 13) is **never cached**, regardless of the
metadata-cache flag. PLC tag values are too volatile.

## The codec layer

<!-- @version-badge type="added" version="v4.3.0" --> All cache values
pass through `Cache\CacheCodecInterface`. The default codec,
`Cache\WireCacheCodec`, encodes as JSON gated by a type allowlist —
no `unserialize()` anywhere. See [Security · Cache path
hardening](../security/cache-path-hardening.md) for the threat model.

Swap the codec when you must:

<!-- @code-block language="php" label="custom codec" -->
```php
use PhpOpcua\Client\Cache\CacheCodecInterface;

$client = ClientBuilder::create()
    ->setCacheCodec(new MyCacheCodec())     // null reverts to WireCacheCodec
    ->connect('opc.tcp://plc.local:4840');
```
<!-- @endcode-block -->

A custom codec must enforce its own type allowlist. Calling
`unserialize()` without `allowed_classes` defeats the purpose of the
layer.

### Corrupted entries

When the codec cannot decode a cache payload, it raises
`Exception\CacheCorruptedException`. The client catches this
internally and treats the entry as a cache miss — the next call
refetches from the server. Pre-v4.3.0 cache entries written by the
old `serialize()` path are the most common source of this; flush
persistent caches on upgrade. See [Recipes · Upgrading to
v4.3](../recipes/upgrading-to-v4.3.md).

## Observability

Two events ride on the cache path:

| Event       | Fires when                                    | Key fields                    |
| ----------- | --------------------------------------------- | ----------------------------- |
| `CacheHit`  | A cached value was returned                   | `key`, `operation`, `nodeId`  |
| `CacheMiss` | A miss triggered a server round-trip          | `key`, `operation`, `nodeId`  |

Wire a PSR-14 listener to measure hit rate:

<!-- @code-block language="php" label="hit-rate metric" -->
```php
$dispatcher->addListener(CacheHit::class,  fn($e) => $metrics->counter('opcua.cache', tags: ['result' => 'hit'])->increment());
$dispatcher->addListener(CacheMiss::class, fn($e) => $metrics->counter('opcua.cache', tags: ['result' => 'miss'])->increment());
```
<!-- @endcode-block -->

A hit rate below ~70% on browse-heavy workloads usually means TTLs
are too tight or invalidations are too aggressive.

## Invalidation patterns

**Per node, across all operations:**

<!-- @code-block language="php" label="invalidate one node" -->
```php
$client->invalidateCache(NodeId::numeric(2, 1001));
```
<!-- @endcode-block -->

Drops every cache entry whose key includes that NodeId — browse,
resolve, metadata. Useful after a write you know changed the
structure.

**Everything:**

<!-- @code-block language="php" label="flush all" -->
```php
$client->flushCache();
```
<!-- @endcode-block -->

Drops the entire keyspace. Acceptable at startup or after a known
schema change; aggressive in normal operation.

**Per call:**

<!-- @code-block language="php" label="bypass for one call" -->
```php
$client->browse('i=85', useCache: false);
$client->resolveNodeId('/Objects/Server', useCache: false);
```
<!-- @endcode-block -->

Bypasses the cache for the call and **does not** refresh the cached
entry. Use it when you suspect a stale entry but don't want to flush.

## When the cache is the wrong tool

- **Values you need fresh.** Always read directly; the cache never
  serves a Value attribute.
- **Servers with very dynamic address spaces.** Browse caching across
  schema changes returns stale results. Either invalidate aggressively
  or set the cache to null.
- **One-shot CLI scripts.** The cache costs more than it returns on
  short runs — disable it.

## Performance

- `InMemoryCache` reads and writes are O(1) hash lookups; the codec
  cost dominates.
- `FileCache` adds disk I/O. Place it on local SSD; networked
  filesystems negate the benefit.
- PSR-16 Redis backends add network latency. The trade-off is shared
  state across processes — worth it for worker pools, overkill for
  single-instance scripts.

The codec layer adds one JSON encode/decode per cache write/read. On
in-memory caches that's measurable; on disk- or network-backed
caches, it disappears in the I/O noise.
