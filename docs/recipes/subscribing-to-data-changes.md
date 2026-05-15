---
eyebrow: 'Docs · Recipes'
lede:    'Subscribe to a few tags, react to every change. The publish loop is yours to drive; events make the reactive shape ergonomic.'

see_also:
  - { href: '../operations/subscriptions.md',    meta: '7 min' }
  - { href: '../operations/monitored-items.md',  meta: '7 min' }
  - { href: '../observability/events.md',        meta: '6 min' }

prev: { label: 'Browsing recursively', href: './browsing-recursively.md' }
next: { label: 'Writing typed arrays', href: './writing-typed-arrays.md' }
---

# Subscribing to data changes

The typical OPC UA worker shape: subscribe to a set of tags, run an
event loop that pulls notifications, react to each change. This
recipe shows the minimal complete version, then the extension points
worth knowing.

## Minimum worker

<!-- @code-block language="php" label="examples/data-change-worker.php" -->
```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Event\DataChangeReceived;
use Symfony\Component\EventDispatcher\EventDispatcher;

$dispatcher = new EventDispatcher();

$dispatcher->addListener(DataChangeReceived::class, function (DataChangeReceived $e) use ($logger) {
    $logger->info('opcua.change', [
        'monitoredItemId' => $e->monitoredItemId,
        'value'           => $e->dataValue->getValue(),
        'timestamp'       => $e->dataValue->sourceTimestamp?->format('c'),
    ]);
    // …react…
});

$client = ClientBuilder::create()
    ->setEventDispatcher($dispatcher)
    ->setAutoRetry(3)
    ->connect('opc.tcp://plc.local:4840');

$sub = $client->createSubscription(publishingInterval: 500.0);

$client->createMonitoredItems($sub->subscriptionId)
    ->add('ns=2;s=Devices/PLC/Speed')->samplingInterval(500.0)
    ->add('ns=2;s=Devices/PLC/Mode')->samplingInterval(1000.0)
    ->add('ns=2;s=Devices/PLC/Health')->samplingInterval(1000.0)
    ->execute();

$pendingAcks = [];
while (true) {
    $publish = $client->publish(acknowledgements: $pendingAcks);

    // DataChangeReceived listeners have already run by this point.
    // Build the next round's acks.
    $pendingAcks = $publish->sequenceNumber !== 0
        ? [['subscriptionId' => $sub->subscriptionId, 'sequenceNumber' => $publish->sequenceNumber]]
        : [];

    if (! $publish->moreNotifications) {
        usleep(10_000);   // back off slightly when the server has nothing queued
    }
}
```
<!-- @endcode-block -->

Worth restating:

- **The publish loop is yours.** The library does not run a thread —
  every `publish()` is a synchronous request. The loop above hammers
  the server hard; a back-off when `moreNotifications === false`
  keeps it polite.
- **`DataChangeReceived` fires inside `publish()`.** Your listener
  runs synchronously, before `publish()` returns. Fast listeners only.
- **Acknowledge what you received.** The server retains
  notifications until acked; failing to ack means the server's
  retransmission queue fills up.

## Mapping monitored items back to NodeIds

`DataChangeReceived` carries the `monitoredItemId` and `dataValue`,
not the NodeId. The library does not maintain a server-side
item-id-to-NodeId mapping for you — keep one client-side:

<!-- @code-block language="php" label="examples/item-mapping.php" -->
```php
$client->createMonitoredItems($sub->subscriptionId)
    ->add('ns=2;s=Devices/PLC/Speed')->samplingInterval(500.0)->clientHandle(101)
    ->add('ns=2;s=Devices/PLC/Mode')->samplingInterval(1000.0)->clientHandle(102)
    ->add('ns=2;s=Devices/PLC/Health')->samplingInterval(1000.0)->clientHandle(103);

$results = $client->createMonitoredItems($sub->subscriptionId)
    ->add('ns=2;s=Devices/PLC/Speed')->samplingInterval(500.0)
    ->add('ns=2;s=Devices/PLC/Mode')->samplingInterval(1000.0)
    ->add('ns=2;s=Devices/PLC/Health')->samplingInterval(1000.0)
    ->execute();

$itemMap = array_combine(
    array_column($results, 'monitoredItemId'),
    ['ns=2;s=Devices/PLC/Speed', 'ns=2;s=Devices/PLC/Mode', 'ns=2;s=Devices/PLC/Health'],
);

$dispatcher->addListener(DataChangeReceived::class, function ($e) use ($itemMap, $logger) {
    $nodeId = $itemMap[$e->monitoredItemId] ?? '<unknown>';
    $logger->info('opcua.change', ['nodeId' => $nodeId, 'value' => $e->dataValue->getValue()]);
});
```
<!-- @endcode-block -->

