---
eyebrow: 'Docs · Security'
lede:    'Two certificates are in play on every secured connection — the client''s and the server''s. This page covers generation, loading, and the auto-generated fallback the library ships with.'

see_also:
  - { href: './policies.md',      meta: '7 min' }
  - { href: './trust-store.md',   meta: '6 min' }
  - { href: 'https://www.rfc-editor.org/rfc/rfc5280', meta: 'external', label: 'RFC 5280 — X.509 PKI' }

prev: { label: 'Policies',       href: './policies.md' }
next: { label: 'Authentication', href: './authentication.md' }
---

# Certificates

Every non-`None` OPC UA channel uses a pair of X.509 certificates:

- **Server certificate** — proves the server's identity. Pinned or
  CA-validated by the client.
- **Client certificate** — proves the client's identity. Pinned or
  CA-validated by the server.

The OPC UA stack uses both certificates' public keys to seed the
asymmetric portion of the channel handshake. The cryptography is the
boring part; the operational question is which certificates go where.

## Client certificate

### Loading an existing one

Pass paths to PEM-encoded cert and private-key files:

<!-- @code-block language="php" label="loading PEM files" -->
```php
$client = ClientBuilder::create()
    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
    ->setSecurityMode(SecurityMode::SignAndEncrypt)
    ->setClientCertificate(
        certPath: '/etc/opcua/client.pem',
        keyPath:  '/etc/opcua/client.key',
        caCertPath: '/etc/opcua/ca.pem',   // optional — for chain validation
    )
    ->connect('opc.tcp://plc.local:4840');
```
<!-- @endcode-block -->

The PEM files must contain a single certificate and a single private
key respectively. DER input is accepted too — the loader auto-detects
the format by header. Passphrase-protected keys are **not** currently
supported; remove the passphrase before pointing the builder at the
file.

### Generating one

For RSA, OpenSSL on the command line is the most reliable path:

<!-- @code-block language="bash" label="terminal — RSA 2048" -->
```bash
# 1. CA (one-time per environment)
openssl req -x509 -newkey rsa:2048 -nodes \
    -keyout ca.key -out ca.pem \
    -subj "/CN=opcua-internal-ca" -days 3650

# 2. Client certificate signed by the CA
openssl req -new -newkey rsa:2048 -nodes \
    -keyout client.key -out client.csr \
    -subj "/CN=opcua-client/O=integrations"

openssl x509 -req -in client.csr \
    -CA ca.pem -CAkey ca.key -CAcreateserial \
    -out client.pem -days 730 \
    -extfile <(printf '%s\n' \
        "subjectAltName=URI:urn:opcua-client,DNS:opcua.internal" \
        "extendedKeyUsage=clientAuth,serverAuth")
```
<!-- @endcode-block -->

Two extension fields the OPC UA stack expects:

- `subjectAltName: URI` — must carry the **Application URI** the
  server will validate against the certificate. The default this
  library uses is `urn:opcua-client`; configure your own when you
  generate the cert.
- `extendedKeyUsage: clientAuth,serverAuth` — many servers reject
  certs that are not flagged for both.

For ECC, swap the key generation step:

<!-- @code-block language="bash" label="terminal — ECC P-256" -->
```bash
openssl ecparam -name prime256v1 -genkey -noout -out client.key
openssl req -new -key client.key -out client.csr \
    -subj "/CN=opcua-client"
# Sign with the CA as above.
```
<!-- @endcode-block -->

Supported curves: `prime256v1` (NIST P-256), `secp384r1` (NIST P-384),
`brainpoolP256r1`, `brainpoolP384r1`. The curve **must** match the
`SecurityPolicy` selected on the builder — a P-256 cert with an
`EccNistP384` policy is a configuration error.

### Auto-generated fallback

When you configure a non-`None` policy but do not call
`setClientCertificate()`, the builder generates a self-signed
certificate on first connect:

- RSA policies → 2048-bit RSA, SHA-256, 365-day validity
- ECC policies → curve matching the policy, SHA-256/384, 365-day
  validity

The certificate is regenerated on every process restart. Its
fingerprint changes, so the server's trust store will see it as a new
identity every time — useful for one-off scripts, disastrous for any
deployment that survives a restart.

<!-- @do-dont -->
<!-- @do -->
Generate a stable certificate once, deploy it alongside the
application, and reference it via `setClientCertificate()`. Treat the
cert and key as infrastructure secrets — same vault, same rotation
discipline as the password they replace.
<!-- @enddo -->
<!-- @dont -->
Don't rely on the auto-generated certificate in production. Every
process restart looks like a new client to the server; auditing, role
binding, and trust-store pinning all break.
<!-- @enddont -->
<!-- @enddo-dont -->

## Server certificate

The client receives the server's certificate during discovery
(`GetEndpoints`). What happens next depends on the trust store
configuration:

- **No trust store** (`setTrustStore(null)`, the default) — any
  certificate is accepted. **Not appropriate for production.**
- **TOFU** (`autoAccept(true)`) — the certificate is recorded on first
  contact, then enforced on subsequent connections.
- **Pinned** — the trust store contains the certificate's DER bytes;
  matches are exact.
- **CA-validated** — the trust store contains the issuing CA; the
  server cert is validated against the chain.

See [Trust store](./trust-store.md) for the three trust policies and
the API surface.

### Fingerprinting

Certificate identity is summarised by a **SHA-256 fingerprint** of the
DER bytes, lowercased hex. Use this everywhere identity is logged —
not the Common Name, not the subject string.

<!-- @code-block language="php" label="compute a fingerprint" -->
```php
$der = file_get_contents('/etc/opcua/server.der');
$fingerprint = hash('sha256', $der);
```
<!-- @endcode-block -->

The trust store, the events (`ServerCertificateTrusted`,
`ServerCertificateAutoAccepted`, …), and the `UntrustedCertificateException`
all use the same fingerprint encoding.

## Validity windows

OPC UA certificates carry standard X.509 `notBefore` / `notAfter`
fields. The library:

- Rejects a server certificate that is not yet valid or has expired
  when the trust policy is `Full` or `FingerprintAndExpiry`.
- Ignores validity windows when the policy is `Fingerprint` only —
  the assumption being that you accepted this exact certificate
  deliberately and want to keep accepting it.
- Does not currently warn ahead of expiry. Track expirations in your
  monitoring system, not in the OPC UA client.

## Application URI

Every OPC UA application has an **Application URI**, embedded in the
client certificate (`subjectAltName: URI`) and announced at session
creation. The default the library emits is `urn:opcua-client`. To
override, regenerate the certificate with your own URI in the SAN
field — the value in the cert wins.

Servers that enforce URI matching will reject sessions where the URI
in the certificate does not match the URI in `CreateSession`. This is
a frequent root cause of `BadCertificateUriInvalid`.
