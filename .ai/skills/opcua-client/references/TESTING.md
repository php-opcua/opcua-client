# Testing reference

How to write tests for code that uses `opcua-client`. The library exposes `MockClient` — a complete in-memory implementation of `OpcUaClientInterface` (no TCP, no server needed).

## Basic shape

```php
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\AttributeId;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\Variant;

it('reads the temperature', function () {
    $client = MockClient::create()
        ->onRead('ns=2;s=Temp', fn () => new DataValue(new Variant(BuiltinType::Double, 22.5)));

    $value = $client->read('ns=2;s=Temp')->getValue();

    expect($value)->toBe(22.5);
    expect($client->callCount('read'))->toBe(1);
});
```

`MockClient` implements the full `OpcUaClientInterface` (all service methods), so swap it in anywhere your code expects a client.

## Handler registration

`MockClient` exposes six callable-based registrars. Each takes a `callable` that *returns* the result DTO (never the raw DTO directly). Every other `OpcUaClientInterface` method returns a fixed, deterministic stub and cannot be customized.

| Built-in method | MockClient registrar |
| --- | --- |
| `read()` | `onRead(string\|NodeId $node, callable(): DataValue)` |
| `write()` | `onWrite(string\|NodeId $node, callable(mixed $value, ?BuiltinType $type): int)` |
| `browse()` | `onBrowse(string\|NodeId $node, callable(): ReferenceDescription[])` |
| `call()` | `onCall(string\|NodeId $objectId, string\|NodeId $methodId, callable(Variant[] $args): CallResult)` |
| `resolveNodeId()` | `onResolveNodeId(string $path, callable(): NodeId)` |
| `getEndpoints()` | `onGetEndpoints(callable(string $endpointUrl): EndpointDescription[])` |

`readMulti()`/`writeMulti()` reuse the per-node `onRead`/`onWrite` handlers. `createSubscription()`, `publish()`, the `history*` methods, file and node-management methods, etc. return hard-coded stub values and are not user-overridable.

To mock a method call, register against `call()` with both the object and method NodeIds. The handler receives the input `Variant[]` and must return a `CallResult`:

```php
use PhpOpcua\Client\Module\ReadWrite\CallResult;

$client = MockClient::create()
    ->onCall('ns=2;s=MyObject', 'ns=2;s=MyMethod', fn (array $args) => new CallResult(
        0,                                          // statusCode
        [],                                         // inputArgumentResults (int[])
        [new Variant(BuiltinType::Int32, 42)],      // outputArguments (Variant[])
    ));

$result = $client->call('ns=2;s=MyObject', 'ns=2;s=MyMethod', []);
```

## Call tracking

Every invocation is recorded uniformly as a plain array `['method' => string, 'args' => array<int, mixed>]` (positional args, exactly as the system-under-test passed them). There are no typed `*Call` accessors.

```php
$client->getCalls();                       // array<array{method: string, args: array<int, mixed>}> — chronological log
$client->getCallsFor('read');              // same shape, filtered to read()
$client->getCallsFor('write');             // write()
$client->getCallsFor('browse');            // browse()
$client->getCallsFor('call');              // callMethod is recorded under 'call'
$client->getCallsFor('createSubscription');
$client->callCount('read');                // int — convenience count
$client->resetCalls();                     // void — clear the log

// Assertions — each entry is an ARRAY; args are positional.
expect($client->callCount('read'))->toBe(3);

$call = $client->getCallsFor('read')[0];
expect($call['method'])->toBe('read');

// read() records [$nodeId, $attributeId]; the nodeId is whatever the SUT
// passed — a string OR a NodeId — so normalize before comparing.
$nodeArg = $call['args'][0];
$nodeStr = $nodeArg instanceof NodeId ? $nodeArg->toString() : $nodeArg;
expect($nodeStr)->toBe('ns=2;s=Temp');
expect($call['args'][1])->toBe(AttributeId::Value);   // recorded attributeId

// write() records [$nodeId, $value, $type]; call() records [$objectId, $methodId, $inputArguments].
```

## Testing custom modules

A module's `register()` takes no arguments and returns `void`. Modules attach methods by calling `$this->client->registerMethod('name', $this->method(...))` on the injected host (which implements both `ModuleHostInterface` and `OpcUaClientInterface`); they do not return a handlers array. The kernel is injected via `setKernel(ClientKernelInterface)` and the session arrives later via `boot(SessionService)`, not through `register()`.

```php
class MyModuleTest extends TestCase {
    public function test_my_module_registers_method(): void {
        $kernel = $this->createMock(ClientKernelInterface::class);

        // The host implements BOTH ModuleHostInterface and OpcUaClientInterface.
        $host = $this->createMockForIntersectionOfInterfaces([
            ModuleHostInterface::class,
            OpcUaClientInterface::class,
        ]);
        $host->expects($this->once())
            ->method('registerMethod')
            ->with('myCustomCall', $this->isType('callable'));

        $module = new MyServiceModule();
        $module->setKernel($kernel);   // inject kernel (NOT a register() arg)
        $module->setClient($host);     // inject host (ModuleHostInterface&OpcUaClientInterface)
        $module->register();           // returns void; pushes methods onto $host
    }
}
```

For end-to-end module behaviour (kernel I/O), use `InMemoryTransport`. The custom method only resolves if its module is added to the builder before `connect()`:

```php
use PhpOpcua\Client\Tests\Unit\Helpers\InMemoryTransport;

$transport = new InMemoryTransport();
$transport->queueResponse($preEncodedBytes);   // pre-load the next receive()

$client = ClientBuilder::create()
    ->setTransport($transport)
    ->addModule(new MyServiceModule())          // registers myCustomCall via register()
    ->connect('opc.tcp://test:4840');

// Trigger your module
$result = $client->myCustomCall(...);

// Assert what went on the wire ($sentMessages is a public array, not a getter)
expect($transport->sentMessages)->toHaveCount(1);
expect($transport->sentMessages[0])->toContain($expectedBytes);
```

Note: `connect()` performs the real handshake and calls `$transport->receive()`, so a true end-to-end test must queue the handshake/session frames too — not just the call response.

## Pest convention

Project uses Pest, not PHPUnit:

```php
// tests/Unit/MyServiceTest.php
use PhpOpcua\Client\Testing\MockClient;

it('does the thing', function () {
    $client = MockClient::create()->onRead('i=2259', fn () => DataValue::ofInt32(0));

    $service = new MyService($client);
    expect($service->isRunning())->toBeTrue();
});

it('does the integration thing', function () {
    // ... integration shape — uses real server
})->group('integration');                    // tag for filtering
```

Run:

```bash
composer test                # all tests
composer test:unit           # unit only
composer test:integration    # integration only (requires test-suite running)
```

## Integration tests

For tests that exercise the real wire:

- Server: `php-opcua/uanetstandard-test-suite` (and `php-opcua/extra-test-suite`) docker-compose stacks
- Ports (via `uanetstandard-test-suite`): `4840` (no-security), `4841` (userpass), `4842` (cert), `4843` (all-security), `4844` (discovery), `4845` (auto-accept), `4846` (sign-only), `4847` (legacy), `4848`/`4849` (ECC NIST/Brainpool)
- Ports (via `extra-test-suite`): `24840` (node-management), `24841` (all-security open62541), `24842` (historizing)
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
