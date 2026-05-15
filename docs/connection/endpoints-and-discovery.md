---
eyebrow: 'Docs · Connection'
lede:    'Before opening a channel, the client asks the server which endpoints it offers — what URLs, what security policies, what user-token policies. Discovery is how those answers find their way into the connection you actually open.'

see_also:
  - { href: './opening-and-closing.md',    meta: '6 min' }
  - { href: '../security/policies.md',     meta: '7 min' }
  - { href: '../observability/caching.md', meta: '5 min' }

prev: { label: 'Thinking in OPC UA',  href: '../getting-started/thinking-in-opc-ua.md' }
next: { label: 'Opening and closing', href: './opening-and-closing.md' }
---

# Endpoints and discovery

An OPC UA server exposes one or more **endpoints**. Each endpoint is a
URL plus a security policy, a security mode, and a list of accepted
identity-token policies (anonymous, username, certificate). A server
that supports `Basic256Sha256 + Sign` and `Basic256Sha256 +
SignAndEncrypt` advertises two endpoints, not one.

Discovery is the GetEndpoints service call that returns the full
catalogue, plus the server certificate and the identity-token policy
IDs the client needs in order to authenticate correctly.

## Listing endpoints

<!-- @code-block language="php" label="examples/list-endpoints.php" -->
```php
use PhpOpcua\Client\ClientBuilder;

$client = ClientBuilder::create()->connect('opc.tcp://localhost:4840');

foreach ($client->getEndpoints('opc.tcp://localhost:4840') as $ep) {
    printf(
        "%-50s %-10s %-20s\n",
        $ep->endpointUrl,
        $ep->securityMode->name,
        basename($ep->securityPolicyUri)
    );
}

$client->disconnect();
```
<!-- @endcode-block -->

Output, against a real server, looks like:

<!-- @code-block language="text" label="sample output" -->
```text
opc.tcp://plc.local:4840                          None       None
opc.tcp://plc.local:4840                          Sign       Basic256Sha256
opc.tcp://plc.local:4840                          SignAndEncrypt  Basic256Sha256
opc.tcp://plc.local:4840                          Sign       Aes256Sha256RsaPss
opc.tcp://plc.local:4840                          SignAndEncrypt  Aes256Sha256RsaPss
```
<!-- @endcode-block -->

Each row is an `EndpointDescription` DTO:

<!-- @method name="$client->getEndpoints(string \$endpointUrl, bool \$useCache = true): array" returns="EndpointDescription[]" visibility="public" -->

<!-- @params -->
<!-- @param name="$endpointUrl" type="string" required -->
The discovery URL — usually the same `opc.tcp://host:port` you would
connect to. Discovery does not require authentication or security; the
call runs over a transient, unencrypted channel that the client opens
and closes around the request.
<!-- @endparam -->
<!-- @param name="$useCache" type="bool" default="true" -->
When `true`, results are cached per endpoint URL for 300 seconds via
the configured PSR-16 cache. Pass `false` to force a fresh GetEndpoints
round-trip — useful when a server's endpoint list changes (rare) or
when debugging.
<!-- @endparam -->
<!-- @endparams -->

The returned `EndpointDescription` carries every field you need to
configure the *next* connection:

| Property                | Use                                                          |
| ----------------------- | ------------------------------------------------------------ |
| `endpointUrl`           | The exact URL to pass to `connect()` — may differ from the discovery URL (load-balanced setups, multi-homed servers). |
| `securityPolicyUri`     | Pass to `SecurityPolicy::from()` to recover the enum case.   |
| `securityMode`          | `MessageSecurityMode` — None / Sign / SignAndEncrypt.        |
| `serverCertificate`     | DER-encoded server cert. Feed it to the trust store, or pin it. |
| `userIdentityTokens`    | Array of `UserTokenPolicy` — one per acceptable auth mode.   |
| `transportProfileUri`   | Always `…UA-TCP UA-SC UA-Binary` for this library.           |
| `securityLevel`         | Server-assigned hint; higher is stronger. Not authoritative — pick by policy + mode you trust, not by this number. |

