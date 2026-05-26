---
eyebrow: 'Docs · Extensibility'
lede:    'Swap the wire transport — TCP today, anything you want tomorrow. ClientTransportInterface is the contract; TcpTransport is the default; everything else is one PSR-4 class away.'

see_also:
  - { href: './modules.md',                          meta: '6 min' }
  - { href: '../reference/builder-api.md',           meta: '6 min' }
  - { href: '../connection/opening-and-closing.md',  meta: '6 min' }

prev: { label: 'Wire serialization', href: './wire-serialization.md' }
next: { label: 'Logging',            href: '../observability/logging.md' }
---

# Custom transports

The client moves OPC UA framed messages between PHP
and the server through a single abstraction — `ClientTransportInterface`.
The default implementation is `TcpTransport`, which speaks `opc.tcp://`
over a regular TCP socket. Implementing the interface lets you target
alternative encapsulations (`opc.tls://`, `opc.https://`, `opc.wss://`,
an in-process loopback) without touching the rest of the library.

This is the contract that any new transport implements:

<!-- @code-block language="php" label="ClientTransportInterface" -->
```php
namespace PhpOpcua\Client\Transport;

interface ClientTransportInterface
{
    public function connect(string $host, int $port, null|float $timeout = null): void;
    public function send(string $data): void;
    public function receive(): string;
    public function setReceiveBufferSize(int $size): void;
    public function close(): void;
    public function isConnected(): bool;
}
```
<!-- @endcode-block -->

Six methods. The framing (the OPC UA 8-byte message header) is already
in `$data` for `send()`; `receive()` parses that same header to read
exactly one complete message back. The transport is byte-level — it
never inspects payload semantics.

## When to write a custom transport

