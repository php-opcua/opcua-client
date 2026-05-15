---
eyebrow: 'Docs · Observability'
lede:    'A PSR-3 logger and a structured context — that''s the whole logging surface. The library logs at every protocol step; what you see is governed by your logger''s level.'

see_also:
  - { href: './events.md',        meta: '6 min' }
  - { href: './caching.md',       meta: '5 min' }
  - { href: '../recipes/disconnection-recovery.md', meta: '6 min' }

prev: { label: 'Wire serialization', href: '../extensibility/wire-serialization.md' }
next: { label: 'Events',             href: './events.md' }
---

# Logging

The library logs through any PSR-3 `LoggerInterface`. There is no
custom log facade, no global configuration — pass the logger to the
builder and the library writes through it.

## Wiring a logger

<!-- @code-block language="php" label="examples/logged-client.php" -->
```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use PhpOpcua\Client\ClientBuilder;

$logger = new Logger('opcua');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

$client = ClientBuilder::create()
    ->setLogger($logger)
    ->connect('opc.tcp://plc.local:4840');
```
<!-- @endcode-block -->

Any PSR-3 logger works — Monolog, Laravel's logger, Symfony's logger,
your own. Without `setLogger()`, the client uses a `NullLogger` and
emits nothing.

## What gets logged

The library logs at four PSR-3 levels:

| Level     | What                                                       |
| --------- | ---------------------------------------------------------- |
| `error`   | Failures the caller will see as exceptions: connection, security, encoding. |
| `warning` | Recoverable surprises: retries triggered, ServiceFault on optional service sets, cache corruption recovered. |
| `info`    | Lifecycle and significant transitions: connect, disconnect, subscription create/delete, channel open/close, certificate trust decisions. |
| `debug`   | Per-call protocol detail: every Read / Write / Browse / Call request and response with timing. |

Default Monolog handlers ship at `Logger::DEBUG`. In production, set
the threshold to `INFO` or higher unless you are actively debugging —
`DEBUG` produces one log entry per service call, which is a lot.

## Per-call context

Every log entry carries a structured context. The fields that appear
on most entries:

| Key            | Type      | Meaning                                                 |
| -------------- | --------- | ------------------------------------------------------- |
| `endpoint`     | `string`  | The configured `opc.tcp://` URL                         |
| `state`        | `string`  | `ConnectionState` at the time of the call               |
| `requestId`    | `int`     | The protocol-level request ID, when one is allocated    |
| `service`      | `string`  | Short name (`read`, `browse`, `createSession`, …)       |
| `nodeId`       | `string`  | Stringified NodeId for node-scoped calls                |
| `duration_ms`  | `float`   | Call duration in milliseconds (debug level only)        |
| `statusCode`   | `int`     | OPC UA status (when present)                            |

These keys are stable across versions — safe to grep, index, alert
on.

## Reading the debug stream

Wire a logger at `DEBUG` for a session, run one of your normal calls,
and the protocol shape becomes obvious. A typical `read()` looks
like:

<!-- @code-block language="text" label="sample debug output" -->
```text
DEBUG  opcua.connecting  {"endpoint":"opc.tcp://plc.local:4840"}
DEBUG  opcua.transport.hello {"endpoint":"opc.tcp://plc.local:4840"}
DEBUG  opcua.transport.ack   {"endpoint":"opc.tcp://plc.local:4840"}
DEBUG  opcua.opn.request     {"endpoint":"opc.tcp://plc.local:4840","requestId":1}
DEBUG  opcua.opn.response    {"endpoint":"opc.tcp://plc.local:4840","requestId":1,"duration_ms":12.7}
DEBUG  opcua.session.create  {"endpoint":"opc.tcp://plc.local:4840","requestId":2}
DEBUG  opcua.session.activate {"endpoint":"opc.tcp://plc.local:4840","requestId":3}
INFO   opcua.connected       {"endpoint":"opc.tcp://plc.local:4840"}
DEBUG  opcua.read.request    {"nodeId":"i=2261","requestId":4}
DEBUG  opcua.read.response   {"nodeId":"i=2261","requestId":4,"statusCode":0,"duration_ms":3.1}
```
<!-- @endcode-block -->

That trace is the protocol — if you ever wondered "what is the
client actually doing", this is where you find out.

## Routing by source

Most loggers support routing by channel name. The library writes
under the channel you configured (`'opcua'` in the Monolog example).
A common production setup:

- `opcua` channel at `INFO` to a regular log handler
- `opcua` channel at `DEBUG` to a per-host file, rotated daily and
  shipped on demand to the troubleshooting workflow

Avoid mixing OPC UA debug logs into the main application channel —
they will dominate volume.

## Sensitive payloads

The library logs:

- Endpoint URLs and NodeIds (architectural information)
- Status codes and timings
- Subscription IDs and request IDs

The library does **not** log:

- Authentication tokens (`getAuthToken()` is opaque on the wire and
  never serialised into log context)
- Username / password values
- Certificate bodies (only fingerprints, at trust decisions)
- Variant `value` fields

The trace above is safe to share for support. If you nonetheless need
to redact further, wrap your PSR-3 logger with a filter that strips
the `nodeId` field — server architecture is the most sensitive
remaining signal.

## Logging vs events

Logs are append-only, human-readable, and best for diagnostics. Events
(see [Events](./events.md)) are typed, addressable, and best for
programmatic reactions (metrics, alerts, business logic).

Wire both: the dispatcher for things you want to react to, the logger
for things you want to read later.

<!-- @callout variant="tip" -->
For a quick first run, set the logger to `DEBUG` and dump to
`php://stderr`. Once you have a feel for the protocol cadence, lift
the threshold to `INFO` and start wiring events.
<!-- @endcallout -->

## Performance

Logging cost is dominated by the handler. Monolog at `DEBUG` to a
file is fast enough to leave on in production; the same logger to
syslog over a network is slow enough to dominate a busy worker. Use a
`BufferHandler` or `FingersCrossedHandler` if you want debug detail
on error and silence otherwise.

The library does not memoize log messages — they are formatted at the
call site. A `NullLogger` skips the formatting entirely.
