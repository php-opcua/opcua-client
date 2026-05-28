# Testing reference

How to write tests for code that uses `opcua-client`. The library exposes `MockClient` — a complete in-memory implementation of `OpcUaClientInterface` (no TCP, no server needed).

## Basic shape

```php
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\Variant;

it('reads the temperature', function () {
    $client = MockClient::create()
        ->onRead('ns=2;s=Temp', new DataValue(new Variant(BuiltinType::Double, 22.5)));

    $value = $client->read('ns=2;s=Temp')->getValue();

    expect($value)->toBe(22.5);
    expect($client->getReadCalls())->toHaveCount(1);
});
```

`MockClient` implements the full `OpcUaClientInterface` (including v4.4.0 surface), so swap it in anywhere your code expects a client.

## Handler registration

| Built-in method | MockClient registrar |
| --- | --- |
| `read()` | `onRead(string\|NodeId, DataValue)` |
| `readMulti()` | `onReadMulti(array $nodeAttrs, array $dataValues)` |
| `write()` | `onWrite(string\|NodeId, mixed $value, int $statusCode = 0)` |
| `writeMulti()` | `onWriteMulti(int[] $statusCodes)` |
| `browse()` | `onBrowse(string\|NodeId, ReferenceDescription[])` |
| `callMethod()` | `onCallMethod(string\|NodeId $object, string\|NodeId $method, CallResult)` |
| `createSubscription()` | `onCreateSubscription(SubscriptionResult)` |
| `publish()` | `onPublish(PublishResult)` — can register a queue of responses |
| `historyReadRaw()` | `onHistoryReadRaw(string\|NodeId, DataValue[])` |
| ... | every service method has a matching `on*` registrar |

For custom modules:

```php
$client = MockClient::create()
    ->onCall('myCustomMethod', fn ($arg1, $arg2) => new MyResult(...));
```

## Call tracking

```php
$client->getReadCalls();                   // ReadCall[]: nodeId, attributeId, useCache
$client->getWriteCalls();                  // WriteCall[]: nodeId, value, type
$client->getBrowseCalls();
$client->getCallMethodCalls();
$client->getSubscriptionCalls();
$client->getAllCalls();                    // raw chronological log of every method invocation

// Assertions
expect($client->getReadCalls())->toHaveCount(3);
expect($client->getReadCalls()[0]->nodeId->toString())->toBe('ns=2;s=Temp');
```

## Testing custom modules

```php
class MyModuleTest extends TestCase {
    public function test_my_module_registers_method(): void {
        $kernel = $this->createMock(ClientKernelInterface::class);
        $session = $this->createMock(SessionService::class);

        $module = new MyServiceModule();
        $handlers = $module->register($kernel, $session);

        $this->assertArrayHasKey('myCustomCall', $handlers);
        $this->assertIsCallable($handlers['myCustomCall']);
    }
}
```

For end-to-end module behaviour (kernel I/O), use `InMemoryTransport`:

```php
use PhpOpcua\Client\Tests\Unit\Helpers\InMemoryTransport;

$transport = new InMemoryTransport();
$transport->enqueueResponse($preEncodedBytes);

$client = ClientBuilder::create()
    ->setTransport($transport)
    ->connect('opc.tcp://test:4840');

// Trigger your module
$result = $client->myCustomCall(...);

// Assert what went on the wire
expect($transport->getSentFrames())->toHaveCount(1);
expect($transport->getSentFrames()[0])->toContain($expectedBytes);
```

## Pest convention

Project uses Pest, not PHPUnit:

```php
// tests/Unit/MyServiceTest.php
use PhpOpcua\Client\Testing\MockClient;

it('does the thing', function () {
    $client = MockClient::create()->onRead('i=2259', DataValue::ofInt32(0));

    $service = new MyService($client);
    expect($service->isRunning())->toBeTrue();
});

it('does the integration thing', function () {
    // ... integration shape — uses real server
})->group('integration');                    // tag for filtering
```

Run:

```bash
vendor/bin/pest                              # all tests
vendor/bin/pest --exclude-group=integration # unit only
vendor/bin/pest --group=integration         # integration only (requires test-suite running)
```

## Integration tests

For tests that exercise the real wire:

- Server: `uanetstandard-test-suite` docker-compose stack
- Port: `4840` (no-security), `4841` (userpass), `4842` (cert), `4843` (all-security), `4848-4849` (ECC), `4851` (SKS), `4852` (HTTPS Binary)
- Tag with `->group('integration')` so CI can run them on a Linux job with docker

```php
use PhpOpcua\Client\Tests\Integration\Helpers\TestHelper;

it('reads from a real server', function () {
    $client = TestHelper::connectNoSecurity();
    expect($client->read('i=2259')->getValue())->toBe(0);  // Server.Status.State = Running
    $client->disconnect();
})->group('integration');
```

`TestHelper` (under `tests/Integration/Helpers/`) provides factory methods for every common scenario.

## What NOT to do

- Don't open real TCP from a unit test — use `MockClient`
- Don't generate random NodeIds in tests — pick stable IDs from `i=85`-`i=2300` range (well-known)
- Don't depend on test execution order — Pest randomises
- Don't share `MockClient` between tests (it accumulates call history)
- Don't `expect(...)` on `DataValue` equality — compare specific fields:
  ```php
  // Wrong (different DateTimeImmutable instances fail ===)
  expect($dv)->toEqual(new DataValue(...));
  // Right
  expect($dv->getValue())->toBe(42);
  expect($dv->statusCode)->toBe(0);
  ```
