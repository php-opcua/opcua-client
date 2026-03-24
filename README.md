<h1 align="center"><strong>OPC UA PHP Client</strong></h1>

<div align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="assets/logo-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset="assets/logo-light.svg">
    <img alt="OPC UA PHP Client" src="assets/logo-light.svg" width="435">
  </picture>
</div>

<p align="center">
  <a href="https://github.com/GianfriAur/opcua-php-client/actions/workflows/integration-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/GianfriAur/opcua-php-client/integration-tests.yml?branch=master&label=tests&style=flat-square" alt="Tests"></a>
  <a href="https://codecov.io/gh/GianfriAur/opcua-php-client"><img src="https://img.shields.io/codecov/c/github/GianfriAur/opcua-php-client?style=flat-square&logo=codecov" alt="Coverage"></a>
  <a href="https://packagist.org/packages/gianfriaur/opcua-php-client"><img src="https://img.shields.io/packagist/v/gianfriaur/opcua-php-client?style=flat-square&label=packagist" alt="Latest Version"></a>
  <!-- <a href="https://packagist.org/packages/gianfriaur/opcua-php-client"><img src="https://img.shields.io/packagist/dt/gianfriaur/opcua-php-client?style=flat-square" alt="Total Downloads"></a> -->
  <a href="https://packagist.org/packages/gianfriaur/opcua-php-client"><img src="https://img.shields.io/packagist/php-v/gianfriaur/opcua-php-client?style=flat-square" alt="PHP Version"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/GianfriAur/opcua-php-client?style=flat-square" alt="License"></a>
</p>

---

