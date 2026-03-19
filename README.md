# OPC UA PHP Client

A pure PHP implementation of an OPC UA (Open Platform Communications Unified Architecture) client library. Communicates directly over TCP using the OPC UA binary protocol, with no external C/C++ dependencies beyond PHP's built-in `ext-openssl`.

OPC UA is a platform-independent, service-oriented architecture for industrial automation and IoT. It provides a standardized way to access data from PLCs, SCADA systems, sensors, historians, and other industrial devices. This library allows PHP applications to connect to any OPC UA-compliant server to read/write process variables, browse the address space, call methods, subscribe to data changes and events, and query historical data.

The library handles the full OPC UA communication stack: TCP transport, binary message encoding/decoding, secure channel establishment with asymmetric/symmetric encryption, session management, and all major OPC UA services. It supports six security policies (from None to Aes256Sha256RsaPss) and three authentication modes (Anonymous, Username/Password, X.509 Certificate).

> **Disclaimer:** OPC UA is a protocol based on persistent sessions and long-lived connections. PHP, by its nature, is designed for a short-lived request/response model (e.g., web requests). This makes using this library **conceptually unsuitable** for scenarios like subscription polling or continuous monitoring, which require a long-running process.\
> To mitigate this limitation, it is recommended to use this library together with [`gianfriaur/opcua-php-client-session-manager`](https://github.com/gianfriaur/opcua-php-client-session-manager), which provides session persistence and management across PHP requests.

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
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

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

// Disconnect
$client->disconnect();
```

## Features

- **Browse** - Navigate the server address space with recursive browsing, automatic continuation, and tree building
- **Path Resolution** - Resolve human-readable paths like `/Objects/MyPLC/Temperature` to NodeIds
- **Read / Write** - Single and multi read/write with all OPC UA data types
- **Method Call** - Invoke OPC UA methods with typed arguments and outputs
- **Subscriptions** - Data change and event monitoring with publish/acknowledge
- **History Read** - Raw, processed (aggregated), and at-time historical queries
- **Endpoint Discovery** - Discover available server endpoints and security policies
- **Security** - Full security stack with 6 policies (None through Aes256Sha256RsaPss)
- **Authentication** - Anonymous, Username/Password, X.509 Certificate
- **Configurable Timeout** - Customizable timeout for connection and I/O operations
- **Connection State** - Track connection lifecycle (Disconnected, Connected, Broken) with `reconnect()` support
- **Auto-Retry** - Automatic reconnect and retry on connection failures (configurable)
- **Auto-Batching** - Transparent batching for `readMulti`/`writeMulti` with automatic server limits discovery
- **ExtensionObject Codecs** - Pluggable codec system for decoding custom OPC UA structures

## Secure Connection Example

```php
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;

$client = new Client();
$client->setTimeout(10.0); // optional: custom timeout (default: 5s)
$client->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
$client->setSecurityMode(SecurityMode::SignAndEncrypt);
$client->setClientCertificate('/certs/client.pem', '/certs/client.key', '/certs/ca.pem');
$client->setUserCredentials('operator', 'secret');
$client->connect('opc.tcp://192.168.1.100:4840');
```

## Documentation

Full documentation is available in the [`doc/`](doc/) directory:

| # | Document | Description |
|---|----------|-------------|
| 01 | [Introduction](doc/01-introduction.md) | Overview, requirements, architecture, quick start |
| 02 | [Connection & Configuration](doc/02-connection.md) | Connecting, security setup, authentication methods |
| 03 | [Browsing](doc/03-browsing.md) | Navigating the address space, continuation, common NodeIds |
| 04 | [Reading & Writing](doc/04-reading-writing.md) | Read/write values, multi operations, data types, status codes |
| 05 | [Method Call](doc/05-method-call.md) | Invoking OPC UA methods, arguments, results |
| 06 | [Subscriptions](doc/06-subscriptions.md) | Subscriptions, monitored items, events, publish loop |
| 07 | [History Read](doc/07-history-read.md) | Raw, processed, and at-time historical queries |
| 08 | [Types Reference](doc/08-types.md) | Complete reference of all types, enums, and constants |
| 09 | [Error Handling](doc/09-error-handling.md) | Exception hierarchy, error patterns |
| 10 | [Security](doc/10-security.md) | Security policies, certificates, crypto internals |
| 11 | [Architecture](doc/11-architecture.md) | Project structure, layers, protocol flow, binary encoding |
| 12 | [ExtensionObject Codecs](doc/12-extension-object-codecs.md) | Custom type decoding, codec interface, repository API |

## Contributing

Contributions are welcome! See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines on how to get started.

## Testing

This library is fully tested. The test suite uses [opcua-test-server-suite](https://github.com/GianfriAur/opcua-test-server-suite) to spin up a complete OPC UA test environment covering all standard scenarios: browsing, reading, writing, method calls, subscriptions, history read, security policies, authentication modes, and error handling.

## License

[MIT](LICENSE)
