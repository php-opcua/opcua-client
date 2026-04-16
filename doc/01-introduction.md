# Introduction

## What is it?

`php-opcua/opcua-client` is a pure PHP client for OPC UA. It speaks the binary protocol over TCP, handles secure channels, sessions, and cryptography — no C/C++ extensions required. Just PHP and `ext-openssl`.

## Requirements

- PHP >= 8.2
- `ext-openssl`

## Installation

```bash
composer require php-opcua/opcua-client
```

## Quick Start

```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;

$client = ClientBuilder::create()
    ->connect('opc.tcp://localhost:4840');

// Read a value
$dataValue = $client->read(NodeId::numeric(0, 2259));

if (StatusCode::isGood($dataValue->statusCode)) {
    echo "Server status: " . $dataValue->getValue();
}

// Browse the Objects folder
$references = $client->browse(NodeId::numeric(0, 85));

foreach ($references as $ref) {
    echo $ref->displayName . "\n";
}

$client->disconnect();
```

## Features

**Protocol & Transport**
- Full OPC UA binary encoding/decoding over TCP
- Configurable timeouts for connection and I/O

**Browsing & Navigation**
- Browse the server address space with automatic continuation
- Recursive browsing with cycle detection
- Path resolution via `TranslateBrowsePathsToNodeIds` — turn `"/Objects/MyPLC/Temperature"` into a NodeId
- Cache for browse and resolve results — InMemoryCache (default) and FileCache included

**Read & Write**
- Read and write node attributes, single or multi
- Server BuildInfo — `getServerBuildInfo()` returns product name, manufacturer, version, build number, and build date in one call
- Automatic batching when the server imposes per-request limits
- Human-readable NodeId strings — all methods accept `'i=2259'` or `'ns=2;s=MyNode'` in addition to NodeId objects

**Advanced**
- Method calls on the server
- Subscriptions with data change and event monitoring
- History reads (raw, processed, at-time)
- Endpoint discovery
- Pluggable ExtensionObject codecs for custom structures
- Automatic DataType discovery — auto-detect and decode custom structures without writing codecs

**Security**
- 10 security policies: 6 RSA (None through Aes256Sha256RsaPss) + 4 ECC (EccNistP256, EccNistP384, EccBrainpoolP256r1, EccBrainpoolP384r1)
- 3 security modes (None, Sign, SignAndEncrypt)
- Anonymous, username/password, and X.509 certificate authentication
- PEM and DER certificate support with auto-detection
- Persistent server certificate trust store with configurable validation policies
- TOFU (Trust On First Use) auto-accept for new certificates

**Observability**
- PSR-3 logging — pass any compatible logger (Monolog, Laravel, etc.) for structured diagnostics
- NullLogger by default — zero overhead when logging is not needed
- PSR-14 events — 47 granular events dispatched at lifecycle points (connection, session, subscription, data change, alarms, read/write, write type detection, browse, cache, retry, trust store)
- NullEventDispatcher by default — zero overhead when events are not needed

**Testing**
- MockClient for testing — implements `OpcUaClientInterface` with no TCP connection, register handlers, assert calls

**Reliability**
- Connection state tracking (Disconnected, Connected, Broken)
- `reconnect()` for re-establishing dropped connections
- Auto-retry on connection failures

## Architecture

```
Client (main entry point)
  |
  +-- Transport/TcpTransport        TCP socket communication
  +-- Protocol/*Service              OPC UA service encoding/decoding
  +-- Encoding/BinaryEncoder         Binary serialization
  +-- Encoding/BinaryDecoder         Binary deserialization
  +-- Security/SecureChannel         Message-level security
  +-- Security/MessageSecurity       Crypto operations
  +-- Security/CertificateManager    Certificate handling
  +-- Types/*                        OPC UA data types
  +-- Exception/*                    Error hierarchy
```
