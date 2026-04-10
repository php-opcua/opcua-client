<h1 align="center"><strong>OPC UA PHP Client</strong></h1>

<div align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="assets/logo-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset="assets/logo-light.svg">
    <img alt="OPC UA PHP Client" src="assets/logo-light.svg" width="435">
  </picture>
</div>

<p align="center">
  <a href="https://github.com/php-opcua/opcua-client/actions/workflows/tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/php-opcua/opcua-client/tests.yml?branch=master&label=tests&style=flat-square" alt="Tests"></a>
  <a href="https://codecov.io/gh/php-opcua/opcua-client"><img src="https://img.shields.io/codecov/c/github/php-opcua/opcua-client?style=flat-square&logo=codecov" alt="Coverage"></a>
  <a href="https://packagist.org/packages/php-opcua/opcua-client"><img src="https://img.shields.io/packagist/v/php-opcua/opcua-client?style=flat-square&label=packagist" alt="Latest Version"></a>
  <!-- <a href="https://packagist.org/packages/php-opcua/opcua-client"><img src="https://img.shields.io/packagist/dt/php-opcua/opcua-client?style=flat-square" alt="Total Downloads"></a> -->
  <a href="https://packagist.org/packages/php-opcua/opcua-client"><img src="https://img.shields.io/packagist/php-v/php-opcua/opcua-client?style=flat-square" alt="PHP Version"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/php-opcua/opcua-client?style=flat-square" alt="License"></a>
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
- **Secure everything** — 10 security policies: 6 RSA (plaintext to AES-256 with RSA-PSS) + 4 ECC (NIST P-256/P-384 and Brainpool P-256/P-384 with ECDSA/ECDH), plus anonymous, username/password, or X.509 certificate authentication <sup>*</sup>

All of this with zero external dependencies beyond `ext-openssl`, and full support for PHP 8.2 through 8.5.

> **Note:** OPC UA relies on persistent sessions and long-lived connections. PHP's request/response model means connections are short-lived by default. For use cases like continuous monitoring or subscription polling, pair this with [`opcua-session-manager`](https://github.com/php-opcua/opcua-session-manager) to persist sessions across requests — or use it in a long-running worker process.
>
> The session manager is a **separate package by design** — it runs as a daemon process using ReactPHP and Unix sockets, which would break this library's zero-dependency, cross-platform philosophy if bundled here. See the [Ecosystem](#ecosystem) section for details.

