---
eyebrow: 'Docs · Operations'
lede:    'Subscriptions are the long-poll mechanism for OPC UA — once created, the server pushes notifications until the session ends. This page covers the subscription itself; monitored items, the things being watched, live on their own page.'

see_also:
  - { href: './monitored-items.md',             meta: '7 min' }
  - { href: '../recipes/subscribing-to-data-changes.md', meta: '5 min' }
  - { href: '../recipes/disconnection-recovery.md', meta: '6 min' }

prev: { label: 'Calling methods', href: './calling-methods.md' }
next: { label: 'Monitored items', href: './monitored-items.md' }
---

# Subscriptions

A **subscription** is a server-side construct that delivers
notifications to the client at a configured cadence. It carries zero
or more **monitored items** (variables to sample, events to filter)
and runs an internal publish cycle: every `publishingInterval`, the
server packages whatever changed and queues a `PublishResponse`.

The client retrieves those responses by calling `publish()`. There is
no socket-level "push" — the publish loop is request/response under
the covers — but the model is otherwise the long-poll you'd expect.

## Creating a subscription

<!-- @method name="$client->createSubscription(float \$publishingInterval = 1000.0, int \$lifetimeCount = 2400, int \$maxKeepAliveCount = 10, int \$maxNotificationsPerPublish = 0, int \$priority = 0): SubscriptionResult" returns="SubscriptionResult" visibility="public" -->

<!-- @code-block language="php" label="examples/subscribe.php" -->
```php
$sub = $client->createSubscription(publishingInterval: 500.0);

echo "Subscription #{$sub->subscriptionId}, "
   . "revised interval: {$sub->revisedPublishingInterval}ms\n";
```
<!-- @endcode-block -->

<!-- @params -->
<!-- @param name="$publishingInterval" type="float" default="1000.0" -->
Target sampling rate in milliseconds. The server may revise this — the
actual rate is in `$sub->revisedPublishingInterval`.
<!-- @endparam -->
<!-- @param name="$lifetimeCount" type="int" default="2400" -->
Number of publish-interval ticks the server will keep the subscription
alive without a publish request. Default of 2400 × 1000 ms = 40 min.
<!-- @endparam -->
<!-- @param name="$maxKeepAliveCount" type="int" default="10" -->
After this many empty ticks, the server emits an empty `PublishResponse`
to keep the connection warm. Default of 10 × 1000 ms = 10 s heartbeat.
<!-- @endparam -->
<!-- @param name="$maxNotificationsPerPublish" type="int" default="0" -->
Cap on notifications per publish reply. `0` = unbounded.
<!-- @endparam -->
<!-- @param name="$priority" type="int" default="0" -->
Server-side priority hint when multiple subscriptions compete.
<!-- @endparam -->
<!-- @endparams -->

`SubscriptionResult`:

| Field                          | Meaning                                  |
| ------------------------------ | ---------------------------------------- |
| `subscriptionId`               | Server-assigned id — use it for every later call |
| `revisedPublishingInterval`    | Actual interval the server agreed to     |
| `revisedLifetimeCount`         | Actual lifetime count                    |
| `revisedMaxKeepAliveCount`     | Actual keep-alive count                  |

## The publish loop

After creating a subscription and adding monitored items, drive the
publish loop yourself:

<!-- @code-block language="php" label="examples/publish-loop.php" -->
```php
$running = true;

while ($running) {
    $publish = $client->publish(acknowledgements: $pendingAcks);
    $pendingAcks = [];

    foreach ($publish->notifications as $notification) {
        // DataChangeReceived events have already been dispatched at this point,
        // but you also get the raw notification array here.
        handleNotification($notification);
    }

    if ($publish->sequenceNumber !== 0) {
        $pendingAcks[] = [
            'subscriptionId'  => $publish->subscriptionId,
            'sequenceNumber'  => $publish->sequenceNumber,
        ];
    }

    if ($publish->moreNotifications === false) {
        // Server has nothing else queued — back off slightly.
        usleep(10_000);
    }
}
```
<!-- @endcode-block -->

The `publish()` call returns a `PublishResult` carrying:

- `subscriptionId` — the subscription this response belongs to
- `sequenceNumber` — to acknowledge in a later `publish()`
- `availableSequenceNumbers` — outstanding (un-acked) sequence numbers
  the server still holds
- `moreNotifications` — `true` if more data is queued server-side; call
  `publish()` again immediately
- `notifications` — the array of decoded notification payloads

The client also dispatches granular events (`DataChangeReceived`,
`EventNotificationReceived`, alarm-typed events). Consumers who prefer
event-driven code can register a PSR-14 listener and ignore the
notification array entirely. See [Observability ·
Events](../observability/events.md).

## Acknowledgements

OPC UA requires the client to acknowledge notification sequence numbers
so the server can retire them from its retransmission queue.
Acknowledgements are piggy-backed on the next `publish()` call:

<!-- @code-block language="php" label="ack on the next publish" -->
```php
$acks = [
    ['subscriptionId' => 12, 'sequenceNumber' => 42],
    ['subscriptionId' => 12, 'sequenceNumber' => 43],
];

$client->publish(acknowledgements: $acks);
```
<!-- @endcode-block -->

If you fall behind, the server queues until `availableSequenceNumbers`
reaches its `maxNotificationsPerPublish` cap or the subscription
expires. To recover lost notifications after a brief outage, see the
republish section below.

## Republish

<!-- @method name="$client->republish(int \$subscriptionId, int \$retransmitSequenceNumber): array" returns="array" visibility="public" -->

Ask the server for a specific sequence number it still has buffered.
Useful when the client missed a publish reply (process restart, brief
network blip) but the subscription itself is still alive:

<!-- @code-block language="php" label="recover a missed notification" -->
```php
try {
    $missed = $client->republish($subId, retransmitSequenceNumber: 41);
    process($missed);
} catch (ServiceException $e) {
    // BadMessageNotAvailable — the server already discarded it. Tough luck.
}
```
<!-- @endcode-block -->

## Deleting

<!-- @method name="$client->deleteSubscription(int \$subscriptionId): int" returns="int (StatusCode)" visibility="public" -->

Delete the subscription cleanly when you are done. The server frees
all its server-side resources (monitored items, queues, sequence
numbers). Leaving subscriptions to expire works but burns server
memory until the lifetime count runs out.

## Transferring across sessions

<!-- @method name="$client->transferSubscriptions(array \$subscriptionIds, bool \$sendInitialValues = false): array" returns="TransferResult[]" visibility="public" -->

When the channel dies and you `reconnect()`, the new session has no
subscriptions of its own. If the server preserved the subscription
resources (some servers do, some do not), `transferSubscriptions()`
re-binds them to the new session:

<!-- @code-block language="php" label="re-bind after reconnect" -->
```php
$client->reconnect();

$results = $client->transferSubscriptions(
    subscriptionIds: [$oldSubId],
    sendInitialValues: true,
);

if ($results[0]->statusCode !== 0) {
    // Server lost the subscription too — recreate from scratch.
}
```
<!-- @endcode-block -->

`sendInitialValues: true` asks the server to re-send the most recent
value of every monitored item, so the client's local cache is fresh.

See [Recipes · Recovering from
disconnection](../recipes/disconnection-recovery.md) for the full
re-subscription pattern.

## What to read next

- [Operations · Monitored items](./monitored-items.md) — what to put
  *inside* a subscription.
- [Recipes · Subscribing to data
  changes](../recipes/subscribing-to-data-changes.md) — end-to-end
  pattern, event-driven.
