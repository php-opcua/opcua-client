---
eyebrow: 'Docs · Security'
lede:    'OPC UA security is two orthogonal axes — what the channel does (policies and modes) and who the user is (identity tokens). Build the threat model before reaching for an algorithm.'

see_also:
  - { href: './policies.md',         meta: '7 min' }
  - { href: './authentication.md',   meta: '5 min' }
  - { href: './trust-store.md',      meta: '6 min' }

prev: { label: 'Managing nodes',  href: '../operations/managing-nodes.md' }
next: { label: 'Policies',        href: './policies.md' }
---

# Security overview

OPC UA's security model is two independent decisions:

1. **Channel security** — how the messages between client and server
   are protected. This is the `SecurityPolicy` × `SecurityMode` matrix.
2. **Authentication** — who the user behind the session is. This is
   the *identity token*: anonymous, username/password, or X.509
   certificate.

They can be combined freely. A client may open a `SignAndEncrypt`
channel and authenticate as anonymous. Another may open a `None`
channel and authenticate with a username (transport-insecure — bad
idea, but the spec allows it). Servers advertise *which* combinations
they accept via the discovery flow.

This page is the orientation map. Each axis has its own page.

## Threat model checklist

Before picking algorithms, answer these:

<!-- @steps -->
- **Is the network between client and server trusted?**

  Inside an air-gapped plant LAN: maybe `None` is acceptable. Anything
  that crosses a switch you do not own: `SignAndEncrypt` and certificate
  pinning, no exceptions.

- **Who is the user?**

  A backend service reading published metrics → anonymous + channel
  encryption. An operator performing writes → at least username, ideally
  X.509. Mixed populations → multiple sessions with different identities.

- **Does the server enforce role-based access?**

  If yes, the identity is meaningful — credentials must map to a server-
  side role with the right write/method permissions. If no, identity is
  audit-only; lean on channel security to keep traffic confidential.

- **Is the server certificate trusted out-of-band?**

  If yes, configure the trust store with fingerprint or full-chain
  validation. If no, you are on a TOFU posture — see [Trust
  store](./trust-store.md) — and need an operator-driven validation
  workflow.

- **Are credentials at risk?**

  Passwords leak. Rotate to certificate authentication if the server
  supports it. The OPC UA stack encrypts password tokens under the
  channel's asymmetric keys, but that does not protect against
  server-side compromise.
<!-- @endsteps -->

## The configuration surface

Channel and identity are configured on the builder:

<!-- @code-block language="php" label="full security configuration" -->
```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\TrustStore\TrustPolicy;
use PhpOpcua\Client\TrustStore\FileTrustStore;

$client = ClientBuilder::create()
    // Channel
    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
    ->setSecurityMode(SecurityMode::SignAndEncrypt)
    ->setClientCertificate('/etc/opcua/client.pem', '/etc/opcua/client.key')

    // Authentication
    ->setUserCredentials('integrations', getenv('OPCUA_PASSWORD'))

    // Trust store
    ->setTrustStore(new FileTrustStore('/var/lib/opcua/trust'))
    ->setTrustPolicy(TrustPolicy::Full)

    ->connect('opc.tcp://plc.local:4840');
```
<!-- @endcode-block -->

Defaults to remember:

| Builder default                | Effective behaviour                                |
| ------------------------------ | -------------------------------------------------- |
| `securityPolicy = None`        | No encryption, no signing                          |
| `securityMode = None`          | Same                                               |
| `setTrustStore(null)`          | No certificate validation — the client accepts any server cert |
| `setUserCredentials(...)` unset | Anonymous authentication                         |

The defaults are deliberately permissive — they let `quick-start.md`
work against a fresh server with no configuration. They are **not**
appropriate for production.

## Channel axis — quick map

The full table lives in [Policies](./policies.md). At a glance:

| When                              | Policy                                | Mode               |
| --------------------------------- | ------------------------------------- | ------------------ |
| Local dev, throwaway test         | `None`                                | `None`             |
| Legacy server, integrity only     | `Basic256Sha256`                      | `Sign`             |
| Default for new deployments       | `Basic256Sha256` or `Aes256Sha256RsaPss` | `SignAndEncrypt` |
| Hardened RSA                      | `Aes256Sha256RsaPss`                  | `SignAndEncrypt`   |
| Future, ECC-aware                 | `EccNistP256` / `EccNistP384`         | `SignAndEncrypt`   |

The four ECC policies are implemented per 1.05.4 but are **experimental
in practice** — no commercial server vendor ships ECC endpoints yet.
Stay on RSA for production until that changes.

## Identity axis — quick map

| When                              | Token            |
| --------------------------------- | ---------------- |
| Read-only published metrics       | Anonymous        |
| Operator writes, password-managed | Username         |
| Operator writes, certificate-managed | X.509         |
| Service-to-service, machine identity | X.509 (preferred) |

See [Authentication](./authentication.md) for the three flows and the
related discovery semantics.

## Cache path

A v4.3.0 hardening worth flagging here so it does not get lost in the
operational docs: this library no longer calls `unserialize()` on
cache values. PSR-16 cache payloads pass through `WireCacheCodec`,
which is JSON-only and gated by a type allowlist. If your cache
backend is writable by anyone other than the client itself, this
removes a real object-injection surface. See [Cache path
hardening](./cache-path-hardening.md).

## What to read next

Pick the axis that fits the task:

- [Policies](./policies.md) — the full 10-policy map.
- [Certificates](./certificates.md) — generation, formats,
  fingerprinting.
- [Authentication](./authentication.md) — anonymous, username, X.509.
- [Trust store](./trust-store.md) — how the client decides which
  server certificates to trust.
