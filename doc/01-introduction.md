# Introduction

## What is it?

`gianfriaur/opcua-php-client` is a pure PHP client for OPC UA. It speaks the binary protocol over TCP, handles secure channels, sessions, and cryptography — no C/C++ extensions required. Just PHP and `ext-openssl`.

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
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

$client = new Client();
$client->connect('opc.tcp://localhost:4840');

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

**Read & Write**
- Read and write node attributes, single or multi
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
- 6 security policies (None through Aes256Sha256RsaPss)
- 3 security modes (None, Sign, SignAndEncrypt)
- Anonymous, username/password, and X.509 certificate authentication
- PEM and DER certificate support with auto-detection

**Observability**
- PSR-3 logging — pass any compatible logger (Monolog, Laravel, etc.) for structured diagnostics
- NullLogger by default — zero overhead when logging is not needed

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
