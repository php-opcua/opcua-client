# Connection reference

How to establish, secure, and tear down an OPC UA session with `opcua-client` v4.5.0.

## Anatomy of `connect()`

`ClientBuilder::connect(string $endpointUrl): Client` runs this pipeline:

1. **URL parse** — extracts host + port. Default port 4840 if not specified.
2. **Discovery** (if needed) — opens a side connection via `transport->createProbe()`, sends HEL/ACK + OPN (skipped if `isSecureChannelExternal()`) + GetEndpoints. Extracts server certificate and `UserTokenPolicy` list. Result cached for the session.
3. **Certificate validation** — checks server cert against the configured `TrustStore` policy. Raises `UntrustedCertificateException` on rejection.
4. **Transport `connect()`** — opens the actual wire connection (TCP socket, or no-op for HTTPS).
5. **HEL/ACK handshake** — UA-TCP buffer negotiation. Skipped/faked by HTTPS transport.
6. **OpenSecureChannel** (OPN) — UA-level secure channel. **Skipped** when `transport->isSecureChannelExternal() === true` (e.g. HTTPS — TLS already wraps the channel); `ManagesSecureChannelTrait::openSecureChannelExternal()` initialises a synthetic `SessionService`.
7. **CreateSession + ActivateSession** — server identity / nonces, client cert (if any), user token. Returns the `authenticationToken` used for all subsequent calls.
8. **Discover server operation limits** — `MaxNodesPerRead`, `MaxNodesPerWrite` (used by auto-batching; readable via `getServerMaxNodesPerRead()` / `getServerMaxNodesPerWrite()`). `MaxNodesPerBrowse` is not discovered.
9. Returns the `Client`.

Only steps 4–7 (transport `connect()`, HEL/ACK handshake, OpenSecureChannel, CreateSession/ActivateSession) run inside the guarded block of `ManagesConnectionTrait::performConnect()`, and that block `catch`es **only** `ConnectionException`. So if one of those steps throws a `ConnectionException`, the client is set to `ConnectionState::Broken`, a `ConnectionFailed` event is dispatched, and the exception is rethrown. Other failures are NOT remapped: steps 1–3 throw before the guarded block (invalid URL → `ConfigurationException`; discovery → `SecurityException` / `HandshakeException` / `MessageTypeException`; certificate rejection → `UntrustedCertificateException`), and a non-`ConnectionException` raised inside steps 4–7 (e.g. `ProtocolException` / `HandshakeException` / `MessageTypeException` / `ConfigurationException`, which are siblings or subclasses of `ProtocolException`, not of `ConnectionException`) propagates without entering `Broken` or dispatching `ConnectionFailed`.

## Minimal example

```php
use PhpOpcua\Client\ClientBuilder;

$client = ClientBuilder::create()
    ->connect('opc.tcp://localhost:4840');

try {
    // ... use the client
} finally {
    $client->disconnect();
}
```

## Security configuration

### Security policies (10 total)

`PhpOpcua\Client\Security\SecurityPolicy` enum:

| Case | URI | RSA / ECC | Note |
| --- | --- | --- | --- |
| `None` | `...#None` | — | Cleartext over the wire. Use only for development. |
| `Basic128Rsa15` | `...#Basic128Rsa15` | RSA | Deprecated by spec but still common. |
| `Basic256` | `...#Basic256` | RSA | Deprecated. |
| `Basic256Sha256` | `...#Basic256Sha256` | RSA | The default modern choice. |
| `Aes128Sha256RsaOaep` | `...#Aes128_Sha256_RsaOaep` | RSA | |
| `Aes256Sha256RsaPss` | `...#Aes256_Sha256_RsaPss` | RSA | Strongest RSA. |
| `EccNistP256` | `...#ECC_nistP256` | ECC | NIST P-256 curve. |
| `EccNistP384` | `...#ECC_nistP384` | ECC | NIST P-384 curve. |
| `EccBrainpoolP256r1` | `...#ECC_brainpoolP256r1` | ECC | Brainpool P-256r1. |
| `EccBrainpoolP384r1` | `...#ECC_brainpoolP384r1` | ECC | Brainpool P-384r1. |

### Security modes

`PhpOpcua\Client\Security\SecurityMode` enum: `None`, `Sign`, `SignAndEncrypt`.

- `None` requires policy `None`. Otherwise mismatch error.
- `Sign` adds message-level signatures (integrity).
- `SignAndEncrypt` adds signatures + confidentiality. The production default.

### Authentication modes

Three options, mutually exclusive at runtime:

