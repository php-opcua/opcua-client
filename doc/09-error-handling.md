# Error Handling

## Exception Hierarchy

Every exception extends `RuntimeException` through a single base class:

```
RuntimeException
  └── OpcUaException
        ├── CacheCorruptedException
        ├── ConfigurationException
        ├── ConnectionException
        ├── EncodingException
        ├── InvalidNodeIdException
        ├── MissingModuleDependencyException
        ├── ModuleConflictException
        ├── ProtocolException
        │     ├── HandshakeException
        │     └── MessageTypeException
        ├── SecurityException
        │     ├── CertificateParseException
        │     ├── OpenSslException
        │     ├── SignatureVerificationException
        │     └── UnsupportedCurveException
        ├── ServiceException
        │     └── ServiceUnsupportedException
        ├── UntrustedCertificateException
        ├── WriteTypeDetectionException
        └── WriteTypeMismatchException
```

All live in `PhpOpcua\Client\Exception`.

## Recommended Try/Catch Pattern

Start here. This covers the most common failure modes in order of likelihood:

```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\SecurityException;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Exception\OpcUaException;
use PhpOpcua\Client\Types\ConnectionState;
use PhpOpcua\Client\Types\StatusCode;

$client = null;

try {
    $client = ClientBuilder::create()
        ->connect('opc.tcp://localhost:4840');

    $value = $client->read(NodeId::numeric(2, 1001));

} catch (ConnectionException $e) {
    // TCP-level failure: host unreachable, timeout, connection dropped
    // Note: if connect() itself fails, $client is null — there is no Client to reconnect
    echo "Connection failed: {$e->getMessage()}\n";

    if ($client !== null && $client->getConnectionState() === ConnectionState::Broken) {
        $client->reconnect(); // or connect() again
    }

} catch (SecurityException $e) {
    // Certificate rejected, key mismatch, encryption failure
    echo "Security error: {$e->getMessage()}\n";

} catch (ServiceException $e) {
    // Server returned an OPC UA error status code
    echo "Server error: " . StatusCode::getName($e->getStatusCode()) . "\n";
    echo "Status code: " . sprintf('0x%08X', $e->getStatusCode()) . "\n";

} catch (OpcUaException $e) {
    // Catch-all for anything else (encoding, protocol, config)
    echo "OPC UA error: {$e->getMessage()}\n";

} finally {
    $client?->disconnect();
}
```

> **Note:** Because `connect()` returns the `Client`, if it throws an exception, no `Client` instance exists. Always initialize `$client = null` before the try block and use null-safe calls (`$client?->disconnect()`) in the finally block.

> **Tip:** With auto-retry enabled (default: 1 retry after first connect), the client attempts reconnection before throwing. You only need manual recovery if auto-retry is exhausted or disabled.

> **Events:** Connection failures dispatch `ConnectionFailed`. Each retry dispatches `RetryAttempt`, and when all retries are exhausted `RetryExhausted` is dispatched. Use these events for monitoring and alerting. See [Events](14-events.md).

## Exception Types

### OpcUaException

Base class for all library exceptions. Catch this when you want a single catch-all:

```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Exception\OpcUaException;

try {
    $client = ClientBuilder::create()
        ->connect('opc.tcp://localhost:4840');
    $value = $client->read(NodeId::numeric(0, 2259));
} catch (OpcUaException $e) {
    echo "OPC UA error: {$e->getMessage()}\n";
}
```

### ServiceException

The server returned an error. This is the only exception that carries a status code:

```php
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Types\StatusCode;

try {
    $client->read(NodeId::numeric(0, 99999));
} catch (ServiceException $e) {
    $code = $e->getStatusCode();
    echo StatusCode::getName($code);        // e.g. "BadNodeIdUnknown"
    echo sprintf('0x%08X', $code);          // e.g. "0x80340000"
}
```

`ServiceException` is raised both when the server's `ResponseHeader` carries a bad status and when the server returns a top-level `ServiceFault` (NodeId `i=397`) — the latter typically means the whole service set is not implemented on that server.

### ServiceUnsupportedException

A specialization of `ServiceException` raised specifically when the `ServiceFault` carries `BadServiceUnsupported (0x800B0000)`. Lets you distinguish "this server does not implement this service set" from other service-level faults without inspecting the message:

```php
use PhpOpcua\Client\Exception\ServiceUnsupportedException;

try {
    $client->addNodes([...]);
} catch (ServiceUnsupportedException $e) {
    // Server (e.g. UA-.NETStandard) does not implement NodeManagement.
    // Fall back, skip, or switch endpoint. $e->getStatusCode() === 0x800B0000.
}
```

Since `ServiceUnsupportedException extends ServiceException`, code that already catches `ServiceException` keeps working — the subclass only matters when you want capability-specific handling.

### ConnectionException

TCP-level problems. Thrown when:

- Cannot connect to host/port
- Connection closed by remote
- Read timeout (default: 5s, configurable via `setTimeout()`)
- Failed to send data
- `"Not connected"` -- you called a method before `connect()`
- `"Connection lost"` -- state is `Broken`, call `reconnect()` or `connect()`

### ConfigurationException

Invalid setup. Thrown when:

- Invalid endpoint URL format
- Certificate or private key file not found / unreadable
- `reconnect()` called without prior `connect()`

### SecurityException

Crypto failures. Base class for all security-related exceptions. Catch this for broad security error handling, or use the specific subclasses below:

#### OpenSslException

Low-level OpenSSL failure. Thrown when an OpenSSL function returns `false` — includes the OpenSSL error string in the message. Covers: key generation, CSR signing, certificate export, encrypt/decrypt, sign/verify operations.

#### SignatureVerificationException

