---
eyebrow: 'Docs · Operations'
lede:    'Read one attribute or many. The Value attribute is the default; everything else — DisplayName, DataType, BrowseName — uses the same call shape.'

see_also:
  - { href: './writing-values.md',     meta: '7 min' }
  - { href: '../types/data-value-and-variant.md', meta: '6 min' }
  - { href: '../observability/caching.md', meta: '5 min' }

prev: { label: 'Timeouts and retry', href: '../connection/timeouts-and-retry.md' }
next: { label: 'Writing values',     href: './writing-values.md' }
---

# Reading attributes

A Read service call returns one or more **attributes** of one or more
nodes. In OPC UA, a node's *value* is just one of its attributes — the
`Value` attribute, ID `13`. Reading the `DisplayName`, `DataType`, or
`BrowseName` of the same node uses the same Read call with a different
attribute ID.

## Single attribute

<!-- @method name="$client->read(NodeId|string \$nodeId, int \$attributeId = AttributeId::Value, bool \$refresh = false): DataValue" returns="DataValue" visibility="public" -->

<!-- @code-block language="php" label="basic read" -->
```php
use PhpOpcua\Client\Types\AttributeId;
use PhpOpcua\Client\Types\StatusCode;

$dv = $client->read('ns=2;s=Devices/PLC/Speed');

if (StatusCode::isGood($dv->statusCode)) {
    $speed = $dv->getValue();           // unwrapped Variant value
    $when  = $dv->sourceTimestamp;      // DateTimeImmutable on the device
}
```
<!-- @endcode-block -->

<!-- @params -->
<!-- @param name="$nodeId" type="NodeId|string" required -->
The node to read. Accepts a `NodeId` instance or the string shorthand
(`i=2261`, `ns=2;s=Tag/Path`, `ns=0;g=…GUID`).
<!-- @endparam -->
<!-- @param name="$attributeId" type="int" default="AttributeId::Value" -->
Which attribute to read. Use the `AttributeId::*` constants:
`Value` (13), `DisplayName` (4), `BrowseName` (3), `DataType` (14),
`NodeClass` (2), `Description` (5), `AccessLevel` (17), etc.
<!-- @endparam -->
<!-- @param name="$refresh" type="bool" default="false" -->
When the metadata cache is enabled (see below) and the request is for
a non-Value attribute, `refresh: true` bypasses the cache and forces a
server round-trip, updating the cached entry. The `Value` attribute is
never cached, so `refresh` is irrelevant for it.
<!-- @endparam -->
<!-- @endparams -->

The returned `DataValue` carries:

- `value` (`Variant`) — the typed value
- `statusCode` (`int`) — `0` for `Good`, non-zero for bad/uncertain
- `sourceTimestamp` (`?DateTimeImmutable`) — when the device sampled it
- `serverTimestamp` (`?DateTimeImmutable`) — when the server packaged
  the reply

`getValue()` unwraps the `Variant` to its native PHP value:
`bool`, `int`, `float`, `string`, `array` (for `Variant` array values),
or a decoded `ExtensionObject` body.

<!-- @callout variant="warning" -->
A successful `read()` call (no exception) can still contain a bad
status code for the individual node — `BadNodeIdUnknown`,
`BadAttributeIdInvalid`, `BadUserAccessDenied`. Always check
`$dv->statusCode` before trusting the value. The library only raises
on transport / channel / session failures.
<!-- @endcallout -->

## Multiple attributes — readMulti

`readMulti()` issues a single ReadRequest with N items. One round-trip,
N results, in the same order:

<!-- @code-block language="php" label="readMulti — array form" -->
```php
use PhpOpcua\Client\Types\AttributeId;

$results = $client->readMulti([
    ['nodeId' => 'i=2261'],
    ['nodeId' => 'ns=2;s=Devices/PLC/Speed'],
    ['nodeId' => 'ns=2;s=Devices/PLC/Speed', 'attributeId' => AttributeId::DataType],
]);

[$productName, $speed, $speedType] = $results;
```
<!-- @endcode-block -->

