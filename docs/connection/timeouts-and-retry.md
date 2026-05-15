---
eyebrow: 'Docs · Connection'
lede:    'One timeout knob, one retry knob, and a clear line between recoverable and fatal failures. Wire them deliberately — defaults are conservative, not optimal.'

see_also:
  - { href: './opening-and-closing.md',     meta: '6 min' }
  - { href: '../reference/exceptions.md',   meta: '7 min' }
  - { href: '../recipes/disconnection-recovery.md', meta: '6 min' }

prev: { label: 'Opening and closing',     href: './opening-and-closing.md' }
next: { label: 'Reading attributes',      href: '../operations/reading-attributes.md' }
---

# Timeouts and retry

The client exposes two configuration values that govern its tolerance
to slow servers and transient failures: **timeout** and
**auto-retry**. Both are set on the builder before `connect()`.

## Timeout

<!-- @method name="ClientBuilder::setTimeout(float \$timeout): self" returns="self" visibility="public" -->

<!-- @params -->
<!-- @param name="$timeout" type="float" required -->
Socket-level read and write timeout, in seconds. Applied to both the
TCP handshake and every subsequent service call. Default: `5.0`.
<!-- @endparam -->
<!-- @endparams -->

The timeout is a **per-syscall** watchdog. It does not bound the total
duration of a service call — a server that streams a 50 MB browse
result in 100 KB chunks, each delivered under the timeout, will keep
the connection alive indefinitely. For end-to-end deadlines, use a
per-request timer in your application code.

<!-- @code-block language="php" label="conservative timeout" -->
```php
$client = ClientBuilder::create()
    ->setTimeout(10.0)
    ->connect('opc.tcp://plc.local:4840');
```
<!-- @endcode-block -->

### Picking a value

- **`5.0` (default)** is appropriate for LAN-attached servers in good
  health. PLC responses normally land in tens of milliseconds.
- **`10.0`–`30.0`** for WAN endpoints, busy servers, or servers running
  on constrained hardware.
- **`> 30.0`** suggests something is wrong with the network path — fix
  the network rather than raise the timeout further.
- **`< 2.0`** is risky. The OPC UA handshake involves a discovery
  round-trip plus the OPN/CreateSession/ActivateSession sequence; sub-
  two-second timeouts will fail spuriously on cold connections.

## Auto-retry

<!-- @method name="ClientBuilder::setAutoRetry(int \$maxRetries): self" returns="self" visibility="public" -->

<!-- @params -->
<!-- @param name="$maxRetries" type="int" required -->
Maximum number of retry attempts per service call. `0` (default)
disables retry — the original exception propagates immediately.
<!-- @endparam -->
<!-- @endparams -->

When auto-retry is on, every public service method (read, write,
browse, …) runs inside `executeWithRetry`. On a recoverable failure
the client calls `reconnect()` and re-issues the request. Each retry
counts; the total attempt budget is `maxRetries + 1` calls (one
original + N retries). After the budget runs out, the **last**
exception is rethrown.

<!-- @code-block language="php" label="retry up to three times" -->
```php
$client = ClientBuilder::create()
    ->setAutoRetry(3)
    ->connect('opc.tcp://plc.local:4840');

$value = $client->read('i=2261');   // up to 4 attempts total
```
<!-- @endcode-block -->

### What counts as recoverable

The retry path triggers on transport-level failures and channel/session
invalidation:

| Trigger                             | Exception                          |
| ----------------------------------- | ---------------------------------- |
| Socket I/O error or timeout         | `ConnectionException`              |
| Channel rejected by the server      | `ConnectionException` (`BadSecureChannelClosed`) |
| Session invalidated                 | `ServiceException` (`BadSessionIdInvalid`, `BadSessionNotActivated`) |

These are reasonable to retry because `reconnect()` clears the state
that caused them. Other exceptions are **not** retried:

- `ServiceException` with any other status — the server rejected the
  request semantically, not transiently. Retrying produces the same
  rejection.
- `SecurityException` — certificate, key, or crypto failures. Almost
  always configuration bugs.
- `ConfigurationException`, `EncodingException`, `InvalidNodeIdException`
  — the call is malformed.
- `ServiceUnsupportedException` — the server does not implement the
  service set. Retrying changes nothing. See [Recipes · Handling
  unsupported services](../recipes/service-unsupported.md).

### Retry events

Each retry attempt dispatches `RetryAttempt` (with the attempt number,
the operation name, and the triggering exception). When the budget is
exhausted and the last attempt fails, `RetryExhausted` fires with the
final exception. Wire a PSR-14 dispatcher to surface those — they are
the most useful observability signal you can collect for an unreliable
network path. See [Observability · Event
reference](../observability/event-reference.md).

## When the two interact

A 30-second timeout combined with `setAutoRetry(3)` produces a worst-
case 4×30 = 120-second call. The client does not bound the total
wall-clock duration; tune both knobs to fit your call-site deadline.

<!-- @do-dont -->
<!-- @do -->
Set timeouts and retries based on the **type** of call site:

- Background workers: generous timeout, retry on
- Web request handlers: tight timeout, retry off (the request budget
  cannot afford 4×timeouts of latency)
- CLI scripts: generous timeout, retry on
<!-- @enddo -->
<!-- @dont -->
Don't crank both to large values "to be safe". A 60-second timeout
with `setAutoRetry(5)` means a single broken endpoint can hang your
process for six minutes — long after the operator has retried by hand.
<!-- @enddont -->
<!-- @enddo-dont -->

## Per-call deadlines

The library does not expose a per-call timeout argument. To enforce a
deadline on a specific call without changing the connection-wide
setting, wrap the call in a short-lived helper or schedule a SIGALRM:

<!-- @code-block language="php" label="deadline guard" -->
```php
$deadline = microtime(true) + 2.0;

if (microtime(true) > $deadline) {
    throw new RuntimeException('Deadline exceeded before issuing request');
}

$value = $client->read('i=2261');
```
<!-- @endcode-block -->

The pattern above is a soft deadline — it checks the clock around the
call but cannot interrupt a hung socket read. For hard deadlines on
unreliable links, run the client behind
[`opcua-session-manager`](https://github.com/php-opcua/opcua-session-manager),
which adds a daemon-side watchdog.

## What to read next

- [Recipes · Recovering from disconnection](../recipes/disconnection-recovery.md)
  — re-subscribing after `reconnect()`.
- [Reference · Exceptions](../reference/exceptions.md) — the full
  classification of recoverable vs fatal errors.
