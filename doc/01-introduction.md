# OPC UA PHP Client - Introduction

## Overview

`gianfriaur/opcua-php-client` is a pure PHP implementation of an OPC UA (Open Platform Communications Unified Architecture) client. It communicates directly over TCP using the OPC UA binary protocol, without requiring any external C/C++ extensions or dependencies beyond PHP's built-in `ext-openssl`.

## Requirements

- PHP >= 8.2
- `ext-openssl` (for security features and certificate handling)

## Installation

```bash
composer require gianfriaur/opcua-php-client
```

## Features

- **Binary Protocol**: Full OPC UA binary encoding/decoding over TCP
- **Browse**: Navigate the server's address space with recursive browsing and automatic continuation
- **Path Resolution**: Resolve human-readable paths to NodeIds (TranslateBrowsePathsToNodeIds)
- **Read/Write**: Read and write node attribute values (single and multi)
- **Method Call**: Invoke OPC UA methods on the server
- **Subscriptions**: Create subscriptions with data change and event monitoring
- **History Read**: Read raw, processed, and at-time historical data
- **Endpoint Discovery**: Discover available server endpoints
- **Security**: Support for multiple security policies and modes
  - SecurityPolicy: None, Basic128Rsa15, Basic256, Basic256Sha256, Aes128Sha256RsaOaep, Aes256Sha256RsaPss
  - SecurityMode: None, Sign, SignAndEncrypt
- **Authentication**: Anonymous, Username/Password, X.509 Certificate
- **Certificate Management**: PEM/DER loading, thumbprint, public key extraction
- **Configurable Timeout**: Customizable timeout for connection and I/O operations
- **Connection State Management**: Track connection state (Disconnected, Connected, Broken) with `reconnect()` support
- **Auto-Retry**: Automatic reconnect and retry on connection failures (configurable, default: 1 retry after first connect)
- **Auto-Batching**: Transparent batching for `readMulti`/`writeMulti` with automatic server operation limits discovery

## Architecture

```
Client (main entry point)
  |
  +-- Transport/TcpTransport        (TCP socket communication)
  +-- Protocol/*Service              (OPC UA service encoding/decoding)
  +-- Encoding/BinaryEncoder         (binary serialization)
  +-- Encoding/BinaryDecoder         (binary deserialization)
  +-- Security/SecureChannel         (message-level security)
  +-- Security/MessageSecurity       (crypto operations)
  +-- Security/CertificateManager    (certificate handling)
  +-- Types/*                        (OPC UA data types)
  +-- Exception/*                    (error hierarchy)
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
if (StatusCode::isGood($dataValue->getStatusCode())) {
    echo "Server status: " . $dataValue->getValue();
}

// Browse the Objects folder
$references = $client->browse(NodeId::numeric(0, 85));
foreach ($references as $ref) {
    echo $ref->getDisplayName() . "\n";
}

$client->disconnect();
```
