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
$nodeId = $client->resolveNodeId('/Objects/MyPLC/Sensors/Temperature');
if ($nodeId === null) {
    throw new RuntimeException('Node not found');
}
$dv = $client->read($nodeId);
echo "{$dv->getValue()} @ {$dv->sourceTimestamp->format('H:i:s.v')}\n";
```

## R6 — Call a method (machine start with recipe)

```php
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\BuiltinType;

$result = $client->callMethod(
    objectId: 'ns=2;s=MyMachine',
    methodId: 'ns=2;s=MyMachine/StartProcess',
    inputs: [
        new Variant(BuiltinType::String, 'recipe-001'),
        new Variant(BuiltinType::Int32, 42),
    ],
);

if ($result->statusCode !== 0) {
    throw new RuntimeException(sprintf('Method call failed: 0x%08X', $result->statusCode));
}

[$jobId, $estimatedSeconds] = array_map(fn ($v) => $v->getValue(), $result->outputArguments);
echo "Job $jobId queued, ETA {$estimatedSeconds}s\n";
```

## R7 — Subscribe + publish loop

```php
$sub = $client->createSubscription(publishingInterval: 500.0);

$items = $client->createMonitoredItems($sub->subscriptionId)
    ->add('ns=2;s=Sensors/Temp')->samplingInterval(500.0)->queueSize(10)
    ->add('ns=2;s=Sensors/Pressure')
    ->execute();

$running = true;
pcntl_signal(SIGINT, function () use (&$running) { $running = false; });

while ($running) {
    $response = $client->publish();
    foreach ($response->notifications as $notif) {
        $itemId = $notif['monitoredItemId'];
        $value = $notif['dataValue']->getValue();
        $ts = $notif['dataValue']->sourceTimestamp->format('H:i:s.v');
        echo "[$ts] item $itemId = $value\n";
    }
    if (!$response->moreNotifications) {
        usleep(50_000);
    }
}

$client->deleteSubscriptions([$sub->subscriptionId]);
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
    ->setMaxRetries(3)
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
    maxValues: 10_000,
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
    start: new DateTimeImmutable('-1 hour'),
    end: new DateTimeImmutable(),
    intervalMs: 60_000,                                         // 60 s buckets
    function: AggregateFunction::Average,
);

foreach ($intervals as $bucket) {
    echo "[{$bucket->startTime->format('H:i')}] {$bucket->dataValue->getValue()}\n";
}
```

## R11 — Insert backfilled data (v4.4.0 HistoryUpdate)

```php
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\DataValue;

$values = [
    new DataValue(new Variant(BuiltinType::Double, 22.1), sourceTimestamp: new DateTimeImmutable('-30 minutes')),
    new DataValue(new Variant(BuiltinType::Double, 22.3), sourceTimestamp: new DateTimeImmutable('-20 minutes')),
    new DataValue(new Variant(BuiltinType::Double, 22.5), sourceTimestamp: new DateTimeImmutable('-10 minutes')),
];

$result = $client->historyInsertData('ns=2;s=Sensors/Temp', $values);

foreach ($result->operationResults as $i => $status) {
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
    echo "  mode:   {$ep->securityMode->name}\n";
    foreach ($ep->userIdentityTokens as $tok) {
        echo "  auth:   {$tok->tokenType->name} (id={$tok->policyId})\n";
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
    error_log("ALARM activated on item {$e->monitoredItemId}");
});

$client = ClientBuilder::create()
    ->setEventDispatcher(new Dispatcher($provider))
    ->connect('opc.tcp://...');
```

## R15 — Custom service module

```php
namespace App\OpcUa;

use PhpOpcua\Client\Module\ServiceModule;
use PhpOpcua\Client\Kernel\ClientKernelInterface;
use PhpOpcua\Client\Protocol\SessionService;

final class PingModule extends ServiceModule
{
    public function name(): string { return 'ping'; }
    public function requires(): array { return []; }

    public function register(ClientKernelInterface $kernel, SessionService $session): array
    {
        return [
            'ping' => fn (): bool => $kernel->ensureConnected()  // returns true if alive
                ? true : false,
        ];
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
            ->onRead('ns=2;s=Temp', new DataValue(new Variant(BuiltinType::Double, 95.0)));

        $service = new ThermalMonitor($client);
        $alerts = $service->scan();

        $this->assertCount(1, $alerts);
        $this->assertSame('Temperature critical', $alerts[0]->message);
        $this->assertCount(1, $client->getReadCalls());
    }
}
```
