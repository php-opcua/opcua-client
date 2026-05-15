---
eyebrow: 'Docs · Security'
lede:    'Ten security policies — six RSA, four ECC — combined with three security modes. Picking the right pair is the load-bearing decision; the algorithms underneath are an implementation detail.'

see_also:
  - { href: './overview.md',       meta: '5 min' }
  - { href: './certificates.md',   meta: '6 min' }
  - { href: 'https://github.com/php-opcua/opcua-client/blob/master/ROADMAP.md#ecc-1054-compliance', meta: 'external', label: 'ROADMAP — ECC 1.05.4 compliance' }

prev: { label: 'Overview',      href: './overview.md' }
next: { label: 'Certificates',  href: './certificates.md' }
---

# Policies

A **security policy** is a named algorithm suite: which asymmetric
cipher for the OPN key exchange, which symmetric cipher for messages,
which hash and signing function for everything else. The
`SecurityPolicy` enum has ten cases — six RSA-based, four ECC-based —
plus `None`, which leaves everything unprotected.

A policy is meaningless on its own. It pairs with a `SecurityMode`:

- **`None`** — no signing, no encryption.
- **`Sign`** — every message is signed (integrity), nothing is
  encrypted (confidentiality off).
- **`SignAndEncrypt`** — every message is signed *and* encrypted.

A server advertises one endpoint per `(policy, mode)` combination it
accepts. The client picks one before opening the channel.

## RSA policies

| Policy                       | Asymmetric          | Symmetric  | Hash        | When to use                              |
| ---------------------------- | ------------------- | ---------- | ----------- | ---------------------------------------- |
| `None`                       | —                   | —          | —           | Local dev, throwaway test only           |
| `Basic128Rsa15` <sup>†</sup> | RSA-1.5             | AES-128-CBC| SHA-1       | **Deprecated**. Legacy interop only      |
| `Basic256` <sup>†</sup>      | RSA-OAEP            | AES-256-CBC| SHA-1       | **Deprecated**. Legacy interop only      |
| `Basic256Sha256`             | RSA-OAEP            | AES-256-CBC| SHA-256     | **Current default for new deployments**  |
| `Aes128Sha256RsaOaep`        | RSA-OAEP            | AES-128-CBC| SHA-256     | Bandwidth-constrained links              |
| `Aes256Sha256RsaPss`         | RSA-PSS             | AES-256-CBC| SHA-256     | Strongest RSA option in the spec         |

<sup>†</sup> The OPC Foundation marks `Basic128Rsa15` and `Basic256` as
deprecated. SHA-1 is broken against collision attacks; RSA-1.5 has
padding-oracle history. They exist in this library for talking to
servers that have not yet upgraded.

## ECC policies

| Policy                  | Curve              | Symmetric  | Hash    | KDF        |
| ----------------------- | ------------------ | ---------- | ------- | ---------- |
| `EccNistP256`           | NIST P-256         | AES-128-CBC| SHA-256 | HKDF-SHA256|
| `EccNistP384`           | NIST P-384         | AES-256-CBC| SHA-384 | HKDF-SHA384|
| `EccBrainpoolP256r1`    | brainpoolP256r1    | AES-128-CBC| SHA-256 | HKDF-SHA256|
| `EccBrainpoolP384r1`    | brainpoolP384r1    | AES-256-CBC| SHA-384 | HKDF-SHA384|

<!-- @callout variant="warning" -->
ECC support is **experimental in practice**. The implementation
follows OPC UA 1.05.3 and is aligned with 1.05.4 on
`ReceiverCertificateThumbprint`, HKDF salt encoding, and
`LegacySequenceNumbers = FALSE`. As of this writing, no commercial OPC
UA server vendor ships ECC endpoints in production firmware — the ECC
code has been validated only against the OPC Foundation's
UA-.NETStandard reference. For real deployments today, stay on RSA.
See [ROADMAP · ECC 1.05.4 compliance](https://github.com/php-opcua/opcua-client/blob/master/ROADMAP.md#ecc-1054-compliance) on the GitHub repo.
<!-- @endcallout -->

## Picking a pair

A decision tree, in order:

<!-- @steps -->
- **Untrusted network?**

  If anything between you and the server crosses hardware you do not
  control: `SignAndEncrypt`. No exceptions, no debate.

- **Server requires a specific policy?**

  Some PLC vendors lock to a single policy/mode pair regardless of
  what the spec allows. Honour that — `getEndpoints()` tells you what
  the server actually accepts.

- **Performance budget tight?**

  AES-128 (used by `Basic128Rsa15`, `Aes128Sha256RsaOaep`) costs less
  than AES-256 on small embedded servers. The cryptographic margin is
  still large; the choice is bandwidth × CPU vs theoretical strength.

- **Compliance requirements?**

  Brainpool curves are mandated by some European regulatory frameworks
  (BSI TR-02102 for German federal use). NIST curves are mandated by
  US federal standards. If neither applies, pick by ecosystem
  familiarity — most teams find NIST tooling more available.

- **Otherwise — default:**

  `Basic256Sha256` + `SignAndEncrypt`. Universally supported, no
  deprecated primitives, no surprises.
<!-- @endsteps -->

## Configuring the pair

<!-- @code-block language="php" label="builder.setSecurityPolicy" -->
```php
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Security\SecurityMode;

$client = ClientBuilder::create()
    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
    ->setSecurityMode(SecurityMode::SignAndEncrypt)
    ->setClientCertificate('/etc/opcua/client.pem', '/etc/opcua/client.key')
    ->connect('opc.tcp://plc.local:4840');
```
<!-- @endcode-block -->

When the policy is anything other than `None`, the client needs a
client certificate. `setClientCertificate()` accepts paths to PEM-
encoded cert and key files (and optionally a CA bundle). If you skip
this step but configure a non-`None` policy, the builder generates a
self-signed RSA certificate on first connect — fine for dev, not for
production. See [Certificates](./certificates.md).

## Inspecting the agreed policy

After `connect()`, the server's chosen `EndpointDescription` is the
source of truth for the negotiated parameters. Recover them via
`getEndpoints()` (filtered by `endpointUrl` + `securityPolicyUri`) or
listen for the `SecureChannelOpened` event, which carries the channel
id, policy URI, and mode.

## When the negotiation fails

| Failure                                | Cause                                                |
| -------------------------------------- | ---------------------------------------------------- |
| `BadSecurityChecksFailed`              | Client cert rejected by the server's trust store     |
| `BadSecurityPolicyRejected`            | Server does not offer the requested policy           |
| `BadCertificateUntrusted`              | Server cert rejected by the client's trust store     |
| `BadCertificateTimeInvalid`            | Server cert not yet valid or expired                 |
| `SignatureVerificationException`       | Channel signature did not verify (crypto-level)      |

Most cases trace back to certificate setup. See [Trust
store](./trust-store.md) and [Certificates](./certificates.md).