```php
// Anonymous (default — no call needed)
->connect(...)

// Username / Password
->setUserCredentials('operator', 's3cret')

// X.509 user identity token
->setUserCertificate('/certs/user.pem', '/certs/user.key')
```

### Client application certificate

Required for any non-None security policy. Auto-generated self-signed if not provided.

```php
// Bring your own
$builder->setClientCertificate(
    certPath: '/certs/client.pem',
    keyPath: '/certs/client.key',
    caCertPath: '/certs/ca.pem',          // optional — chain construction
);

// Or let the client generate one (works for any policy, including ECC)
// — no call needed; an in-memory self-signed cert is produced per session
```

The cert is sent to the server in `CreateSession.ClientCertificate`. The server validates it against its own trust store. For development against `uanetstandard-test-suite`'s auto-accept servers, no client cert pre-trust is needed.

### Secure connection examples

```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Security\SecurityMode;

// RSA — Basic256Sha256 + SignAndEncrypt + username
$client = ClientBuilder::create()
    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
    ->setSecurityMode(SecurityMode::SignAndEncrypt)
    ->setClientCertificate('/certs/client.pem', '/certs/client.key', '/certs/ca.pem')
    ->setUserCredentials('operator', 's3cret')
    ->connect('opc.tcp://plc.example:4840');

// ECC — EccNistP256 (auto-generated ECC cert)
$client = ClientBuilder::create()
    ->setSecurityPolicy(SecurityPolicy::EccNistP256)
    ->setSecurityMode(SecurityMode::SignAndEncrypt)
    ->setUserCredentials('admin', 'admin123')
    ->connect('opc.tcp://plc.example:4848');
```

## Server trust store

Persistent validation of the server's certificate across sessions.

```php
use PhpOpcua\Client\TrustStore\FileTrustStore;
use PhpOpcua\Client\TrustStore\TrustPolicy;

$builder->setTrustStore(new FileTrustStore('/var/lib/myapp/opcua-trust'));
$builder->setTrustPolicy(TrustPolicy::Fingerprint);             // pin SHA-256 thumbprint
// or TrustPolicy::FingerprintAndExpiry                          // also enforce notAfter
// or TrustPolicy::Full                                          // walk the CA chain
```

- **First connection** (TOFU — Trust On First Use): cert is stored, future connections require an exact match.
- **`setTrustPolicy(null)`** disables (the default — accept any cert).
- **`$builder->autoAccept()`** auto-accepts an unknown server cert on first use (TOFU); `$builder->autoAccept(true, force: true)` force-accepts even when a different cert is already trusted — handy for integration tests / first onboarding. (This is a `ClientBuilder` method; there is no `$client->forceAcceptServerCertificate()`.)
- **5 events** (namespace `PhpOpcua\Client\Event`): `ServerCertificateTrusted`, `ServerCertificateAutoAccepted`, `ServerCertificateManuallyTrusted`, `ServerCertificateRejected`, `ServerCertificateRemoved`.
- **CLI** (separate companion package — `composer require php-opcua/opcua-cli`): `opcua-cli trust <endpoint>`, `opcua-cli trust:list`, `opcua-cli trust:remove <fingerprint>`.
- **Rejection** raises `UntrustedCertificateException`.

## Auto-retry

```php
$builder->setAutoRetry(3);               // max automatic reconnection retries on connection loss; 0 disables. Default 0.
```

Every service call (`read`, `write`, `browse`, …) is wrapped in `kernel->executeWithRetry()`. On socket-level errors (connection reset, broken pipe, timeout) the client reconnects + replays the request. `ConnectionState` cycles `Connected → Broken → Connected`. `RetryAttempt` event dispatched on each retry.

`Client::reconnect()` is available for explicit recovery.

## Timeouts

```php
$builder->setTimeout(30.0);              // seconds. Default 5.0.
```

Applies to: connect, send, receive. Per-request override is not exposed at the moment.

## Transport (v4.4.0 — pluggable)

By default the client uses `TcpTransport` for `opc.tcp://`. Two extensions plug in via `ClientBuilder::setTransport()`:

### Reverse Connect (server dials in)

When the server sits behind NAT / firewall:

> Reverse Connect ships in the separate companion package `php-opcua/opcua-client-ext-reverse-connect` (`composer require php-opcua/opcua-client-ext-reverse-connect`), **not** in the core `opcua-client`. The core only exposes the seam `TcpTransport::fromConnectedSocket()`. The `ReverseConnectListener`, `ReverseHelloValidator`, and `ReverseConnectClientFactory` classes below live in that package — confirm their exact namespace against it; they are not under `PhpOpcua\Client\` in core. The imports below are illustrative.

```php
use PhpOpcua\Client\ExtReverseConnect\ReverseConnectListener;      // illustrative — verify namespace in the companion package
use PhpOpcua\Client\ExtReverseConnect\ReverseHelloValidator;       // illustrative — verify namespace in the companion package

