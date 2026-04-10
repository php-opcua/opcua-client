# Connection & Configuration

## Connecting

```php
use PhpOpcua\Client\ClientBuilder;

$client = ClientBuilder::create()
    ->connect('opc.tcp://localhost:4840');

// ... your operations ...

$client->disconnect();
```

The two-phase pattern is: **ClientBuilder configures**, **connect() returns Client**. All configuration setters live on `ClientBuilder` and return `$this` for fluent chaining. The `connect()` method creates and returns the `Client` instance.

Behind the scenes, `connect()` does the following:

1. Parses the endpoint URL (host + port, default 4840)
2. Discovers the server certificate via GetEndpoints (when security is configured)
3. Opens the TCP connection
4. Runs the OPC UA Hello/Acknowledge handshake
5. Opens a secure channel
6. Creates and activates a session
7. Reads server operation limits for auto-batching (unless disabled with `setBatchSize(0)`)

## Timeout

The default timeout is **5 seconds** for both TCP connection and socket I/O.

```php
$client = ClientBuilder::create()
    ->setTimeout(10.0) // 10 seconds
    ->connect('opc.tcp://localhost:4840');
```

This applies to every operation: handshake, secure channel, browse, read, write, and so on. If exceeded, a `ConnectionException` is thrown with "Read timeout".

> **Tip:** Bump the timeout for high-latency networks or slow PLCs. For fast local connections, you can safely lower it.

## Connection State

The client tracks its lifecycle through `ConnectionState`:

```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Types\ConnectionState;

$client = ClientBuilder::create()
    ->connect('opc.tcp://localhost:4840');

$client->getConnectionState(); // ConnectionState::Connected
$client->isConnected();        // true

$client->disconnect();
$client->getConnectionState(); // ConnectionState::Disconnected
```

| State | Meaning |
|-------|---------|
| `Disconnected` | Never connected, or cleanly disconnected |
| `Connected` | Up and running |
| `Broken` | Connection was lost (timeout, remote close, etc.) |

When you call an operation on a non-connected client, the error message reflects the state:

- **Disconnected** -- `"Not connected: call connect() first"`
- **Broken** -- `"Connection lost: call reconnect() or connect() to re-establish"`

## Reconnecting

If the connection drops, `reconnect()` does a full disconnect/connect cycle using the last endpoint URL:

```php
$client = ClientBuilder::create()
    ->connect('opc.tcp://localhost:4840');

// ... connection drops ...

$client->reconnect();
```

> **Note:** `reconnect()` throws `ConfigurationException` if you never called `connect()`. After an explicit `disconnect()`, the endpoint URL is cleared -- use `connect()` with a URL instead.

## Auto-Retry

The client can automatically reconnect and retry when a `ConnectionException` occurs during an operation:

```php
$client = ClientBuilder::create()
    ->setAutoRetry(3) // retry up to 3 times
    ->connect('opc.tcp://localhost:4840');

// If this fails due to a broken connection, the client will:
// 1. Mark the state as Broken
// 2. Call reconnect()
// 3. Retry the operation
// ... up to 3 times before giving up
$value = $client->read(NodeId::numeric(0, 2259));
```

**Defaults:**
- **0 retries** if never connected or after `disconnect()`
- **1 retry** once you have connected at least once

To disable auto-retry entirely:

```php
$client = ClientBuilder::create()
    ->setAutoRetry(0)
    ->connect('opc.tcp://localhost:4840');
```

> **Note:** Auto-retry only applies to operations (read, write, browse, etc.), not to `connect()` itself. After `disconnect()`, there is no endpoint to reconnect to, so retry is off.

## Security

### Security Policy & Mode

```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Security\SecurityMode;

$client = ClientBuilder::create()
    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
    ->setSecurityMode(SecurityMode::SignAndEncrypt)
    ->connect('opc.tcp://localhost:4840');
```

**Available policies:**

