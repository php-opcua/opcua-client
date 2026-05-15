---
eyebrow: 'Docs · Recipes'
lede:    'A connection drops. You want the work to resume without losing the subscription state, the cached browse results, or the calling code''s composure.'

see_also:
  - { href: '../connection/opening-and-closing.md',   meta: '6 min' }
  - { href: '../connection/timeouts-and-retry.md',    meta: '5 min' }
  - { href: '../operations/subscriptions.md',         meta: '7 min' }

prev: { label: 'Upgrading to v4.3',         href: './upgrading-to-v4.3.md' }
next: { label: 'Handling unsupported services', href: './service-unsupported.md' }
---

# Recovering from disconnection

A long-running OPC UA integration meets every shape of failure:
network blip, server restart, channel renewal mishandled, session
expired. The library exposes three primitives — `reconnect()`,
auto-retry, and `transferSubscriptions()` — and one event signal
(`ClientDisconnected` with `reason: 'broken'`) to coordinate around
them.

This recipe wires them into a complete pattern.

## The shape of the failure

When the channel dies mid-operation:

1. The next service call fails. The exception is usually
   `ConnectionException`; sometimes `ServiceException` with
   `BadSessionIdInvalid`.
2. The client's `ConnectionState` flips to `Broken`.
3. `ClientDisconnected` fires with `reason: 'broken'`.
4. Any subscriptions the client held are lost server-side after the
   subscription's `lifetimeCount` ticks elapse.

You have a window — measured in seconds for a server restart, longer
for network blips — during which `reconnect()` can salvage the
subscription state. Past that window, the server has discarded the
subscription, and you have to recreate it.

## Minimum recipe — single-call retry

For everything that is not a subscription, the cheapest pattern is to
let the library retry:

<!-- @code-block language="php" label="auto-retry only" -->
```php
$client = ClientBuilder::create()
    ->setAutoRetry(3)
    ->setTimeout(10.0)
    ->connect('opc.tcp://plc.local:4840');

// Every service call retries up to 3 times on recoverable failures.
// reconnect() is called automatically between attempts.
$value = $client->read('ns=2;s=Tag');
```
<!-- @endcode-block -->

This handles the easy cases: brief network blips, server restarts
that complete within the retry budget. It does **not** handle
subscriptions — they need explicit re-registration.

## Full recipe — preserving subscriptions

<!-- @code-block language="php" label="examples/recoverable-worker.php" -->
```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Types\ConnectionState;

$client = ClientBuilder::create()
    ->setAutoRetry(3)
    ->setTimeout(10.0)
    ->setLogger($logger)
    ->setEventDispatcher($dispatcher)
    ->connect('opc.tcp://plc.local:4840');

$sub      = $client->createSubscription(publishingInterval: 500.0);
$itemIds  = createMonitoredItems($client, $sub->subscriptionId, $watchedNodes);
$savedIds = ['subscriptionId' => $sub->subscriptionId, 'monitoredItems' => $itemIds];

$running = true;
while ($running) {
    try {
        $publish = $client->publish();
        handleNotifications($publish);
    } catch (ConnectionException $e) {
        $logger->warning('opcua.publish.failed', ['exception' => $e]);
        if (! recoverConnection($client, $savedIds)) {
            $logger->error('opcua.recovery.gave_up');
            $running = false;
        }
    }
}

function recoverConnection($client, array &$saved): bool
{
    // Sleep with backoff before retrying — the library does no backoff itself.
    $backoffs = [1, 2, 5, 10, 30];   // seconds
    foreach ($backoffs as $delay) {
        sleep($delay);
        try {
            $client->reconnect();
        } catch (ConnectionException $e) {
            continue;
        }

        // Re-bind the old subscription to the new session.
        $transfer = $client->transferSubscriptions(
            [$saved['subscriptionId']],
            sendInitialValues: true,
        );

        if ($transfer[0]->statusCode === 0) {
            return true;   // Subscription survived. Items are still alive.
        }

        // Server discarded the subscription. Recreate from scratch.
        $sub = $client->createSubscription(publishingInterval: 500.0);
        $saved['subscriptionId']  = $sub->subscriptionId;
        $saved['monitoredItems']  = createMonitoredItems(
            $client,
            $sub->subscriptionId,
            $watchedNodes,
        );
        return true;
    }
    return false;
}
```
<!-- @endcode-block -->

