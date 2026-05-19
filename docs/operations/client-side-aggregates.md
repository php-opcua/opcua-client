---
eyebrow: 'Docs · Operations'
lede:    'Compute OPC UA aggregate functions (Average, Min, Max, Count, Interpolate) client-side from a raw DataValue buffer — useful when the server lacks HistoryRead Processed or you need bucket boundaries the server will not honour.'

see_also:
  - { href: './history-reads.md',  meta: '6 min' }
  - { href: '../extensibility/modules.md', meta: '6 min' }
  - { href: 'https://opcfoundation.org/specs/part13', meta: 'external', label: 'OPC UA Part 13 — Aggregates' }

prev: { label: 'History reads',  href: './history-reads.md' }
next: { label: 'Managing nodes', href: './managing-nodes.md' }
---

# Client-side aggregates

The `AggregateModule` (added in v4.4.0, registered by default) computes
OPC UA Part 13 aggregate functions **on the client**, from a buffer of
raw `DataValue`s you supply — or from raw history it fetches for you
in one call.

Use it when:

- The server does not implement HistoryRead Processed
  (`Bad_ServiceUnsupported` / `Bad_HistoryOperationUnsupported`).
- You already have raw samples in memory (from a subscription, a CSV
  import, a test fixture) and want bucketed aggregates without a
  server round-trip.
- You need bucket boundaries the server would not honour exactly —
  e.g. precisely-aligned 5-minute windows independent of what the
  historian decides to deliver.

Five aggregate functions ship out of the box:

| `AggregateFunction` case | Part 13 NodeId | Semantics                                        |
| ------------------------ | -------------- | ------------------------------------------------ |
| `Interpolate`            | `i=2341`       | Interpolated value at the start of each bucket   |
| `Minimum`                | `i=2346`       | Minimum raw value within the bucket              |
| `Maximum`                | `i=2345`       | Maximum raw value within the bucket              |
| `Average`                | `i=2342`       | Arithmetic mean of raw values within the bucket  |
| `Count`                  | `i=2352`       | Number of raw values within the bucket           |

The Part 13 NodeIds are only used for reference here — the calculators
are pure PHP and never round-trip through the server.

## Method surface

Both methods are exposed via `Client::__call()` (not on
`OpcUaClientInterface`) — they are added by the module at boot time.
Static analysers won't see them; the runtime dispatcher will.

<!-- @method name="$client->aggregate(DataValue[] \$rawValues, DateTimeImmutable \$startTime, DateTimeImmutable \$endTime, float \$processingIntervalMs, AggregateFunction \$function, ?AggregateOptions \$options = null): DataValue[]" returns="DataValue[]" visibility="public" -->

<!-- @method name="$client->historyAggregate(NodeId|string \$nodeId, DateTimeImmutable \$startTime, DateTimeImmutable \$endTime, float \$processingIntervalMs, AggregateFunction \$function, ?AggregateOptions \$options = null): DataValue[]" returns="DataValue[]" visibility="public" -->

`aggregate()` is the in-memory call: you supply the `DataValue[]`
ascending by `sourceTimestamp`, the module slices the
`[startTime, endTime]` range into windows of `processingIntervalMs`
and emits one `DataValue` per window. A `processingIntervalMs` of `0`
collapses the whole range into a single window.

`historyAggregate()` is a convenience wrapper that calls
`historyReadRaw($nodeId, $startTime, $endTime, 0, true)` first and
feeds the result through `aggregate()`. It requires the server to
support HistoryRead Raw and the node to be historicising; the same
caveats from [History reads](./history-reads.md) apply.

## Quick example — in-memory buffer

<!-- @code-block language="php" label="aggregate live samples" -->
```php
use PhpOpcua\Client\Module\Aggregate\AggregateFunction;
use PhpOpcua\Client\Module\Aggregate\AggregateOptions;

$samples = $buffer->drainLastFiveMinutes();   // DataValue[], sourceTimestamp asc

$averages = $client->aggregate(
    $samples,
    new DateTimeImmutable('-5 minutes'),
    new DateTimeImmutable(),
    processingIntervalMs: 30_000.0,           // 30 s windows
    function:             AggregateFunction::Average,
    options:              new AggregateOptions(treatUncertainAsBad: true),
);

foreach ($averages as $dv) {
    echo $dv->sourceTimestamp->format('H:i:s') . " avg=" . $dv->getValue() . "\n";
}
```
<!-- @endcode-block -->

The output array is one `DataValue` per window, in order. Each entry
carries the aggregated value, the window's start `sourceTimestamp`,
and a `statusCode` that combines the per-window severity with Part 11
**Historian InfoBits** (`Calculated`, `Interpolated`, `Partial`,
`ExtraData`, `MultiValue`) where applicable.

