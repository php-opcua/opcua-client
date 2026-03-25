# Subscriptions & Monitoring

## Overview

Subscriptions let you receive notifications when values change, instead of polling with `read()`. The workflow:

1. Create a subscription
2. Add monitored items to it
3. Call `publish()` to collect notifications
4. Clean up when done

## Creating a Subscription

```php
$sub = $client->createSubscription(
    publishingInterval: 1000.0,
    lifetimeCount: 2400,
    maxKeepAliveCount: 10,
    maxNotificationsPerPublish: 0,
    publishingEnabled: true,
    priority: 0,
);

echo 'Subscription ID: ' . $sub->subscriptionId . "\n";
echo 'Revised interval: ' . $sub->revisedPublishingInterval . " ms\n";
```

Returns a [`SubscriptionResult`](08-types.md#subscriptionresult). The server may revise your requested intervals -- always check the `revised*` properties.

> **Events:** Dispatches `SubscriptionCreated` after creation. See [Events](14-events.md).

## Monitoring Data Changes

Add nodes to watch for value changes:

```php
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

// Fluent builder
$results = $client->createMonitoredItems($sub->subscriptionId)
    ->add('i=2258')->samplingInterval(500.0)->queueSize(10)->clientHandle(1)
    ->add('ns=2;i=1001')
    ->execute();

foreach ($results as $result) {
    echo 'Item ' . $result->monitoredItemId
        . ': ' . StatusCode::getName($result->statusCode) . "\n";
}

// Or with array (still works)
$results = $client->createMonitoredItems(
    $sub->subscriptionId,
    [
        [
            'nodeId' => NodeId::numeric(0, 2258),   // CurrentTime
            'samplingInterval' => 500.0,
            'queueSize' => 10,
            'clientHandle' => 1,
        ],
        [
            'nodeId' => NodeId::numeric(2, 1001),
        ],
    ]
);
```

> **Tip:** The builder's `->add()` starts a new monitored item. Chain `->samplingInterval()`, `->queueSize()`, and `->clientHandle()` to configure it. Unset options use server defaults.

> **Events:** Dispatches `MonitoredItemCreated` for each item created. See [Events](14-events.md).

### Monitored Item Parameters

| Parameter | Default | Description |
|---|---|---|
| `nodeId` | *(required)* | Node to monitor |
| `attributeId` | `13` (Value) | Which attribute to watch |
| `samplingInterval` | `-1.0` | Sampling rate in ms (`-1` = server decides) |
| `queueSize` | `1` | Max queued notifications before oldest is dropped |
| `clientHandle` | auto | Your identifier -- comes back in notifications |
| `monitoringMode` | `2` (Reporting) | `0` = Disabled, `1` = Sampling, `2` = Reporting |

## Monitoring Events

Watch a node for OPC UA events:

```php
$result = $client->createEventMonitoredItem(
    $sub->subscriptionId,
    NodeId::numeric(0, 2253), // Server object
    ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'],
    clientHandle: 1,
);

echo 'Status: ' . StatusCode::getName($result->statusCode) . "\n";
```

The field list defaults to `EventId`, `EventType`, `SourceName`, `Time`, `Message`, `Severity` if you omit it.

## Receiving Notifications

Call `publish()` to get pending notifications:

```php
$response = $client->publish();

echo 'Subscription: ' . $response->subscriptionId . "\n";
echo 'More waiting: ' . ($response->moreNotifications ? 'yes' : 'no') . "\n";

foreach ($response->notifications as $notif) {
    if ($notif['type'] === 'DataChange') {
        echo 'Handle ' . $notif['clientHandle']
            . ': ' . $notif['dataValue']->getValue() . "\n";
    }

    if ($notif['type'] === 'Event') {
        echo 'Event on handle ' . $notif['clientHandle'] . ":\n";
        foreach ($notif['eventFields'] as $field) {
            echo '  ' . $field->value . "\n";
        }
    }
}
```

`publish()` returns a [`PublishResult`](08-types.md#publishresult).

> **Events:** Each `publish()` dispatches `PublishResponseReceived`. Per-notification events are also fired: `DataChangeReceived` for data changes, `EventNotificationReceived` for events, plus alarm-specific events (`AlarmActivated`, `AlarmDeactivated`, `AlarmSeverityChanged`, etc.) when alarm fields are present. When no notifications are returned, `SubscriptionKeepAlive` is dispatched instead. See [Events](14-events.md) for the full list and alarm deduction logic.

### Acknowledging Notifications

Pass acknowledgment info to `publish()` so the server stops resending:

```php
$response = $client->publish();

// Acknowledge the previous notification on the next publish call
$response2 = $client->publish([
    [
        'subscriptionId' => $response->subscriptionId,
        'sequenceNumber' => $response->sequenceNumber,
    ],
]);
```

## Full Polling Loop

Here is a complete example that creates a subscription, monitors a node, and processes notifications in a loop:

```php
$sub = $client->createSubscription(publishingInterval: 500.0);

$client->createMonitoredItems($sub->subscriptionId, [
    ['nodeId' => NodeId::numeric(2, 1001)],
]);

$lastAck = [];

for ($i = 0; $i < 100; $i++) {
    $response = $client->publish($lastAck);

    foreach ($response->notifications as $notif) {
        echo $notif['dataValue']->getValue() . "\n";
    }

    $lastAck = [[
        'subscriptionId' => $response->subscriptionId,
        'sequenceNumber' => $response->sequenceNumber,
    ]];
}
```

## Modifying Monitored Items

Change sampling interval, queue size, or other parameters on existing monitored items without recreating them:

```php
$results = $client->modifyMonitoredItems($subscriptionId, [
    ['monitoredItemId' => $monId1, 'samplingInterval' => 1000.0],
    ['monitoredItemId' => $monId2, 'queueSize' => 10, 'discardOldest' => false],
]);

foreach ($results as $result) {
    echo "Status: " . StatusCode::getName($result->statusCode) . "\n";
    echo "Revised interval: {$result->revisedSamplingInterval}ms\n";
    echo "Revised queue: {$result->revisedQueueSize}\n";
}
```

> **Events:** Dispatches `MonitoredItemModified` per item. See [Events](14-events.md).

## SetTriggering

Configure a monitored item as a trigger for other items. Linked items are only sampled and reported when the triggering item changes — useful for reducing traffic when you have many variables but only care about them during specific conditions:

```php
$result = $client->setTriggering(
    $subscriptionId,
    $triggeringItemId,    // master: e.g. a "CycleComplete" flag
    [$linkedId1, $linkedId2],  // slaves: sampled only when master changes
);

foreach ($result->addResults as $statusCode) {
    echo StatusCode::getName($statusCode) . "\n"; // Good
}

// Later: remove a link
$result = $client->setTriggering(
    $subscriptionId,
    $triggeringItemId,
    linksToRemove: [$linkedId1],
);
```

> **Events:** Dispatches `TriggeringConfigured` after the operation completes. See [Events](14-events.md).

## Cleanup

Delete monitored items or the entire subscription when you are done:

```php
// Remove specific monitored items
$statuses = $client->deleteMonitoredItems(
    $subscriptionId,
    [$monitoredItemId1, $monitoredItemId2]
);

// Remove the subscription
$status = $client->deleteSubscription($subscriptionId);
```

> **Tip:** Subscriptions are automatically cleaned up when you call `$client->disconnect()`.

> **Events:** `deleteMonitoredItems()` dispatches `MonitoredItemDeleted` per item. `deleteSubscription()` dispatches `SubscriptionDeleted`. See [Events](14-events.md).

## Transfer & Recovery

These two methods support subscription transfer and notification recovery. They are primarily useful when working with the [session manager package](https://github.com/GianfriAur/opcua-php-client-session-manager), which persists sessions across PHP requests, but they are exposed on the client for completeness.

### Transferring Subscriptions

Move existing subscriptions from one session to another. This is how the session manager reclaims subscriptions after reconnecting:

```php
$results = $client->transferSubscriptions(
    subscriptionIds: [1, 2, 3],
    sendInitialValues: true,
);

foreach ($results as $result) {
    echo 'Status: ' . StatusCode::getName($result->statusCode) . "\n";
    echo 'Available sequence numbers: ' . implode(', ', $result->availableSequenceNumbers) . "\n";
}
```

Each [`TransferResult`](08-types.md#transferresult) contains the status code and a list of sequence numbers available for republishing. Pass `sendInitialValues: true` to have the server queue the current value of each monitored item as an initial notification.

### Republishing Notifications

Re-request a notification message that was not acknowledged. Use the sequence numbers from `TransferResult::$availableSequenceNumbers` or from a previous `publish()` call:

```php
$notifications = $client->republish(
    subscriptionId: 1,
    retransmitSequenceNumber: 42,
);

foreach ($notifications as $notif) {
    if ($notif['type'] === 'DataChange') {
        echo 'Handle ' . $notif['clientHandle']
            . ': ' . $notif['dataValue']->getValue() . "\n";
    }
}
```

Returns the same notification array format as `publish()`.

> **Note:** In most applications you will not call these methods directly. The session manager package handles transfer and republish automatically when it reconnects a persisted session. These methods are exposed for advanced use cases and custom session recovery logic.
