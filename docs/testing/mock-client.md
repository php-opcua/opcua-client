---
eyebrow: 'Docs · Testing'
lede:    'MockClient implements the full OpcUaClientInterface in memory — no TCP, no server, no surprises. Inject it everywhere your code depends on an OPC UA client.'

see_also:
  - { href: './handlers.md',                meta: '6 min' }
  - { href: './integration.md',             meta: '5 min' }
  - { href: '../reference/client-api.md',   meta: '8 min' }

prev: { label: 'Caching',    href: '../observability/caching.md' }
next: { label: 'Handlers',   href: './handlers.md' }
---

# MockClient

`PhpOpcua\Client\Testing\MockClient` implements
`OpcUaClientInterface` with zero network I/O. Use it for:

- Unit tests of application code that depends on the client.
- Reproducible regression cases for OPC UA bugs.
- Documentation examples that need to be runnable without a server.

`MockClient` is the substitute, not a wrapper — your code receives an
object that satisfies the interface and behaves as you've programmed
it.

## Creating

<!-- @code-block language="php" label="examples/mock-test.php" -->
```php
use PhpOpcua\Client\Testing\MockClient;

$client = MockClient::create();

// $client is OpcUaClientInterface — read, write, browse, etc. all work.
$dv = $client->read('i=2261');
```
<!-- @endcode-block -->

Out of the box, `MockClient`:

- Returns `DataValue` with `Null` value and `Good` status from
  `read()`.
- Returns `Good` from `write()` and `call()`.
- Returns an empty array from `browse()`, `browseAll()`,
  `historyRead*()`.
- Returns valid empty result DTOs from subscription / monitored-item
  methods.
- Reports `ConnectionState::Disconnected` until `connect()` is called;
  after that, `Connected`.
- Has pre-populated BuildInfo defaults: `MockServer` /
  `php-opcua` / `1.0.0` / `1` / `2026-01-01`.

That's enough to write tests that exercise the call-site shape
without needing to register a single handler.

## The minimal test

<!-- @code-block language="php" label="tests/Unit/Foo.test.php" -->
```php
use PhpOpcua\Client\Testing\MockClient;

it('records the speed setpoint', function () {
    $client = MockClient::create();

    $service = new MyDeviceService($client);
    $service->setSpeed(42);

    expect($client->callCount('write'))->toBe(1);
    expect($client->getCallsFor('write')[0]['args'][1])->toBe(42);
});
```
<!-- @endcode-block -->

Three test affordances are at play here:

1. **Direct substitution** — `MockClient` *is* an
   `OpcUaClientInterface`. No mock framework, no doubles.
2. **Call tracking** — `callCount()`, `getCallsFor()`, `getCalls()`,
   `resetCalls()` record every interaction.
3. **Default behaviour** — `write()` returns Good without the test
   having to script it.

## When to register handlers

Default behaviour is enough for tests that care *that* a call was
made. For tests that care about the *result* of a call — what shape
your application receives, how it reacts to a bad status — register a
handler. See [Handlers](./handlers.md).

## Connection lifecycle

Mock connections do not perform a real handshake — `connect()` flips
the state and that is it. No `MockClient` connection ever fails
unless you make it fail by overriding `connect()`. To simulate a
broken connection mid-test:

<!-- @code-block language="php" label="simulate broken state" -->
```php
$client = MockClient::create();
$client->connect('opc.tcp://test');

// later in the test:
$client->onRead('i=2261', fn() => throw new ConnectionException('simulated drop'));
```
<!-- @endcode-block -->

`onRead()` registers a handler for that specific NodeId; throwing
from inside the handler surfaces an exception at the call site, just
like a real broken connection would.

## Interface compliance

`MockClient` implements every method on `OpcUaClientInterface`,
including the v4.2.0 introspection methods:

| Introspection          | Mock behaviour                                     |
| ---------------------- | --------------------------------------------------- |
| `hasMethod($name)`     | Reflects on the interface — returns `true` for built-in methods |
| `hasModule($class)`    | Returns `false` by default. Override via `onHasModule()` if your code branches on module presence |
| `getRegisteredMethods()` | Returns the interface's method names |
| `getLoadedModules()`   | Returns `[]` by default                            |

This matters for code that introspects before calling. The default
mock looks like a vanilla client with the built-in modules and no
custom ones — which is the right default for most tests.

## Event dispatcher

`MockClient` exposes `setEventDispatcher()` / `getEventDispatcher()`.
Pass an in-memory PSR-14 dispatcher to capture the events your
application dispatches:

<!-- @code-block language="php" label="event capture" -->
```php
use Symfony\Component\EventDispatcher\EventDispatcher;
use PhpOpcua\Client\Event\NodeValueWritten;

$dispatcher = new EventDispatcher();
$captured = [];
$dispatcher->addListener(NodeValueWritten::class, fn($e) => $captured[] = $e);

$client = MockClient::create();
$client->setEventDispatcher($dispatcher);

// Your application writes through the mock; the dispatcher captures the events.
$service = new MyDeviceService($client);
$service->setSpeed(42);

expect($captured)->toHaveCount(1);
expect($captured[0]->value)->toBe(42);
```
<!-- @endcode-block -->

Note: `MockClient` only dispatches events when handlers explicitly
do so (the built-in event dispatchers in modules don't run because
modules don't run). Test pattern: the application code under test is
responsible for dispatching, or you wire `NullEventDispatcher` and
ignore events.

## Fluent builders

The fluent forms (`readMulti()`, `writeMulti()`,
`createMonitoredItems()`, `translateBrowsePaths()`) work against the
mock. They return the same builder types and end up calling the
same underlying method — the mock intercepts at that lower level.

<!-- @code-block language="php" label="fluent against mock" -->
```php
$results = $client->readMulti()
    ->node('ns=2;s=Tag1')->value()
    ->node('ns=2;s=Tag2')->dataType()
    ->execute();

// $results is an array of DataValues, mocked.
```
<!-- @endcode-block -->

## When MockClient is the wrong tool

- **Testing the encoder/decoder.** Mock the transport instead — the
  library's own unit tests do this in `tests/Unit/Encoding/`.
- **Testing custom modules.** `MockClient` does not run modules. Test
  modules in isolation against a stub kernel, then wire them into a
  real `Client` for integration coverage.
- **Testing reconnection behaviour.** The state machine is internal
  to `Client`. Run against a real (or test-suite) server.

For everything else — application code that reads, writes, browses,
subscribes through an `OpcUaClientInterface` — `MockClient` is the
right substitute.
