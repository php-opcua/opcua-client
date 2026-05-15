---
eyebrow: 'Docs · Security'
lede:    'The trust store is the client''s answer to "is this server who it claims to be?" Three policies, one on-disk implementation, and a TOFU mode for first-contact provisioning.'

see_also:
  - { href: './certificates.md',     meta: '6 min' }
  - { href: './overview.md',         meta: '5 min' }
  - { href: '../reference/exceptions.md', meta: '7 min' }

prev: { label: 'Authentication',         href: './authentication.md' }
next: { label: 'Cache path hardening',   href: './cache-path-hardening.md' }
---

# Trust store

A **trust store** holds the set of server certificates the client is
willing to accept. When the client receives a server certificate during
discovery, it asks the trust store to validate it. If the store rejects
the certificate, `connect()` raises `UntrustedCertificateException`
and the channel is never opened.

## Defaults

Out of the box, **no trust store is configured** — `setTrustStore(null)`.
In this state the client accepts any server certificate. That is fine
for `opc.tcp://localhost:4840` against a dev container; it is **not**
fine for anything that touches a real network.

The first step toward production: configure a trust store and a trust
policy.

## Quick start

<!-- @code-block language="php" label="trust store setup" -->
```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\TrustStore\FileTrustStore;
use PhpOpcua\Client\TrustStore\TrustPolicy;

$client = ClientBuilder::create()
    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
    ->setSecurityMode(SecurityMode::SignAndEncrypt)
    ->setClientCertificate('/etc/opcua/client.pem', '/etc/opcua/client.key')
    ->setTrustStore(new FileTrustStore('/var/lib/opcua/trust'))
    ->setTrustPolicy(TrustPolicy::FingerprintAndExpiry)
    ->autoAccept(true, force: false)    // TOFU on first contact, then enforce
    ->connect('opc.tcp://plc.local:4840');
```
<!-- @endcode-block -->

## Trust policies

The `TrustPolicy` enum has three cases. They differ in what counts as
"trusted":

| Policy                       | Trust decision                                            |
| ---------------------------- | --------------------------------------------------------- |
| `TrustPolicy::Fingerprint`   | The server cert's SHA-256 fingerprint is in the store. Validity window is ignored. |
| `TrustPolicy::FingerprintAndExpiry` | Fingerprint matches **and** the cert is currently inside its `notBefore`/`notAfter` window. |
| `TrustPolicy::Full`          | Full X.509 chain validation against a CA stored in the trust store, including expiry. |

`null` (the default when `setTrustPolicy()` is never called) means
"accept anything". The trust store is consulted only when both a store
and a policy are configured.

### Picking a policy

- **Pinning + rotation discipline** → `Fingerprint` or
  `FingerprintAndExpiry`. The simplest model: list the server
  fingerprints you accept, rotate when they change.
- **PKI in place** → `Full`. The CA validates the certificate chain
  and expiry; the trust store holds the CA bundle, not the leaves.
- **No PKI, no time to maintain a fingerprint list** → TOFU + auto-
  accept. See below. Pragmatic, weaker than the other two.

## FileTrustStore

`FileTrustStore` is the default implementation. It stores DER-encoded
certificates on disk, one file per certificate, named by fingerprint:

<!-- @code-block language="text" label="on-disk layout" -->
```text
/var/lib/opcua/trust/
├── trusted/
│   ├── 2d1f5b8a….der        ← server certs the client accepts
│   └── a47c80a3….der
└── rejected/
    └── ff03c2a7….der        ← server certs the client explicitly refused
```
<!-- @endcode-block -->

`FileTrustStore::defaultBasePath()` picks `~/.opcua/` on POSIX,
`%APPDATA%\opcua\` on Windows. Pass an explicit path in production —
the home directory of the running user is rarely the right place for
operational state.

### API

<!-- @code-block language="php" label="programmatic management" -->
```php
$store = new FileTrustStore('/var/lib/opcua/trust');

