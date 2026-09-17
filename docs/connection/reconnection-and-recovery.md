---
eyebrow: 'Docs · Connection'
lede:    'After a broken connection the question is not whether the client reconnected, but what you missed and whether you can still fetch it. Three server-side clocks decide the answer.'

see_also:
  - { href: './timeouts-and-retry.md',              meta: '5 min' }
  - { href: '../recipes/disconnection-recovery.md', meta: '6 min' }
  - { href: '../operations/subscriptions.md',       meta: '7 min' }

prev: { label: 'Timeouts and retry',  href: './timeouts-and-retry.md' }
next: { label: 'Reading attributes',  href: '../operations/reading-attributes.md' }
---

# Reconnection and data recovery

A reconnect that succeeds is not the same as a recovery that loses
nothing. What survives a broken connection is decided by three
server-side clocks, and by how long the outage lasted against them.

## What the client does on its own

| Situation | What happens automatically | Default | Switch |
| --------- | -------------------------- | ------- | ------ |
| Security token near expiry | The secure channel token is renewed at 75% of its revised lifetime, on the next call | on | `setRenewSecurityToken(false)` |
| Connection lost during a call (`ConnectionException`) | `reconnect()` is called and the call repeated, up to N times, immediately — no backoff. Dispatches `RetryAttempt` and `RetryExhausted` | **off** (N = 0) | `setAutoRetry(N)` |
| Any `reconnect()`, yours or auto-retry's | A new secure channel, then ActivateSession of the **same** session; a new session only if the server refuses it. The session is remembered across failed attempts. Dispatches `ClientReconnecting` and `SessionReactivated` | on | `setReactivateSession(false)` |
| A call fails with `BadSessionIdInvalid`, `BadSessionClosed` or `BadSessionNotActivated` | A new session, and the call is repeated once, whatever `setAutoRetry()` says | on | `setRecreateExpiredSession(false)` |
| `connect()` with a saved session | The saved session is reactivated; a new one is created if the server refuses it | when `resumeSession()` is used | — |
| A long history read | Continuation points are followed until the interval is complete | always | — |

What stays with your application:

- **Reconnecting at all** when auto-retry is off — a call on a broken client throws `ConnectionException` — and the **backoff** between attempts.
- **Acknowledging notifications**: the acknowledgements you pass to `publish()`.
- **`transferSubscriptions()`** when you ended up with a new session.
- **`republish()`** of the `availableSequenceNumbers`.
- **Recreating** subscriptions and monitored items.
- **Checking the Overflow bit** and reading the missing interval from history.
- **Storing** the session state and the subscription ids across a restart (`suspend()`, `getSessionState()`).

<!-- @callout variant="warning" -->
By default a lost connection is **not** healed on its own: without
`setAutoRetry()` the next call throws and the client stays `Broken` until
you call `reconnect()`. What is on by default is what makes that reconnect
safe — keeping the session, renewing the token, recreating an expired
session. Nothing touches your subscriptions: the scenarios below are
yours to handle.
<!-- @endcallout -->

## The three clocks

| Clock | Value | While it runs |
| ----- | ----- | ------------- |
| Session timeout | `Client::getSessionTimeout()` — the value the server granted, not the one you asked for | The session can be reactivated on a new secure channel, with its subscriptions |
| Subscription lifetime | `SubscriptionResult::$revisedPublishingInterval × $revisedLifetimeCount` | The subscription stays on the server without publish requests, and can be transferred to a new session |
| Monitored item queue | `MonitoredItemResult::$revisedQueueSize × $revisedSamplingInterval` | The server keeps that many values per item for you |

Every one of them is negotiated: the server revises what you request.
Read the revised values back — they are returned by `createSubscription()`
and `createMonitoredItems()` — and size your recovery budget on those.

<!-- @callout variant="info" -->
The clocks are independent. A session can expire while its subscriptions
are still alive, and a subscription can be alive while its monitored item
queues have already overflowed.
<!-- @endcallout -->

## Scenario 1 — short outage: the session survives

Within the session timeout, `reconnect()` opens a new secure channel and
reactivates the **same** session with ActivateSession (on by default, see
`ClientBuilder::setReactivateSession()`). Subscriptions, monitored items,
sequence numbers and the server's retransmission queue are untouched:
there is nothing to transfer and nothing to rebuild.

| Step | Done by the client? | How to turn it off |
| ---- | ------------------- | ------------------ |
| Reconnecting after the connection broke | Only with `setAutoRetry(N)`, which is **off by default**; otherwise the next call throws and you call `reconnect()` | Leave `setAutoRetry()` at `0` |
| Reactivating the same session on the new channel | Yes, on every `reconnect()` | `setReactivateSession(false)` — every reconnect then creates a new session and you land in scenario 2 |
| Republishing the notifications you never acknowledged | No | — |

<!-- @code-block language="php" label="short outage" -->
```php
try {
    $publish = $client->publish($acknowledgements);
} catch (ConnectionException) {
    $client->reconnect();   // same session, same subscriptions

    // The first publish after the reconnect lists what the server still holds.
    $publish = $client->publish();
    foreach ($publish->availableSequenceNumbers as $sequenceNumber) {
        $message = $client->republish($subscriptionId, $sequenceNumber);
        handleNotifications($message['notifications']);
    }
}
```
<!-- @endcode-block -->

The reconnect dispatches `ClientReconnecting`, and `SessionReactivated`
when the session came back. Calling `transferSubscriptions()` here is
pointless: the subscription is already yours and the server answers
`BadNothingToDo`.

## Scenario 2 — the session is gone, the subscription is not

