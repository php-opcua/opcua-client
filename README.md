<h1 align="center"><strong>OPC UA PHP Client</strong></h1>
<p align="center">
  Pure PHP OPC UA client — no external dependencies, just <code>ext-openssl</code>.
</p>

<p align="center">
  <a href="https://github.com/GianfriAur/opcua-php-client/actions/workflows/integration-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/GianfriAur/opcua-php-client/integration-tests.yml?branch=master&label=tests&style=flat-square" alt="Tests"></a>
  <a href="https://codecov.io/gh/GianfriAur/opcua-php-client"><img src="https://img.shields.io/codecov/c/github/GianfriAur/opcua-php-client?style=flat-square&logo=codecov" alt="Coverage"></a>
  <a href="https://packagist.org/packages/gianfriaur/opcua-php-client"><img src="https://img.shields.io/packagist/v/gianfriaur/opcua-php-client?style=flat-square&label=packagist" alt="Latest Version"></a>
  <!-- <a href="https://packagist.org/packages/gianfriaur/opcua-php-client"><img src="https://img.shields.io/packagist/dt/gianfriaur/opcua-php-client?style=flat-square" alt="Total Downloads"></a> -->
  <a href="https://packagist.org/packages/gianfriaur/opcua-php-client"><img src="https://img.shields.io/packagist/php-v/gianfriaur/opcua-php-client?style=flat-square" alt="PHP Version"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/GianfriAur/opcua-php-client?style=flat-square" alt="License"></a>
</p>

---

A PHP library that talks OPC UA binary protocol over TCP. It handles the full stack — transport, encoding, secure channels, sessions, crypto — so you can connect to any OPC UA server straight from PHP, without shelling out to C/C++ libraries.

OPC UA is the industry standard for accessing data from PLCs, SCADA systems, sensors, historians, and IoT devices. This library lets you read/write variables, browse the address space, call methods, subscribe to data changes and events, and query historical data.

