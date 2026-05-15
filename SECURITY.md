# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 4.x     | Yes       |
| 3.x     | No        |
| 2.x     | No        |
| 1.x     | No        |

## Reporting a Vulnerability

If you discover a security vulnerability in this library, please report it responsibly.

**Do not open a public issue.** Instead, send an email to [security@php-opcua.com](mailto:security@php-opcua.com) with:

- A description of the vulnerability
- Steps to reproduce
- The affected version(s)
- Any potential impact assessment

You should receive an acknowledgment within 48 hours. From there, we'll work together to understand the scope and develop a fix before any public disclosure.

## Scope

This policy covers the `php-opcua/opcua-client` library itself. For vulnerabilities in dependencies or related packages, please report them to the respective maintainers:

- [opcua-session-manager](https://github.com/php-opcua/opcua-session-manager)
- [laravel-opcua](https://github.com/php-opcua/laravel-opcua)
- [uanetstandard-test-suite](https://github.com/php-opcua/uanetstandard-test-suite)

## Security Considerations

OPC UA is used in industrial environments where security matters. This library implements the full OPC UA security stack (10 security policies — 6 RSA, including `None`, plus 4 ECC; 3 security modes; X.509 certificate authentication). When deploying in production:

- Use `SecurityPolicy::Basic256Sha256` or stronger
- Use `SecurityMode::SignAndEncrypt`
- Provide proper CA-signed certificates (don't rely on auto-generated self-signed certs)
- Keep PHP and OpenSSL up to date

### Cache Path

Since v4.3.0 the client never calls `unserialize()` on cache values. Cached entries are encoded via `Cache\CacheCodecInterface` (default `Cache\WireCacheCodec` — JSON gated by `Wire\WireTypeRegistry`); poisoned or unknown payloads are detected and discarded as cache misses. If your PSR-16 backend is writable by a less-trusted party (shared Redis, world-readable file cache, multi-tenant Memcached), this removes the object-injection surface that `unserialize()`-based storage would otherwise expose. See [docs/security/cache-path-hardening.md](docs/security/cache-path-hardening.md) for upgrade notes and codec customisation.

## Sharing Debug Logs and Reproducers

When asking for help in a **public** channel (GitHub issues, discussions, Stack Overflow, chat rooms) or attaching logs to a bug report, treat the following as sensitive and either redact or omit them:

| Information                                          | Why it matters                                                                                              |
|------------------------------------------------------|-------------------------------------------------------------------------------------------------------------|
| Endpoint URLs, hostnames, IP addresses, ports        | Reveal industrial network topology — a reachable OPC UA endpoint is a direct attack surface.                |
| Session IDs and authentication tokens                | Short-lived but exploitable within their validity window if intercepted.                                    |
| Server `BuildInfo` (`productName`, `softwareVersion`, `buildNumber`) | Server fingerprint — speeds up CVE lookup against the specific vendor/version running in your plant. |
| Client and server certificates, certificate thumbprints, application URIs | May embed company, site, or device identifiers; thumbprints identify a specific key pair.       |
| Usernames and passwords passed to `setUserCredentials()` | Plain credentials — never include them in shared logs.                                                  |

The example script `scripts/debug.php` ships with a PSR-3 logger that already masks URLs (host → `***`, scheme/port/path preserved), `host` context keys, and `session_id` / `token` / `authToken` values (md5, last 5 hex chars). The `getServerBuildInfo()` log block is commented out by default for the same reason — uncomment it only after deciding the resulting log is safe to share given this policy.

That said, **`BuildInfo` shared anonymously** (vendor and software version only, without endpoint URLs, certificates, network details, or anything tying it to your deployment) is welcome in the [Tested Hardware & Software](https://github.com/php-opcua/opcua-client/discussions/categories/tested-hardware-software) discussions category — it helps other users know which servers and devices have been verified to work with the library.

### Recommended workflow

- For **public** bug reports and reproducers: use the masked logger (or hand-redact equivalents) before attaching logs.
- For **private** support requests where extra context is genuinely required (e.g., a sub-protocol issue tied to a specific host or build): send unmasked logs to the maintainer email above, not to a public thread.
- Treat any historical paste/gist of OPC UA logs as effectively permanent — search engines and forks may have already indexed it. When in doubt, regenerate credentials and certificates rather than relying on deletion.

### Requests for more detailed logs

If a maintainer explicitly asks for richer diagnostics (e.g., unmasked `BuildInfo`, full endpoint URLs, raw certificate chains, or stack traces containing sensitive identifiers), **do not paste them into the public issue or discussion thread.** Send them by email to [security@php-opcua.com](mailto:security@php-opcua.com), referencing the issue/discussion number in the subject line. The maintainer will summarize publicly only the parts relevant to the fix once the investigation is complete.