Each entry is a `DataValue`. The order matches the request order; gaps
or reordering would be a protocol violation.

### Fluent builder

`readMulti()` returns a `ReadMultiBuilder` when called with no
arguments. The builder produces the same array structure with clearer
intent at the call site:

<!-- @code-block language="php" label="readMulti — fluent form" -->
```php
$results = $client->readMulti()
    ->node('i=2261')->value()
    ->node('ns=2;s=Devices/PLC/Speed')->value()
    ->node('ns=2;s=Devices/PLC/Speed')->dataType()
    ->execute();
```
<!-- @endcode-block -->

Builder methods:

| Method                | Effect                                        |
| --------------------- | --------------------------------------------- |
| `node(NodeId\|string)`| Start a new entry on the current node         |
| `value()`             | Read `AttributeId::Value`                     |
| `displayName()`       | Read `AttributeId::DisplayName`               |
| `browseName()`        | Read `AttributeId::BrowseName`                |
| `nodeClass()`         | Read `AttributeId::NodeClass`                 |
| `description()`       | Read `AttributeId::Description`               |
| `dataType()`          | Read `AttributeId::DataType`                  |
| `attribute(int)`      | Read an arbitrary attribute ID                |
| `execute()`           | Issue the call, return `DataValue[]`          |

The builder is a one-shot — call `execute()` exactly once.

## Auto-batching

Servers advertise a `MaxNodesPerRead` operational limit. If you pass
more items than the server allows, `readMulti()` automatically slices
the request, issues N parallel reads, and stitches the results back in
order. The server limit is discovered on `connect()` and overridable
on the builder via `setBatchSize()`. See [Connection · Endpoints and
discovery](../connection/endpoints-and-discovery.md).

## Server BuildInfo helpers

The OPC UA spec mandates a small set of well-known nodes under
`Server.ServerStatus.BuildInfo`. Reading them with `read()` works, but
the library ships convenience methods:

<!-- @code-block language="php" label="examples/build-info.php" -->
```php
echo $client->getServerProductName();        // ?string
echo $client->getServerManufacturerName();   // ?string
echo $client->getServerSoftwareVersion();    // ?string
echo $client->getServerBuildNumber();        // ?string
print_r($client->getServerBuildDate());      // ?DateTimeImmutable

$info = $client->getServerBuildInfo();       // BuildInfo DTO, single readMulti()
```
<!-- @endcode-block -->

`getServerBuildInfo()` is the most efficient form — it batches all five
attributes into a single readMulti call. Use it when you need them all.

## Metadata cache

Reading the `DataType` or `DisplayName` of a node is common during
discovery and rarely changes for the lifetime of a session. The library
ships an opt-in metadata cache that stores **non-Value** attributes
keyed by NodeId + attribute ID:

<!-- @code-block language="php" label="enable metadata cache" -->
```php
$client = ClientBuilder::create()
    ->setReadMetadataCache(true)
    ->connect('opc.tcp://plc.local:4840');

$client->read('ns=2;s=Tag', AttributeId::DataType);   // server hit
$client->read('ns=2;s=Tag', AttributeId::DataType);   // cache hit
$client->read('ns=2;s=Tag', AttributeId::DataType, refresh: true); // server hit
```
<!-- @endcode-block -->

- **`Value` (attribute 13) is never cached**, even when the metadata
  cache is on.
- The cache uses the same PSR-16 backend as browse caching; see
  [Observability · Caching](../observability/caching.md) for the
  storage codec and invalidation API.

## Status codes vs exceptions

| Situation                                | What happens                       |
| ---------------------------------------- | ---------------------------------- |
| Network / channel / session failure      | Exception thrown                   |
| Bad request (wrong arg type, etc.)       | Exception thrown                   |
| Server returned a bad per-item status    | `DataValue` carries the status code — **you check it** |

The exception path is for "the call could not complete"; the
status-code path is for "the call completed, this specific node had a
problem". Both are real failure modes — handle both.

See [Reference · Exceptions](../reference/exceptions.md) for the full
hierarchy.