| Policy | URI |
|--------|-----|
| `None` | `http://opcfoundation.org/UA/SecurityPolicy#None` |
| `Basic128Rsa15` | `http://opcfoundation.org/UA/SecurityPolicy#Basic128Rsa15` |
| `Basic256` | `http://opcfoundation.org/UA/SecurityPolicy#Basic256` |
| `Basic256Sha256` | `http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256` |
| `Aes128Sha256RsaOaep` | `http://opcfoundation.org/UA/SecurityPolicy#Aes128_Sha256_RsaOaep` |
| `Aes256Sha256RsaPss` | `http://opcfoundation.org/UA/SecurityPolicy#Aes256_Sha256_RsaPss` |
| `EccNistP256` | `http://opcfoundation.org/UA/SecurityPolicy#ECC_nistP256` |
| `EccNistP384` | `http://opcfoundation.org/UA/SecurityPolicy#ECC_nistP384` |
| `EccBrainpoolP256r1` | `http://opcfoundation.org/UA/SecurityPolicy#ECC_brainpoolP256r1` |
| `EccBrainpoolP384r1` | `http://opcfoundation.org/UA/SecurityPolicy#ECC_brainpoolP384r1` |

> **ECC:** The ECC policies use ECDH key agreement instead of RSA encryption. ECC endpoints require ECC certificates and a separate server endpoint. When no client certificate is provided, the library auto-generates an ECC certificate matching the policy curve. Brainpool curves are the European alternative to NIST curves, required by BSI TR-03116 and other EU regulations.

**Available modes:**

| Mode | Value | Effect |
|------|-------|--------|
| `None` | 1 | No security |
| `Sign` | 2 | Messages are signed |
| `SignAndEncrypt` | 3 | Messages are signed and encrypted |

### Client Certificate

Required for any security policy other than `None`:

```php
$builder = ClientBuilder::create()
    ->setClientCertificate(
        '/path/to/client-cert.pem',   // or .der
        '/path/to/client-key.pem',
        '/path/to/ca-cert.pem'        // optional
    );
```

Both PEM and DER formats are supported -- the library auto-detects.

## Authentication

### Anonymous (default)

Nothing to configure. Anonymous authentication is used by default.

### Username & Password

```php
$builder = ClientBuilder::create()
    ->setUserCredentials('myuser', 'mypassword');
```

When security is active:
- **RSA policies:** The password is encrypted with the server's RSA public key before transmission.
- **ECC policies:** The password is encrypted via the `EccEncryptedSecret` protocol (ECDH key agreement + AES encryption + ECDSA signature).

### X.509 Certificate

```php
$builder = ClientBuilder::create()
    ->setUserCertificate(
        '/path/to/user-cert.pem',
        '/path/to/user-key.pem'
    );
```

## Full Secure Connection Example

```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Security\SecurityMode;

$client = ClientBuilder::create()
    ->setTimeout(10.0)
    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
    ->setSecurityMode(SecurityMode::SignAndEncrypt)
    ->setClientCertificate(
        '/certs/client.pem',
        '/certs/client.key',
        '/certs/ca.pem'
    )
    ->setUserCredentials('operator', 'secret123')
    ->connect('opc.tcp://192.168.1.100:4840/UA/Server');

// ... secure operations ...

$client->disconnect();
```

## Server Certificate Trust

By default the client accepts any server certificate. For industrial deployments, enable trust validation:

```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\TrustStore\FileTrustStore;
use PhpOpcua\Client\TrustStore\TrustPolicy;

$client = ClientBuilder::create()
    ->setTrustStore(new FileTrustStore())           // ~/.opcua/trusted/
    ->setTrustPolicy(TrustPolicy::Fingerprint)
    ->connect('opc.tcp://192.168.1.100:4840');
```

If the server certificate is not in the trust store, `UntrustedCertificateException` is thrown during `connect()`. Use `autoAccept(true)` on the builder for TOFU or `setTrustPolicy(null)` to disable.

> **Tip:** See [Trust Store](16-trust-store.md) for the full guide — policies, TOFU, CLI commands, events.

## Endpoint Discovery

