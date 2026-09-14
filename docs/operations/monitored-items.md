---
eyebrow: 'Docs · Operations'
lede:    'Monitored items live inside subscriptions. Each one watches a single attribute (usually Value) or an event source, with its own sampling rate, queue size, and trigger.'

see_also:
  - { href: './subscriptions.md',  meta: '7 min' }
  - { href: '../observability/events.md', meta: '6 min' }
  - { href: '../recipes/subscribing-to-data-changes.md', meta: '5 min' }

prev: { label: 'Subscriptions',  href: './subscriptions.md' }
next: { label: 'History reads',  href: './history-reads.md' }
---

# Monitored items

A monitored item is the unit of observation inside a subscription.
There are two flavours:

- **Data-change monitored items** — watch the `Value` (or another
  attribute) of a node and emit a notification when it crosses a
  configurable filter (deadband, sampling rate).
- **Event monitored items** — watch an `Object` node that emits OPC UA
  events and emit a notification when one matches the event filter
  (severity, type, contents).

Both share the same CRUD operations on the client; the difference is
in how the server reports back.

## Creating data-change items

<!-- @method name="$client->createMonitoredItems(int \$subscriptionId, ?array \$items = null): array|MonitoredItemsBuilder" returns="MonitoredItemResult[] or MonitoredItemsBuilder" visibility="public" -->

<!-- @code-block language="php" label="array form" -->
```php
$sub = $client->createSubscription(publishingInterval: 250.0);

$results = $client->createMonitoredItems($sub->subscriptionId, [
    ['nodeId' => 'ns=2;s=PLC/Speed',    'samplingInterval' => 250.0, 'queueSize' => 10],
    ['nodeId' => 'ns=2;s=PLC/Mode',     'samplingInterval' => 1000.0],
    ['nodeId' => 'ns=2;s=PLC/Health',   'samplingInterval' => 1000.0],
]);

foreach ($results as $r) {
    if ($r->statusCode !== 0) {
        // BadNodeIdUnknown, BadAttributeIdInvalid, BadMonitoringModeInvalid …
    }
}
```
<!-- @endcode-block -->

`MonitoredItemResult`:

| Field                      | Meaning                                       |
| -------------------------- | --------------------------------------------- |
| `statusCode`               | Per-item creation status                      |
| `monitoredItemId`          | Server handle — needed for `modify` / `delete` |
| `revisedSamplingInterval`  | Actual interval (may differ from requested)   |
| `revisedQueueSize`         | Actual queue size                             |

### Fluent builder

<!-- @code-block language="php" label="fluent form" -->
```php
$results = $client->createMonitoredItems($sub->subscriptionId)
    ->add('ns=2;s=PLC/Speed')->samplingInterval(250.0)->queueSize(10)
    ->add('ns=2;s=PLC/Mode')->samplingInterval(1000.0)
    ->add('ns=2;s=PLC/Health')->samplingInterval(1000.0)
    ->execute();
```
<!-- @endcode-block -->

Builder methods, accumulated against the most recent `add()`:

| Method                        | Meaning                                   |
| ----------------------------- | ----------------------------------------- |
| `add(NodeId\|string)`         | Start a new item                          |
| `samplingInterval(float)`     | Target ms; `0` = server-default           |
| `queueSize(int)`              | Server-side notification queue depth (default `1`) |
| `clientHandle(int)`           | Caller-defined correlation handle         |
| `attributeId(int)`            | Which attribute to monitor (default `Value`) |
| `monitoringMode(int)`         | `0` Disabled, `1` Sampling, `2` Reporting (default) |
| `discardOldest(bool)`         | Drop the oldest queued value when the queue is full (default `true`) |
| `dataChangeFilter(int $trigger = 1, int $deadbandType = 0, float $deadbandValue = 0.0)` | Set a `DataChangeFilter` — see below |
| `execute()`                   | Issue the call                            |

