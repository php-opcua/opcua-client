---
eyebrow: 'Docs · Security'
lede:    'Three identity tokens — anonymous, username, X.509. Authentication is independent of channel security, but the channel''s keys protect the token in flight.'

see_also:
  - { href: './overview.md',        meta: '5 min' }
  - { href: './certificates.md',    meta: '6 min' }
  - { href: '../connection/endpoints-and-discovery.md', meta: '6 min' }

prev: { label: 'Certificates',  href: './certificates.md' }
next: { label: 'Trust store',   href: './trust-store.md' }
---

# Authentication

The OPC UA session's identity is carried by an **identity token**. The
library supports the three the spec defines:

| Token         | Builder method                            | When                                  |
| ------------- | ----------------------------------------- | ------------------------------------- |
| Anonymous     | (default — no builder call)               | Service-to-service, read-only telemetry |
| Username/password | `setUserCredentials($u, $p)`          | Operators, legacy ACL systems         |
| X.509 certificate | `setUserCertificate($certPath, $keyPath)` | Hardened service identity, audit-grade |

Pick the token independently from the channel `SecurityPolicy` and
`SecurityMode`. A common combination: `Basic256Sha256 +
SignAndEncrypt` channel with anonymous identity, for a backend that
reads published metrics; another session on the same client (or a
different one) opens with username credentials for write paths.

## Anonymous

The default. No builder call required. The server's identity-token
policy table must include `UserTokenType::Anonymous` for this to work
— otherwise `ActivateSession` returns `BadIdentityTokenRejected`.

<!-- @code-block language="php" label="anonymous session" -->
```php
$client = ClientBuilder::create()
    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
    ->setSecurityMode(SecurityMode::SignAndEncrypt)
    ->connect('opc.tcp://plc.local:4840');
```
<!-- @endcode-block -->

Anonymous sessions can read everything the server publishes
unauthenticated. Writes and method calls usually require a real
identity. This is server-side configuration; the client cannot tell
you what an anonymous session is allowed to do — try it and check the
status code.

## Username / password

<!-- @method name="ClientBuilder::setUserCredentials(string \$username, string \$password): self" returns="self" visibility="public" -->

<!-- @code-block language="php" label="username session" -->
```php
$client = ClientBuilder::create()
    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
    ->setSecurityMode(SecurityMode::SignAndEncrypt)
    ->setUserCredentials('operator', getenv('OPCUA_PASSWORD'))
    ->connect('opc.tcp://plc.local:4840');
```
<!-- @endcode-block -->

The password is **encrypted under the channel's asymmetric keys**
before transit, using the encryption algorithm the server's
`UserTokenPolicy[].securityPolicyUri` declares. The library handles
this transparently — you pass the cleartext password to the builder,
it never leaves the process in cleartext.

<!-- @callout variant="warning" -->
Username over a `SecurityPolicy::None` channel transmits the password
in cleartext. The OPC UA spec allows the combination; the library
allows it; do not use it. If your server does not offer at least
`Sign` on the username endpoint, file a vendor bug and use the
anonymous endpoint until it's fixed.
<!-- @endcallout -->

### Policy ID discovery

Each `UserTokenPolicy` the server publishes has a unique `policyId`
string — `"anonymous"`, `"username"`, `"certificate"` are common
conventions but not standards. open62541, Siemens S7, and several COTS
PLCs publish non-standard IDs (`"open62541-anonymous-policy"`, vendor
prefixes). The library discovers these IDs via `GetEndpoints` before
encoding the identity token — without that, `ActivateSession` returns
`BadIdentityTokenInvalid`.

<!-- @version-badge type="changed" version="v4.3.1" --> the discovery
trigger now fires for **anonymous**, **username**, and **certificate**
policy IDs. Earlier versions only discovered the anonymous ID and
hardcoded `"username"` / `"certificate"`.

## X.509 certificate

<!-- @method name="ClientBuilder::setUserCertificate(string \$certPath, string \$keyPath): self" returns="self" visibility="public" -->

X.509 user authentication is the strongest of the three. The session
identity is a certificate distinct from the **client application**
certificate (the one set with `setClientCertificate()`) — one
identifies the application, the other identifies the user behind the
session.

<!-- @code-block language="php" label="user-certificate session" -->
```php
$client = ClientBuilder::create()
    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
    ->setSecurityMode(SecurityMode::SignAndEncrypt)
    ->setClientCertificate(
        certPath: '/etc/opcua/app.pem',
        keyPath:  '/etc/opcua/app.key',
    )
    ->setUserCertificate(
        certPath: '/etc/opcua/users/ci-bot.pem',
        keyPath:  '/etc/opcua/users/ci-bot.key',
    )
    ->connect('opc.tcp://plc.local:4840');
```
<!-- @endcode-block -->

The library:

1. Reads the user certificate's DER bytes and signs them under the
   `securityPolicyUri` of the matching server `UserTokenPolicy`.
2. Sends the signed token in `ActivateSession`.
3. Re-signs if the channel is renewed during the session lifetime.

Server-side, the certificate is validated against the server's
user-certificate trust store — a separate store from the
application-certificate store on most products.

### Same cert for application and user?

Some servers accept it; some reject it. The conservative choice is
two distinct certificates with two distinct subjects, generated by
the same internal CA.

## Anonymous fallback

If the server's identity-token policy table includes both Anonymous
and Username, calling `setUserCredentials()` with empty strings does
**not** revert to anonymous — it produces an invalid token. To switch
identity at runtime, build a new client.

## Failure modes

| StatusCode                          | Meaning                                                  |
| ----------------------------------- | -------------------------------------------------------- |
| `BadIdentityTokenInvalid`           | Token shape rejected — usually a non-standard policy ID  |
| `BadIdentityTokenRejected`          | Server does not accept this identity type at this endpoint |
| `BadUserAccessDenied`               | Username/password mismatch, or certificate not in user trust store |
| `BadCertificateUntrusted`           | User certificate rejected by the server                  |
| `BadCertificateTimeInvalid`         | User certificate expired                                 |
| `BadCertificateUriInvalid`          | Application URI in the cert does not match `CreateSession` |

For client-side surfaces, see [Reference ·
Exceptions](../reference/exceptions.md). The bad statuses listed here
are returned in the `ActivateSession` response and surface as a
`ServiceException` carrying the status code.