$listener = new ReverseConnectListener(
    bindHost: '0.0.0.0',
    bindPort: 4840,
    validator: new ReverseHelloValidator(['urn:my-server']),
);
$session = $listener->accept(timeoutSeconds: 30.0);
// Hand the validated, live socket to the client factory
$client = (new ReverseConnectClientFactory())->buildClient($session, function ($builder) {
    $builder->setUserCredentials('admin', 'admin123');
});
```

The factory uses `TcpTransport::fromConnectedSocket()` so no new TCP handshake is performed.

### HTTPS (Part 6 §7.4)

When the network only allows outbound `https://`:

> The `opc.https://` transport ships in the separate companion package `php-opcua/opcua-client-ext-transport-https` (`composer require php-opcua/opcua-client-ext-transport-https`), **not** in the core `opcua-client`. The core only exposes the seam (`ClientTransportInterface::createProbe()` + `isSecureChannelExternal()`). The `HttpsTransport`, `BinaryHttpsEncoding`, and `CurlHttpClient` classes below live in that package — confirm their exact namespace against it. The imports below are illustrative.

```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\ExtTransportHttps\HttpsTransport;                // illustrative — verify namespace in the companion package
use PhpOpcua\Client\ExtTransportHttps\Encoding\BinaryHttpsEncoding;  // illustrative — verify namespace in the companion package
use PhpOpcua\Client\ExtTransportHttps\Http\CurlHttpClient;           // illustrative — verify namespace in the companion package

$transport = new HttpsTransport(
    httpClient: new CurlHttpClient(verifyTls: true),
    encoding: new BinaryHttpsEncoding(),                 // §7.4.4 binary (production-ready)
    endpointUrl: 'opc.https://server.example:443/UA/',
);

$client = ClientBuilder::create()
    ->setSecurityPolicy(SecurityPolicy::None)            // TLS is the channel
    ->setSecurityMode(SecurityMode::None)
    ->setTransport($transport)
    ->setUserCredentials('admin', 'admin123')            // Anonymous filtered out by UA-.NETStandard when HttpsMutualTls=false
    ->connect('opc.https://server.example:443/UA/');
```

The `HttpsTransport` returns `isSecureChannelExternal(): true` — the core's `ManagesSecureChannelTrait` skips OpenSecureChannel and initialises a synthetic `SessionService`.

## Cache (PSR-16)

Browse / browseAll / resolveNodeId / getEndpoints / discoverDataTypes are cached for 300 s by default (in-memory).

```php
use PhpOpcua\Client\Cache\FileCache;

$builder->setCache(new FileCache('/var/cache/opcua', defaultTtl: 600));
$builder->setReadMetadataCache(true);                    // also cache non-Value attributes
```

Per-call bypass for `read()`: `$client->read($id, refresh: true)` forces a fresh read (`read()` has no `useCache` parameter). For the cached methods, pass `useCache: false`, e.g. `$client->browse($id, useCache: false)` or `$client->resolveNodeId($id, useCache: false)`.

Custom codec: `$builder->setCacheCodec(new MyCodec())` — must implement `CacheCodecInterface`. Default `WireCacheCodec` uses JSON gated by the wire allowlist.

## Logging (PSR-3) + Events (PSR-14)

```php
$builder
    ->setLogger($psr3Logger)                             // any Monolog / Laravel / Symfony channel
    ->setEventDispatcher($psr14Dispatcher);              // any PSR-14 dispatcher
```

Both are optional. Defaults: `NullLogger` (zero overhead) and `NullEventDispatcher` (zero overhead, event factories never invoked).

See [`EVENTS.md`](EVENTS.md) for the 56 event catalog.

## Disconnect

```php
$client->disconnect();
```

Sends `CloseSession` (with `deleteSubscriptions=true`) and `CloseSecureChannel`. Idempotent. Skipped for HTTPS transport (TLS owns the channel close).

Always wrap in `try` / `finally` to ensure cleanup on exception:

```php
$client = ClientBuilder::create()->connect($url);
try {
    // work
} finally {
    $client->disconnect();
}
```

When using `opcua-session-manager`, the daemon keeps sessions alive across PHP requests — your code calls `Opcua::connect()` / disconnect cycles but the underlying socket is reused.
