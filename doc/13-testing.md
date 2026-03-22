# Testing

## MockClient

The library ships with a `MockClient` that implements `OpcUaClientInterface` without any TCP connection. Use it to unit test your application code that depends on the OPC UA client.

```php
use Gianfriaur\OpcuaPhpClient\Testing\MockClient;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

$mock = MockClient::create()
    ->onRead('i=2259', fn() => DataValue::ofInt32(0))
    ->onRead('ns=2;i=1001', fn() => DataValue::ofDouble(23.5))
    ->onWrite('ns=2;i=1001', fn($value, $type) => StatusCode::Good);

$service = new MyPlcService($mock);
$this->assertEquals(23.5, $service->readTemperature());
```

> **Tip:** `MockClient` accepts both `NodeId` objects and OPC UA string format (`'i=2259'`, `'ns=2;i=1001'`) for handler registration — same as the real client.

## Registering Handlers

### Read

```php
use Gianfriaur\OpcuaPhpClient\Types\DataValue;

$mock->onRead('i=2259', fn() => DataValue::ofInt32(0));
$mock->onRead('ns=2;i=1001', fn() => DataValue::ofDouble(23.5));

$dv = $mock->read('i=2259');
// $dv->getValue() === 0
```

Unregistered reads return an empty `DataValue` (null value, status Good).

### Write

```php
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

$mock->onWrite('ns=2;i=1001', function(mixed $value, BuiltinType $type): int {
    return $value > 100 ? StatusCode::BadTypeMismatch : StatusCode::Good;
});

$status = $mock->write('ns=2;i=1001', 42, BuiltinType::Int32);
// $status === StatusCode::Good
```

Unregistered writes return `0` (Good).

### Browse

```php
use Gianfriaur\OpcuaPhpClient\Types\ReferenceDescription;

$mock->onBrowse('i=85', fn() => [
    // return ReferenceDescription[] or any array
]);

$refs = $mock->browse('i=85');
```

Unregistered browses return `[]`.

### Call

```php
use Gianfriaur\OpcuaPhpClient\Types\CallResult;
use Gianfriaur\OpcuaPhpClient\Types\Variant;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;

$mock->onCall('i=2253', 'i=11492', function(array $args): CallResult {
    return new CallResult(0, [], [new Variant(BuiltinType::Int32, 42)]);
});

$result = $mock->call('i=2253', 'i=11492', [new Variant(BuiltinType::UInt32, 1)]);
// $result->statusCode === 0
// $result->outputArguments[0]->value === 42
```

Unregistered calls return `CallResult(0, [], [])`.

### Resolve NodeId

```php
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

$mock->onResolveNodeId('/Objects/Server', fn() => NodeId::numeric(0, 2253));

$nodeId = $mock->resolveNodeId('/Objects/Server');
// $nodeId->identifier === 2253
```

Unregistered paths return `NodeId::numeric(0, 0)`.

## Call Tracking

Every method call on the mock is recorded. Use the tracking API to assert your code called the right operations.

```php
$mock = MockClient::create()
    ->onRead('i=2259', fn() => DataValue::ofInt32(0));

$mock->read('i=2259');
$mock->read('i=2259');
$mock->browse('i=85');

$mock->callCount('read');         // 2
$mock->callCount('browse');       // 1
$mock->callCount('write');        // 0

$mock->getCallsFor('read');       // [{method: 'read', args: [...]}, ...]
$mock->getCalls();                // all calls in order

$mock->resetCalls();              // clear history
$mock->callCount('read');         // 0
```

## Connection Lifecycle

The mock simulates connection state without any TCP:

```php
$mock = MockClient::create();

$mock->isConnected();             // false
$mock->getConnectionState();      // ConnectionState::Disconnected

$mock->connect('opc.tcp://fake:4840');
$mock->isConnected();             // true

$mock->disconnect();
$mock->isConnected();             // false
```

> **Note:** The mock does not require `connect()` before operations — reads, writes, and browses work regardless of connection state. This is intentional to simplify test setup.

## Fluent Builder Compatibility

The mock fully supports the fluent builder API:

```php
$mock = MockClient::create()
    ->onRead('i=2259', fn() => DataValue::ofInt32(42))
    ->onRead('i=2267', fn() => DataValue::ofInt32(255));

$results = $mock->readMulti()
    ->node('i=2259')->value()
    ->node('i=2267')->value()
    ->execute();

// $results[0]->getValue() === 42
// $results[1]->getValue() === 255
```

Same for `writeMulti()`, `createMonitoredItems()`, and `translateBrowsePaths()`.

## DataValue Factory Methods

For quick test fixture creation, `DataValue` provides static factory methods:

```php
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

DataValue::ofInt32(42);
DataValue::ofDouble(3.14);
DataValue::ofString('hello');
DataValue::ofBoolean(true);
DataValue::ofFloat(1.5);
DataValue::ofUInt32(100);
DataValue::ofInt16(-100);
DataValue::ofUInt16(100);
DataValue::ofInt64(999);
DataValue::ofUInt64(999);
DataValue::ofDateTime(new DateTimeImmutable());

// Custom type + status
DataValue::of('raw', BuiltinType::ByteString, StatusCode::Good);

// Bad status (no value)
DataValue::bad(StatusCode::BadNodeIdUnknown);
```

## Configuration

All configuration methods work on the mock and store values:

```php
$mock = MockClient::create()
    ->setTimeout(10.0)
    ->setAutoRetry(3)
    ->setBatchSize(50)
    ->setDefaultBrowseMaxDepth(20);

$mock->getTimeout();              // 10.0
$mock->getAutoRetry();            // 3
$mock->getBatchSize();            // 50
$mock->getDefaultBrowseMaxDepth();// 20
```

## Default Behaviors

Operations without registered handlers return sensible defaults:

| Operation | Default |
|-----------|---------|
| `read()` | Empty `DataValue` (null value, status 0) |
| `write()` | `0` (Good) |
| `browse()` / `browseAll()` | `[]` |
| `browseRecursive()` | `[]` |
| `call()` | `CallResult(0, [], [])` |
| `resolveNodeId()` | `NodeId::numeric(0, 0)` |
| `createSubscription()` | `SubscriptionResult(1, ...)` |
| `createMonitoredItems()` | Auto-generated `MonitoredItemResult[]` |
| `publish()` | `PublishResult(1, 1, false, [], [])` |
| `discoverDataTypes()` | `0` |
| `getEndpoints()` | `[]` |
| `historyRead*()` | `[]` |

## Example: Testing a Service Class

```php
class TemperatureService
{
    public function __construct(
        private OpcUaClientInterface $client,
    ) {}

    public function getCurrentTemperature(): float
    {
        $dv = $this->client->read('ns=2;i=1001');
        return $dv->getValue();
    }

    public function setSetpoint(float $value): bool
    {
        $status = $this->client->write('ns=2;i=1002', $value, BuiltinType::Double);
        return StatusCode::isGood($status);
    }
}

// In your test:
it('reads the current temperature', function () {
    $mock = MockClient::create()
        ->onRead('ns=2;i=1001', fn() => DataValue::ofDouble(23.5));

    $service = new TemperatureService($mock);

    expect($service->getCurrentTemperature())->toBe(23.5);
    expect($mock->callCount('read'))->toBe(1);
});

it('sets the setpoint', function () {
    $mock = MockClient::create()
        ->onWrite('ns=2;i=1002', fn($v, $t) => StatusCode::Good);

    $service = new TemperatureService($mock);

    expect($service->setSetpoint(25.0))->toBeTrue();
    expect($mock->callCount('write'))->toBe(1);
});
```