| Scenario                                                | Custom transport pays off                                |
| ------------------------------------------------------- | -------------------------------------------------------- |
| Tunnel through HTTPS / WebSocket to traverse a proxy    | Yes — implement `opc.https://` or `opc.wss://`            |
| Defense-in-depth TLS under `opc.tcp://`                  | Yes — wrap a TLS stream and implement against it          |
| Unit tests without a real OPC UA server                  | Yes — see the `InMemoryTransport` example below           |
| Replace TCP timeout / retry semantics                    | Often unnecessary — `setAutoRetry()` + `setTimeout()` cover most cases |
| Add PubSub support                                       | **No** — use the sibling [`opcua-client-ext-pubsub`](https://github.com/php-opcua/opcua-client-ext-pubsub) (different request/response vs broadcast model) |

The interface is intentionally shaped for the **request/response**
pattern OPC UA uses over `opc.tcp://`. PubSub's broadcast model needs
a different surface; that's why it lives in its own package with
its own `PubSubTransportInterface`.

## Wiring a custom transport

Pass an instance to `ClientBuilder::setTransport()` before `connect()`:

<!-- @code-block language="php" label="builder wiring" -->
```php
use PhpOpcua\Client\ClientBuilder;

$client = ClientBuilder::create()
    ->setTransport(new MyHttpsTransport())
    ->connect('opc.https://plc.example.org:443/UA/MyServer');
```
<!-- @endcode-block -->

Without `setTransport()`, the builder leaves the transport `null` and
the client falls back to `new TcpTransport()` — fully backward
compatible.

`ClientBuilder::getTransport()` returns the configured instance (or
`null`) for inspection.

## The TcpTransport reference implementation

`TcpTransport` (`src/Transport/TcpTransport.php`) is ~150 lines and
the simplest example of a correct implementation:

- `connect()` calls `stream_socket_client('tcp://host:port', ...)`
- `send()` loops `fwrite()` until the whole payload is written
- `receive()` reads the 8-byte header, decodes the message size from
  bytes 4-7, then reads the body via a `readExact()` helper that
  loops `fread()` until the requested length is collected
- `setReceiveBufferSize()` caps the largest message `receive()` will
  accept — the client calls this after the HEL/ACK handshake with
  the value the server negotiated
- `close()` calls `fclose()` and nulls the socket; `isConnected()`
  reports whether the socket is set

Copy the structure, replace the I/O calls with your transport's
primitives, and you have a new transport.

## Example — `InMemoryTransport` for tests

A reusable mock that satisfies the contract without any real socket.
Lives at `tests/Unit/Helpers/InMemoryTransport.php`:

<!-- @code-block language="php" label="tests/Unit/Helpers/InMemoryTransport.php" -->
```php
final class InMemoryTransport implements ClientTransportInterface
{
    /** @var string[] */ public array $sentMessages = [];
    /** @var string[] */ private array $responseQueue = [];

    public ?string $connectedHost = null;
    public ?int    $connectedPort = null;
    public ?float  $connectTimeout = null;
    public int     $receiveBufferSize = 65535;
    private bool   $connected = false;

    public function connect(string $host, int $port, null|float $timeout = null): void
    {
        $this->connectedHost  = $host;
        $this->connectedPort  = $port;
        $this->connectTimeout = $timeout;
        $this->connected      = true;
    }

    public function send(string $data): void
    {
        if (! $this->connected) {
            throw new ConnectionException('Not connected');
        }
        $this->sentMessages[] = $data;
    }

    public function receive(): string
    {
        if (! $this->connected) {
            throw new ConnectionException('Not connected');
        }
        if ($this->responseQueue === []) {
            throw new ConnectionException('Response queue exhausted');
        }
        return array_shift($this->responseQueue);
    }

    public function setReceiveBufferSize(int $size): void { $this->receiveBufferSize = $size; }
    public function close(): void                          { $this->connected = false; }
    public function isConnected(): bool                    { return $this->connected; }

    public function queueResponse(string $framedMessage): void
    {
        $this->responseQueue[] = $framedMessage;
    }
}
```
<!-- @endcode-block -->

Use it with the builder to assert what the client sends without
spinning up a real server:

<!-- @code-block language="php" label="test wiring" -->
```php
$transport = new InMemoryTransport();
$transport->queueResponse($cannedHelloAckBytes);
$transport->queueResponse($cannedOpenSecureChannelResponse);
// ... pre-load every response your test will trigger ...

$client = ClientBuilder::create()
    ->setTransport($transport)
    ->connect('opc.tcp://test:4840');

// later …
expect($transport->sentMessages[0])->toStartWith("HELF");
```
<!-- @endcode-block -->

A real implementation needs to pre-load the full HEL/ACK + OPN +
CreateSession + ActivateSession flow before `connect()` returns —
that's why the library ships a higher-level `MockClient` in
`tests/Unit/MockClientSimplifiedTest.php` for tests that don't care
about the wire layer.

## Contract details

A correct implementation observes the following invariants:

- **`connect()` opens** the transport. Subsequent `connect()` without
  a prior `close()` is implementation-defined; document what your
  transport does.
- **`send()` writes the entire payload** or throws `ConnectionException`.
  Partial writes that complete on retry are fine; partial writes that
  silently truncate the message are not.
- **`receive()` returns exactly one complete OPC UA framed message**
  with its 8-byte header. It blocks until that message arrives or
  the transport errors. The header bytes 4-7 carry the message size
  (little-endian `uint32`).
- **`setReceiveBufferSize()` caps the largest message** the transport
  is willing to receive. Transports that don't impose a wire-level
  size limit (e.g. HTTPS with chunked bodies) may treat this as
  advisory — but they must honour the size on the OPC UA path or the
  server-side negotiation becomes meaningless.
- **`close()` is idempotent** — calls after the first are a no-op.
- **`isConnected()` reflects reality** — after `close()` it returns
  `false`; after a transport-level disconnect (`Connection reset by
  peer`, etc.) it should also return `false` on the next call, even
  if the implementation discovers the disconnect lazily inside
  `receive()`.

The transport is **not** required to be thread-safe — the `Client`
owns a single transport instance and uses it from a single thread.

## Exceptions

Throw the right exception type:

- `ConnectionException` — transport-level failures: socket can't open,
  read/write returned a permanent error, peer closed the channel,
  timeout reading data.
- `ProtocolException` — framing failures: the 8-byte header says the
  message is 1 GB, the message-type field isn't a recognized 3-letter
  ASCII code, the chunk type byte is invalid.

Higher-level OPC UA semantics (`Bad_ServiceUnsupported`,
`Bad_NodeIdUnknown`) belong upstream of the transport — the transport
just returns the bytes.

## Reverse Connect — feeding a pre-connected socket

`TcpTransport::fromConnectedSocket(mixed $socket, ?float $readTimeout = null): self`
is a public factory that constructs a transport around a stream socket
that is already in CONNECTED state, bypassing `stream_socket_client()`.

It is the seam used by the Reverse Connect pattern (OPC UA Part 6 §7.1.2.3):
a listener-side component accepts the inbound TCP connection from a server,
consumes the ReverseHello (`RHE`) frame, and hands the remaining socket to
this factory. The UA-TCP pipeline (HEL/ACK, OPN, CreateSession, …) then
runs identically to the standard connector flow — `Client::connect()`
detects the already-connected transport via
`ManagesConnectionTrait::performConnect()` and skips the redundant
`transport->connect($host, $port)` step.

Direct usage:

```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Transport\TcpTransport;

$socket = /* a stream resource already TCP-connected to the server */;
$transport = TcpTransport::fromConnectedSocket($socket);

$client = (new ClientBuilder())
    ->setTransport($transport)
    ->connect('opc.tcp://server.example:4840');
```

In practice the listener, parser, whitelist validator, and bridge to
`ClientBuilder` live in the companion package
[`php-opcua/opcua-client-ext-reverse-connect`](https://github.com/php-opcua/opcua-client-ext-reverse-connect).
The core only exposes this factory; applications that do not need
Reverse Connect take no extra dependency.

Non-resource input raises `ConnectionException` with message
`fromConnectedSocket requires a valid stream resource`. The factory takes
ownership of the socket: `close()` will `fclose()` it.

## What to read next

- [Modules](./modules.md) — the other extension point. Custom modules
  register methods on the client; custom transports change how those
  methods reach the wire.
- [Builder API](../reference/builder-api.md) — every setter on
  `ClientBuilder`, including `setTransport()`.
- [Connection · Opening and closing](../connection/opening-and-closing.md) —
  the lifecycle the transport participates in.