Connect your PHP application directly to industrial PLCs, SCADA systems, sensors, historians, and IoT devices using the [OPC UA](https://opcfoundation.org/about/opc-technologies/opc-ua/) standard — without any C/C++ extensions, HTTP gateways, or middleware in between.

This library implements the full OPC UA binary protocol stack in pure PHP: TCP transport, binary encoding/decoding, secure channel establishment with asymmetric and symmetric encryption, session management, and all the major OPC UA services. Just `composer require` and you're talking to PLCs from your Laravel app, your Symfony worker, or a plain PHP script.

**What you can do with it:**

- **Read and write** process variables from any OPC UA-compliant device — temperatures, pressures, motor speeds, setpoints, counters, anything the server exposes
- **Browse** the entire address space to discover what's available, build tree views, or auto-map variables
- **Subscribe** to data changes and events in real time — get notified when a sensor value changes or an alarm fires
- **Call methods** on the server — trigger operations, run diagnostics, execute commands on the PLC
- **Query historical data** — pull raw logs, aggregated trends (min, max, average), or interpolated values at specific timestamps
- **Secure everything** — 6 security policies from plaintext to AES-256 with RSA-PSS signatures, plus anonymous, username/password, or X.509 certificate authentication

All of this with zero external dependencies beyond `ext-openssl`, and full support for PHP 8.2 through 8.5.

> **Note:** OPC UA relies on persistent sessions and long-lived connections. PHP's request/response model means connections are short-lived by default. For use cases like continuous monitoring or subscription polling, pair this with [`opcua-php-client-session-manager`](https://github.com/GianfriAur/opcua-php-client-session-manager) to persist sessions across requests — or use it in a long-running worker process.
>
> The session manager is a **separate package by design** — it runs as a daemon process using ReactPHP and Unix sockets, which would break this library's zero-dependency, cross-platform philosophy if bundled here. See the [Ecosystem](#ecosystem) section for details.

----

> **A note on versioning:** We're aware of the rapid major releases in a short time frame. This library is under active, full-time development right now — the goal is to reach a production-stable state as quickly as possible. Breaking changes are being bundled and shipped deliberately to avoid dragging them out across many minor releases. Once the API surface settles, major version bumps will become rare. Thanks for your patience.


## Quick Start

```bash
composer require gianfriaur/opcua-php-client
```

```php
use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

$client = new Client();
$client->connect('opc.tcp://localhost:4840');

// Read server status — string format
$status = $client->read('i=2259');
echo $status->getValue(); // 0 = Running

// NodeId objects work too
$status = $client->read(NodeId::numeric(0, 2259));

$client->disconnect();
```

That's it. Three lines to connect, read, and disconnect. No config files, no service containers, no XML.

> **Tip:** All client methods accept NodeId strings like `'i=2259'`, `'ns=2;i=1001'`, or `'ns=2;s=MyNode'` anywhere a `NodeId` is expected. Invalid strings throw `InvalidNodeIdException`.

## See It in Action

### Browse the address space

```php
$refs = $client->browse('i=85'); // Objects folder

foreach ($refs as $ref) {
    echo "{$ref->displayName} ({$ref->nodeId})\n";
    //=> Server (ns=0;i=2253)
    //=> MyPLC (ns=2;i=1000)
}
```

### Read multiple values

```php
$results = $client->readMulti()
    ->node('i=2259')->value()
    ->node('ns=2;i=1001')->displayName()
    ->node('ns=2;s=Temperature')->value()
    ->execute();

foreach ($results as $dataValue) {
    echo $dataValue->getValue() . "\n";
}
```

> **Tip:** You can also pass an array to `readMulti([...])` -- the builder is just a fluent alternative.

### Resolve a path and read a value

```php
$nodeId = $client->resolveNodeId('/Objects/MyPLC/Temperature');
$value = $client->read($nodeId);

echo $value->getValue();        // 23.5
echo $value->statusCode;        // 0 (Good)
echo $value->sourceTimestamp;    // DateTimeImmutable
```

### Write to a PLC

```php
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;

$client->write('ns=2;i=1001', 42, BuiltinType::Int32);
```

### Call a method on the server

```php
use Gianfriaur\OpcuaPhpClient\Types\Variant;

$result = $client->call(
    'i=2253',   // Server object
    'i=11492',  // GetMonitoredItems
    [new Variant(BuiltinType::UInt32, 1)],
);

echo $result->statusCode;                   // 0
echo $result->outputArguments[0]->value;    // [1001, 1002, ...]
```

### Subscribe to data changes

```php
$sub = $client->createSubscription(publishingInterval: 500.0);

$client->createMonitoredItems($sub->subscriptionId, [
    ['nodeId' => NodeId::numeric(2, 1001)],
]);

$response = $client->publish();
foreach ($response->notifications as $notif) {
    echo $notif['dataValue']->getValue() . "\n";
}
```

### Read historical data

```php
$values = $client->historyReadRaw(
    'ns=2;i=1001',
    startTime: new DateTimeImmutable('-1 hour'),
    endTime: new DateTimeImmutable(),
);

foreach ($values as $dv) {
    echo "[{$dv->sourceTimestamp->format('H:i:s')}] {$dv->getValue()}\n";
}
```

### Connect with full security

```php
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;

$client = new Client();
$client->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
$client->setSecurityMode(SecurityMode::SignAndEncrypt);
$client->setClientCertificate('/certs/client.pem', '/certs/client.key', '/certs/ca.pem');
$client->setUserCredentials('operator', 'secret');
$client->connect('opc.tcp://192.168.1.100:4840');
```

> **Tip:** Skip `setClientCertificate()` and a self-signed cert gets auto-generated in memory — perfect for quick tests or servers with auto-accept.

### Decode custom structures with codecs

```php
use Gianfriaur\OpcuaPhpClient\Repository\ExtensionObjectRepository;

$repo = new ExtensionObjectRepository();
$repo->register(NodeId::numeric(2, 5001), MyPointCodec::class);

$client = new Client(extensionObjectRepository: $repo);
$client->connect('opc.tcp://localhost:4840');

$point = $client->read($pointNodeId)->getValue();
// ['x' => 1.5, 'y' => 2.5, 'z' => 3.5]
```

Each client gets its own isolated codec registry — no global state, no cross-contamination.

### Test without a real server

```php
use Gianfriaur\OpcuaPhpClient\Testing\MockClient;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;

$client = MockClient::create();

// Register a handler for read operations
$client->onRead(function (NodeId $nodeId) {
    return DataValue::ofDouble(23.5);
});

// Use the same API as a real client
$value = $client->read('ns=2;s=Temperature');
echo $value->getValue(); // 23.5

// Verify what was called
echo $client->callCount('read'); // 1
```

`MockClient` implements `OpcUaClientInterface` with no TCP connection. Register handlers with `onRead()`, `onWrite()`, `onBrowse()`, `onCall()`, and `onResolveNodeId()`. Track calls with `getCalls()`, `getCallsFor($method)`, `callCount($method)`, and `resetCalls()`. Works with fluent builders (`readMulti()`, `writeMulti()`, etc.).

### Add structured logging

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('opcua');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

$client = new Client(logger: $logger);
$client->connect('opc.tcp://localhost:4840');
// Logs: handshake, secure channel, session creation, reads, retries, errors...
```

Any [PSR-3](https://www.php-fig.org/psr/psr-3/) logger works — Monolog, Laravel's logger, or your own. Without one, logging is silently disabled (`NullLogger`).

### React to events (PSR-14)

```php
use Gianfriaur\OpcuaPhpClient\Event\DataChangeReceived;
use Gianfriaur\OpcuaPhpClient\Event\AlarmActivated;

// Set any PSR-14 event dispatcher
$client->setEventDispatcher($yourDispatcher);

// In your listener:
class HandleDataChange {
    public function __invoke(DataChangeReceived $event): void {
        echo "Node changed on subscription {$event->subscriptionId}: "
            . $event->dataValue->getValue() . "\n";
    }
}
```

38 granular events covering connection, session, subscription, data change, alarms, read/write, browse, cache, and retry. Zero overhead with the default `NullEventDispatcher`. See [Events documentation](doc/14-events.md) for the full list.

### Monitor alarms in real time

```php
use Gianfriaur\OpcuaPhpClient\Event\AlarmActivated;
use Gianfriaur\OpcuaPhpClient\Event\AlarmSeverityChanged;

// Listen for alarm activation
class AlarmHandler {
    public function handleActivated(AlarmActivated $event): void {
        Log::critical("Alarm active: {$event->sourceName} (severity: {$event->severity})");
    }

    public function handleSeverity(AlarmSeverityChanged $event): void {
        if ($event->severity >= 800) {
            Notification::send($operators, new HighSeverityAlarm($event));
        }
    }
}
```

### Explore from the terminal

```bash
# Browse the address space
php vendor/bin/opcua-cli browse opc.tcp://192.168.1.10:4840 /Objects

# Read a value
php vendor/bin/opcua-cli read opc.tcp://192.168.1.10:4840 "ns=2;i=1001"

# Watch a value in real time (subscription mode)
php vendor/bin/opcua-cli watch opc.tcp://192.168.1.10:4840 "ns=2;i=1001"

# Discover endpoints
php vendor/bin/opcua-cli endpoints opc.tcp://192.168.1.10:4840

# With security and JSON output
php vendor/bin/opcua-cli read opc.tcp://server:4840 "i=2259" \
  -s Basic256Sha256 -m SignAndEncrypt \
  --cert=/certs/client.pem --key=/certs/client.key \
  -u operator -p secret --json
```

Zero additional dependencies. Full security support, JSON output (`--json`), debug logging (`--debug`). See [CLI documentation](doc/15-cli.md) for details.

### Auto-discover custom types

```php
$client = new Client();
$client->connect('opc.tcp://localhost:4840');
$client->discoverDataTypes();

$point = $client->read($pointNodeId)->getValue();
// ['x' => 1.5, 'y' => 2.5, 'z' => 3.5] — no codec needed
```

## Why This Library?

- **Zero runtime dependencies** — only `ext-openssl`. Optional PSR-3 logging, PSR-16 caching, and PSR-14 events via any compatible implementation.
- **PHP 8.2+** — runs on any modern PHP.
- **Native binary protocol** — speaks OPC UA directly over TCP. No HTTP gateway, no REST bridge, no sidecar.
- **Full security stack** — 6 policies up to Aes256Sha256RsaPss, 3 auth modes, auto-generated certs.
- **Batteries included** — browse, read, write, call, subscriptions, events, history, path resolution, batching, retry.
- **Cross-platform** — Linux, macOS, Windows. No FFI, no COM.
- **Thoroughly tested** — 750+ tests, 99%+ code coverage across PHP 8.2, 8.3, 8.4, and 8.5.
- **Typed everywhere** — all service responses return `public readonly` DTOs, not arrays.
- **Session persistence** — keep OPC UA connections alive across PHP requests via [`opcua-php-client-session-manager`](https://github.com/GianfriAur/opcua-php-client-session-manager).
- **Laravel-ready** — drop-in via [`opcua-laravel-client`](https://github.com/GianfriAur/opcua-laravel-client).

## Features

| Feature | What it does |
|---|---|
| **Browse** | Navigate the address space — recursive, automatic continuation, tree building |
| **Path Resolution** | Resolve `/Objects/MyPLC/Temperature` to a NodeId in one call |
| **Read / Write** | Single and multi operations, all OPC UA data types |
| **Method Call** | Invoke server methods with typed arguments and results |
| **Subscriptions** | Data change and event monitoring with publish/acknowledge |
| **Transfer & Recovery** | Transfer subscriptions across sessions and republish unacknowledged notifications |
| **History Read** | Raw, processed (aggregated), and at-time historical queries |
| **Endpoint Discovery** | Discover available endpoints and security policies |
| **Security** | 6 policies from None through Aes256Sha256RsaPss |
| **Authentication** | Anonymous, Username/Password, X.509 Certificate |
| **Auto-Retry** | Automatic reconnect on connection failures |
| **Fluent Builder API** | Chain `readMulti()`, `writeMulti()`, `createMonitoredItems()`, and `translateBrowsePaths()` calls with a fluent builder |
| **Auto-Batching** | Transparent batching for `readMulti`/`writeMulti` |
| **ExtensionObject Codecs** | Pluggable per-client codec system for custom structures |
| **Auto-Discovery** | `discoverDataTypes()` auto-detects custom structures without manual codecs |
| **MockClient** | In-memory test double — register handlers, assert calls, no TCP connection needed |
| **Logging** | Optional structured logging via any PSR-3 logger — connect, retry, errors, protocol details |
| **Cache** | Browse and resolve results cached by default (InMemoryCache, 300s TTL). Plug in any PSR-16 driver (FileCache, Laravel, Redis) |
| **Events** | 38 granular PSR-14 events — connection, session, subscription, data change, alarms, read/write, browse, cache, retry. Zero overhead when unused |
| **CLI Tool** | `opcua-cli` — browse, read, watch, and discover endpoints from the terminal. Security, JSON output, and debug logging |

## Documentation

| # | Document | Covers |
|---|----------|--------|
| 01 | [Introduction](doc/01-introduction.md) | Overview, requirements, architecture, quick start |
| 02 | [Connection & Configuration](doc/02-connection.md) | Connecting, security, authentication, timeout, retry |
| 03 | [Browsing](doc/03-browsing.md) | Address space navigation, recursive browse, path resolution |
| 04 | [Reading & Writing](doc/04-reading-writing.md) | Read/write, multi ops, batching, data types |
| 05 | [Method Call](doc/05-method-call.md) | Invoking methods, arguments, results |
| 06 | [Subscriptions](doc/06-subscriptions.md) | Subscriptions, monitored items, events, publish loop |
| 07 | [History Read](doc/07-history-read.md) | Raw, processed, and at-time historical queries |
| 08 | [Types Reference](doc/08-types.md) | All types, enums, DTOs, and constants |
| 09 | [Error Handling](doc/09-error-handling.md) | Exception hierarchy, error patterns |
| 10 | [Security](doc/10-security.md) | Security policies, certificates, crypto internals |
| 11 | [Architecture](doc/11-architecture.md) | Project structure, layers, protocol flow |
| 12 | [ExtensionObject Codecs](doc/12-extension-object-codecs.md) | Custom type decoding, codec interface, repository API |
| 13 | [Testing](doc/13-testing.md) | MockClient, DataValue factories, call tracking, test examples |
| 14 | [Events](doc/14-events.md) | PSR-14 event system, 38 events, alarm deduction, Laravel integration, examples |
| 15 | [CLI Tool](doc/15-cli.md) | Browse, read, watch, endpoints — from the terminal with security and JSON |

## Testing

940+ tests with **99%+ code coverage**. Unit tests cover encoding, crypto, protocol services, and error paths. Integration tests run against [opcua-test-server-suite](https://github.com/GianfriAur/opcua-test-server-suite) — a Docker-based OPC UA environment with multiple security configs, custom types, and real-world scenarios.

```bash
./vendor/bin/pest                                          # everything
./vendor/bin/pest tests/Unit/                              # unit only
./vendor/bin/pest tests/Integration/ --group=integration   # integration only
```

CI runs on PHP 8.2, 8.3, 8.4, and 8.5 via GitHub Actions.

## Alternatives & Comparison

### PHP

| Library | PHP | Dependencies | Security Policies | History Read | Auto-Batching | Notes |
|---------|-----|-------------|-------------------|-------------|---------------|-------|
| **gianfriaur/opcua-php-client** | 8.2+ | `ext-openssl` only | 6 (None → Aes256Sha256RsaPss) | Yes | Yes | Zero external deps, full binary protocol |
| [techdock/opcua](https://github.com/TECHDOCK-CH/php-opc-ua) | 8.4+ | phpseclib, symfony/cache, monolog, ... | Basic256Sha256 | No | Yes | Heavier dependency tree, still v0.2 |
| [techdock/opcua-webapi-client](https://packagist.org/packages/techdock/opcua-webapi-client) | 8.1+ | Guzzle | N/A (HTTP) | No | No | Needs an OPC UA WebAPI gateway, not binary protocol |
| [QuickOPC](https://opclabs.com/products/quickopc) | COM | Windows + COM | Yes | Yes | N/A | Commercial, Windows-only, not a real PHP package |

## Ecosystem

| Package | Description |
|---------|-------------|
| [opcua-php-client](https://github.com/GianfriAur/opcua-php-client) | Pure PHP OPC UA client (this package) |
| [opcua-php-client-session-manager](https://github.com/GianfriAur/opcua-php-client-session-manager) | Daemon-based session persistence across PHP requests. Keeps OPC UA connections alive between short-lived PHP processes via a ReactPHP daemon and Unix sockets. Separate package by design — see [ROADMAP.md](ROADMAP.md#session-manager-integration-here) for rationale. |
| [opcua-laravel-client](https://github.com/GianfriAur/opcua-laravel-client) | Laravel integration — service provider, facade, config |
| [opcua-test-server-suite](https://github.com/GianfriAur/opcua-test-server-suite) | Docker-based OPC UA test servers for integration testing |

## Roadmap

See [ROADMAP.md](ROADMAP.md) for what's coming next.

## Contributing

Contributions welcome — see [CONTRIBUTING.md](CONTRIBUTING.md).

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

[MIT](LICENSE)