The structure:

<!-- @steps -->
- **Save the subscription state.**

  Keep `subscriptionId` and the list of nodes you registered. After a
  reconnect, you either transfer (cheap) or recreate (expensive).

- **Catch `ConnectionException` around the publish loop.**

  Other failures (`ServiceException` for individual operations) are
  unrelated and propagate as bugs.

- **Backoff before reconnect.**

  The library does no backoff. Hammering a freshly-restarted server
  with reconnect attempts wastes both ends' resources.

- **Try `transferSubscriptions()` first.**

  When the server kept the subscription resources, transfer rebinds
  it to the new session in one round-trip. `sendInitialValues: true`
  re-sends the most recent value of every monitored item so the
  local cache is fresh.

- **Recreate if transfer failed.**

  `transferSubscriptions()` returns a `BadSubscriptionIdInvalid` per
  result when the server lost the subscription. Recreate from
  scratch and re-register the items.
<!-- @endsteps -->

## Event-driven flavour

If your application already reacts to events for the publish loop
(see [Recipes · Subscribing to data
changes](./subscribing-to-data-changes.md)), wire the recovery to
`ClientDisconnected`:

<!-- @code-block language="php" label="event-driven recovery" -->
```php
$dispatcher->addListener(ClientDisconnected::class, function ($e) use ($client, &$savedIds) {
    if ($e->reason !== 'broken') {
        return;   // Clean disconnects are not failures.
    }
    if (! recoverConnection($client, $savedIds)) {
        // Surface to an alerting path.
        throw new RuntimeException('OPC UA recovery exhausted budget');
    }
});
```
<!-- @endcode-block -->

The dispatcher fires on the I/O thread (the same one that just
detected the failure). The listener runs synchronously before the
exception escapes — which means it has the chance to recover before
the call site even sees the error.

<!-- @callout variant="warning" -->
Event-driven recovery means the listener can call back into the
library. Make sure your listener does **not** recursively dispatch
`ClientDisconnected` (e.g. by calling something that fails again).
The library does not detect re-entrancy.
<!-- @endcallout -->

## Picking a backoff strategy

The numbers in the example (`1, 2, 5, 10, 30`) are not magic — they
come from operational experience with PLCs and industrial servers.
Three principles:

- **Start short.** A network blip resolves in <2 seconds. Don't wait
  longer than that for the first attempt.
- **Grow geometrically.** A server restart takes 10-60 seconds.
  Doubling roughly is reasonable.
- **Cap the wait.** 60+ seconds between attempts wastes more time
  than it saves. After ~5 attempts, accept that the integration is
  dead and escalate.

A worker that gives up should fail loudly — restart the worker, page
oncall, surface to a status page. The library does not have an
opinion about the right response; that is your operational layer.

## When you cannot transfer

Some servers (open62541 default config, smaller PLCs) discard
subscriptions on every channel reset. `transferSubscriptions()`
returns `BadSubscriptionIdInvalid` for every entry. In that case the
transfer step is dead weight — skip it and go straight to recreate:

<!-- @code-block language="php" label="skip transfer" -->
```php
$client->reconnect();

$sub = $client->createSubscription(publishingInterval: 500.0);
$saved['subscriptionId'] = $sub->subscriptionId;
$saved['monitoredItems'] = createMonitoredItems(
    $client,
    $sub->subscriptionId,
    $watchedNodes,
);
```
<!-- @endcode-block -->

The cost is one extra round-trip plus N more for `createMonitoredItems()`.
Acceptable.
