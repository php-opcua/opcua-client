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

<!-- @method name="$client->createMonitoredItems(int \$subscriptionId, ?array \$itemsToCreate = null): array|MonitoredItemsBuilder" returns="MonitoredItemResult[] or MonitoredItemsBuilder" visibility="public" -->

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
| `queueSize(int)`              | Server-side notification queue depth      |
| `clientHandle(int)`           | Caller-defined correlation handle         |
| `attributeId(int)`            | Which attribute to monitor (default `Value`) |
| `execute()`                   | Issue the call                            |

## Creating event items

<!-- @method name="$client->createEventMonitoredItem(int \$subscriptionId, NodeId|string \$objectId, array \$eventFilter): MonitoredItemResult" returns="MonitoredItemResult" visibility="public" -->

Event monitoring is a single-item shortcut — attach a typed filter to a
single object (typically `Server` for global events, or a specific
device emitting alarms):

<!-- @code-block language="php" label="example event monitoring" -->
```php
$filter = [
    'eventTypeId' => 'i=2041',         // BaseEventType — match everything
    'fields'      => ['SourceName', 'Message', 'Severity', 'EventType'],
    'whereClause' => null,             // optional structured filter
];

$result = $client->createEventMonitoredItem(
    subscriptionId: $sub->subscriptionId,
    objectId:       'i=2253',          // Server
    eventFilter:    $filter,
);
```
<!-- @endcode-block -->

Filter contents are passed through as-is; the client does not enforce
a schema. Refer to OPC UA Part 4 §7.22 for the formal event filter
grammar.

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

Change sampling interval, queue size, or client handle without
deleting/recreating:

<!-- @code-block language="php" label="modify sampling rate" -->
```php
$client->modifyMonitoredItems($sub->subscriptionId, [
    [
        'monitoredItemId'  => $results[0]->monitoredItemId,
        'samplingInterval' => 1000.0,
        'queueSize'        => 20,
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
