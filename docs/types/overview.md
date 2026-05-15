---
eyebrow: 'Docs · Types'
lede:    'The type system is small on the surface — Variant, DataValue, NodeId, ExtensionObject — and everything else is a refinement. Internalise these four and the rest follows.'

see_also:
  - { href: './node-id.md',                meta: '5 min' }
  - { href: './data-value-and-variant.md', meta: '6 min' }
  - { href: './built-in-types.md',         meta: '5 min' }

prev: { label: 'Cache path hardening',  href: '../security/cache-path-hardening.md' }
next: { label: 'NodeId',                href: './node-id.md' }
---

# Types overview

OPC UA has a generous type system; this library exposes it through a
small set of PHP value objects, all read-only and immutable.

## The four pillars

| Type            | Role                                                          |
| --------------- | ------------------------------------------------------------- |
| `NodeId`        | Address-space identity                                        |
| `Variant`       | Typed value container — wraps a PHP value with its `BuiltinType` |
| `DataValue`     | A `Variant` plus a status code and two timestamps             |
| `ExtensionObject` | A typed structure (raw bytes or decoded)                    |

Every value crossing the OPC UA wire is one of these. Read returns
`DataValue`. Write takes a value the library wraps in a `Variant`.
Browse returns `ReferenceDescription`, which is built from `NodeId`,
`QualifiedName`, and `LocalizedText`. Methods return `Variant[]` for
their outputs.

## Value object discipline

Every type in `PhpOpcua\Client\Types\` is:

- A `final` PHP class with **public readonly** properties.
- Constructed once, never mutated. To "change" a field you build a new
  instance.
- Serialisable through `Wire\WireSerializable` for IPC use.

This means you can pass these objects around freely — they cannot drift
under you, and equality is structural.

<!-- @code-block language="php" label="property access, not getters" -->
```php
$dv = $client->read('i=2261');

echo $dv->statusCode;            // int
echo $dv->sourceTimestamp?->format('c');
echo $dv->getValue();            // the unwrapped PHP value

// Pre-v3 getters still exist (deprecated):
$dv->getValue();                 // ok
$dv->getStatusCode();            // ok but deprecated — use $dv->statusCode
```
<!-- @endcode-block -->

Property access is the idiomatic style. The deprecated `getX()` helpers
exist for migrations from older versions and will be removed in v5.

## Enum types

Several configuration surfaces are PHP 8.1 backed enums:

| Enum                 | Cases                                              |
| -------------------- | -------------------------------------------------- |
| `BuiltinType`        | 25 cases — `Boolean`, `Int32`, `Double`, … See [Built-in types](./built-in-types.md). |
| `NodeClass`          | 8 cases — `Object`, `Variable`, `Method`, …        |
| `BrowseDirection`    | 3 cases — `Forward`, `Inverse`, `Both`             |
| `ConnectionState`    | 3 cases — `Disconnected`, `Connected`, `Broken`    |
| `SecurityPolicy`     | 10 cases — see [Security · Policies](../security/policies.md) |
| `SecurityMode`       | 3 cases — `None`, `Sign`, `SignAndEncrypt`         |
| `TrustPolicy`        | 3 cases — `Fingerprint`, `FingerprintAndExpiry`, `Full` |

All are backed enums; their `value` corresponds to the OPC UA-spec
integer or URI. `BuiltinType::from(11)` returns `BuiltinType::Double`.

## Module-scoped DTOs

Service methods return DTOs that live in the relevant module
namespace, not in `Types\`. These are still read-only public-property
classes, but they document a specific service's result shape:

| DTO                                                  | Returned by                  |
| ---------------------------------------------------- | ---------------------------- |
| `Module\Browse\BrowseResultSet`                      | `browseWithContinuation()`   |
| `Module\TranslateBrowsePath\BrowsePathResult`        | `translateBrowsePaths()`     |
| `Module\TranslateBrowsePath\BrowsePathTarget`        | inside `BrowsePathResult`    |
| `Module\ReadWrite\CallResult`                        | `call()`                     |
| `Module\Subscription\SubscriptionResult`             | `createSubscription()`       |
| `Module\Subscription\MonitoredItemResult`            | `createMonitoredItems()`     |
| `Module\Subscription\MonitoredItemModifyResult`      | `modifyMonitoredItems()`     |
| `Module\Subscription\SetTriggeringResult`            | `setTriggering()`            |
| `Module\Subscription\PublishResult`                  | `publish()`                  |
| `Module\Subscription\TransferResult`                 | `transferSubscriptions()`    |
| `Module\NodeManagement\AddNodesResult`               | `addNodes()`                 |
| `Module\ServerInfo\BuildInfo`                        | `getServerBuildInfo()`       |

These DTOs are not deep — most are status code + a payload tuple. The
field-by-field details are in the relevant operations pages.

## Status codes

Status codes are 32-bit OPC UA integers, not enums. They are passed as
`int` and tested with helpers on the `StatusCode` class:

<!-- @code-block language="php" label="status code helpers" -->
```php
use PhpOpcua\Client\Types\StatusCode;

if (StatusCode::isGood($dv->statusCode)) { … }
if (StatusCode::isBad($dv->statusCode))  { … }
if (StatusCode::isUncertain($dv->statusCode)) { … }

echo StatusCode::getName($dv->statusCode);   // "Good", "BadNodeIdUnknown", …
```
<!-- @endcode-block -->

The full list of named codes is in [Reference ·
Enums](../reference/enums.md).

## What to read next

- [NodeId](./node-id.md) — the identifier you will pass most often.
- [DataValue and Variant](./data-value-and-variant.md) — the value
  container and its metadata wrapper.
- [Extension objects](./extension-objects.md) — server-specific
  structures.
- [Built-in types](./built-in-types.md) — the 25 `BuiltinType` cases
  and their PHP mappings.