// Accept a certificate (manually, e.g. after an operator review)
$store->trust(file_get_contents('/path/to/server.der'));

// Reject a certificate (records it under rejected/, removes from trusted/)
$store->reject($der);

// Forget a fingerprint
$store->untrust('2d1f5b8a…');

// Read-only checks
$store->isTrusted($der);                          // bool
$store->getTrustedCertificates();                 // string[] (DER blobs)
$store->validate($der, TrustPolicy::Full, $caPem); // TrustResult
```
<!-- @endcode-block -->

The same surface is exposed on the `Client`:
`$client->trustCertificate($der)`, `$client->untrustCertificate($fp)`.

## Auto-accept (TOFU)

`autoAccept()` enables a Trust-On-First-Use posture. When the client
receives a server certificate that is not yet in the trust store, the
store records it under `trusted/` and accepts the connection. Every
subsequent connection enforces the now-recorded fingerprint.

<!-- @code-block language="php" label="TOFU configuration" -->
```php
$client = ClientBuilder::create()
    ->setTrustStore(new FileTrustStore('/var/lib/opcua/trust'))
    ->setTrustPolicy(TrustPolicy::FingerprintAndExpiry)
    ->autoAccept(true, force: false)
    ->connect('opc.tcp://plc.local:4840');
```
<!-- @endcode-block -->

| Argument         | Effect                                                  |
| ---------------- | ------------------------------------------------------- |
| `$enabled = true`| Auto-accept unknown certs on first contact              |
| `$force = false` | Re-accept a cert that was previously **rejected**       |

`autoAccept(true, force: true)` is the operator override: the
explicitly-rejected fingerprint moves back to `trusted/`. Use it from
admin tooling, not from application code.

<!-- @callout variant="warning" -->
TOFU is convenient but it trusts the first observation blindly. An
attacker on the path during the first connection can substitute their
own certificate and the client will record it as legitimate. Treat
TOFU as a deployment-time bootstrap, then disable `autoAccept()` once
the fingerprint is captured.
<!-- @endcallout -->

## Events

The trust store emits five PSR-14 events the moment a decision is
made:

| Event                              | When                                                  |
| ---------------------------------- | ----------------------------------------------------- |
| `ServerCertificateTrusted`         | Cert was already in the trust store and accepted      |
| `ServerCertificateAutoAccepted`    | Cert was unknown; TOFU recorded it as trusted         |
| `ServerCertificateRejected`        | Cert was rejected — connection will fail              |
| `ServerCertificateManuallyTrusted` | `$store->trust()` was called explicitly               |
| `ServerCertificateRemoved`         | `$store->untrust()` was called                        |

Wire a dispatcher to record these — they are the only audit trail of
certificate decisions outside the file system.

## Custom implementations

`TrustStoreInterface` has six methods (`isTrusted`, `trust`,
`untrust`, `reject`, `getTrustedCertificates`, `validate`). Implement
it against any backing store — a database, a centralised vault, an
HSM. The `Client` calls only `isTrusted` and `validate` on the hot
path; the rest are management operations.

## Failure surface

`UntrustedCertificateException` is raised by `connect()` when the
trust store rejects the server certificate. It carries:

- `$fingerprint` — the certificate's SHA-256 hex fingerprint
- `$certDer` — the DER bytes themselves, so admin tooling can decode
  and display the offending cert

Catch it from setup scripts to surface a "trust this server?" prompt:

<!-- @code-block language="php" label="operator-prompted trust" -->
```php
use PhpOpcua\Client\Exception\UntrustedCertificateException;

try {
    $client = $builder->connect($url);
} catch (UntrustedCertificateException $e) {
    if (prompt("Trust certificate {$e->fingerprint}?")) {
        $store->trust($e->certDer);
        $client = $builder->connect($url);
    } else {
        throw $e;
    }
}
```
<!-- @endcode-block -->
