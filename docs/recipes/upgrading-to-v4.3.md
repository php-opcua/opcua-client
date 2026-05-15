---
eyebrow: 'Docs · Recipes'
lede:    'Upgrade to v4.3 in three steps: composer update, flush persistent caches, sweep your code for the BrowsePathTarget namespace move and the removed concrete kernel class.'

see_also:
  - { href: '../security/cache-path-hardening.md', meta: '5 min' }
  - { href: '../observability/caching.md',         meta: '5 min' }
  - { href: '../extensibility/modules.md',         meta: '8 min' }

prev: { label: 'Enums',                  href: '../reference/enums.md' }
next: { label: 'Recovering from disconnection', href: './disconnection-recovery.md' }
---

# Upgrading to v4.3

v4.3.0 is a consolidation release. The public API stays compatible
except for two surfaces:

1. **Cache encoding changed.** Persistent caches written by older
   versions are unreadable by the new codec.
2. **Two namespace-internal moves** that may surface in your code if
   you depend on internals.

This page walks through each.

## Step 1 — Update Composer

<!-- @code-block language="bash" label="terminal" -->
```bash
composer require php-opcua/opcua-client:^4.3
```
<!-- @endcode-block -->

The constraint allows everything from v4.3 forward. Pin tighter
(`~4.3.0`) if you want to vet patches before they roll out.

## Step 2 — Flush persistent caches

The cache codec changed from `serialize()`-based to JSON gated by a
type allowlist (see [Security · Cache path
hardening](../security/cache-path-hardening.md)). Old entries cannot
be decoded by the new codec; the client catches the resulting
`CacheCorruptedException` and treats those entries as misses.

That works — the next request refetches from the server — but it
also means a transient cold-cache window after deploy. Flush the
relevant keyspace to skip the window:

<!-- @tabs labels="Redis, FileCache, In-memory" -->
<!-- @tab index="0" -->
```bash
# If your Redis cache keys are isolated under an opcua: prefix:
redis-cli --scan --pattern 'opcua:*' | xargs -L 100 redis-cli DEL
```
<!-- @endtab -->
<!-- @tab index="1" -->
```bash
# Remove the FileCache directory the builder was pointed at.
rm -rf /var/cache/opcua/*
```
<!-- @endtab -->
<!-- @tab index="2" -->
```bash
# Nothing to do — in-memory caches reset on PHP process restart.
```
<!-- @endtab -->
<!-- @endtabs -->

Flush is optional. The library degrades correctly without it; cold-
cache traffic is the only cost.

## Step 3 — Sweep for the two internal moves

### 3a — `BrowsePathTarget` namespace

The DTO moved from `Types\` to the module that produces it:

<!-- @code-block language="php" label="before / after" -->
```php
// Before v4.3
use PhpOpcua\Client\Types\BrowsePathTarget;

// After v4.3
use PhpOpcua\Client\Module\TranslateBrowsePath\BrowsePathTarget;
```
<!-- @endcode-block -->

If your code imported the class explicitly (which is rare — most
callers receive instances via `BrowsePathResult::$targets` and never
mention the type), update the import.

`grep -rn "Types\\\\BrowsePathTarget" .` in your project will find the
references.

### 3b — The concrete `Kernel\ClientKernel` is gone

The `Kernel\ClientKernel` concrete class was removed; the interface
`Kernel\ClientKernelInterface` remains. `Client` now implements the
interface directly via its `Manages*Traits`.

If you wrote a custom `ServiceModule` that type-hinted against the
concrete:

<!-- @code-block language="php" label="before / after" -->
```php
// Before v4.3
use PhpOpcua\Client\Kernel\ClientKernel;

public function __construct(private ClientKernel $kernel) {}

// After v4.3
use PhpOpcua\Client\Kernel\ClientKernelInterface;

public function __construct(private ClientKernelInterface $kernel) {}
```
<!-- @endcode-block -->

The base `ServiceModule` class already type-hints the interface;
modules that extend it without overriding the constructor are
unaffected.

## What did not change

- **Public method names and signatures on `OpcUaClientInterface`.**
  Every call you make on a `Client` still works.
- **`ClientBuilder` setters.** No setter renamed, none removed.
- **Default module count.** Still 8 modules (NodeManagement is back
  in `defaultModules()` after the v4.2.x detour).
- **Exception class names.** New exceptions were added
  (`ServiceUnsupportedException`, `CacheCorruptedException`); none
  were renamed.

## What's new and worth wiring

- **`ServiceUnsupportedException`** — a subclass of `ServiceException`
  for `BadServiceUnsupported`. Catch it specifically to distinguish
  "server lacks this service set" from generic ServiceFaults. See
  [Recipes · Handling unsupported
  services](./service-unsupported.md).
- **`setCacheCodec()`** — swap the cache codec for a custom one if
  your operational stack requires it. The default is fine for most
  setups.
- **Improved policy-ID discovery (v4.3.1).** The client now discovers
  the username and certificate identity-token policy IDs as well as
  the anonymous one. Servers with non-standard policy IDs (open62541,
  Siemens S7, …) now work with username and X.509 auth out of the
  box. Nothing to wire — just upgrade.

## Verify after the upgrade

A small sanity check covers the changes:

<!-- @code-block language="php" label="post-upgrade smoke test" -->
```php
$client = ClientBuilder::create()->connect('opc.tcp://plc.local:4840');

// 1. Round-trip a browse — exercises the new cache codec.
$refs = $client->browse('i=85');
assert(count($refs) > 0);

// 2. Round-trip a non-Value read — exercises the metadata cache.
$client->read('i=2261', AttributeId::DataType);
$client->read('i=2261', AttributeId::DataType); // cache hit

// 3. Inspect the loaded modules — should be 8 by default.
assert(count($client->getLoadedModules()) === 8);

$client->disconnect();
```
<!-- @endcode-block -->

If all three pass, the upgrade is clean.
