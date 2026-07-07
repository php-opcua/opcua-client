# Recipes — complete working examples

Copy-pasteable snippets for common tasks. Every recipe is end-to-end runnable.

## R1 — Connect, read, disconnect (simplest)

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use PhpOpcua\Client\ClientBuilder;

$client = ClientBuilder::create()->connect('opc.tcp://localhost:4840');
try {
    $status = $client->read('i=2259')->getValue();
    echo $status === 0 ? "Running\n" : "State $status\n";
} finally {
    $client->disconnect();
}
```

## R2 — Browse the Objects folder recursively (1 level)

```php
$client = ClientBuilder::create()->connect('opc.tcp://localhost:4840');
try {
    foreach ($client->browse('i=85') as $ref) {                 // i=85 = Objects folder
        echo str_pad((string) $ref->nodeId, 30) . " {$ref->displayName} [{$ref->nodeClass->name}]\n";
    }
} finally {
    $client->disconnect();
}
```

## R3 — Read many values in one round-trip

```php
$results = $client->readMulti()
    ->node('i=2259')->value()                                   // Server.Status.State
    ->node('i=2258')->value()                                   // Server.Status.CurrentTime
    ->node('i=2257')->value()                                   // Server.Status.StartTime
    ->node('ns=2;s=Sensors/Temp')->value()
    ->node('ns=2;s=Sensors/Temp')->displayName()
    ->node('ns=2;s=Sensors/Temp')->dataType()
    ->execute();

foreach ($results as $i => $dv) {
    echo "$i: " . var_export($dv->getValue(), true) . "\n";
}
```

## R4 — Write with auto-type detection vs explicit type

```php
use PhpOpcua\Client\Types\BuiltinType;

// Auto-detect — uses read-before-write (cached after first hit)
$status = $client->write('ns=2;s=Setpoint', 42.5);

// Explicit — one less round-trip, recommended for hot paths
$status = $client->write('ns=2;s=Setpoint', 42.5, BuiltinType::Double);

echo $status === 0 ? "OK\n" : sprintf("Failed: 0x%08X\n", $status);
```

## R5 — Resolve a human path then read

```php
use PhpOpcua\Client\Exception\ServiceException;

// resolveNodeId() returns a non-nullable NodeId; it THROWS on failure (never returns null)
try {
    $nodeId = $client->resolveNodeId('/Objects/MyPLC/Sensors/Temperature');
} catch (ServiceException $e) {
    throw new RuntimeException('Node not found', 0, $e);
}
$dv = $client->read($nodeId);
echo "{$dv->getValue()} @ {$dv->sourceTimestamp->format('H:i:s.v')}\n";
```

## R6 — Call a method (machine start with recipe)

```php
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\BuiltinType;

$result = $client->call(
    objectId: 'ns=2;s=MyMachine',
    methodId: 'ns=2;s=MyMachine/StartProcess',
    inputArguments: [
        new Variant(BuiltinType::String, 'recipe-001'),
        new Variant(BuiltinType::Int32, 42),
    ],
);

if ($result->statusCode !== 0) {
    throw new RuntimeException(sprintf('Method call failed: 0x%08X', $result->statusCode));
}

[$jobId, $estimatedSeconds] = array_map(fn ($v) => $v->value, $result->outputArguments);
echo "Job $jobId queued, ETA {$estimatedSeconds}s\n";
```

## R7 — Subscribe + publish loop

```php
use PhpOpcua\Client\Module\Subscription\DataChangeNotification;

$sub = $client->createSubscription(publishingInterval: 500.0);

$items = $client->createMonitoredItems($sub->subscriptionId)
    ->add('ns=2;s=Sensors/Temp')->samplingInterval(500.0)->queueSize(10)
    ->add('ns=2;s=Sensors/Pressure')
    ->execute();

$running = true;
pcntl_signal(SIGINT, function () use (&$running) { $running = false; });

while ($running) {
    $response = $client->publish();
    // $notifications is array<int, DataChangeNotification|EventNotification> — discriminate with instanceof
    foreach ($response->notifications as $notif) {
        if (! $notif instanceof DataChangeNotification) {
            continue;                                               // skip EventNotification etc.
        }
        $handle = $notif->clientHandle;
        $value = $notif->dataValue->getValue();
        $ts = $notif->dataValue->sourceTimestamp?->format('H:i:s.v') ?? 'n/a';
        echo "[$ts] handle $handle = $value\n";
    }
    if (!$response->moreNotifications) {
        usleep(50_000);
    }
}

$client->deleteSubscription($sub->subscriptionId);
$client->disconnect();
```

## R8 — Secure connection (Basic256Sha256 + username)

```php
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Security\SecurityMode;

$client = ClientBuilder::create()
    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
    ->setSecurityMode(SecurityMode::SignAndEncrypt)
    ->setClientCertificate('/var/lib/myapp/client.pem', '/var/lib/myapp/client.key')
    ->setUserCredentials('operator', getenv('PLC_PASSWORD'))
    ->setTimeout(15.0)
    ->setAutoRetry(3)
    ->connect('opc.tcp://192.168.1.100:4840');
```

## R9 — Read 1 hour of history

```php
$end = new DateTimeImmutable();
$start = $end->sub(new DateInterval('PT1H'));