> **Note:** OPC UA relies on persistent sessions and long-lived connections, while PHP is inherently short-lived (request/response). For use cases like subscription polling or continuous monitoring, pair this with [`gianfriaur/opcua-php-client-session-manager`](https://github.com/gianfriaur/opcua-php-client-session-manager) to persist sessions across PHP requests.

## Why this library?

- **Zero dependencies** — only `ext-openssl`, nothing from Composer in production. No phpseclib, no symfony/cache, no monolog.
- **PHP 8.2+** — runs on any modern PHP, no need for bleeding-edge 8.4.
- **Native binary protocol** — speaks OPC UA directly over TCP. No HTTP gateway, no REST bridge, no sidecar in another language.
- **Full security stack** — 6 security policies up to Aes256Sha256RsaPss, 3 authentication modes, auto-generated certificates for quick testing.
- **Batteries included** — browse, read, write, method call, subscriptions, events, history read, path resolution, auto-batching, auto-retry. Everything in one package.
- **Cross-platform** — works on Linux, macOS, and Windows. No FFI, no COM, no platform-specific extensions.
- **Laravel-ready** — drop-in integration via [`opcua-laravel-client`](https://github.com/GianfriAur/opcua-laravel-client) with service provider, facade, and config.

If your stack is PHP and you need to talk to PLCs, SCADA, or any OPC UA server, this is the shortest path from `composer require` to reading your first variable.

## Requirements

- PHP >= 8.2
- `ext-openssl`

## Installation

```bash
composer require gianfriaur/opcua-php-client
```

## Quick Start

```php
use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;

$client = new Client();
$client->connect('opc.tcp://localhost:4840');

// Read a value
$dataValue = $client->read(NodeId::numeric(0, 2259));
echo $dataValue->getValue();

// Browse the Objects folder
$refs = $client->browse(NodeId::numeric(0, 85));
foreach ($refs as $ref) {
    echo $ref->getDisplayName() . "\n";
}

// Write a value
$client->write(NodeId::numeric(2, 1001), 42, BuiltinType::Int32);

$client->disconnect();
```

## Features

| Feature | What it does |
|---|---|
| **Browse** | Navigate the address space with recursive browsing, automatic continuation, and tree building |
| **Path Resolution** | Resolve readable paths like `/Objects/MyPLC/Temperature` to NodeIds |
| **Read / Write** | Single and multi operations with all OPC UA data types |
| **Method Call** | Invoke OPC UA methods with typed arguments and outputs |
| **Subscriptions** | Data change and event monitoring with publish/acknowledge |
| **History Read** | Raw, processed (aggregated), and at-time historical queries |
| **Endpoint Discovery** | Discover available server endpoints and security policies |
| **Security** | Full security stack — 6 policies from None through Aes256Sha256RsaPss |
| **Authentication** | Anonymous, Username/Password, X.509 Certificate |
| **Configurable Timeout** | Custom timeout for connection and I/O operations |
| **Connection State** | Lifecycle tracking (Disconnected, Connected, Broken) with `reconnect()` |
| **Auto-Retry** | Automatic reconnect and retry on connection failures (configurable) |
| **Auto-Batching** | Transparent batching for `readMulti`/`writeMulti` with automatic server limits discovery |
| **ExtensionObject Codecs** | Pluggable codec system for decoding custom OPC UA structures |

## Secure Connection

```php
use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;

$client = new Client();
$client->setTimeout(10.0);
$client->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
$client->setSecurityMode(SecurityMode::SignAndEncrypt);
$client->setClientCertificate('/certs/client.pem', '/certs/client.key', '/certs/ca.pem');
$client->setUserCredentials('operator', 'secret');
$client->connect('opc.tcp://192.168.1.100:4840');
```

If you don't provide a client certificate, one gets auto-generated in memory (self-signed, RSA 2048) — handy for quick tests or servers with auto-accept.

## Documentation

Full docs live in the [`doc/`](doc/) directory:

| # | Document | Covers |
|---|----------|--------|
| 01 | [Introduction](doc/01-introduction.md) | Overview, requirements, architecture, quick start |
| 02 | [Connection & Configuration](doc/02-connection.md) | Connecting, security setup, authentication |
| 03 | [Browsing](doc/03-browsing.md) | Address space navigation, continuation, common NodeIds |
| 04 | [Reading & Writing](doc/04-reading-writing.md) | Read/write, multi ops, data types, status codes |
| 05 | [Method Call](doc/05-method-call.md) | Invoking methods, arguments, results |
| 06 | [Subscriptions](doc/06-subscriptions.md) | Subscriptions, monitored items, events, publish loop |
| 07 | [History Read](doc/07-history-read.md) | Raw, processed, and at-time historical queries |
| 08 | [Types Reference](doc/08-types.md) | All types, enums, and constants |
| 09 | [Error Handling](doc/09-error-handling.md) | Exception hierarchy, error patterns |
| 10 | [Security](doc/10-security.md) | Security policies, certificates, crypto internals |
| 11 | [Architecture](doc/11-architecture.md) | Project structure, layers, protocol flow, binary encoding |
| 12 | [ExtensionObject Codecs](doc/12-extension-object-codecs.md) | Custom type decoding, codec interface, repository API |

## Testing

The test suite runs on [Pest PHP](https://pestphp.com/) and covers both unit and integration tests. Integration tests run against [opcua-test-server-suite](https://github.com/GianfriAur/opcua-test-server-suite), a Docker-based OPC UA environment with multiple security configurations, custom types, and a broad address space designed to cover real-world scenarios.

```bash
# Everything
./vendor/bin/pest

# Unit tests only
./vendor/bin/pest tests/Unit/

# Integration tests only
./vendor/bin/pest tests/Integration/ --group=integration
```

CI runs on PHP 8.2, 8.3, and 8.4 via GitHub Actions.

## Ecosystem

This library is part of a broader OPC UA ecosystem for PHP:

| Package | Description |
|---------|-------------|
| [opcua-php-client](https://github.com/GianfriAur/opcua-php-client) | Pure PHP OPC UA client (this package) |
| [opcua-php-client-session-manager](https://github.com/GianfriAur/opcua-php-client-session-manager) | Session persistence across PHP requests |
| [opcua-laravel-client](https://github.com/GianfriAur/opcua-laravel-client) | Laravel integration — service provider, facade, config |
| [opcua-test-server-suite](https://github.com/GianfriAur/opcua-test-server-suite) | Docker-based OPC UA test servers for integration testing |

## Roadmap

See [ROADMAP.md](ROADMAP.md) for planned features and what's coming next.

## Contributing

Contributions welcome — see [CONTRIBUTING.md](CONTRIBUTING.md) for how to get started.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history.

## License

[MIT](LICENSE)
