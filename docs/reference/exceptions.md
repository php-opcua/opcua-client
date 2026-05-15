---
eyebrow: 'Docs · Reference'
lede:    'Every exception the library raises, where it sits in the hierarchy, and the call sites that produce it. All extend OpcUaException — catching that handles every library failure.'

see_also:
  - { href: './client-api.md',  meta: '8 min' }
  - { href: '../recipes/disconnection-recovery.md', meta: '6 min' }
  - { href: '../recipes/service-unsupported.md',    meta: '4 min' }

prev: { label: 'Builder API', href: './builder-api.md' }
next: { label: 'Enums',       href: './enums.md' }
---

# Exceptions

All exceptions live in `PhpOpcua\Client\Exception\`. The root is
`OpcUaException`, which extends `\RuntimeException`. Catch
`OpcUaException` to match every library exception; catch the
intermediate base classes (`ConnectionException`, `SecurityException`,
`ProtocolException`, `ServiceException`) for axis-specific handling.

## The hierarchy

<!-- @code-block language="text" label="full hierarchy" -->
```text
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
        │     ├── HandshakeException                    ($errorCode)
        │     └── MessageTypeException                  ($expected, $actual)
        ├── SecurityException
        │     ├── CertificateParseException
        │     ├── OpenSslException
        │     ├── SignatureVerificationException
        │     └── UnsupportedCurveException             ($curveName)
        ├── ServiceException                            (getStatusCode())
        │     └── ServiceUnsupportedException           (BadServiceUnsupported)
        ├── UntrustedCertificateException               ($fingerprint, $certDer)
        ├── WriteTypeDetectionException
        └── WriteTypeMismatchException                  ($nodeId, $expectedType, $givenType)
```
<!-- @endcode-block -->

## Catch this, not that

<!-- @code-block language="php" label="recommended try/catch shape" -->
```php
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\SecurityException;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Exception\OpcUaException;

