# Events reference

57 PSR-14 events under `PhpOpcua\Client\Event\`. All `final readonly`. Every event carries a `$client` reference (the firing `Client` instance).

## Setup

```php
use PhpOpcua\Client\ClientBuilder;

$client = ClientBuilder::create()
    ->setEventDispatcher($psr14Dispatcher)         // any PSR-14 dispatcher
    ->connect('opc.tcp://...');
```

`NullEventDispatcher` is used by default. **Zero overhead when not configured** — event objects are constructed inside lambdas passed to `kernel->dispatch(fn () => new Event(...))`, so the closure is invoked only when a real dispatcher is set.

## Event catalog

### Connection lifecycle (4)

- `ClientConnecting($client, $endpointUrl)` — before connect attempt
- `ClientConnected($client, $endpointUrl)` — after successful connect
- `ConnectionFailed($client, $endpointUrl, Throwable $cause)` — connect failed
- `ClientDisconnecting($client, $endpointUrl)`, `ClientDisconnected($client)` — disconnect

### Secure channel (2 — v4.4)

- `SecureChannelOpened($client, $secureChannelId, $securityPolicy, $securityMode)`
- `SecureChannelClosed($client, $secureChannelId)`

### Session (3)

- `SessionCreated($client, $endpointUrl, NodeId $authenticationToken)`
- `SessionActivated($client, $endpointUrl)`
- `SessionClosed($client)`

### Subscription (5)

- `SubscriptionCreated($client, $subscriptionId, $publishingInterval, ...)`
- `SubscriptionModified($client, $subscriptionId, ...)`
- `SubscriptionDeleted($client, $subscriptionId)`
- `SubscriptionTransferred($client, $subscriptionId, $sourceSessionId, $availableSequenceNumbers)`
- `SubscriptionRepublishRequested($client, $subscriptionId, $sequenceNumber)`

### Monitored items (3)

- `MonitoredItemCreated($client, $subscriptionId, $monitoredItemId, $nodeId, $samplingInterval)`
- `MonitoredItemModified($client, $monitoredItemId, ...)`
- `MonitoredItemDeleted($client, $subscriptionId, $monitoredItemId)`

### Data / event / alarm notifications (5)

- `DataChangeReceived($client, $monitoredItemId, DataValue $dataValue)`
- `EventNotificationReceived($client, $monitoredItemId, Variant[] $eventFields)`
- `AlarmEventReceived($client, $monitoredItemId, $eventFields)`
- `AlarmActivated($client, $monitoredItemId, $eventFields)`
- `AlarmDeactivated($client, $monitoredItemId, $eventFields)`

Alarm vs data-change is auto-deduced from the notification fields (presence of `Severity`, `Time`, `SourceNode`, etc.).

### Read / write (4)

- `NodeValueRead($client, NodeId $nodeId, DataValue $dataValue)`
- `NodeValueWritten($client, NodeId $nodeId, mixed $value, $statusCode)`
- `WriteTypeDetecting($client, NodeId $nodeId)` — before the read-before-write probe
- `WriteTypeDetected($client, NodeId $nodeId, BuiltinType $detectedType, bool $fromCache)`

### Browse (1)

- `NodeBrowsed($client, NodeId $nodeId, ReferenceDescription[] $references)`

### Cache (3)

- `CacheHit($client, string $cacheKey, mixed $value)`
- `CacheMiss($client, string $cacheKey)`
- `CacheStored($client, string $cacheKey, mixed $value, int $ttl)`

### Retry (2)

- `RetryAttempt($client, int $attempt, int $maxAttempts, Throwable $cause)`
- `ConnectionRecovered($client, $endpointUrl, int $attemptsUsed)`

### Trust store (5)

- `ServerCertificateTrusted($client, $thumbprint, $endpointUrl)`
- `ServerCertificateRejected($client, $thumbprint, $endpointUrl, string $reason)`
- `ServerCertificateAdded($client, $thumbprint, $endpointUrl)`
- `ServerCertificateRotated($client, $oldThumbprint, $newThumbprint, $endpointUrl)`
- `ServerCertificateRemoved($client, $thumbprint, $endpointUrl)`

### History update (4 — v4.4)

- `HistoryDataUpdated($client, NodeId $nodeId, PerformUpdateType $type, int $valueCount, int[] $operationResults)`
- `HistoryDataDeleted($client, NodeId $nodeId, string $kind, int $statusCode, int[] $operationResults)` — `kind` is `'rawModified'` or `'atTime'`
- `HistoryEventUpdated($client, NodeId $nodeId, PerformUpdateType $type, int $eventCount, int[] $operationResults)`
- `HistoryEventDeleted($client, NodeId $nodeId, int $eventCount, int[] $operationResults)`

### Aggregate (1 — v4.4)

- `AggregateComputed($client, AggregateFunction $function, int $rawInputCount, int $intervalCount, ?NodeId $nodeId)`

### File transfer (several — v4.4)

- `FileOpened($client, NodeId $fileNodeId, int $fileHandle, int $mode)`
- `FileClosed($client, NodeId $fileNodeId, int $fileHandle)`
- `FileRead($client, $fileHandle, int $bytesRead)`
- `FileWritten($client, $fileHandle, int $bytesWritten)`
- + directory operations

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
        $this->log->warning('Alarm activated', ['fields' => $e->eventFields]);
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
