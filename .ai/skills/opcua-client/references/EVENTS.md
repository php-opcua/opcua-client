# Events reference

56 PSR-14 events under `PhpOpcua\Client\Event\`. All `readonly`. Every event carries a `$client` reference, typed `OpcUaClientInterface` (the firing client — at runtime a `Client` instance).

## Setup

```php
use PhpOpcua\Client\ClientBuilder;

$client = ClientBuilder::create()
    ->setEventDispatcher($psr14Dispatcher)         // any PSR-14 dispatcher
    ->connect('opc.tcp://...');
```

`NullEventDispatcher` is used by default. **Zero overhead when not configured** — event objects are constructed inside lambdas passed to `kernel->dispatch(fn () => new Event(...))`, so the closure is invoked only when a real dispatcher is set.

## Event catalog

### Connection lifecycle (5)

- `ClientConnecting($client, $endpointUrl)` — before connect attempt
- `ClientConnected($client, $endpointUrl)` — after successful connect
- `ConnectionFailed($client, $endpointUrl, Throwable $exception)` — connect failed (access the throwable via `$e->exception`)
- `ClientDisconnecting($client, $endpointUrl)`, `ClientDisconnected($client)` — disconnect

### Secure channel (2 — v4.0)

- `SecureChannelOpened($client, int $channelId, SecurityPolicy $securityPolicy, SecurityMode $securityMode)`
- `SecureChannelClosed($client, int $channelId)`

### Session (3)

- `SessionCreated($client, $endpointUrl, NodeId $authenticationToken)`
- `SessionActivated($client, $endpointUrl)`
- `SessionClosed($client)`

### Subscription (5)

- `SubscriptionCreated($client, int $subscriptionId, float $revisedPublishingInterval, int $revisedLifetimeCount, int $revisedMaxKeepAliveCount)`
- `SubscriptionDeleted($client, int $subscriptionId, int $statusCode)`
- `SubscriptionTransferred($client, int $subscriptionId, int $statusCode)`
- `SubscriptionKeepAlive($client, int $subscriptionId, int $sequenceNumber)` — publish response with no notifications
- `PublishResponseReceived($client, int $subscriptionId, int $sequenceNumber, int $notificationCount, bool $moreNotifications)` — after every publish response is decoded

### Monitored items (3)

- `MonitoredItemCreated($client, int $subscriptionId, int $monitoredItemId, NodeId $nodeId, int $statusCode)`
- `MonitoredItemModified($client, int $subscriptionId, int $monitoredItemId, int $statusCode)`
- `MonitoredItemDeleted($client, int $subscriptionId, int $monitoredItemId, int $statusCode)`

### Triggering (1)

- `TriggeringConfigured($client, int $subscriptionId, int $triggeringItemId, array $addResults, array $removeResults)` — triggering links added/removed for a triggering item

### Data / event / alarm notifications (5)

- `DataChangeReceived($client, int $subscriptionId, int $sequenceNumber, int $clientHandle, DataValue $dataValue)`
- `EventNotificationReceived($client, int $subscriptionId, int $sequenceNumber, int $clientHandle, Variant[] $eventFields)`
- `AlarmEventReceived($client, int $subscriptionId, int $clientHandle, Variant[] $eventFields, ?int $severity = null, ?string $sourceName = null, ?string $message = null, ?NodeId $eventType = null, ?DateTimeImmutable $time = null)`
- `AlarmActivated($client, int $subscriptionId, int $clientHandle, ?string $sourceName = null, ?int $severity = null, ?string $message = null)`
- `AlarmDeactivated($client, int $subscriptionId, int $clientHandle, ?string $sourceName = null, ?string $message = null)`

Correlate each notification to its monitored item via `$e->clientHandle` (the client handle assigned when the item was created) — there is no `monitoredItemId` on these events. Data-change vs event is decided by notification type — `DataChangeNotification` vs `EventNotification` (`instanceof`), not by field inspection. An event notification is then classified as an alarm only when its `EventNotification` fields yield a `Severity` value (field index 5) or an `EventType` NodeId (field index 1); otherwise no `Alarm*` event fires. The `Time` (field 3) and `SourceName` (field 2) fields are extracted as alarm metadata but play no part in that decision.

### Alarm state changes (6)

Further `Alarm*` events derived from the same `EventNotification` fields, dispatched alongside the notifications above.

- `AlarmSeverityChanged($client, int $subscriptionId, int $clientHandle, ?string $sourceName = null, int $severity = 0)` — fires whenever a severity field is present
- `LimitAlarmExceeded($client, int $subscriptionId, int $clientHandle, ?string $sourceName = null, ?string $limitState = null, ?int $severity = null)` — EventType is a limit alarm type
- `OffNormalAlarmTriggered($client, int $subscriptionId, int $clientHandle, ?string $sourceName = null, ?int $severity = null)` — EventType is an off-normal alarm type
- `AlarmAcknowledged($client, int $subscriptionId, int $clientHandle, ?string $sourceName = null)`
- `AlarmConfirmed($client, int $subscriptionId, int $clientHandle, ?string $sourceName = null)`
- `AlarmShelved($client, int $subscriptionId, int $clientHandle, ?string $sourceName = null)`

### Read / write (5)

- `NodeValueRead($client, NodeId $nodeId, int $attributeId, DataValue $dataValue)`
- `NodeValueWritten($client, NodeId $nodeId, mixed $value, BuiltinType $type, int $statusCode)`
- `NodeValueWriteFailed($client, NodeId $nodeId, int $statusCode)` — write returned a bad status code
- `WriteTypeDetecting($client, NodeId $nodeId)` — before the read-before-write probe
- `WriteTypeDetected($client, NodeId $nodeId, BuiltinType $detectedType, bool $fromCache)`

### Browse (1)

- `NodeBrowsed($client, NodeId $nodeId, BrowseDirection $direction, int $resultCount)` — `$resultCount` is the number of references returned, not the references themselves

### Type discovery (1)

- `DataTypesDiscovered($client, ?int $namespaceIndex, int $count)` — server data types registered/discovered (`$count` of types; `$namespaceIndex` is null when scanning all namespaces)

### Cache (2)

- `CacheHit($client, string $key)`
- `CacheMiss($client, string $key)`

### Retry / reconnect (3)

- `RetryAttempt($client, int $attempt, int $maxRetries, Throwable $exception)`
- `RetryExhausted($client, int $attempts, Throwable $exception)` — all automatic retries failed
- `ClientReconnecting($client, string $endpointUrl)` — a reconnection attempt is starting

### Trust store (5)

- `ServerCertificateTrusted($client, string $fingerprint, ?string $subject = null)` — passed trust store validation
- `ServerCertificateRejected($client, string $fingerprint, ?string $reason = null, ?string $subject = null)`
- `ServerCertificateAutoAccepted($client, string $fingerprint, ?string $subject = null)` — TOFU auto-accept saved to trust store
- `ServerCertificateManuallyTrusted($client, string $fingerprint, ?string $subject = null)` — manual add via `trustCertificate()`
- `ServerCertificateRemoved($client, string $fingerprint)`

### History update (4 — v4.4)

- `HistoryDataUpdated($client, NodeId $nodeId, PerformUpdateType $operation, int $valueCount, int[] $operationResults)`
- `HistoryDataDeleted($client, NodeId $nodeId, string $kind, int $statusCode, int[] $operationResults)` — `kind` is `'rawModified'` or `'atTime'`
- `HistoryEventUpdated($client, NodeId $nodeId, PerformUpdateType $operation, int $eventCount, int[] $operationResults)`
- `HistoryEventDeleted($client, NodeId $nodeId, int $eventCount, int[] $operationResults)`

### Aggregate (1 — v4.4)

- `AggregateComputed($client, AggregateFunction $function, int $rawInputCount, int $intervalCount, ?NodeId $nodeId)`

### File transfer (4 — v4.4)

- `FileOpened($client, NodeId $fileNodeId, int $fileHandle, int $mode)`
- `FileClosed($client, NodeId $fileNodeId, int $fileHandle)`
- `FileBytesRead($client, NodeId $fileNodeId, int $fileHandle, int $bytesRead, int $requestedLength)`
- `FileBytesWritten($client, NodeId $fileNodeId, int $fileHandle, int $bytesWritten)`

## Common patterns

### Audit log

```php
class AuditListener {
    public function __construct(private LoggerInterface $log) {}