Past the session timeout — or when the server refuses the activation, or
when a new process starts without the saved session — the client creates
a new session. The old subscriptions are orphaned but still alive until
their lifetime expires, and `transferSubscriptions()` takes them back.

| Step | Done by the client? | How to turn it off |
| ---- | ------------------- | ------------------ |
| Creating a new session when the old one is gone | Yes: during `reconnect()` when the server refuses the reactivation, and when a call fails with `BadSessionIdInvalid`, `BadSessionClosed` or `BadSessionNotActivated` (the call is then repeated once) | The fallback during `reconnect()` cannot be turned off; the one on a failed call with `setRecreateExpiredSession(false)`, which makes the call throw instead |
| Taking the subscriptions back with `transferSubscriptions()` | No | — |
| Republishing the `availableSequenceNumbers` | No | — |

<!-- @code-block language="php" label="transfer and republish" -->
```php
$results = $client->transferSubscriptions([$subscriptionId], sendInitialValues: false);

if (($results[0]->statusCode & 0x80000000) === 0) {
    // The subscription is ours again, with its queue and its sequence numbers.
    foreach ($results[0]->availableSequenceNumbers as $sequenceNumber) {
        $message = $client->republish($subscriptionId, $sequenceNumber);
        handleNotifications($message['notifications']);
    }
} else {
    // Nothing to take back: see scenario 3.
}
```
<!-- @endcode-block -->

Two preconditions are easy to miss:

- **You need the subscription ids.** Store them where they survive a
  process restart, together with the sequence numbers you acknowledged.
- **The new session needs the right to take them.** Against
  UA-.NETStandard an anonymous session is refused with
  `BadUserAccessDenied`; an authenticated one works.

A notification the server has already dropped from its retransmission
queue fails `republish()` with `BadMessageNotAvailable`. That interval is
a real gap: recover it as in scenario 3.

## Scenario 3 — nothing survived

Past the subscription lifetime, after a server restart, or when the
transfer is refused, there is no server-side state left. Create the
subscription and the monitored items again, and treat the interval as
missing data:

| Step | Done by the client? | How to turn it off |
| ---- | ------------------- | ------------------ |
| Creating a new session | Yes, as in scenario 2 | As in scenario 2 |
| Recreating subscriptions and monitored items | No | — |
| Reading the missing interval from history | No — but once you call `historyReadRaw()`, continuation points are followed automatically until the interval is complete | Continuation handling cannot be turned off; pass `numValuesPerNode` to cap the values returned |

<!-- @code-block language="php" label="recreate and backfill" -->
```php
$subscription = $client->createSubscription(publishingInterval: 1000.0);
$results      = $client->createMonitoredItems($subscription->subscriptionId, $items);

// The gap runs from the last value you stored to now.
$values = $client->historyReadRaw($nodeId, $lastStoredTimestamp, new DateTimeImmutable());
```
<!-- @endcode-block -->

<!-- @steps -->
- **Measure the gap.** Its start is the source timestamp of the last
  value you stored, not the moment the connection broke.

- **Fill it from the server history** with `historyReadRaw()`, for the
  nodes the server historizes. The reads follow continuation points, so
  a long interval comes back complete.

- **What the server did not historize stays missing.** The client returns
  what the server holds and nothing more: for a node without history, the
  interval between the last stored value and the new subscription cannot be
  read back.
<!-- @endsteps -->

## Restarting the process

A restart is scenario 1 or 2 depending on how long it takes. To land in
scenario 1, save the session before stopping and resume it on start:

<!-- @code-block language="php" label="across a restart" -->
```php
// Before stopping: close the channel, keep the session alive on the server.
$state = $client->suspend();
file_put_contents($path, json_encode($state), LOCK_EX);

// On start: reactivate it, within the session timeout.
$client = ClientBuilder::create()
    ->resumeSession(SessionState::fromArray(json_decode(file_get_contents($path), true)))
    ->connect('opc.tcp://plc.local:4840');
```
<!-- @endcode-block -->

<!-- @callout variant="warning" -->
The saved state contains the authentication token and the server nonce:
anyone holding it can take over the session. Write it with restrictive
permissions.
<!-- @endcallout -->

## The limit that survives everything: the queue

Even when session and subscription both survive, the server only kept
`revisedQueueSize` values per monitored item. Beyond that the oldest are
discarded and the next `DataValue` carries the Overflow info bit:

<!-- @code-block language="php" label="detecting discarded values" -->
```php
foreach ($publish->notifications as $notification) {
    if ($notification instanceof DataChangeNotification && $notification->dataValue->isOverflow()) {
        // The server dropped values for this item: backfill that node from history.
    }
}
```
<!-- @endcode-block -->

Without that check a recovery looks perfect and is not: the values are
gone, and nothing else reports it.

<!-- @do-dont -->
<!-- @do -->
Size `lifetimeCount` and `queueSize` on the longest outage you want to
survive, and read the revised values back: the server decides what you
actually get.
<!-- @enddo -->
<!-- @dont -->
Don't read a successful reconnect as "no data lost". Only the sequence
numbers, the Overflow bit and the history say what really arrived.
<!-- @enddont -->
<!-- @enddo-dont -->

## What to read next

- [Recipes · Recovering from disconnection](../recipes/disconnection-recovery.md)
  — the same three scenarios wired into a worker loop, with backoff.
- [Operations · Subscriptions](../operations/subscriptions.md) — publish,
  acknowledgements and republish in detail.
- [Operations · History reads](../operations/history-reads.md) — reading
  back the interval you missed.