$values = $client->historyReadRaw(
    'ns=2;s=Sensors/Temp',
    startTime: $start,
    endTime: $end,
    numValuesPerNode: 10_000,
);

foreach ($values as $dv) {
    echo "[{$dv->sourceTimestamp->format('H:i:s.v')}] {$dv->getValue()}\n";
}

echo "Total samples: " . count($values) . "\n";
```

## R10 — Aggregate 1 hour into 1-minute averages (v4.4.0)

```php
use PhpOpcua\Client\Module\Aggregate\AggregateFunction;

$intervals = $client->historyAggregate(
    'ns=2;s=Sensors/Temp',
    startTime: new DateTimeImmutable('-1 hour'),
    endTime: new DateTimeImmutable(),
    processingIntervalMs: 60_000,                               // 60 s buckets
    function: AggregateFunction::Average,
);

// historyAggregate() returns DataValue[] — one DataValue per interval
foreach ($intervals as $dv) {
    echo "[{$dv->sourceTimestamp?->format('H:i') ?? 'n/a'}] {$dv->getValue()}\n";
}
```

## R11 — Insert backfilled data (v4.4.0 HistoryUpdate)

```php
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\Variant;

$values = [
    new DataValue(new Variant(BuiltinType::Double, 22.1), sourceTimestamp: new DateTimeImmutable('-30 minutes')),
    new DataValue(new Variant(BuiltinType::Double, 22.3), sourceTimestamp: new DateTimeImmutable('-20 minutes')),
    new DataValue(new Variant(BuiltinType::Double, 22.5), sourceTimestamp: new DateTimeImmutable('-10 minutes')),
];

// historyInsertData() returns int[] — one per-entry status code
$results = $client->historyInsertData('ns=2;s=Sensors/Temp', $values);

foreach ($results as $i => $status) {
    if ($status !== 0) {
        echo sprintf("Insert %d failed: 0x%08X\n", $i, $status);
    }
}
```

## R12 — Discover endpoints (probe before configuring)

```php
$endpoints = $client->getEndpoints('opc.tcp://10.0.0.5:4840');

foreach ($endpoints as $ep) {
    echo "{$ep->endpointUrl}\n";
    echo "  policy: {$ep->securityPolicyUri}\n";
    echo "  mode:   {$ep->securityMode}\n";                       // int: 1=None 2=Sign 3=SignAndEncrypt
    foreach ($ep->userIdentityTokens as $tok) {
        echo "  auth:   {$tok->tokenType} (id={$tok->policyId})\n"; // tokenType int: 0=Anonymous 1=UserName 2=Certificate 3=IssuedToken
    }
    echo "\n";
}
```

## R13 — Transparent caching

```php
use PhpOpcua\Client\Cache\FileCache;

$client = ClientBuilder::create()
    ->setCache(new FileCache('/var/cache/opcua', defaultTtl: 300))
    ->setReadMetadataCache(true)                                // cache non-Value attributes too
    ->connect('opc.tcp://...');

$client->browse('i=85');                                        // hits the server, populates cache
$client->browse('i=85');                                        // cache hit — no wire I/O
$client->browse('i=85', useCache: false);                       // forced fresh read
```

## R14 — PSR-14 events for audit logging

```php
use PhpOpcua\Client\Event\NodeValueWritten;
use PhpOpcua\Client\Event\AlarmActivated;
use Crell\Tukio\Dispatcher;
use Crell\Tukio\OrderedListenerProvider;

$provider = new OrderedListenerProvider();
$provider->listener(function (NodeValueWritten $e) {
    error_log("OPC UA write: {$e->nodeId} = " . var_export($e->value, true));
});
$provider->listener(function (AlarmActivated $e) {
    error_log(
        "ALARM activated on handle {$e->clientHandle}"
        . ($e->sourceName !== null ? " ({$e->sourceName})" : '')
        . ($e->message !== null ? ": {$e->message}" : '')
    );
});

$client = ClientBuilder::create()
    ->setEventDispatcher(new Dispatcher($provider))
    ->connect('opc.tcp://...');
```

## R15 — Custom service module

```php
namespace App\OpcUa;

use PhpOpcua\Client\Module\ServiceModule;

final class PingModule extends ServiceModule
{
    public function requires(): array
    {
        return [];
    }

    public function register(): void
    {
        // inject the method onto the Client via the inherited $this->client
        $this->client->registerMethod('ping', $this->ping(...));
    }

    private function ping(): bool
    {
        // ensureConnected() returns void and throws if the channel/session is down;
        // reaching the return means the client is alive
        $this->kernel->ensureConnected();

        return true;
    }
}

// Wire it
$client = ClientBuilder::create()
    ->addModule(new PingModule())
    ->connect('opc.tcp://...');

if ($client->ping()) {
    echo "alive\n";
}
```

## R16 — Mock for testing

```php
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\Variant;

class MyServiceTest extends TestCase {
    public function test_alerts_when_temperature_too_high(): void {
        $client = MockClient::create()
            ->onRead('ns=2;s=Temp', fn (): DataValue => new DataValue(new Variant(BuiltinType::Double, 95.0)));

        $service = new ThermalMonitor($client);
        $alerts = $service->scan();

        $this->assertCount(1, $alerts);
        $this->assertSame('Temperature critical', $alerts[0]->message);
        $this->assertCount(1, $client->getCallsFor('read'));
    }
}
```
