---
eyebrow: 'Docs · Connection'
lede:    'The connection lifecycle has three states and three transitions. Get the state machine right and most "the client just hung" bugs disappear.'

see_also:
  - { href: './timeouts-and-retry.md',           meta: '5 min' }
  - { href: '../recipes/disconnection-recovery.md', meta: '6 min' }
  - { href: '../observability/events.md',        meta: '6 min' }

prev: { label: 'Endpoints and discovery', href: './endpoints-and-discovery.md' }
next: { label: 'Timeouts and retry',      href: './timeouts-and-retry.md' }
---

# Opening and closing

The client's connection state is exposed as a three-case enum,
`ConnectionState`:

<!-- @code-block language="text" label="state machine" -->
```text
        ┌──────────────────────┐
        │     Disconnected     │ ← initial state
        └──────────────────────┘
                 │   ▲
        connect()│   │ disconnect()
                 ▼   │
        ┌──────────────────────┐
        │      Connected       │
        └──────────────────────┘
                 │   ▲
   I/O error,    │   │ reconnect()
   server reset, │   │  succeeds
   stale token   │   │
                 ▼   │
        ┌──────────────────────┐
        │       Broken         │
        └──────────────────────┘
```
<!-- @endcode-block -->

Three states, three transitions:

| From         | Transition       | To           |
| ------------ | ---------------- | ------------ |
| `Disconnected` | `connect()`    | `Connected`  |
| `Connected`    | I/O error      | `Broken`     |
| `Broken`       | `reconnect()`  | `Connected`  |
| any            | `disconnect()` | `Disconnected` |

Read the state with `$client->getConnectionState()`. The boolean
shortcut `$client->isConnected()` returns `true` only when the state is
`Connected`.

## Opening

There is no separate `open()` call. `ClientBuilder::connect()` returns
a `Client` already in `ConnectionState::Connected`. Under the hood it
performs:

1. TCP open + HEL/ACK handshake
2. GetEndpoints discovery, if needed
3. `OpenSecureChannel` (OPN)
4. `CreateSession` + `ActivateSession`

If any step fails, no `Client` is returned — `connect()` throws and the
caller never sees a half-initialised object.

<!-- @code-block language="php" label="examples/connect.php" -->
```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\SecurityException;

try {
    $client = ClientBuilder::create()
        ->setTimeout(10.0)
        ->connect('opc.tcp://plc.local:4840');
} catch (ConnectionException $e) {
    // TCP failure, DNS error, HEL/ACK timeout, server refused, etc.
    throw $e;
} catch (SecurityException $e) {
    // Certificate validation, key load, OpenSSL primitive failure
    throw $e;
}
```
<!-- @endcode-block -->

Configure the builder **before** calling `connect()` — the builder is
the only configuration surface. Once you have a `Client`, the connection
parameters (security, timeout, batching, …) are frozen until you build
a new one.

## Closing

Always `disconnect()` when you are done. The call sends `CloseSession`
and `CloseSecureChannel` to the server and frees the TCP socket. Both
messages can fail silently — `disconnect()` does not raise on the
return trip, so it is safe in `finally` blocks.

<!-- @code-block language="php" label="examples/scoped-client.php" -->
```php
$client = ClientBuilder::create()->connect('opc.tcp://plc.local:4840');

try {
    $client->read('i=2261');
    // … real work …
} finally {
    $client->disconnect();
}
```
<!-- @endcode-block -->

<!-- @callout variant="warning" -->
A `Client` that has been `disconnect()`ed cannot be reused for normal
operations — every service call will raise `ConnectionException("Not
connected: call connect() first")`. To revive it, call `reconnect()`.
The builder is a one-shot factory by design; if you need a second
connection, build a second client.
<!-- @endcallout -->

## Reconnecting

When a connection drops, the client transitions to
`ConnectionState::Broken` on the next service call. Call `reconnect()`
to rebuild the channel and session against the same endpoint, with the
same configuration:

<!-- @method name="$client->reconnect(): void" returns="void" visibility="public" -->

`reconnect()` does **not** restore subscriptions on its own — the
server has discarded them along with the old session. If your
application maintains live subscriptions, see [Recipes · Recovering
from disconnection](../recipes/disconnection-recovery.md) for the
re-subscription pattern.

## Detecting a broken connection

A connection only flips to `Broken` after the client tries to use it.
There is no background heartbeat in this library. The detection points
are:

- A blocking read or write on the socket fails or times out.
- The server returns a session-invalid status (`BadSessionIdInvalid`,
  `BadSessionNotActivated`).
- The secure channel is rejected (`BadSecureChannelClosed`).

When that happens, the client dispatches `ClientDisconnected` (with
`reason = 'broken'`), sets the state to `Broken`, and raises the
underlying exception to the caller. The next call into the client will
raise `ConnectionException("Connection broken: call reconnect()")` —
the state remains stuck on `Broken` until `reconnect()` succeeds or
`disconnect()` is called.

## Auto-retry

For transient failures inside a single call, the client supports an
opt-in retry loop. Configure it on the builder:

<!-- @code-block language="php" label="builder.setAutoRetry" -->
```php
$client = ClientBuilder::create()
    ->setAutoRetry(maxRetries: 3)
    ->connect('opc.tcp://plc.local:4840');
```
<!-- @endcode-block -->

When `maxRetries > 0`, every service method routes through
`executeWithRetry`. On a recoverable error it calls `reconnect()` and
re-issues the request, up to `maxRetries` total tries. See [Connection
· Timeouts and retry](./timeouts-and-retry.md) for the classification
of recoverable vs fatal failures.

## Lifecycle events

The connection lifecycle is fully observable via PSR-14 events. Wire a
dispatcher with `setEventDispatcher()` and listen for:

| Event                | When                                                      |
| -------------------- | --------------------------------------------------------- |
| `ClientConnecting`   | `connect()` started                                       |
| `ClientConnected`    | Session activated; client is usable                       |
| `ConnectionFailed`   | `connect()` raised — payload carries the exception        |
| `ClientDisconnecting`| `disconnect()` started                                    |
| `ClientDisconnected` | Disconnected — `reason` is `'clean'` or `'broken'`        |
| `ClientReconnecting` | `reconnect()` started                                     |
| `SecureChannelOpened` / `SecureChannelClosed` | OPN / CLO frames     |
| `SessionCreated` / `SessionActivated` / `SessionClosed` | CreateSession / ActivateSession / CloseSession |

See [Observability · Event reference](../observability/event-reference.md).