## Quick example — fetch-and-aggregate

<!-- @code-block language="php" label="historyAggregate" -->
```php
$daily = $client->historyAggregate(
    nodeId:               'ns=2;s=Tank42/Level',
    startTime:            new DateTimeImmutable('-7 days'),
    endTime:              new DateTimeImmutable(),
    processingIntervalMs: 24 * 3_600_000.0,   // 1-day buckets
    function:             AggregateFunction::Maximum,
);
```
<!-- @endcode-block -->

For high-cardinality ranges this can be expensive — `historyReadRaw`
follows continuation points until the server reports done. Bound the
range or use `aggregate()` directly with a pre-paginated buffer.

## `AggregateOptions`

<!-- @code-block language="php" label="default options" -->
```php
new AggregateOptions(
    stepped:                 false,
    treatUncertainAsBad:     true,
    useSlopedExtrapolation:  false,
    percentDataBad:          100,
    percentDataGood:         100,
)
```
<!-- @endcode-block -->

| Field                     | Default | Effect                                                              |
| ------------------------- | ------- | ------------------------------------------------------------------- |
| `stepped`                 | `false` | `Interpolate` uses stepped interpolation instead of linear           |
| `treatUncertainAsBad`     | `true`  | `Uncertain*` raw values are excluded from selection and averaging   |
| `useSlopedExtrapolation`  | `false` | `Interpolate` extrapolates past the last raw sample by slope         |
| `percentDataBad`          | `100`   | Bad-data threshold (0–100) above which the result `statusCode` is `Bad`     |
| `percentDataGood`         | `100`   | Good-data threshold (0–100) below which the result `statusCode` is `Bad`    |

`AggregateOptions::default()` returns the constructor defaults. All
fields are `readonly`; build a new instance to tweak a single value.

## When to prefer `historyReadProcessed`

The server-side `historyReadProcessed` is still the right call when:

- The server supports it (most commercial historians do).
- You need an aggregate this module does not ship
  (`TotalDuration`, `TimeAverage`, `StandardDeviation`, …).
- The buckets are wide and the network is the bottleneck — the server
  returns one value per bucket; this module fetches every raw sample.

Reach for `AggregateModule` when the server is the bottleneck
(missing service set, slow aggregates) or when you already hold the
raw values in memory.

## Status codes the module emits

Each output `DataValue` carries a `statusCode` composed of:

- A **severity code** (`Good`, `Uncertain*`, `Bad*`) reflecting whether
  the bucket had enough good samples per `percentDataGood/Bad`.
- **Historian InfoBits** OR-ed in via `StatusCode::withDataValueInfoBits()`:
  - `HistorianCalculated` — value was computed (not a stored sample)
  - `HistorianInterpolated` — value was interpolated between samples
  - `HistorianPartial` — bucket only partly covered by raw data
  - `HistorianExtraData` — extra raw data is available in the bucket
  - `HistorianMultiValue` — multiple raw samples contributed

Three Part 13 status codes also appear when input validation fails:

| Status code                              | Cause                                                |
| ---------------------------------------- | ---------------------------------------------------- |
| `BadAggregateInvalidInputs`              | Window range is invalid, raw buffer is malformed     |
| `BadAggregateNotSupported`               | The requested `AggregateFunction` is not registered  |
| `BadAggregateConfigurationRejected`      | `AggregateOptions` combination is rejected           |

These travel inside the result's `statusCode` (the call itself
returns successfully). Inspect each output `DataValue::$statusCode`
before consuming the value.

## Extending the module — custom calculators

`AggregateModule::setCalculator(AggregateFunction $fn, AggregateCalculatorInterface $impl)`
swaps the implementation behind a function case. Useful in tests
(stub the calculator) or to ship a vendor-specific aggregate without
forking the module.

<!-- @code-block language="php" label="swap a calculator" -->
```php
use PhpOpcua\Client\Module\Aggregate\AggregateFunction;
use PhpOpcua\Client\Module\Aggregate\AggregateModule;

/** @var AggregateModule $module */
$module = $client->getModule(AggregateModule::class);
$module->setCalculator(AggregateFunction::Average, new MedianCalculator());
```
<!-- @endcode-block -->

`AggregateCalculatorInterface::compute(Interval $window, AggregateOptions $opts, DataValue[] $rawValues): DataValue`
is the contract. Implementations live under
`src/Module/Aggregate/Calculator/`; `AbstractAggregateCalculator`
gives you the bucket-status helpers for `percentDataBad/Good`.

## What to read next

- [History reads](./history-reads.md) — fetch raw or server-side
  processed history.
- [Modules](../extensibility/modules.md) — how `AggregateModule` fits
  alongside the other built-in modules.