try {
    $client = ClientBuilder::create()->connect($url);
    $value  = $client->read($nodeId);
} catch (ConnectionException $e) {
    // Network failure, timeout, server unreachable, channel closed.
    // Recovery: retry with backoff, or reconnect().
} catch (SecurityException $e) {
    // Certificate, crypto, or trust failure. Almost always a setup bug.
    // Recovery: fix configuration, do not retry.
} catch (ServiceException $e) {
    // Server returned a top-level bad status, including ServiceFault.
    // Recovery: depends on the status code — check it.
} catch (OpcUaException $e) {
    // Anything else from the library. Likely a malformed request,
    // a server-side ServiceFault for an unknown reason, or an
    // encoding error.
}
```
<!-- @endcode-block -->

## Per-exception detail

### OpcUaException

The root. Catch this to handle "anything went wrong inside the
library". Not raised directly — only its subclasses are.

### ConnectionException

Network / channel failures: TCP open, HEL/ACK timeout, socket
disconnect, channel rejected by the server. The caller usually wants
to retry (with `setAutoRetry()`) or `reconnect()` and re-issue.

Raised by: `connect()`, every service method when the underlying
socket fails, `reconnect()` itself when the rebuild fails.

### ConfigurationException

Builder / setup mistakes. Raised at `connect()` time when the
configuration is internally inconsistent — for example, a
`SecurityMode::SignAndEncrypt` policy with no client certificate and
no auto-generation path enabled.

Always a code bug. No runtime recovery; fix the configuration.

### EncodingException

The binary codec hit a buffer underflow or an unknown type tag.
Indicates either a server bug (a non-conformant response) or a
library bug (a missing decoder branch). Open an issue with a wire
capture if you see this in production.

### InvalidNodeIdException

A string passed where `NodeId|string` was expected did not parse.
Raised by the path-vs-NodeId dispatcher when the string is neither a
valid NodeId nor a recognisable browse path.

### CacheCorruptedException

Raised by `Cache\CacheCodecInterface::decode()` when a cache payload
cannot be decoded. The client catches it internally and treats the
entry as a miss; you only see it if you call the codec yourself.
Pre-v4.3.0 cache entries are the most common source. See
[Recipes · Upgrading to v4.3](../recipes/upgrading-to-v4.3.md).

### ModuleConflictException

Two modules registered the same method name on the same client. Fix
by removing one of the duplicates, or by using
`ClientBuilder::replaceModule()` to intentionally swap.

### MissingModuleDependencyException

A module declared `requires()` for a class that is not registered.
Fix by adding the missing module to the builder.

### ProtocolException

Generic violation of the OPC UA protocol — a base class. Concrete
subclasses:

#### HandshakeException

`$errorCode` — the OPC UA status code from the server's ERR response
during HEL/ACK. The server explicitly refused the handshake. Common
causes: protocol version mismatch, buffer size negotiation failure,
server in shutdown.

#### MessageTypeException

`$expected`, `$actual` — the message type frames did not match.
Indicates the server sent an unexpected frame mid-handshake. Almost
always a protocol-level bug, on either side.

### SecurityException

Cryptographic failure or trust violation. Base class for five
concrete subclasses:

#### CertificateParseException

A certificate file is missing fields required by OPC UA (subjectAltName
URI, ExtendedKeyUsage, …) or is not parseable as X.509 at all. Fix the
certificate.

#### OpenSslException

An `openssl_*` PHP function returned `false`. The cause is in the
OpenSSL error stack at the time — the exception message captures it.
Usually means the certificate or key file is corrupt, the key does
not match the cert, or OpenSSL was built without the algorithm in
question.

#### SignatureVerificationException

Channel-level signature did not verify. Either the server sent a
malformed signed message (rare) or the keys derived from the OPN
exchange disagree (more common, indicates a cipher-suite mismatch).

#### UnsupportedCurveException

`$curveName` — the configured ECC policy requires a curve OpenSSL on
this host does not support. Check `openssl_get_curve_names()`.

#### UntrustedCertificateException

`$fingerprint`, `$certDer` — the server certificate was rejected by
the trust store. Catch this in setup tools to prompt the operator to
trust the certificate manually. See [Security ·
Trust store](../security/trust-store.md).

### ServiceException

The server returned a top-level bad status or a ServiceFault.
`getStatusCode()` returns the OPC UA status — match against the named
constants in `StatusCode`. Most calls that touch the server can raise
this; semantic per-item failures (per-node bad statuses in
`readMulti()`) come through the result instead.

#### ServiceUnsupportedException

`getStatusCode() === 0x800B0000` (`BadServiceUnsupported`). The
server does not implement the requested service set. Raised by the
first `addNodes()` / `historyReadRaw()` / etc. call on a server that
does not support those service sets. Catch it specifically to
distinguish "server lacks this capability" from "server hit a bad
status mid-call". See [Recipes · Handling unsupported
services](../recipes/service-unsupported.md).

### WriteTypeDetectionException

Auto-detect could not determine the `BuiltinType` for a write. Either
the node's `DataType` read returned a bad status, or the resolved
DataType does not map to a built-in (a structure without a registered
codec, an unknown abstract type).

Fix: register a codec, or pass `$type` explicitly to `write()`.

### WriteTypeMismatchException

`$nodeId`, `$expectedType`, `$givenType` — reserved for type-mismatch
detection. Currently not thrown by the library itself; the class
exists for use by custom validation code and a planned future
static-analysis pass.

## Status codes vs exceptions

OPC UA status codes signal a *condition*; exceptions signal an
*event*. The library applies this rule:

| Situation                                | Mechanism                          |
| ---------------------------------------- | ---------------------------------- |
| Network / channel / session failed       | Exception                          |
| Builder configuration invalid            | Exception                          |
| Encoding / decoding failed               | Exception                          |
| Server returned a top-level bad status   | Exception (`ServiceException`)     |
| Server returned a bad per-item status    | Status code in the result — **you check it** |

Per-item failures (`BadNodeIdUnknown` from a single node in
`readMulti()`) are not exceptions — they ride in the `DataValue`.
Always check `$dv->statusCode` after a successful service call.
