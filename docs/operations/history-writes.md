---
eyebrow: 'Docs · Operations'
lede:    'Nine methods that target the OPC UA HistoryUpdate service set — insert, replace, update, and delete on raw values and on historical events. Optional service set; many servers do not implement it.'

see_also:
  - { href: './history-reads.md',           meta: '6 min' }
  - { href: './client-side-aggregates.md',  meta: '7 min' }
  - { href: '../observability/event-reference.md#history-updates-5', meta: '4 min' }
  - { href: 'https://opcfoundation.org/specs/part11', meta: 'external', label: 'OPC UA Part 11 — Historical Access' }

prev: { label: 'History reads',           href: './history-reads.md' }
next: { label: 'Client-side aggregates',  href: './client-side-aggregates.md' }
---

# History writes — HistoryUpdate

The HistoryUpdate service set (Part 11 §6.9) lets a
client mutate the historical record itself — insert missing samples,
replace one that turned out to be wrong, delete a range, manage
historical events. Like HistoryRead, it is **optional** in the spec:
many servers reject every operation with `Bad_ServiceUnsupported` or
`Bad_HistoryOperationUnsupported`. Test against your target server
before designing a feature around the call.

This library exposes **nine** methods, all on `OpcUaClientInterface`:

| Group         | Method                          | Per-entry result          |
| ------------- | ------------------------------- | ------------------------- |
| **Data**       | `historyInsertData()`           | `int[]` (one per value)   |
| **Data**       | `historyReplaceData()`          | `int[]`                   |
| **Data**       | `historyUpdateData()`           | `int[]`                   |
| **Data**       | `historyDeleteRawModified()`    | `int` (one overall)       |
| **Data**       | `historyDeleteAtTime()`         | `int[]` (one per timestamp) |
| **Events**     | `historyInsertEvent()`          | `int[]` (one per event)   |
| **Events**     | `historyReplaceEvent()`         | `int[]`                   |
| **Events**     | `historyUpdateEvent()`          | `int[]`                   |
| **Events**     | `historyDeleteEvent()`          | `int[]` (one per EventId) |

Insert / Replace / Update share the same semantics as the
underlying `PerformUpdateType` enum (Part 11 §6.9.2): **Insert**
fails per-entry if a value already exists at the timestamp;
**Replace** fails if none exists; **Update** is the upsert
combination.

## `PerformUpdateType` enum

The four server-side semantics that travel inside `HistoryUpdateData`
calls:

| Case      | Int value | Meaning                                                 |
| --------- | --------- | ------------------------------------------------------- |
| `Insert`  | `1`       | Fail per-entry if a value already exists at the timestamp |
| `Replace` | `2`       | Fail per-entry if no value exists at the timestamp       |
| `Update`  | `3`       | Insert if missing, replace if present (upsert)           |
| `Remove`  | `4`       | Reserved for the delete operations                       |

You normally don't construct this enum yourself — each typed method
picks the right `PerformUpdateType` internally. The enum surfaces
on the `HistoryDataUpdated` / `HistoryEventUpdated` events so a
listener can branch on which operation produced the change.

The enum lives at
`PhpOpcua\Client\Module\History\PerformUpdateType` and is backed by
`int` matching the Part 11 numeric values.

## Inserting / replacing / updating data

<!-- @method name="$client->historyInsertData(NodeId|string \$nodeId, DataValue[] \$values): int[]" returns="int[]" visibility="public" -->
<!-- @method name="$client->historyReplaceData(NodeId|string \$nodeId, DataValue[] \$values): int[]" returns="int[]" visibility="public" -->
<!-- @method name="$client->historyUpdateData(NodeId|string \$nodeId, DataValue[] \$values): int[]" returns="int[]" visibility="public" -->

All three accept a `NodeId|string` and an array of `DataValue`s.
Each `DataValue` carries the value, status code, and the
**`sourceTimestamp`** that identifies the entry. The return value is
an `int[]` parallel to the input array — one OPC UA status code per
value.

<!-- @code-block language="php" label="backfill missing samples" -->
```php
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\StatusCode;

$values = [
    new DataValue(
        value:           42.5,
        statusCode:      StatusCode::Good,
        sourceTimestamp: new DateTimeImmutable('2026-05-19 08:00:00'),
    ),
    new DataValue(
        value:           42.7,
        statusCode:      StatusCode::Good,
        sourceTimestamp: new DateTimeImmutable('2026-05-19 08:00:01'),
    ),
];

$statuses = $client->historyInsertData('ns=2;s=Tank42/Level', $values);

foreach ($statuses as $i => $status) {
    if (! StatusCode::isGood($status)) {
        // BadEntryExists, BadHistoryOperationUnsupported, …
    }
}
```
<!-- @endcode-block -->

