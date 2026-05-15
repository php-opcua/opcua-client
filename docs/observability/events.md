---
eyebrow: 'Docs · Observability'
lede:    'PSR-14 events are how you wire metrics, alerts, and reactions to the OPC UA lifecycle. Wire a dispatcher once, listen to the events you care about, ignore the rest.'

see_also:
  - { href: './event-reference.md', meta: '8 min' }
  - { href: './logging.md',         meta: '5 min' }
  - { href: '../recipes/disconnection-recovery.md', meta: '6 min' }

prev: { label: 'Logging',          href: './logging.md' }
next: { label: 'Event reference',  href: './event-reference.md' }
---

# Events

The library dispatches **47** event classes through PSR-14. They cover
the full lifecycle: connection, session, secure channel,
subscription, monitored items, read / write / browse, alarms, retries,
cache, trust store. Wire a dispatcher and listen — there is no other
configuration.

The full event catalogue lives in [Event
reference](./event-reference.md). This page is about *using* events.

## Wiring a dispatcher

Any PSR-14 `EventDispatcherInterface` works:

<!-- @code-block language="php" label="examples/event-wired-client.php" -->
```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Event\NodeValueWritten;
use Symfony\Component\EventDispatcher\EventDispatcher;

$dispatcher = new EventDispatcher();

$dispatcher->addListener(NodeValueWritten::class, function (NodeValueWritten $e) {
    metric('opcua.writes', 1, ['node' => (string) $e->nodeId]);
});

$client = ClientBuilder::create()
    ->setEventDispatcher($dispatcher)
    ->connect('opc.tcp://plc.local:4840');
```
<!-- @endcode-block -->

Without `setEventDispatcher()`, the client uses `NullEventDispatcher`
— a no-op. Cost: zero.

## Listening idioms

PSR-14 dispatchers are dumb pipes. Two listener idioms cover most
cases:

**Per-event class — typed, simple.**

<!-- @code-block language="php" label="single-event listener" -->
```php
$dispatcher->addListener(ClientReconnecting::class, function (ClientReconnecting $e) {
    $this->logger->warning('opcua.reconnecting', ['endpoint' => $e->endpoint]);
});
```
<!-- @endcode-block -->

**Class hierarchy — broader nets.**

Some dispatcher implementations support listener registration against
a base class. The library's alarm events all extend a common
`AlarmEventReceived`-shaped surface, so a listener on the parent
catches every alarm variant. Check your dispatcher's docs — Symfony's
`EventDispatcher` does not do this; `league/event` does.

## Where events fire

Each event has a single dispatch point. The contract:

- **Lifecycle events** fire **after** the underlying transition is
  visible. `ClientConnected` fires after the session is activated and
  before `connect()` returns. `ClientDisconnected` fires after the
  socket is closed.
- **Service-call events** fire **after** the response is decoded.
  `NodeValueRead` fires after `read()` has its `DataValue`; the event
  carries the value.
- **Error events** fire **before** the exception is rethrown.
  `ConnectionFailed` and `NodeValueWriteFailed` give listeners a
  chance to record the failure before the call site sees it.
- **Cache / retry events** fire **inline**, around the hot path.
  Listeners should be fast — a slow `CacheHit` listener will dominate
  the cost of a cache hit.

## The dispatcher contract

The library expects a PSR-14 dispatcher that **runs listeners
synchronously**, in the calling thread, before returning. The library
makes no thread-safety claims about asynchronous dispatchers; if your
dispatcher defers, the event-payload references may be stale by the
time the listener runs.

## A worked example — track every read

<!-- @code-block language="php" label="metrics on every read" -->
```php
use PhpOpcua\Client\Event\NodeValueRead;

$dispatcher->addListener(NodeValueRead::class, function (NodeValueRead $e) use ($metrics) {
    $metrics->counter('opcua.reads', tags: [
        'endpoint' => $e->endpoint,
        'good'     => StatusCode::isGood($e->dataValue->statusCode),
    ])->increment();

    if (! StatusCode::isGood($e->dataValue->statusCode)) {
        $metrics->counter('opcua.reads.bad_status', tags: [
            'statusCode' => StatusCode::getName($e->dataValue->statusCode),
        ])->increment();
    }
});
```
<!-- @endcode-block -->

This is the canonical instrumentation pattern. Wire it once, get
visibility everywhere `read()` is called — including in third-party
modules that compose `read()` internally.

## A worked example — alarm dispatch

Alarms are events with extra fields. The library auto-deduces which
alarm-typed event to dispatch and gives you a typed listener target
per transition:

<!-- @code-block language="php" label="alarm reactions" -->
```php
use PhpOpcua\Client\Event\AlarmActivated;
use PhpOpcua\Client\Event\AlarmAcknowledged;
use PhpOpcua\Client\Event\LimitAlarmExceeded;

$dispatcher->addListener(AlarmActivated::class, fn($e) =>
    pagerduty()->trigger($e->sourceName, $e->message, $e->severity)
);

$dispatcher->addListener(AlarmAcknowledged::class, fn($e) =>
    pagerduty()->resolve($e->sourceName)
);

$dispatcher->addListener(LimitAlarmExceeded::class, fn($e) =>
    audit()->record('limit-exceeded', $e->sourceName, $e->limitValue)
);
```
<!-- @endcode-block -->

The generic `AlarmEventReceived` fires for every alarm — useful for
"log every alarm" surfaces. The specific variants
(`AlarmActivated`, `AlarmDeactivated`, etc.) fire when the relevant
state field crosses a transition. Pick the level of granularity that
fits your reaction.

See [Event reference](./event-reference.md) for the full alarm matrix.

## Throwing from a listener

Listener exceptions propagate. If a listener throws, the dispatcher
typically propagates the exception, which interrupts the OPC UA call
path. The library treats listener exceptions like any other unchecked
exception — they bubble to the caller as-is. To insulate the OPC UA
call from listener failures, wrap your listener body in a try/catch.

This is a deliberate choice: the library does not silently swallow
listener exceptions, because a silent swallow would hide bugs in
instrumentation.

## When events are the wrong tool

- **You want a full audit log.** Use the PSR-3 logger. Events are for
  reactions, not for sequential record-keeping.
- **You want to mutate behaviour.** Events are read-only signals. To
  change what the client does, swap the module — see [Extensibility ·
  Replacing modules](../extensibility/replacing-modules.md).
- **You need cross-process delivery.** PSR-14 is in-process.
  Republish to a queue from a listener if you need cross-process
  reach.

## Performance

Listener cost is on the hot path of every dispatched event. A slow
listener — anything that blocks on I/O, anything that does meaningful
work — multiplies the call cost. The library dispatches an event per
service call at minimum; a busy worker dispatching to a 50 ms HTTP
sink per `read()` will be CPU-bound on dispatch, not on OPC UA.

When in doubt, buffer in the listener (in-memory ring buffer, batched
flush) and ship asynchronously.
