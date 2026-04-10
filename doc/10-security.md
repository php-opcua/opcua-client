# Security

## Security Policies

Each policy defines the algorithms used for encryption and signing:

### RSA Policies

| Policy | Asymmetric Sign | Asymmetric Encrypt | Symmetric Sign | Symmetric Encrypt | Min Key |
|--------|----------------|-------------------|---------------|-------------------|---------|
| None | -- | -- | -- | -- | -- |
| Basic128Rsa15 | RSA-SHA1 | RSA-PKCS1-v1_5 | HMAC-SHA1 | AES-128-CBC | 1024 bit |
| Basic256 | RSA-SHA1 | RSA-OAEP | HMAC-SHA1 | AES-256-CBC | 1024 bit |
| Basic256Sha256 | RSA-SHA256 | RSA-OAEP | HMAC-SHA256 | AES-256-CBC | 2048 bit |
| Aes128Sha256RsaOaep | RSA-SHA256 | RSA-OAEP | HMAC-SHA256 | AES-128-CBC | 2048 bit |
| Aes256Sha256RsaPss | RSA-PSS-SHA256 | RSA-OAEP-SHA256 | HMAC-SHA256 | AES-256-CBC | 2048 bit |

### ECC Policies

| Policy | Asymmetric Sign | Key Agreement | Symmetric Sign | Symmetric Encrypt | Curve |
|--------|----------------|--------------|---------------|-------------------|-------|
| EccNistP256 | ECDSA-SHA256 | ECDH P-256 | HMAC-SHA256 | AES-128-CBC | prime256v1 |
| EccNistP384 | ECDSA-SHA384 | ECDH P-384 | HMAC-SHA384 | AES-256-CBC | secp384r1 |
| EccBrainpoolP256r1 | ECDSA-SHA256 | ECDH BP-256 | HMAC-SHA256 | AES-128-CBC | brainpoolP256r1 |
| EccBrainpoolP384r1 | ECDSA-SHA384 | ECDH BP-384 | HMAC-SHA384 | AES-256-CBC | brainpoolP384r1 |

ECC policies use ECDH key agreement instead of RSA encryption. The OpenSecureChannel message is sign-only (no asymmetric encryption). Symmetric keys are derived via HKDF instead of P_SHA.

The Brainpool curves are the European alternative to NIST curves. They provide equivalent security levels but with curve parameters generated in a verifiable way ("nothing-up-my-sleeve"). Required by BSI TR-03116 and other European regulations. The protocol is identical to NIST — only the underlying curve differs.

> **Tip:** For new deployments, use `Basic256Sha256`, `Aes256Sha256RsaPss`, or any ECC policy for modern security. Choose NIST curves for maximum interoperability or Brainpool curves for European regulatory compliance. The older policies (`Basic128Rsa15`, `Basic256`) exist for legacy server compatibility.

