# Events

## Overview

The client dispatches granular [PSR-14](https://www.php-fig.org/psr/psr-14/) events at every key lifecycle point. Inject any `EventDispatcherInterface` implementation — Laravel's dispatcher, Symfony's event dispatcher, or your own — and react to connections, sessions, subscriptions, data changes, alarms, reads, writes, browses, cache operations, and retries.

A `NullEventDispatcher` is used by default, ensuring **zero overhead** when no dispatcher is configured. Event objects are lazily instantiated via closures, so no allocation happens unless a real dispatcher is listening.

## Configuration

```php
use Gianfriaur\OpcuaPhpClient\Client;
use Psr\EventDispatcher\EventDispatcherInterface;

$client = new Client();
$client->setEventDispatcher($yourDispatcher);

// Get the current dispatcher
$dispatcher = $client->getEventDispatcher();
```

### Laravel

```php
$client->setEventDispatcher(app(EventDispatcherInterface::class));
```

Or in a service provider:

```php
$this->app->afterResolving(Client::class, function (Client $client) {
    $client->setEventDispatcher($this->app->make(EventDispatcherInterface::class));
});
```

Then listen with standard Laravel listeners:

```php
// EventServiceProvider
protected $listen = [
    \Gianfriaur\OpcuaPhpClient\Event\DataChangeReceived::class => [
        \App\Listeners\HandleOpcUaDataChange::class,
    ],
    \Gianfriaur\OpcuaPhpClient\Event\AlarmActivated::class => [
        \App\Listeners\HandleOpcUaAlarm::class,
    ],
];
```

## Event Reference

Every event is a `readonly` class in `Gianfriaur\OpcuaPhpClient\Event\`. All events carry a `$client` property referencing the `OpcUaClientInterface` that emitted them.

### Connection Events

| Event | Properties | When |
|---|---|---|
| `ClientConnecting` | `$endpointUrl` | Before `connect()` starts |
| `ClientConnected` | `$endpointUrl` | After successful connection |
| `ConnectionFailed` | `$endpointUrl`, `$exception` | When connection attempt fails |
| `ClientReconnecting` | `$endpointUrl` | Before `reconnect()` starts |
| `ClientDisconnecting` | `$endpointUrl` | Before `disconnect()` starts |
| `ClientDisconnected` | | After full disconnect |

### Session Events

| Event | Properties | When |
|---|---|---|
| `SessionCreated` | `$endpointUrl`, `$authenticationToken` | After CreateSession succeeds |
| `SessionActivated` | `$endpointUrl` | After ActivateSession succeeds |
| `SessionClosed` | | Before session close request |

### Secure Channel Events

| Event | Properties | When |
|---|---|---|
| `SecureChannelOpened` | `$channelId`, `$securityPolicy`, `$securityMode` | After secure channel is opened |
| `SecureChannelClosed` | `$channelId` | Before secure channel close |

### Subscription Events

| Event | Properties | When |
|---|---|---|
| `SubscriptionCreated` | `$subscriptionId`, `$revisedPublishingInterval`, `$revisedLifetimeCount`, `$revisedMaxKeepAliveCount` | After `createSubscription()` |
| `SubscriptionDeleted` | `$subscriptionId`, `$statusCode` | After `deleteSubscription()` |
| `SubscriptionTransferred` | `$subscriptionId`, `$statusCode` | After `transferSubscriptions()` (per item) |
| `MonitoredItemCreated` | `$subscriptionId`, `$monitoredItemId`, `$nodeId`, `$statusCode` | After `createMonitoredItems()` / `createEventMonitoredItem()` (per item) |
| `MonitoredItemDeleted` | `$subscriptionId`, `$monitoredItemId`, `$statusCode` | After `deleteMonitoredItems()` (per item) |
| `MonitoredItemModified` | `$subscriptionId`, `$monitoredItemId`, `$statusCode` | After `modifyMonitoredItems()` (per item) |
| `TriggeringConfigured` | `$subscriptionId`, `$triggeringItemId`, `$addResults`, `$removeResults` | After `setTriggering()` |

### Publish Events

| Event | Properties | When |
|---|---|---|
| `PublishResponseReceived` | `$subscriptionId`, `$sequenceNumber`, `$notificationCount`, `$moreNotifications` | After every `publish()` call |
| `SubscriptionKeepAlive` | `$subscriptionId`, `$sequenceNumber` | When `publish()` returns no notifications |
| `DataChangeReceived` | `$subscriptionId`, `$sequenceNumber`, `$clientHandle`, `$dataValue` | Per data change notification |
| `EventNotificationReceived` | `$subscriptionId`, `$sequenceNumber`, `$clientHandle`, `$eventFields` | Per event notification |

### Alarm Events (Generic)

| Event | Properties | When |
|---|---|---|
| `AlarmEventReceived` | `$subscriptionId`, `$clientHandle`, `$eventFields`, `$severity`, `$sourceName`, `$message`, `$eventType`, `$time` | For every event notification with alarm-relevant data |

### Alarm Events (Specific)

These are automatically deduced from event notification fields. They require the corresponding fields to be included in `createEventMonitoredItem()`'s `$selectFields`.

| Event | Properties | Deduced from |
|---|---|---|
| `AlarmActivated` | `$subscriptionId`, `$clientHandle`, `$sourceName`, `$severity`, `$message` | ActiveState = true / "Active" |
| `AlarmDeactivated` | `$subscriptionId`, `$clientHandle`, `$sourceName`, `$message` | ActiveState = false / "Inactive" |
| `AlarmAcknowledged` | `$subscriptionId`, `$clientHandle`, `$sourceName` | AckedState text contains "acknowledged" |
| `AlarmConfirmed` | `$subscriptionId`, `$clientHandle`, `$sourceName` | ConfirmedState text contains "confirmed" |
| `AlarmShelved` | `$subscriptionId`, `$clientHandle`, `$sourceName` | ShelvingState text contains "shelved" |
| `AlarmSeverityChanged` | `$subscriptionId`, `$clientHandle`, `$sourceName`, `$severity` | Severity field present in notification |
| `LimitAlarmExceeded` | `$subscriptionId`, `$clientHandle`, `$sourceName`, `$limitState`, `$severity` | EventType is a known LimitAlarm type |
| `OffNormalAlarmTriggered` | `$subscriptionId`, `$clientHandle`, `$sourceName`, `$severity` | EventType is OffNormalAlarm/DiscreteAlarm |

### Read / Write / Browse Events

| Event | Properties | When |
|---|---|---|
| `NodeValueRead` | `$nodeId`, `$attributeId`, `$dataValue` | After `read()` |
| `NodeValueWritten` | `$nodeId`, `$value`, `$type`, `$statusCode` | After successful `write()` |
| `NodeValueWriteFailed` | `$nodeId`, `$statusCode` | After `write()` with non-Good status |
| `NodeBrowsed` | `$nodeId`, `$direction`, `$resultCount` | After `browse()` |

### Write Type Detection Events

| Event | Properties | When |
|---|---|---|
| `WriteTypeDetecting` | `$nodeId` | Before type detection starts (read or cache lookup) |
| `WriteTypeDetected` | `$nodeId`, `$detectedType`, `$fromCache` | After type is successfully determined |

### Cache Events

| Event | Properties | When |
|---|---|---|
| `CacheHit` | `$key` | When a cached result is found |
| `CacheMiss` | `$key` | When a cached result is not found |

### Retry Events

| Event | Properties | When |
|---|---|---|
| `RetryAttempt` | `$attempt`, `$maxRetries`, `$exception` | Before each automatic retry |
| `RetryExhausted` | `$attempts`, `$exception` | When all retries are exhausted |

### Type Discovery Events

| Event | Properties | When |
|---|---|---|
| `DataTypesDiscovered` | `$namespaceIndex`, `$count` | After `discoverDataTypes()` completes |

### Trust Store Events

| Event | Properties | When |
|---|---|---|
| `ServerCertificateTrusted` | `$fingerprint`, `$subject` | Server cert passes trust store validation |
| `ServerCertificateRejected` | `$fingerprint`, `$reason`, `$subject` | Server cert rejected by trust store |
| `ServerCertificateAutoAccepted` | `$fingerprint`, `$subject` | Server cert auto-accepted via TOFU |
| `ServerCertificateManuallyTrusted` | `$fingerprint`, `$subject` | Cert added via `trustCertificate()` |
| `ServerCertificateRemoved` | `$fingerprint` | Cert removed via `untrustCertificate()` |

## Practical Examples

### Log all data changes to a database

```php
class DataChangeListener
{
    public function __invoke(DataChangeReceived $event): void
    {
        DB::table('opcua_values')->insert([
            'subscription_id' => $event->subscriptionId,
            'client_handle' => $event->clientHandle,
            'value' => $event->dataValue->getValue(),
            'status_code' => $event->dataValue->statusCode,
            'source_timestamp' => $event->dataValue->sourceTimestamp,
            'recorded_at' => now(),
        ]);
    }
}
```

### Send Slack alerts on alarm activation

```php
class AlarmAlertListener
{
    public function __invoke(AlarmActivated $event): void
    {
        Notification::route('slack', config('opcua.slack_webhook'))
            ->notify(new AlarmNotification(
                source: $event->sourceName,
                severity: $event->severity,
                message: $event->message,
            ));
    }
}
```

### Monitor connection health

```php
class ConnectionHealthListener
{
    public function handleConnected(ClientConnected $event): void
    {
        Cache::put("opcua:{$event->endpointUrl}:status", 'connected');
        Metrics::gauge('opcua.connections.active', 1);
    }

    public function handleFailed(ConnectionFailed $event): void
    {
        Cache::put("opcua:{$event->endpointUrl}:status", 'failed');
        Log::error('OPC UA connection failed', [
            'endpoint' => $event->endpointUrl,
            'error' => $event->exception->getMessage(),
        ]);
    }

    public function handleRetry(RetryAttempt $event): void
    {
        Metrics::increment('opcua.retries', tags: [
            'attempt' => $event->attempt,
        ]);
    }
}
```

### Track subscription lifecycle for session manager

```php
class SubscriptionTracker
{
    public function handleCreated(SubscriptionCreated $event): void
    {
        Redis::hSet('opcua:subscriptions', $event->subscriptionId, json_encode([
            'interval' => $event->revisedPublishingInterval,
            'created_at' => now()->toIso8601String(),
        ]));
    }

    public function handleDeleted(SubscriptionDeleted $event): void
    {
        Redis::hDel('opcua:subscriptions', $event->subscriptionId);
    }
}
```

### Alarm event monitoring with extended fields

To receive specific alarm events (AlarmActivated, AlarmDeactivated, etc.), include the relevant state fields when creating the event monitored item:

```php
$result = $client->createEventMonitoredItem(
    $sub->subscriptionId,
    $alarmNodeId,
    [
        'EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity',
        'ActiveState',      // enables AlarmActivated / AlarmDeactivated
        'AckedState',       // enables AlarmAcknowledged
        'ConfirmedState',   // enables AlarmConfirmed
    ],
);
```

The default 6 fields (`EventId`, `EventType`, `SourceName`, `Time`, `Message`, `Severity`) always trigger `AlarmEventReceived` and `AlarmSeverityChanged`. Adding state fields beyond position 6 enables the corresponding specific events.

## Performance

- **NullEventDispatcher** (default): `dispatch()` does an `instanceof` check and returns immediately. No event object is allocated.
- **Lazy closures**: all dispatch calls use `fn() => new Event(...)`. The closure is only invoked when a real dispatcher is set.
- **Zero overhead when unused**: the entire event system adds no measurable cost to operations when no dispatcher is configured.
