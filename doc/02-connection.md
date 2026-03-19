# Connection & Configuration

## Basic Connection

```php
use Gianfriaur\OpcuaPhpClient\Client;

$client = new Client();
$client->connect('opc.tcp://localhost:4840');

// ... operations ...

$client->disconnect();
```

The `connect()` method performs the following steps automatically:
1. Parses the endpoint URL to extract host and port (default: 4840)
2. If security is configured, discovers the server certificate via GetEndpoints
3. Establishes TCP connection
4. Performs the OPC UA Hello/Acknowledge handshake
5. Opens a secure channel (with or without encryption)
6. Creates and activates a session

## Timeout Configuration

By default, the client uses a **5-second timeout** for both TCP connection and I/O operations (read/write on the socket). You can customize this value using `setTimeout()`:

```php
$client = new Client();
$client->setTimeout(10.0); // 10 seconds
$client->connect('opc.tcp://localhost:4840');
```

The timeout (in seconds) applies to:
- The initial TCP connection attempt
- All subsequent socket read/write operations (handshake, secure channel, session, browse, read, write, etc.)

If an operation exceeds the timeout, a `ConnectionException` is thrown with the message "Read timeout".

> **Tip:** In environments with high-latency networks or slow PLC responses, increase the timeout accordingly. For fast local connections, you can reduce it.

## Connection State

The client tracks its connection state via `ConnectionState` enum:

```php
use Gianfriaur\OpcuaPhpClient\Types\ConnectionState;

$client = new Client();
$client->getConnectionState(); // ConnectionState::Disconnected

$client->connect('opc.tcp://localhost:4840');
$client->getConnectionState(); // ConnectionState::Connected
$client->isConnected();        // true

$client->disconnect();
$client->getConnectionState(); // ConnectionState::Disconnected
```

| State | Description |
|-------|-------------|
| `ConnectionState::Disconnected` | Never connected, or cleanly disconnected |
| `ConnectionState::Connected` | Connected and operational |
| `ConnectionState::Broken` | Connection was lost (timeout, remote close, etc.) |

The state determines the exception message when an operation is attempted on a non-connected client:
- `Disconnected` → `"Not connected: call connect() first"`
- `Broken` → `"Connection lost: call reconnect() or connect() to re-establish"`

## Reconnect

If the connection is lost, you can re-establish it using `reconnect()`, which performs a full disconnect/connect cycle using the last endpoint URL:

```php
$client->connect('opc.tcp://localhost:4840');

// ... connection drops ...

$client->reconnect(); // re-establishes to opc.tcp://localhost:4840
```

`reconnect()` throws `ConfigurationException` if `connect()` was never called. After an explicit `disconnect()`, the endpoint URL is cleared and `reconnect()` is not available — use `connect()` instead.

## Auto-Retry

The client can automatically reconnect and retry operations when a `ConnectionException` occurs. This is controlled by `setAutoRetry()`:

```php
$client = new Client();
$client->setAutoRetry(3); // retry up to 3 times on connection failure
$client->connect('opc.tcp://localhost:4840');

// If a read() fails due to a broken connection, the client will:
// 1. Mark state as Broken
// 2. Call reconnect()
// 3. Retry the operation
// Repeating up to 3 times before giving up
$value = $client->read(NodeId::numeric(0, 2259));
```

**Default behavior:**
- **0 retries** if `connect()` was never called or after `disconnect()`
- **1 retry** if the client has connected at least once (even if the connection failed)

To disable auto-retry explicitly:

```php
$client->setAutoRetry(0);
```

> **Note:** Auto-retry only applies to `ConnectionException` during operations (read, write, browse, etc.). It does not apply to the initial `connect()` call itself. After an explicit `disconnect()`, auto-retry is not triggered since there is no endpoint to reconnect to.

## Security Configuration

### Security Policy & Mode

```php
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;

$client = new Client();
$client->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
$client->setSecurityMode(SecurityMode::SignAndEncrypt);
```

**Available Security Policies:**

| Policy | URI |
|--------|-----|
| `SecurityPolicy::None` | `http://opcfoundation.org/UA/SecurityPolicy#None` |
| `SecurityPolicy::Basic128Rsa15` | `http://opcfoundation.org/UA/SecurityPolicy#Basic128Rsa15` |
| `SecurityPolicy::Basic256` | `http://opcfoundation.org/UA/SecurityPolicy#Basic256` |
| `SecurityPolicy::Basic256Sha256` | `http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256` |
| `SecurityPolicy::Aes128Sha256RsaOaep` | `http://opcfoundation.org/UA/SecurityPolicy#Aes128_Sha256_RsaOaep` |
| `SecurityPolicy::Aes256Sha256RsaPss` | `http://opcfoundation.org/UA/SecurityPolicy#Aes256_Sha256_RsaPss` |

**Available Security Modes:**

| Mode | Value | Description |
|------|-------|-------------|
| `SecurityMode::None` | 1 | No security |
| `SecurityMode::Sign` | 2 | Messages are signed |
| `SecurityMode::SignAndEncrypt` | 3 | Messages are signed and encrypted |

### Client Certificate

Required when using any security policy other than `None`:

```php
$client->setClientCertificate(
    '/path/to/client-cert.pem',   // or .der
    '/path/to/client-key.pem',
    '/path/to/ca-cert.pem'        // optional CA certificate
);
```

Both PEM and DER certificate formats are supported. The library auto-detects the format.

## Authentication

### Anonymous (Default)

No configuration needed. The client uses anonymous authentication by default.

### Username/Password

```php
$client->setUserCredentials('myuser', 'mypassword');
```

When security is active, the password is encrypted with the server's public key before transmission.

### X.509 Certificate

```php
$client->setUserCertificate(
    '/path/to/user-cert.pem',
    '/path/to/user-key.pem'
);
```

## Full Secure Connection Example

```php
$client = new Client();

$client->setTimeout(10.0); // optional: custom timeout

$client->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
$client->setSecurityMode(SecurityMode::SignAndEncrypt);

$client->setClientCertificate(
    '/certs/client.pem',
    '/certs/client.key',
    '/certs/ca.pem'
);

$client->setUserCredentials('operator', 'secret123');

$client->connect('opc.tcp://192.168.1.100:4840/UA/Server');

// ... secure operations ...

$client->disconnect();
```

## Endpoint Discovery

You can discover available endpoints after connecting:

```php
$client = new Client();
$client->connect('opc.tcp://localhost:4840');

$endpoints = $client->getEndpoints('opc.tcp://localhost:4840');
foreach ($endpoints as $ep) {
    echo "URL: " . $ep->getEndpointUrl() . "\n";
    echo "Security: " . $ep->getSecurityPolicyUri() . "\n";
    echo "Mode: " . $ep->getSecurityMode() . "\n";

    foreach ($ep->getUserIdentityTokens() as $token) {
        echo "  Auth: " . $token->getPolicyId()
            . " (type=" . $token->getTokenType() . ")\n";
    }
}
```

**Token types:**
- `0` = Anonymous
- `1` = Username/Password
- `2` = X.509 Certificate

## Disconnection

Always call `disconnect()` when done. It:
1. Sends CloseSession request
2. Sends CloseSecureChannel request
3. Closes the TCP socket
4. Clears all internal state (including the last endpoint URL)
5. Sets connection state to `Disconnected`

```php
$client->disconnect();
```

After `disconnect()`, auto-retry is disabled and `reconnect()` is not available. You must call `connect()` with an endpoint URL to re-establish.