> **ECC support disclaimer:** The ECC security policies (ECC_nistP256, ECC_nistP384, ECC_brainpoolP256r1, ECC_brainpoolP384r1) are implemented following the OPC UA 1.05.3 specification, but should be considered **experimental**. The implementation is aligned with 1.05.4 regarding `ReceiverCertificateThumbprint` and HKDF salt encoding. Two ECC-specific changes from 1.05.4 (per-message IV derivation and LegacySequenceNumbers) are not yet implemented. See the [ECC 1.05.4 Compliance](../ROADMAP.md#ecc-1054-compliance) section in the roadmap for a detailed technical analysis of each point, its impact, and the planned fix.
>
> As of today, no commercial OPC UA server vendor — not Siemens, not Beckhoff, not Kepware, not any other — has released firmware or hardware with ECC OPC UA endpoints. This is not a limitation of this library: it is the current reality of the OPC UA ecosystem. The specification defines ECC support, but the industrial market has not yet adopted it in production devices.
>
> The ECC implementation in this library has been developed and tested exclusively against **[UA-.NETStandard](https://github.com/OPCFoundation/UA-.NETStandard)**, the OPC Foundation's reference implementation, used as the counterpart for integration testing. It has not been validated against any physical industrial device or commercial server product.
>
> If you are evaluating ECC for a production deployment, be aware that you will likely need a UA-.NETStandard-based server (or a custom server built on it) as the only available counterpart. The RSA policies are the proven, battle-tested choice for real-world industrial deployments today.

## Certificate Setup

### RSA Certificates

```bash
# 1. Create a CA
openssl genpkey -algorithm RSA -out ca.key -pkeyopt rsa_keygen_bits:2048
openssl req -x509 -new -key ca.key -days 365 -out ca.pem \
  -subj "/CN=Test CA"

# 2. Create a client certificate signed by the CA
openssl genpkey -algorithm RSA -out client.key -pkeyopt rsa_keygen_bits:2048
openssl req -new -key client.key -out client.csr \
  -subj "/CN=OPC UA Client" \
  -addext "subjectAltName=URI:urn:opcua-client:client"
openssl x509 -req -in client.csr -CA ca.pem -CAkey ca.key \
  -CAcreateserial -days 365 -out client.pem \
  -copy_extensions copy
```

### ECC Certificates

```bash
# P-256 client certificate
openssl ecparam -name prime256v1 -genkey -noout -out client-ecc.key
openssl req -new -key client-ecc.key -out client-ecc.csr \
  -subj "/CN=OPC UA ECC Client" \
  -addext "subjectAltName=URI:urn:opcua-client:client"
openssl x509 -req -in client-ecc.csr -CA ca.pem -CAkey ca.key \
  -CAcreateserial -days 365 -out client-ecc.pem \
  -copy_extensions copy
```

> **Note:** The `subjectAltName` URI is required by OPC UA. It must match the application URI your server expects.

## Client Configuration

### RSA

```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Security\SecurityMode;

$client = ClientBuilder::create()
    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
    ->setSecurityMode(SecurityMode::SignAndEncrypt)
    ->setClientCertificate(
        '/path/to/client.pem',   // PEM or DER, auto-detected
        '/path/to/client.key',
        '/path/to/ca.pem'        // optional: appended to the certificate chain
    )
    ->connect('opc.tcp://server:4840');
```

### ECC

```php
$client = ClientBuilder::create()
    ->setSecurityPolicy(SecurityPolicy::EccNistP256)
    ->setSecurityMode(SecurityMode::SignAndEncrypt)
    ->setClientCertificate(
        '/path/to/client-ecc.pem',
        '/path/to/client-ecc.key',
    )
    ->connect('opc.tcp://server:4848');
```

If you skip `setClientCertificate()`, the library auto-generates a self-signed certificate in memory:
- **RSA policies**: RSA 2048-bit certificate
- **ECC policies**: ECC certificate matching the security policy curve (P-256, P-384, brainpoolP256r1, or brainpoolP384r1)

> **Warning:** Auto-generated certificates are ephemeral. Every new `Client` instance gets a different certificate. For production, always provide your own.

### ECC with Username/Password

```php
$client = ClientBuilder::create()
    ->setSecurityPolicy(SecurityPolicy::EccNistP256)
    ->setSecurityMode(SecurityMode::SignAndEncrypt)
    ->setUserCredentials('admin', 'admin123')
    ->connect('opc.tcp://server:4848');
```

For ECC policies, the password is encrypted using the `EccEncryptedSecret` protocol (ECDH key agreement + AES encryption). The client automatically:
1. Requests an ephemeral ECDH key from the server via `AdditionalHeader` in CreateSession
2. Generates its own ephemeral ECDH keypair
3. Derives an AES encryption key from the ECDH shared secret
4. Encrypts the password and signs the blob with ECDSA

## CertificateManager API

Utilities for loading and inspecting X.509 certificates:

```php
use PhpOpcua\Client\Security\CertificateManager;

$cm = new CertificateManager();

// Load certificates -- PEM and DER both work
$derBytes = $cm->loadCertificatePem('/path/to/cert.pem');
$derBytes = $cm->loadCertificateDer('/path/to/cert.der');

// Load a private key
$privateKey = $cm->loadPrivateKeyPem('/path/to/key.pem');

// Inspect
$thumbprint = $cm->getThumbprint($derBytes);         // SHA1 hash (binary)
$keyLength  = $cm->getPublicKeyLength($derBytes);     // bytes (256 = 2048-bit key)
$publicKey  = $cm->getPublicKeyFromCert($derBytes);   // OpenSSLAsymmetricKey
$appUri     = $cm->getApplicationUri($derBytes);      // from SAN extension
$keyType    = $cm->getKeyType($derBytes);             // OPENSSL_KEYTYPE_RSA or OPENSSL_KEYTYPE_EC

// Generate self-signed certificates
$rsa = $cm->generateSelfSignedCertificate('urn:my-app');              // RSA 2048
$ecc = $cm->generateSelfSignedCertificate('urn:my-app', 'prime256v1');      // ECC P-256
$bp  = $cm->generateSelfSignedCertificate('urn:my-app', 'brainpoolP256r1'); // ECC Brainpool P-256
// Returns: ['certDer' => string, 'privateKey' => OpenSSLAsymmetricKey]
```

## MessageSecurity API

Low-level cryptographic operations. You rarely need these directly -- the `SecureChannel` handles them -- but they are available:

```php
use PhpOpcua\Client\Security\MessageSecurity;

$ms = new MessageSecurity();

// Asymmetric (RSA or ECDSA -- auto-detected from key type)
$signature = $ms->asymmetricSign($data, $privateKey, $policy);
$valid     = $ms->asymmetricVerify($data, $signature, $derCert, $policy);
$encrypted = $ms->asymmetricEncrypt($data, $derCert, $policy);   // RSA only
$decrypted = $ms->asymmetricDecrypt($data, $privateKey, $policy); // RSA only

// Symmetric (AES + HMAC)
$signature = $ms->symmetricSign($data, $signingKey, $policy);
$valid     = $ms->symmetricVerify($data, $signature, $signingKey, $policy);
$encrypted = $ms->symmetricEncrypt($data, $encKey, $iv, $policy);
$decrypted = $ms->symmetricDecrypt($data, $encKey, $iv, $policy);

// Key derivation
$keys = $ms->deriveKeys($secret, $seed, $policy);         // P_SHA1/P_SHA256 (RSA)
$keys = $ms->deriveKeysHkdf($ikm, $salt, $info, $policy); // HKDF (ECC)
// Returns: ['signingKey' => ..., 'encryptingKey' => ..., 'iv' => ...]

// ECC-specific operations
$shared = $ms->computeEcdhSharedSecret($privateKey, $publicKey);       // ECDH
$pair   = $ms->generateEphemeralKeyPair('prime256v1');                  // Ephemeral EC keypair
$pubKey = $ms->loadEcPublicKeyFromBytes($uncompressedPoint, 'prime256v1'); // Load from X+Y
$raw    = $ms->ecdsaDerToRaw($derSignature, 32);  // DER -> raw R||S
$der    = $ms->ecdsaRawToDer($rawSignature, 32);  // raw R||S -> DER
```

## Connection Flow

### RSA

```
Client                          Server
  |                               |
  |--- HEL ---------------------->|  TCP handshake
  |<-- ACK -----------------------|
  |                               |
  |--- OPN (asymmetric) --------->|  Encrypted with server's RSA public key
  |<-- OPN response --------------|  Contains server nonce
  |                               |
  |   [derive symmetric keys      |
  |    via P_SHA from nonces]     |
  |                               |
  |--- MSG (symmetric) ---------->|  AES encrypted, HMAC signed
  |<-- MSG (symmetric) ----------|
```

### ECC

```
Client                          Server
  |                               |
  |--- HEL ---------------------->|  TCP handshake
  |<-- ACK -----------------------|
  |                               |
  |--- OPN (sign-only) ---------->|  ECDSA signed, NOT encrypted
  |<-- OPN response (signed) ----|  Contains server ephemeral key
  |                               |
  |   [ECDH key agreement         |
  |    + HKDF key derivation]     |
  |                               |
  |--- MSG (symmetric) ---------->|  AES encrypted, HMAC signed
  |<-- MSG (symmetric) ----------|
```

**Phase 1 -- Discovery.** The client connects without security, calls `GetEndpoints`, and retrieves the server's certificate. For ECC endpoints, the server certificate uses an ECC key.

**Phase 2 -- Asymmetric (OpenSecureChannel).**
- **RSA:** The client sends an OPN request encrypted with the server's RSA public key.
- **ECC:** The OPN request is sign-only (ECDSA). No asymmetric encryption. The client nonce contains the ephemeral ECDH public key (X+Y coordinates, 64 bytes for P-256, 96 for P-384).

**Phase 3 -- Key derivation.**
- **RSA:** Symmetric keys derived via P_SHA256(serverNonce, clientNonce).
- **ECC:** Symmetric keys derived via HKDF from the ECDH shared secret. The salt includes the label ("opcua-client"/"opcua-server") and both nonces. The salt key length in the uint16 prefix depends on the security mode.

**Phase 4 -- Symmetric (Messages).** All `MSG` and `CLO` messages use the derived symmetric keys. Messages are signed with HMAC and encrypted with AES-CBC. This phase is identical for RSA and ECC policies.

The `SecureChannel` class manages this entire lifecycle: asymmetric key exchange, symmetric key derivation, message signing/encryption/padding, sequence number tracking, and token/channel ID management.

> **Events:** `SecureChannelOpened` is dispatched after the secure channel is established (with channelId, securityPolicy, and securityMode). `SecureChannelClosed` is dispatched before the channel is closed. See [Events](14-events.md).