`clientHandle` is a caller-defined integer that the server echoes
back on every notification. Use it as your correlation key when the
item-id ↔ NodeId mapping needs a stable identifier on the wire.

## Sampling interval vs publishing interval

Two intervals matter:

| Parameter            | Where set                            | What it does                                   |
| -------------------- | ------------------------------------ | ---------------------------------------------- |
| `publishingInterval` | `createSubscription()`               | How often the server packages notifications    |
| `samplingInterval`   | `createMonitoredItems()` per item    | How often the server samples the source value  |

A 100 ms `samplingInterval` with a 1 s `publishingInterval` means the
server samples 10 times per second but only sends a batch every
second. Useful when the application can tolerate batched delivery
but needs fine-grained samples.

A 1 s `samplingInterval` with a 100 ms `publishingInterval` is the
opposite: cheap sampling, fast batch turnaround. Most servers cap the
sampling interval at the publishing interval; the `revisedSamplingInterval`
on `MonitoredItemResult` tells you what the server actually agreed
to.

## Deadband filtering

To reduce update volume from a noisy analogue, configure a
`DataChangeFilter`:

<!-- @code-block language="php" label="examples/deadband-filter.php" -->
```php
$client->createMonitoredItems($sub->subscriptionId, [
    [
        'nodeId'           => 'ns=2;s=Sensors/Temperature',
        'samplingInterval' => 250.0,
        'queueSize'        => 10,
        'filter'           => [
            'trigger'      => 1,        // StatusValue (default 0 = Status, 2 = StatusValueTimestamp)
            'deadbandType' => 1,        // Absolute (2 = Percent)
            'deadbandValue'=> 0.5,      // ignore changes smaller than 0.5 units
        ],
    ],
]);
```
<!-- @endcode-block -->

`DataChangeFilter` is the only filter shape supported on data-change
monitored items in this library. For event monitoring (alarms,
arbitrary server events), see [Operations · Monitored
items](../operations/monitored-items.md).

## Stopping cleanly

Mark a stop signal, drain the publish loop, then disconnect:

<!-- @code-block language="php" label="examples/clean-shutdown.php" -->
```php
$running = true;

pcntl_signal(SIGTERM, function () use (&$running) {
    $running = false;
});

while ($running) {
    pcntl_signal_dispatch();
    try {
        $publish = $client->publish();
        // …
    } catch (\Throwable $e) {
        $logger->error('opcua.publish.error', ['exception' => $e]);
        break;
    }
}

$client->deleteSubscription($sub->subscriptionId);
$client->disconnect();
```
<!-- @endcode-block -->

`pcntl_signal_dispatch()` is the cooperative-cancellation hook. It
costs almost nothing per iteration and lets `SIGTERM` propagate
without a `pcntl_signal()` async-mode setup.

## When the connection drops mid-loop

Pair the worker with the recovery pattern in [Recipes · Recovering
from disconnection](./disconnection-recovery.md). The publish loop is
the natural catch point for `ConnectionException`; from there,
`reconnect()` + `transferSubscriptions()` rebinds the work without
losing the configured items.

## Performance notes

- A subscription with 10 items at 500 ms sampling, on a healthy LAN,
  sustains ~20 publish round-trips per second comfortably. Each
  round-trip is ~1-3 ms.
- The library does not buffer notifications client-side beyond a
  single `publish()` response. Slow listeners create back-pressure on
  the loop, not on memory.
- Wire `CacheHit` / `CacheMiss` events to see whether your worker is
  actually hitting the cache during the registration phase — a long
  list of items is a long warm-up.

## When not to subscribe

- **Read-and-go batch jobs.** Polling `readMulti()` once a minute is
  simpler and cheaper. Subscriptions earn their cost when you need
  sub-second reactivity.
- **Servers that misbehave on subscriptions.** Some embedded PLCs
  cap concurrent subscriptions at 1; some leak server-side memory on
  long-lived subscriptions. Probe with one item, observe for a few
  hours, then scale up.