The three calls differ only in their conflict semantics:

| Method                  | If a sample exists at the timestamp | If no sample exists |
| ----------------------- | ----------------------------------- | ------------------- |
| `historyInsertData()`   | `Bad_EntryExists`                   | inserts             |
| `historyReplaceData()`  | replaces                            | `Bad_NoEntryExists` |
| `historyUpdateData()`   | replaces                            | inserts             |

Use `historyUpdateData()` when you don't care which one happened —
the upsert is the most forgiving and the most common choice for
backfill scripts.

## Deleting data by range or timestamp

<!-- @method name="$client->historyDeleteRawModified(NodeId|string \$nodeId, DateTimeImmutable \$startTime, DateTimeImmutable \$endTime, bool \$isDeleteModified = false): int" returns="int (overall StatusCode)" visibility="public" -->

Delete every stored sample in `[startTime, endTime]`. `$isDeleteModified = true`
targets the **Modified** history (the audit copy of previous-value
versions) instead of the raw history.

<!-- @code-block language="php" label="purge a range" -->
```php
$status = $client->historyDeleteRawModified(
    nodeId:     'ns=2;s=Tank42/Level',
    startTime:  new DateTimeImmutable('-30 days'),
    endTime:    new DateTimeImmutable('-7 days'),
);

if (! StatusCode::isGood($status)) {
    throw new RuntimeException(
        'History purge rejected: ' . StatusCode::getName($status)
    );
}
```
<!-- @endcode-block -->

This call returns a single overall status code — the spec does not
expose per-sample failures for range deletes. For a fine-grained
delete, list the timestamps:

<!-- @method name="$client->historyDeleteAtTime(NodeId|string \$nodeId, DateTimeImmutable[] \$timestamps): int[]" returns="int[]" visibility="public" -->

<!-- @code-block language="php" label="delete specific timestamps" -->
```php
$statuses = $client->historyDeleteAtTime(
    nodeId:     'ns=2;s=Tank42/Level',
    timestamps: [
        new DateTimeImmutable('2026-05-19 08:00:00'),
        new DateTimeImmutable('2026-05-19 08:00:01'),
    ],
);
```
<!-- @endcode-block -->

The return is one status per timestamp — `Bad_NoEntryExists` for any
entry that wasn't found.

## Inserting / replacing / updating events

<!-- @method name="$client->historyInsertEvent(NodeId|string \$nodeId, string[] \$selectFields, array \$eventData): int[]" returns="int[]" visibility="public" -->
<!-- @method name="$client->historyReplaceEvent(NodeId|string \$nodeId, string[] \$selectFields, array \$eventData): int[]" returns="int[]" visibility="public" -->
<!-- @method name="$client->historyUpdateEvent(NodeId|string \$nodeId, string[] \$selectFields, array \$eventData): int[]" returns="int[]" visibility="public" -->

Event history writes carry a parallel `selectFields` / `eventData`
shape:

- `$selectFields` — `string[]`, the BrowseName-path of each event
  field (e.g. `['EventId', 'Severity', 'Message', 'Time']`).
- `$eventData` — `array<int, Variant[]>`, one `Variant[]` per event,
  matching `$selectFields` index-by-index.

