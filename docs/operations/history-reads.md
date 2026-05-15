---
eyebrow: 'Docs · Operations'
lede:    'Three flavours of history read: raw samples, aggregated buckets, and resolved-at-time. All three target servers that store historical data — a feature OPC UA marks as optional.'

see_also:
  - { href: './reading-attributes.md', meta: '7 min' }
  - { href: '../recipes/service-unsupported.md', meta: '4 min' }
  - { href: 'https://opcfoundation.org/specs/part11', meta: 'external', label: 'OPC UA Part 11 — Historical Access' }

prev: { label: 'Monitored items', href: './monitored-items.md' }
next: { label: 'Managing nodes',  href: './managing-nodes.md' }
---

# History reads

History reads target servers that retain historical data. The OPC UA
spec defines them in Part 11 as an optional service set — many servers
do not implement it and will respond with `BadServiceUnsupported` or
`BadHistoryOperationUnsupported`. Test against your target server
before designing a feature around the call.

This library exposes three call shapes:

| Method                  | Use                                                   |
| ----------------------- | ----------------------------------------------------- |
| `historyReadRaw()`      | Every stored sample in `[startTime, endTime]`         |
| `historyReadProcessed()`| Aggregates (avg, min, max, …) bucketed by interval    |
| `historyReadAtTime()`   | Values resolved at specific timestamps                |

All three return `DataValue[]` (or `DataValue[][]` for the multi-node
forms). Each entry carries the historical value, its `sourceTimestamp`,
and the status the server attached at storage time.

## historyReadRaw

<!-- @method name="$client->historyReadRaw(NodeId|string \$nodeId, DateTimeInterface \$startTime, DateTimeInterface \$endTime, int \$numValuesPerNode = 0, bool \$returnBounds = false): array" returns="DataValue[]" visibility="public" -->

<!-- @code-block language="php" label="last 15 minutes" -->
```php
$samples = $client->historyReadRaw(
    nodeId:    'ns=2;s=Devices/PLC/Temperature',
    startTime: new DateTimeImmutable('-15 minutes'),
    endTime:   new DateTimeImmutable(),
);

foreach ($samples as $dv) {
    echo $dv->sourceTimestamp->format('c') . "  " . $dv->getValue() . "\n";
}
```
<!-- @endcode-block -->

<!-- @params -->
<!-- @param name="$nodeId" type="NodeId|string" required -->
The Variable node to query. Only `Variable` nodes with historicizing
enabled return history.
<!-- @endparam -->
<!-- @param name="$startTime" type="DateTimeInterface" required -->
Inclusive lower bound. Note: OPC UA history works with **server-side
timestamps**; if the storage was wall-clock at sample time, that is the
clock you're querying.
<!-- @endparam -->
<!-- @param name="$endTime" type="DateTimeInterface" required -->
Inclusive upper bound. Reverse the order (`startTime > endTime`) to
read history in reverse-chronological order.
<!-- @endparam -->
<!-- @param name="$numValuesPerNode" type="int" default="0" -->
Max samples to return. `0` = unlimited (subject to server-side caps).
<!-- @endparam -->
<!-- @param name="$returnBounds" type="bool" default="false" -->
When `true`, the server may synthesise interpolated values at
`startTime` and `endTime` if no stored sample sits exactly there.
<!-- @endparam -->
<!-- @endparams -->

### Pagination

History responses can carry a continuation point when the result set
exceeds `numValuesPerNode` or the server's internal cap. The library
follows continuation points transparently until the server reports
done — the returned array is the full set.

## historyReadProcessed

<!-- @method name="$client->historyReadProcessed(NodeId|string \$nodeId, DateTimeInterface \$startTime, DateTimeInterface \$endTime, double \$processingInterval, NodeId|string \$aggregateType): array" returns="DataValue[]" visibility="public" -->

Aggregated reads bucket history into fixed-width intervals and apply a
**standard OPC UA aggregate** to each bucket — average, min, max,
total, count, time-weighted variants, and so on.

<!-- @code-block language="php" label="hourly averages" -->
```php
$hourly = $client->historyReadProcessed(
    nodeId:             'ns=2;s=Tank42/Level',
    startTime:          new DateTimeImmutable('-1 day'),
    endTime:            new DateTimeImmutable(),
    processingInterval: 3_600_000.0,     // 1 hour in ms
    aggregateType:      'i=2342',        // Average
);
```
<!-- @endcode-block -->

The aggregate is itself a NodeId in namespace 0. The well-known set
includes:

| NodeId      | Aggregate           |
| ----------- | ------------------- |
| `i=2342`    | `Average`           |
| `i=2345`    | `Maximum`           |
| `i=2346`    | `Minimum`           |
| `i=2352`    | `Count`             |
| `i=2350`    | `Total`             |
| `i=2347`    | `TimeAverage`       |
| `i=2348`    | `TimeAverage2`      |

Refer to OPC UA Part 13 for the complete catalogue and the precise
mathematical definitions. Not every server supports every aggregate —
an unsupported aggregate returns `BadAggregateNotSupported` per result
entry.

## historyReadAtTime

<!-- @method name="$client->historyReadAtTime(NodeId|string \$nodeId, array \$timestamps, bool \$useSimpleBounds = false): array" returns="DataValue[]" visibility="public" -->

Resolves one value per requested timestamp — either the stored sample
at exactly that time, or an interpolated value, depending on
`useSimpleBounds`:

<!-- @code-block language="php" label="resolve at four points" -->
```php
$values = $client->historyReadAtTime(
    nodeId:     'ns=2;s=Tank42/Level',
    timestamps: [
        new DateTimeImmutable('2026-05-15 08:00:00'),
        new DateTimeImmutable('2026-05-15 12:00:00'),
        new DateTimeImmutable('2026-05-15 16:00:00'),
        new DateTimeImmutable('2026-05-15 20:00:00'),
    ],
);
```
<!-- @endcode-block -->

When the server does not store a sample at exactly the requested
timestamp:

- `useSimpleBounds = false` (default) interpolates linearly between
  the surrounding samples.
- `useSimpleBounds = true` returns the most recent stored sample before
  the requested time and stamps it with `Uncertain`.

## Capability detection

Before designing a feature around history reads, verify the server
supports the relevant service. The cheapest probe is one of:

1. Call `historyReadRaw()` for a recent 10-second window on a known
   historicizing node, with `numValuesPerNode: 1`. A `Good` or empty
   reply means the service is supported.
2. Browse the `HistoryServerCapabilities` object (`i=2330`) and read
   its `AccessHistoryDataCapability` property.

See [Recipes · Detecting server
capabilities](../recipes/detecting-server-capabilities.md) and
[Recipes · Handling unsupported
services](../recipes/service-unsupported.md).

## Failure modes

| StatusCode                       | Meaning                                            |
| -------------------------------- | -------------------------------------------------- |
| `BadServiceUnsupported`          | The server does not implement HistoryRead at all   |
| `BadHistoryOperationUnsupported` | HistoryRead exists but this operation flavour is not supported |
| `BadHistoryOperationInvalid`     | The request is malformed (start > end with wrong direction, etc.) |
| `BadNoDataAvailable`             | Node is historicizing but has no data in the range |
| `BadAggregateNotSupported`       | The aggregate NodeId is unknown to the server      |
| `BadInvalidTimestampArgument`    | Timestamps in `historyReadAtTime` are out of order |

For `BadServiceUnsupported`, the library raises
`ServiceUnsupportedException` rather than letting the bad status pass
through as `DataValue[]`. See [Reference ·
Exceptions](../reference/exceptions.md).