<sup>*</sup> **ECC note:** The 4 ECC policies are implemented per OPC UA 1.05 spec but should be considered **experimental**. No commercial OPC UA server vendor has released devices with ECC endpoints yet — this is an ecosystem-wide gap. ECC support has been developed and tested exclusively against [UA-.NETStandard](https://github.com/OPCFoundation/UA-.NETStandard) (the OPC Foundation reference implementation). The implementation follows the 1.05.3 specification and is aligned with 1.05.4 regarding `ReceiverCertificateThumbprint` and HKDF salt encoding. Two ECC-specific changes from 1.05.4 (per-message IV and LegacySequenceNumbers) are not yet implemented — see the [ECC 1.05.4 Compliance](ROADMAP.md#ecc-1054-compliance) section in the roadmap for a detailed analysis. For production deployments, use the RSA policies. If you ever manage to connect this library to a real industrial device with ECC OPC UA, let us know — we owe you a coffee :) See the [Security documentation](doc/10-security.md) for details.

<table>
<tr>
<td>

### Tested against the OPC UA reference implementation

This library is integration-tested against **[UA-.NETStandard](https://github.com/OPCFoundation/UA-.NETStandard)** — the **reference implementation** maintained by the OPC Foundation, the organization that defines the OPC UA specification. This is the same stack used by major industrial vendors to certify their products.

1300+ tests (1040+ unit, 250+ integration) run via [uanetstandard-test-suite](https://github.com/php-opcua/uanetstandard-test-suite) against 8 server instances covering every security policy, authentication method, data type, method call, subscription, event, alarm, and historical read defined by the spec — with 99%+ unit test code coverage.

**This library is already used in production with real industrial equipment** in factory automation and process control environments.

</td>
</tr>
</table>

----

## Quick Start

```bash
composer require php-opcua/opcua-client
```

```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Types\NodeId;

$client = ClientBuilder::create()
    ->connect('opc.tcp://localhost:4840');

// Read server status — string format
$status = $client->read('i=2259');
echo $status->getValue(); // 0 = Running

// NodeId objects work too
$status = $client->read(NodeId::numeric(0, 2259));

$client->disconnect();
```

That's it. Three lines to build, connect, and read. No config files, no service containers, no XML.

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
use PhpOpcua\Client\Types\BuiltinType;

// Auto-detect type (reads the node first, caches the type)
$client->write('ns=2;i=1001', 42);

// Explicit type (validated against the node when auto-detect is on)
$client->write('ns=2;i=1001', 42, BuiltinType::Int32);
```

### Call a method on the server

```php
use PhpOpcua\Client\Types\Variant;

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
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Security\SecurityMode;

$client = ClientBuilder::create()
    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
    ->setSecurityMode(SecurityMode::SignAndEncrypt)
    ->setClientCertificate('/certs/client.pem', '/certs/client.key', '/certs/ca.pem')
    ->setUserCredentials('operator', 'secret')
    ->connect('opc.tcp://192.168.1.100:4840');
```

> **Tip:** Skip `setClientCertificate()` and a self-signed cert gets auto-generated in memory — perfect for quick tests or servers with auto-accept.

### Decode custom structures with codecs

```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Repository\ExtensionObjectRepository;

$repo = new ExtensionObjectRepository();
$repo->register(NodeId::numeric(2, 5001), MyPointCodec::class);

$client = ClientBuilder::create($repo)
    ->connect('opc.tcp://localhost:4840');

$point = $client->read($pointNodeId)->getValue();
// ['x' => 1.5, 'y' => 2.5, 'z' => 3.5]
```

Each client gets its own isolated codec registry — no global state, no cross-contamination.

### Test without a real server

```php
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\DataValue;

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
use PhpOpcua\Client\ClientBuilder;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('opcua');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

$client = ClientBuilder::create()
    ->setLogger($logger)
    ->connect('opc.tcp://localhost:4840');
// Logs: handshake, secure channel, session creation, reads, retries, errors...
```

Any [PSR-3](https://www.php-fig.org/psr/psr-3/) logger works — Monolog, Laravel's logger, or your own. Without one, logging is silently disabled (`NullLogger`).

### React to events (PSR-14)

```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Event\DataChangeReceived;
use PhpOpcua\Client\Event\AlarmActivated;

// Set any PSR-14 event dispatcher on the builder
$client = ClientBuilder::create()
    ->setEventDispatcher($yourDispatcher)
    ->connect('opc.tcp://localhost:4840');

// In your listener:
class HandleDataChange {
    public function __invoke(DataChangeReceived $event): void {
        echo "Node changed on subscription {$event->subscriptionId}: "
            . $event->dataValue->getValue() . "\n";
    }
}
```

47 granular events covering connection, session, subscription, data change, alarms, read/write, browse, cache, and retry. Zero overhead with the default `NullEventDispatcher`. See [Events documentation](doc/14-events.md) for the full list.

### Monitor alarms in real time

```php
use PhpOpcua\Client\Event\AlarmActivated;
use PhpOpcua\Client\Event\AlarmSeverityChanged;

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
composer require php-opcua/opcua-cli
```

```bash
# Browse the address space
opcua-cli browse opc.tcp://192.168.1.10:4840 /Objects

# Read a value
opcua-cli read opc.tcp://192.168.1.10:4840 "ns=2;i=1001"

# Watch a value in real time
opcua-cli watch opc.tcp://192.168.1.10:4840 "ns=2;i=1001"

# Discover endpoints
opcua-cli endpoints opc.tcp://192.168.1.10:4840
```

Full security support, JSON output, debug logging, NodeSet2.xml code generation, and more. See [`php-opcua/opcua-cli`](https://github.com/php-opcua/opcua-cli) for full documentation.

### Trust server certificates

```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\TrustStore\FileTrustStore;
use PhpOpcua\Client\TrustStore\TrustPolicy;

$client = ClientBuilder::create()
    ->setTrustStore(new FileTrustStore())           // ~/.opcua/trusted/
    ->setTrustPolicy(TrustPolicy::Fingerprint)      // or FingerprintAndExpiry, Full
    ->connect('opc.tcp://192.168.1.100:4840');       // throws UntrustedCertificateException if not trusted
```

Trust on first use (TOFU):

```php
$builder = ClientBuilder::create()
    ->setTrustStore(new FileTrustStore())
    ->autoAccept(true);                    // accept new certificates
    // ->autoAccept(true, force: true);    // also accept changed certificates
$client = $builder->connect('opc.tcp://192.168.1.100:4840');
```

Disable trust validation:

```php
$client = ClientBuilder::create()
    ->setTrustPolicy(null)                // no trust policy
    ->connect('opc.tcp://192.168.1.100:4840');
```

Or manage from the CLI with [`php-opcua/opcua-cli`](https://github.com/php-opcua/opcua-cli):

```bash
opcua-cli trust opc.tcp://server:4840          # download and trust
opcua-cli trust:list                            # list trusted certs
opcua-cli trust:remove AB:CD:12:34:...          # remove a cert
```

### Auto-discover custom types

```php
$client = ClientBuilder::create()
    ->connect('opc.tcp://localhost:4840');
$client->discoverDataTypes();

$point = $client->read($pointNodeId)->getValue();
// ['x' => 1.5, 'y' => 2.5, 'z' => 3.5] — no codec needed
```

### Use pre-built OPC UA companion types

Instead of writing codecs by hand or relying on runtime discovery, install [`opcua-client-nodeset`](https://github.com/php-opcua/opcua-client-nodeset) to get pre-generated PHP types for 51 OPC Foundation companion specifications — DI, Robotics, Machinery, MachineTool, ISA-95, CNC, MTConnect, and many more:

```bash
composer require php-opcua/opcua-client-nodeset
```

```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Nodeset\Robotics\RoboticsRegistrar;
use PhpOpcua\Nodeset\Robotics\RoboticsNodeIds;
use PhpOpcua\Nodeset\Robotics\Enums\OperationalModeEnumeration;

$client = ClientBuilder::create()
    ->loadGeneratedTypes(new RoboticsRegistrar())  // loads DI + IA dependencies automatically
    ->connect('opc.tcp://192.168.1.100:4840');

// Enum values are auto-cast to PHP BackedEnum
$mode = $client->read(RoboticsNodeIds::OperationalMode)->getValue();
// OperationalModeEnumeration::MANUAL_REDUCED_SPEED (not int 1)

// Structured types return typed DTOs with property access
$data = $client->read(RoboticsNodeIds::SomeStructuredNode)->getValue();
$data->Manufacturer;   // string — IDE autocomplete works
$data->Status;         // OperatingStateEnum — not a raw int
```

Each Registrar automatically loads its NodeSet dependencies. Use `only: true` to skip dependency loading if you manage them yourself.

> **Tip:** You can also generate types from your own custom NodeSet2.xml files using [`opcua-cli generate:nodeset`](https://github.com/php-opcua/opcua-cli).

## Why This Library?

- **Zero runtime dependencies** — only `ext-openssl`. Optional PSR-3 logging, PSR-16 caching, and PSR-14 events via any compatible implementation.
- **PHP 8.2+** — runs on any modern PHP.
- **Native binary protocol** — speaks OPC UA directly over TCP. No HTTP gateway, no REST bridge, no sidecar.
- **Full security stack** — 10 policies: 6 RSA up to Aes256Sha256RsaPss + 4 ECC (NIST and Brainpool), 3 auth modes, auto-generated certs, persistent certificate trust store with TOFU.
- **Industrial-ready** — server certificate trust management, alarm event deduction, subscription recovery, auto-retry — built for certified industrial deployments.
- **Batteries included** — browse, read, write, call, subscriptions, events, history, path resolution, batching, retry, CLI tool.
- **Cross-platform** — Linux, macOS, Windows. No FFI, no COM.
- **Thoroughly tested** — 1300+ tests (1040+ unit, 250+ integration), 99%+ unit test code coverage across PHP 8.2, 8.3, 8.4, and 8.5.
- **Typed everywhere** — all service responses return `public readonly` DTOs, not arrays.
- **Session persistence** — keep OPC UA connections alive across PHP requests via [`opcua-session-manager`](https://github.com/php-opcua/opcua-session-manager).
- **Laravel-ready** — drop-in via [`opcua-laravel-client`](https://github.com/php-opcua/laravel-opcua).

## Features

| Feature | What it does |
|---|---|
| **Browse** | Navigate the address space — recursive, automatic continuation, tree building |
| **Path Resolution** | Resolve `/Objects/MyPLC/Temperature` to a NodeId in one call |
| **Read / Write** | Single and multi operations, all OPC UA data types, automatic type detection with caching |
| **Method Call** | Invoke server methods with typed arguments and results |
| **Subscriptions** | Data change and event monitoring with publish/acknowledge, modify monitored items, conditional triggering |
| **Transfer & Recovery** | Transfer subscriptions across sessions and republish unacknowledged notifications |
| **History Read** | Raw, processed (aggregated), and at-time historical queries |
| **Endpoint Discovery** | Discover available endpoints and security policies |
| **Security** | 10 policies: 6 RSA (None through Aes256Sha256RsaPss) + 4 ECC (NIST P-256/P-384, Brainpool P-256/P-384) |
| **Authentication** | Anonymous, Username/Password, X.509 Certificate |
| **Auto-Retry** | Automatic reconnect on connection failures |
| **Fluent Builder API** | Chain `readMulti()`, `writeMulti()`, `createMonitoredItems()`, and `translateBrowsePaths()` calls with a fluent builder |
| **Auto-Batching** | Transparent batching for `readMulti`/`writeMulti` |
| **ExtensionObject Codecs** | Pluggable per-client codec system for custom structures |
| **Auto-Discovery** | `discoverDataTypes()` auto-detects custom structures without manual codecs |
| **MockClient** | In-memory test double — register handlers, assert calls, no TCP connection needed |
| **Logging** | Optional structured logging via any PSR-3 logger — connect, retry, errors, protocol details |
| **Cache** | Browse, resolve, and metadata read results cached (InMemoryCache, 300s TTL). Plug in any PSR-16 driver (FileCache, Laravel, Redis). Metadata cache opt-in via `setReadMetadataCache(true)` |
| **Events** | 47 granular PSR-14 events — connection, session, subscription, data change, alarms, read/write, browse, cache, retry. Zero overhead when unused |
| **Trust Store** | Persistent server certificate validation — file-based trust store, 3 policies (fingerprint/expiry/full CA chain), TOFU auto-accept, CLI management |
| **CLI Tool** | [`opcua-cli`](https://github.com/php-opcua/opcua-cli) — browse, read, write, watch, discover endpoints, manage trusted certificates, and generate code from NodeSet2.xml (separate package) |

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
| 14 | [Events](doc/14-events.md) | PSR-14 event system, 47 events, alarm deduction, Laravel integration, examples |
| 15 | [Trust Store](doc/15-trust-store.md) | Server certificate trust management, policies, TOFU |

## Testing

1300+ tests with **99%+ code coverage**. Unit tests cover encoding, crypto, protocol services, and error paths. Integration tests run against [uanetstandard-test-suite](https://github.com/php-opcua/uanetstandard-test-suite) — a Docker-based OPC UA environment built on the OPC Foundation's UA-.NETStandard reference implementation, with multiple security configs, custom types, and real-world scenarios.

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
| **php-opcua/opcua-client** | 8.2+ | `ext-openssl` only | 10 (6 RSA + 4 ECC) | Yes | Yes | Zero external deps, full binary protocol |
| [techdock/opcua](https://github.com/TECHDOCK-CH/php-opc-ua) | 8.4+ | phpseclib, symfony/cache, monolog, ... | Basic256Sha256 | No | Yes | Heavier dependency tree, still v0.2 |
| [techdock/opcua-webapi-client](https://packagist.org/packages/techdock/opcua-webapi-client) | 8.1+ | Guzzle | N/A (HTTP) | No | No | Needs an OPC UA WebAPI gateway, not binary protocol |
| [QuickOPC](https://opclabs.com/products/quickopc) | COM | Windows + COM | Yes | Yes | N/A | Commercial, Windows-only, not a real PHP package |

## Ecosystem

| Package | Description |
|---------|-------------|
| [opcua-client](https://github.com/php-opcua/opcua-client) | Pure PHP OPC UA client (this package) |
| [opcua-cli](https://github.com/php-opcua/opcua-cli) | CLI tool — browse, read, write, watch, discover endpoints, manage certificates, generate code from NodeSet2.xml |
| [opcua-session-manager](https://github.com/php-opcua/opcua-session-manager) | Daemon-based session persistence across PHP requests. Keeps OPC UA connections alive between short-lived PHP processes via a ReactPHP daemon and Unix sockets. Separate package by design — see [ROADMAP.md](ROADMAP.md#session-manager-integration-here) for rationale. |
| [opcua-client-nodeset](https://github.com/php-opcua/opcua-client-nodeset) | Pre-generated PHP types from 51 OPC Foundation companion specifications (DI, Robotics, Machinery, MachineTool, ISA-95, CNC, MTConnect, and more). 807 PHP files — NodeId constants, enums, typed DTOs, codecs, registrars with automatic dependency resolution. Just `composer require` and `loadGeneratedTypes()`. |
| [laravel-opcua](https://github.com/php-opcua/laravel-opcua) | Laravel integration — service provider, facade, config |
| [uanetstandard-test-suite](https://github.com/php-opcua/uanetstandard-test-suite) | Docker-based OPC UA test servers (UA-.NETStandard) for integration testing |

## AI-Ready

This package ships with machine-readable documentation designed for AI coding assistants (Claude, Cursor, Copilot, ChatGPT, and others). Feed these files to your AI so it knows how to use the library correctly:

| File | Purpose |
|------|---------|
| [`llms.txt`](llms.txt) | Compact project summary — architecture, key classes, API signatures, and configuration. Optimized for LLM context windows with minimal token usage. |
| [`llms-full.txt`](llms-full.txt) | Comprehensive technical reference — every class, method, DTO, encoding detail, security layer, and protocol service. For deep dives and complex questions. |
| [`llms-skills.md`](llms-skills.md) | Task-oriented recipes — step-by-step instructions for common tasks (connect, read, write, browse, subscribe, security, testing, Laravel integration). Written so an AI can generate correct, production-ready code from a user's intent. |

**How to use:** copy the files you need into your project's AI configuration directory. The files are located in `vendor/php-opcua/opcua-client/` after `composer install`.

- **Claude Code**: reference per-session with `--add-file vendor/php-opcua/opcua-client/llms-skills.md`
- **Cursor**: copy into your project's rules directory — `cp vendor/php-opcua/opcua-client/llms-skills.md .cursor/rules/opcua-client.md`
- **GitHub Copilot**: copy or append the content into your project's `.github/copilot-instructions.md` file (create the file and directory if they don't exist). Copilot reads this file automatically for project-specific context
- **Other tools**: paste the content into your system prompt, project knowledge base, or context configuration

## Roadmap

See [ROADMAP.md](ROADMAP.md) for what's coming next.

## Contributing

Contributions welcome — see [CONTRIBUTING.md](CONTRIBUTING.md).

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

[MIT](LICENSE)