    public function onWrite(NodeValueWritten $e): void {
        $this->log->info('OPC UA write', [
            'nodeId' => (string) $e->nodeId,
            'value' => $e->value,
            'statusCode' => sprintf('0x%08X', $e->statusCode),
        ]);
    }

    public function onAlarm(AlarmActivated $e): void {
        $this->log->warning('Alarm activated', [
            'source' => $e->sourceName,
            'severity' => $e->severity,
            'message' => $e->message,
        ]);
    }

    // To read raw alarm fields, listen for AlarmEventReceived instead — it exposes Variant[] $eventFields.
    public function onAlarmEvent(AlarmEventReceived $e): void {
        $this->log->warning('Alarm event', ['fields' => $e->eventFields]);
    }
}
```

### Metrics

```php
class MetricsListener {
    public function onConnected(ClientConnected $e): void {
        statsd_increment('opcua.connections');
    }

    public function onRetry(RetryAttempt $e): void {
        statsd_increment('opcua.retries');
    }

    public function onCacheHit(CacheHit $e): void { statsd_increment('opcua.cache.hit'); }
    public function onCacheMiss(CacheMiss $e): void { statsd_increment('opcua.cache.miss'); }
}
```

### Latency tracing

```php
class TraceListener {
    private array $started = [];

    public function onConnecting(ClientConnecting $e): void {
        $this->started[$e->endpointUrl] = microtime(true);
    }

    public function onConnected(ClientConnected $e): void {
        $elapsed = microtime(true) - ($this->started[$e->endpointUrl] ?? 0);
        $this->log->info("connected in {$elapsed}s", ['url' => $e->endpointUrl]);
    }
}
```

## Listening in frameworks

- **Laravel** — `laravel-opcua` auto-wires events into the Laravel event bus. Use `Event::listen(NodeValueWritten::class, ...)`.
- **Symfony** — `symfony-opcua` wires into `EventDispatcher`. Use `#[AsEventListener]` attribute on a method.
- **Plain PHP** — any PSR-14 dispatcher works (`crell/tukio`, `php-di/event-dispatcher`, your own).

## Idempotency

Some events may fire MORE than once for the same logical occurrence:

- `RetryAttempt` — once per retry attempt
- `CacheMiss` / `CacheHit` — once per cache lookup (every read goes through cache)
- `DataChangeReceived` — once per notification per monitored item (which is what you want)

Don't write listeners that assume a single-firing — keep them idempotent.
