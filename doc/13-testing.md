# Testing

## MockClient

The library ships with a `MockClient` that implements `OpcUaClientInterface` without any TCP connection. Use it to unit test your application code that depends on the OPC UA client.

```php
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\StatusCode;

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
use PhpOpcua\Client\Types\DataValue;

$mock->onRead('i=2259', fn() => DataValue::ofInt32(0));
$mock->onRead('ns=2;i=1001', fn() => DataValue::ofDouble(23.5));

$dv = $mock->read('i=2259');
// $dv->getValue() === 0
```

Unregistered reads return an empty `DataValue` (null value, status Good).

### Write

```php
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\StatusCode;

$mock->onWrite('ns=2;i=1001', function(mixed $value, BuiltinType $type): int {
    return $value > 100 ? StatusCode::BadTypeMismatch : StatusCode::Good;
});

$status = $mock->write('ns=2;i=1001', 42, BuiltinType::Int32);
// $status === StatusCode::Good
```

Unregistered writes return `0` (Good).

### Browse

```php
use PhpOpcua\Client\Types\ReferenceDescription;

$mock->onBrowse('i=85', fn() => [
    // return ReferenceDescription[] or any array
]);

$refs = $mock->browse('i=85');
```

Unregistered browses return `[]`.

### Call

```php
use PhpOpcua\Client\Types\CallResult;
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\BuiltinType;

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
use PhpOpcua\Client\Types\NodeId;

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
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\StatusCode;

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

## Module Introspection

The mock supports `hasMethod()` and `hasModule()` for testing code that checks module availability:

```php
$mock = MockClient::create();

$mock->hasMethod('read');                    // true (all built-in methods)
$mock->hasMethod('customMethod');            // false
$mock->hasModule('ReadWriteModule');         // true (all built-in modules)
$mock->hasModule('MyCustomModule');          // false
```

These methods always return `true` for built-in methods/modules and `false` for unregistered custom ones. This matches the behavior of a real `Client` with only the default modules loaded.

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

### Event Dispatcher

The mock supports `setEventDispatcher()` / `getEventDispatcher()`. Use `NullEventDispatcher` (default) or inject a test dispatcher to verify event behavior:

```php
use PhpOpcua\Client\Event\NullEventDispatcher;

$mock = MockClient::create();
$mock->getEventDispatcher(); // NullEventDispatcher

// Inject a custom dispatcher for assertions
$mock->setEventDispatcher($testDispatcher);
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
| `transferSubscriptions()` | `[TransferResult(0, [])]` per subscription ID |
| `republish()` | `[]` |
| `addNodes()` | `AddNodesResult[]` with Good status and echoed NodeIds |
| `deleteNodes()` | `[0, ...]` (Good) |
| `addReferences()` | `[0, ...]` (Good) |
| `deleteReferences()` | `[0, ...]` (Good) |
| `getServerProductName()` | `null` (delegates to `read('i=2262')`) |
| `getServerManufacturerName()` | `null` (delegates to `read('i=2263')`) |
| `getServerSoftwareVersion()` | `null` (delegates to `read('i=2264')`) |
| `getServerBuildNumber()` | `null` (delegates to `read('i=2265')`) |
| `getServerBuildDate()` | `null` (delegates to `read('i=2266')`) |
| `getServerBuildInfo()` | `BuildInfo(null, null, null, null, null)` (delegates to `readMulti()`) |

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

## Integration Tests

The integration suite in `tests/Integration/` runs against real OPC UA servers
over TCP. Two stacks are involved:

| Server | Scope | Provisioning |
|---|---|---|
| **UA-.NETStandard** (OPC Foundation reference impl.) | Everything except NodeManagement — 8 endpoints covering every security policy, user token type, subscription scenario, history read, alarm, event, custom structure, ECC policy variant | [`php-opcua/uanetstandard-test-suite`](https://github.com/php-opcua/uanetstandard-test-suite) — `docker compose up -d` |
| **open62541** (`ci_server`, built with `UA_ENABLE_NODEMANAGEMENT=ON`) | NodeManagement only (`AddNodes`, `DeleteNodes`, `AddReferences`, `DeleteReferences`) — UA-.NETStandard does not implement this service set | [`php-opcua/extra-test-suite`](https://github.com/php-opcua/extra-test-suite), port `24840` |

Both suites share the same assumption: **start them once, tests take the endpoints as given.** `TestHelper::ENDPOINT_*` constants encode the port map; there is no environment-variable indirection.

### NodeManagement Integration Tests

Six tests in `tests/Integration/NodeManagementTest.php` exercise the full
NodeManagement service set against a server that actually implements it.
They are regular `->group('integration')` tests — no dedicated group, no
env-var gate. They connect to `opc.tcp://localhost:24840` via
`TestHelper::ENDPOINT_NODE_MANAGEMENT`; the server is assumed to be up, the
same way the UA-.NETStandard endpoints are assumed to be up on `4840-4849`.

In CI, the [`php-opcua/extra-test-suite@v1.0.0`](https://github.com/php-opcua/extra-test-suite) composite action is a **mandatory** step of the `integration` job (every PHP matrix leg) — identical treatment to `php-opcua/uanetstandard-test-suite@v1.2.0`.

### Running Integration Tests Locally

```bash
# Start both test suites once (they keep running across reboots)
cd ../uanetstandard-test-suite && docker compose up -d
cd ../extra-test-suite && docker compose up -d

# From opcua-client/
./vendor/bin/pest --group=integration
```

### Smoke Probe

When validating a candidate server for future CI wiring, use
`scripts/prosys-nodemanagement-smoke.php`:

```bash
PROSYS_ENDPOINT='opc.tcp://<host>:<port>' \
PROSYS_PARENT='i=85' \
PROSYS_NAMESPACE=1 \
  php scripts/prosys-nodemanagement-smoke.php
```

Exit code `0` means all four NodeManagement services returned `Good`; `1` means
at least one failed or the server replied with `ServiceFault`; `2` means the
connection itself failed. Useful to decide whether a server is viable before
adding it to CI.