Discover what security and authentication options the server supports:

```php
$client = ClientBuilder::create()
    ->connect('opc.tcp://localhost:4840');

$endpoints = $client->getEndpoints('opc.tcp://localhost:4840');

foreach ($endpoints as $ep) {
    echo "URL: " . $ep->endpointUrl . "\n";
    echo "Security: " . $ep->securityPolicyUri . "\n";
    echo "Mode: " . $ep->securityMode . "\n";

    foreach ($ep->userIdentityTokens as $token) {
        echo "  Auth: " . $token->policyId
            . " (type=" . $token->tokenType . ")\n";
    }
}
```

**Token types:** `0` = Anonymous, `1` = Username/Password, `2` = X.509 Certificate

## Logging

The client supports [PSR-3](https://www.php-fig.org/psr/psr-3/) logging. Pass any compatible logger to get structured diagnostics about connection lifecycle, protocol events, and errors. When no logger is provided, a `NullLogger` is used — zero overhead.

### Setting up a logger

Pass a logger to the builder:

```php
use PhpOpcua\Client\ClientBuilder;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('opcua');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

$client = ClientBuilder::create()
    ->setLogger($logger)
    ->connect('opc.tcp://localhost:4840');
```

### Laravel integration

Laravel's logger is PSR-3 compatible out of the box:

```php
$client = ClientBuilder::create()
    ->setLogger(app('log'))
    ->connect('opc.tcp://localhost:4840');
```

### What gets logged

| Level | Events |
|-------|--------|
| `DEBUG` | Hello/Acknowledge handshake, secure channel open/renew, session create/activate |
| `INFO` | Successful connect and disconnect, batch splits during multi-operations |
| `WARNING` | Auto-retry attempts, server-imposed operation limits |
| `ERROR` | Connection failures, socket errors, protocol violations |

### Disabling logging

Logging is off by default (uses `NullLogger`). To explicitly disable it on the builder:

```php
use Psr\Log\NullLogger;

$builder->setLogger(new NullLogger());
```

## Events (PSR-14)

The client dispatches [PSR-14](https://www.php-fig.org/psr/psr-14/) events at every lifecycle point. Inject any compatible dispatcher to react to connections, disconnections, retries, and more.

```php
use PhpOpcua\Client\ClientBuilder;
use Psr\EventDispatcher\EventDispatcherInterface;

// Via builder
$client = ClientBuilder::create()
    ->setEventDispatcher($yourDispatcher)
    ->connect('opc.tcp://localhost:4840');

// Laravel
$client = ClientBuilder::create()
    ->setEventDispatcher(app(EventDispatcherInterface::class))
    ->connect('opc.tcp://localhost:4840');
```

A `NullEventDispatcher` is used by default — zero overhead when no dispatcher is configured. Event objects are lazily instantiated.

**Connection events dispatched:**

| Event | When |
|-------|------|
| `ClientConnecting` | Before `connect()` starts |
| `ClientConnected` | After successful connection |
| `ConnectionFailed` | When connection attempt fails |
| `ClientReconnecting` | Before `reconnect()` starts |
| `ClientDisconnecting` | Before `disconnect()` starts |
| `ClientDisconnected` | After full disconnect |
| `SecureChannelOpened` | After secure channel is established |
| `SecureChannelClosed` | Before secure channel is closed |
| `SessionCreated` | After CreateSession succeeds |
| `SessionActivated` | After ActivateSession succeeds |
| `SessionClosed` | Before session close request |
| `RetryAttempt` | Before each automatic retry |
| `RetryExhausted` | When all retries are exhausted |

> **Tip:** See [Events](14-events.md) for the full list of 47 events and practical examples.

## Disconnecting

Always call `disconnect()` when you are done. It sends CloseSession and CloseSecureChannel, closes the TCP socket, and clears all internal state.

```php
$client->disconnect();
```

> **Warning:** After `disconnect()`, auto-retry is off and `reconnect()` will not work. Call `connect()` with a URL to start a new session.