Without `queueSize` an item gets a single-slot queue that keeps only
the latest sample, so intermediate values are overwritten silently —
see [DataValue and Variant · Overflow and limit
bits](../types/data-value-and-variant.md#overflow-and-limit-bits).
Without `clientHandle`, the library assigns each item its position in
the list plus one.

### Data change filter

The `filter` key (or `dataChangeFilter()` on the builder) sets a
`DataChangeFilter`, which decides which changes produce a notification:

| Key             | Values                                                   | Default |
| --------------- | -------------------------------------------------------- | ------- |
| `trigger`       | `0` Status, `1` StatusValue, `2` StatusValueTimestamp    | `1`     |
| `deadbandType`  | `0` None, `1` Absolute, `2` Percent                      | `0`     |
| `deadbandValue` | Engineering units (Absolute) or a percentage of the variable's `EURange` (Percent) | `0.0` |

<!-- @code-block language="php" label="absolute deadband" -->
```php
$results = $client->createMonitoredItems($sub->subscriptionId, [
    [
        'nodeId'           => 'ns=2;s=Sensors/Temperature',
        'samplingInterval' => 250.0,
        'filter'           => ['deadbandType' => 1, 'deadbandValue' => 0.5],
    ],
]);
```
<!-- @endcode-block -->

With an Absolute deadband, a value is reported only when it differs
from the last reported value by more than `deadbandValue`. Against
UA-.NETStandard, with a deadband of `5` on a value of `100`, a write of
`102` produces no notification and a following write of `110` does.

A Percent deadband needs an `AnalogItemType` variable with an
`EURange`; on any other variable the server rejects the item with a Bad
`statusCode`, which is why checking every `MonitoredItemResult` matters.
Without `filter` no filter is sent, and the server reports every change
of status or value.

## Creating event items

<!-- @method name="$client->createEventMonitoredItem(int \$subscriptionId, NodeId|string \$nodeId, array \$selectFields = ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'], int \$clientHandle = 1): MonitoredItemResult" returns="MonitoredItemResult" visibility="public" -->

Event monitoring is a single-item shortcut — attach a list of event
fields to a single object (typically `Server` for global events, or a
specific device emitting alarms). The `$clientHandle` is the
caller-defined integer used to correlate incoming
`EventNotificationReceived` events back to this item.

<!-- @code-block language="php" label="example event monitoring" -->
```php
$result = $client->createEventMonitoredItem(
    subscriptionId: $sub->subscriptionId,
    nodeId:         'i=2253',          // Server object
    selectFields:   ['SourceName', 'Message', 'Severity', 'EventType'],
    clientHandle:   42,
);
```
<!-- @endcode-block -->

Omitting `$selectFields` keeps the default set
(`EventId, EventType, SourceName, Time, Message, Severity`). The
method publishes a simple `SelectClauses`-only event filter; for a
structured `WhereClause` filter, drop down to the underlying
`SubscriptionModule` services. Refer to OPC UA Part 4 §7.22 for the
formal event filter grammar.

### Alarms

OPC UA alarms are events with extra fields. When the dispatcher
receives an event notification whose payload exposes alarm-typed
fields (`AckedState`, `ActiveState`, `Severity`, `OffNormalState`), the
client auto-deduces and dispatches one of the alarm-specific events:

| Event                       | Trigger                                           |
| --------------------------- | ------------------------------------------------- |
| `AlarmEventReceived`        | Any alarm-shaped event                            |
| `AlarmActivated`            | `ActiveState` transitioned to `Active`            |
| `AlarmDeactivated`          | `ActiveState` transitioned to `Inactive`          |
| `AlarmAcknowledged`         | `AckedState` transitioned to acknowledged         |
| `AlarmConfirmed`            | `ConfirmedState` transitioned to confirmed        |
| `AlarmShelved`              | Shelved/Unshelved transitions                     |
| `AlarmSeverityChanged`      | Severity changed                                  |
| `LimitAlarmExceeded`        | LimitAlarmType variants                           |
| `OffNormalAlarmTriggered`   | OffNormalAlarmType variants                       |

Wire a PSR-14 listener for the events you care about — there is no
need to interpret the raw payload yourself for these cases. See
[Observability · Event reference](../observability/event-reference.md).

## Modifying

<!-- @method name="$client->modifyMonitoredItems(int \$subscriptionId, array \$itemsToModify): array" returns="MonitoredItemModifyResult[]" visibility="public" -->

Change sampling interval, queue size, client handle, discard policy or
data change filter without deleting and recreating the item.

**This is not a partial update.** Every item is sent with all of its
parameters, and a key you leave out is sent as a default — the server
does not keep the previous value:

| Omitted key        | Sent as | Effect on the server                                          |
| ------------------ | ------- | ------------------------------------------------------------- |
| `clientHandle`     | `0`     | Notifications arrive with handle `0`; a handle-to-node map no longer matches them |
| `queueSize`        | `0`     | Revised to a single-slot queue                                |
| `samplingInterval` | `-1`    | Samples at the subscription's publishing interval             |
| `discardOldest`    | `true`  | The oldest value is dropped when the queue is full            |
| `filter`           | none    | Any data change filter the item had is removed                |

Against UA-.NETStandard, modifying only the `samplingInterval` of an
item created with `clientHandle: 7` and `queueSize: 10` returns `Good`
with `revisedQueueSize: 1`, and every notification after it carries
`clientHandle` `0`. Always pass every parameter you want to keep:

<!-- @code-block language="php" label="modify sampling rate" -->
```php
$client->modifyMonitoredItems($sub->subscriptionId, [
    [
        'monitoredItemId'  => $results[0]->monitoredItemId,
        'clientHandle'     => 1,        // the handle it was created with
        'samplingInterval' => 1000.0,
        'queueSize'        => 20,
        'discardOldest'    => true,
    ],
]);
```
<!-- @endcode-block -->

## Triggering

OPC UA's `SetTriggering` service links monitored items such that one
item's notification causes another to also fire — useful when you want
a "slow" diagnostic stream to spike to "fast" sampling on demand.

<!-- @method name="$client->setTriggering(int \$subscriptionId, int \$triggeringItemId, array \$linksToAdd = [], array \$linksToRemove = []): SetTriggeringResult" returns="SetTriggeringResult" visibility="public" -->

<!-- @code-block language="php" label="link a triggering chain" -->
```php
$result = $client->setTriggering(
    subscriptionId:   $sub->subscriptionId,
    triggeringItemId: $alarmItem,
    linksToAdd:       [$slowDiagItem1, $slowDiagItem2],
);
```
<!-- @endcode-block -->

`SetTriggeringResult` exposes `addResults` and `removeResults` arrays —
one status code per link operation.

## Deleting

<!-- @method name="$client->deleteMonitoredItems(int \$subscriptionId, array \$monitoredItemIds): array" returns="int[]" visibility="public" -->

Frees server-side resources for the items but keeps the subscription
alive. Returns a parallel array of per-item status codes.

<!-- @callout variant="warning" -->
Deleting a monitored item does **not** drain the server's pending
notification queue for that item. Late notifications can still arrive
in the next `publish()` reply. Filter them by `monitoredItemId` on the
client side if relevance is critical.
<!-- @endcallout -->

## What to read next

- [Recipes · Subscribing to data
  changes](../recipes/subscribing-to-data-changes.md) — a complete
  worker-style example.
- [Observability · Event reference](../observability/event-reference.md)
  — every dispatcher event the subscription path emits.