## Discovery runs automatically on `connect()`

You rarely need to call `getEndpoints()` by hand. `ClientBuilder::connect()`
runs discovery for you whenever the client needs information it does
not yet have — most often: the server's certificate (if security is on
and no certificate is configured), or any identity-token policy ID
(anonymous, username, certificate).

<!-- @version-badge type="changed" version="v4.3.1" --> the discovery
trigger now fires whenever the **anonymous**, **username**, or
**certificate** policy ID is unknown. Before v4.3.1 it only fired for
the anonymous policy; servers advertising non-standard policy IDs for
username or certificate auth (open62541, Siemens S7) responded with
`BadIdentityTokenInvalid`.

The flow inside `connect()` is:

<!-- @steps -->
- **HEL/ACK transport handshake**

  TCP socket open, OPC UA `Hello` / `Acknowledge` framing, buffer-size
  negotiation. No cryptography yet.

- **Discovery (only if needed)**

  If the client lacks the server certificate or a required policy ID,
  it opens a transient unsecured channel, sends `GetEndpoints`, picks
  the entry whose policy URI + mode match the builder configuration,
  and closes the channel. Result is cached.

- **Open the real secure channel**

  `OpenSecureChannel` (OPN) — sign-only for ECC, sign and/or encrypt
  for RSA. The server certificate from discovery is the input to the
  asymmetric key exchange.

- **Create and activate the session**

  `CreateSession` + `ActivateSession`. The identity token (anonymous,
  username, certificate) is encrypted under the channel keys using the
  policy ID that discovery returned.
<!-- @endsteps -->

## Picking the right endpoint manually

When the same server publishes multiple endpoints with the same policy,
the library picks the first match. To pick a different one — for
example, an internal `opc.tcp://10.x.x.x:4840` URL when the discovery
URL is the public one — iterate manually and feed the chosen
`endpointUrl` back into a new builder:

<!-- @code-block language="php" label="targeted endpoint selection" -->
```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Security\SecurityMode;

$probe = ClientBuilder::create()->connect('opc.tcp://plc.local:4840');

$endpoint = null;
foreach ($probe->getEndpoints('opc.tcp://plc.local:4840') as $ep) {
    if ($ep->securityMode === SecurityMode::SignAndEncrypt
        && $ep->securityPolicyUri === SecurityPolicy::Basic256Sha256->value
    ) {
        $endpoint = $ep;
        break;
    }
}
$probe->disconnect();

if ($endpoint === null) {
    throw new RuntimeException('Server does not offer the required endpoint.');
}

$client = ClientBuilder::create()
    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
    ->setSecurityMode(SecurityMode::SignAndEncrypt)
    ->setClientCertificate('/etc/opcua/client.pem', '/etc/opcua/client.key')
    ->connect($endpoint->endpointUrl);
```
<!-- @endcode-block -->

## Caching behaviour

Discovery is cached against the PSR-16 cache the builder is configured
with — `InMemoryCache` with a 300-second TTL by default. The cache key
includes the endpoint URL hash, so probing the same server from
multiple clients in the same PHP process avoids redundant round-trips.

Bypass the cache with `getEndpoints($url, useCache: false)`. Flush all
cached entries (browse, resolve, endpoints, discovered types) with
`$client->flushCache()`. See [Observability ·
Caching](../observability/caching.md).

## What to read next

- [Connection · Opening and closing](./opening-and-closing.md) — the
  session lifecycle, `ConnectionState`, and `reconnect()`.
- [Security · Policies](../security/policies.md) — when to pick which
  policy/mode combination.
- [Security · Authentication](../security/authentication.md) — how the
  identity-token policy IDs returned by discovery are used to
  authenticate.