The `$nodeId` is the **Event source** node (typically the `Server`
object `ns=0;i=2253`, or a specific device that emits the alarms
you're backfilling).

<!-- @code-block language="php" label="insert two historical alarms" -->
```php
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\BuiltinType;

$selectFields = ['EventId', 'Severity', 'Message', 'Time'];

$events = [
    [
        new Variant(BuiltinType::ByteString, hex2bin('0102030405060708')),
        new Variant(BuiltinType::UInt16,     800),
        new Variant(BuiltinType::String,     'Tank42 level exceeded HighLimit'),
        new Variant(BuiltinType::DateTime,   new DateTimeImmutable('2026-05-19 09:14:32')),
    ],
    [
        new Variant(BuiltinType::ByteString, hex2bin('0102030405060709')),
        new Variant(BuiltinType::UInt16,     400),
        new Variant(BuiltinType::String,     'Tank42 level returned to normal'),
        new Variant(BuiltinType::DateTime,   new DateTimeImmutable('2026-05-19 09:18:11')),
    ],
];

$statuses = $client->historyInsertEvent('i=2253', $selectFields, $events);
```
<!-- @endcode-block -->

Replace and Update follow the same pattern, with the same
Insert/Replace/Update conflict semantics as the data variants.

## Deleting events by EventId

<!-- @method name="$client->historyDeleteEvent(NodeId|string \$nodeId, string[] \$eventIds): int[]" returns="int[]" visibility="public" -->

Each EventId is the raw byte string the server originally minted for
the event — typically captured from a prior
`EventNotificationReceived` and stored alongside the user's record of
the alarm. The method targets the same Event-source node and returns
one status per EventId.

<!-- @code-block language="php" label="delete two events" -->
```php
$statuses = $client->historyDeleteEvent(
    nodeId:   'i=2253',
    eventIds: [
        hex2bin('0102030405060708'),
        hex2bin('0102030405060709'),
    ],
);
```
<!-- @endcode-block -->

## Events emitted by the write path

Every HistoryUpdate call dispatches one PSR-14 event after the
request returns. Wire a listener to instrument writes, audit
backfills, or push status to a dashboard:

| Method group                                          | Event class           | Key fields                                                         |
| ----------------------------------------------------- | --------------------- | ------------------------------------------------------------------ |
| `historyInsertData` / `ReplaceData` / `UpdateData`     | `HistoryDataUpdated`  | `$nodeId`, `$operation` (`PerformUpdateType`), `$valueCount`, `$operationResults` |
| `historyDeleteRawModified` / `historyDeleteAtTime`     | `HistoryDataDeleted`  | `$nodeId`, `$kind` (`'rawModified'` or `'atTime'`), `$statusCode`, `$operationResults` |
| `historyInsertEvent` / `ReplaceEvent` / `UpdateEvent`   | `HistoryEventUpdated` | `$nodeId`, `$operation`, `$eventCount`, `$operationResults`        |
| `historyDeleteEvent`                                  | `HistoryEventDeleted` | `$nodeId`, `$eventCount`, `$operationResults`                      |

All four carry a `$client` reference too. See
[Observability · Event reference](../observability/event-reference.md#history-updates-5)
for the full field list.

## Failure modes

The same per-server caveat as HistoryRead applies, plus a few
HistoryUpdate-specific status codes:

| StatusCode                       | Meaning                                                         |
| -------------------------------- | --------------------------------------------------------------- |
| `Bad_ServiceUnsupported`         | Server does not implement HistoryUpdate at all                  |
| `Bad_HistoryOperationUnsupported`| HistoryUpdate is implemented but this specific flavour isn't    |
| `Bad_EntryExists`                | `Insert` against an existing timestamp                          |
| `Bad_NoEntryExists`              | `Replace` against a missing timestamp                            |
| `Bad_InvalidArgument`            | Malformed `selectFields` / `eventData` shape, or empty input     |
| `Bad_NodeIdUnknown`              | The target node doesn't exist                                    |
| `Bad_UserAccessDenied`           | Session lacks the `HISTORYWRITE` access level on the node        |

`Bad_ServiceUnsupported` is unwrapped into a
`ServiceUnsupportedException` rather than being returned as a status
— catch it specifically. See
[Reference · Exceptions](../reference/exceptions.md) and
[Recipes · Handling unsupported services](../recipes/service-unsupported.md).

## Test server

`extra-test-suite` v1.2.0 ships an `open62541-historizing` service
on port `24842` with a single historizing `Double` at
`ns=2;s=Historizing.Counter` and an in-memory backend. The five
**Data** operations round-trip cleanly there; the event variants are
framed correctly at the wire level but the Memory backend doesn't
implement the actual event paths — expect
`Bad_HistoryOperationUnsupported` for those.

See
[`extra-test-suite/docs/servers/open62541-historizing`](https://github.com/php-opcua/extra-test-suite/blob/master/docs/servers/open62541-historizing.md)
for the full behaviour matrix.

## What to read next

- [History reads](./history-reads.md) — the read side of the same
  service set.
- [Client-side aggregates](./client-side-aggregates.md) — compute
  aggregates from a raw read instead of asking the historian for
  them.