Thrown when a cryptographic signature does not match the expected value. This means the message was tampered with or the wrong key was used. Covers: OPN asymmetric signature (RSA and ECDSA), MSG symmetric HMAC signature.

#### UnsupportedCurveException

Thrown when an ECC operation references a curve that is not supported. Carries `$curveName` (the OpenSSL curve name that was rejected). Supported curves: `prime256v1`, `secp384r1`, `brainpoolP256r1`, `brainpoolP384r1`.

```php
use PhpOpcua\Client\Exception\UnsupportedCurveException;

try {
    $ms->generateEphemeralKeyPair('secp521r1');
} catch (UnsupportedCurveException $e) {
    echo "Curve not supported: {$e->curveName}\n";
}
```

#### CertificateParseException

Thrown when a required field is missing from a parsed X.509 certificate (e.g. `validFrom_time_t` or `validTo_time_t` absent after `openssl_x509_parse`).

### EncodingException

Binary encoding/decoding errors. Thrown when:

- Buffer underflow (not enough data)
- Invalid GUID format
- Unknown NodeId encoding byte
- Unknown variant type
- DiagnosticInfo encoding not supported

### InvalidNodeIdException

Malformed node identifiers. Thrown when parsing a string that does not match any valid NodeId format.

### ProtocolException

OPC UA protocol violations. Base class for protocol-level errors. Catch this for broad protocol error handling, or use the specific subclasses below:

- Invalid message size from transport layer

#### HandshakeException

Thrown when the server responds with an ERR message during the HEL/ACK handshake. Carries `$errorCode` (the OPC UA status code from the ERR response).

```php
use PhpOpcua\Client\Exception\HandshakeException;

try {
    $client = ClientBuilder::create()->connect('opc.tcp://server:4840');
} catch (HandshakeException $e) {
    echo "Handshake failed with code: " . sprintf('0x%08X', $e->errorCode) . "\n";
}
```

#### MessageTypeException

Thrown when the server responds with an unexpected message type. Carries `$expected` (what was expected, e.g. `'OPN'`) and `$actual` (what was received, e.g. `'MSG'`).

```php
use PhpOpcua\Client\Exception\MessageTypeException;

try {
    $client = ClientBuilder::create()->connect('opc.tcp://server:4840');
} catch (MessageTypeException $e) {
    echo "Expected {$e->expected}, got {$e->actual}\n";
}
```

### CacheCorruptedException

Raised by `Cache\WireCacheCodec` (or any other `CacheCodecInterface` implementation) when a value pulled from the PSR-16 backend cannot be decoded — typically a payload poisoned by another writer, a partial write, or an entry written by a pre-v4.3.0 codec. The client catches this internally and treats it as a cache miss, so you normally never see it; it surfaces only if you call `Cache\CacheCodecInterface::decode()` yourself.

```php
use PhpOpcua\Client\Exception\CacheCorruptedException;
use PhpOpcua\Client\Cache\CacheCodecInterface;

/** @var CacheCodecInterface $codec */
try {
    $value = $codec->decode($rawFromPsr16);
} catch (CacheCorruptedException $e) {
    // Treat as cache miss — refetch from source
}
```

> **Upgrade note:** Pre-v4.3.0 cache entries were written via `serialize()`; the new codec cannot read them and raises this exception on first access. The client refetches transparently, but flushing persistent caches at deploy time skips the cold-cache window.

### ModuleConflictException

Thrown when two `ServiceModule` instances try to register the same method name on the same client. Use `ClientBuilder::replaceModule()` to intentionally swap a built-in module with a custom one. Re-registering the same method by the same module (e.g. after a `disconnect()` / `reconnect()`) does **not** trigger this.

### MissingModuleDependencyException

Thrown when a module's `requires()` list points at a `ServiceModule` class that is not registered on the client. Either add the missing module via `ClientBuilder::addModule()` or relax the dependency on the module that declares it.

### WriteTypeDetectionException

Thrown when write type auto-detection fails. This happens when:

- Auto-detect is enabled but the node has no readable value (Variant is null)
- Auto-detect is disabled and no explicit `BuiltinType` was provided

```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Exception\WriteTypeDetectionException;

try {
    $client = ClientBuilder::create()
        ->setAutoDetectWriteType(false)
        ->connect('opc.tcp://localhost:4840');

    $client->write('ns=2;i=1001', 42); // no type provided — throws
} catch (WriteTypeDetectionException $e) {
    echo $e->getMessage();
}
```

### WriteTypeMismatchException

Reserved for type mismatch detection. Carries `$nodeId`, `$expectedType`, and `$givenType`. Currently not thrown by the library — when an explicit type is passed to `write()`, it is used directly without validation. The class exists for use in custom validation logic or future features.

## Status Codes vs Exceptions

Not every bad status code throws an exception. The library draws a clear line:

| Situation | What happens |
|-----------|-------------|
| Connection failure, protocol error, security failure | Exception thrown |
| Server-level error (ERR message) | `ServiceException` thrown |
| Per-item result from read/write/call | Status code in the result -- **you check it** |

```php
// read() does NOT throw on BadNodeIdUnknown -- it returns it in the DataValue
$dv = $client->read(NodeId::numeric(0, 99999));

if (StatusCode::isBad($dv->statusCode)) {
    echo "Read failed: " . StatusCode::getName($dv->statusCode) . "\n";
}
```

```php
// writeMulti() returns status codes per item
$results = $client->writeMulti([...]);

foreach ($results as $statusCode) {
    if (StatusCode::isBad($statusCode)) {
        // This specific write failed
    }
}
```

> **Warning:** Always check `statusCode` on `DataValue` results. A successful `read()` call (no exception) can still contain a bad status code for individual nodes.
