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

**Do not open a public issue.** Instead, send an email to [gianfri.aur@gmail.com](mailto:gianfri.aur@gmail.com) with:

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

OPC UA is used in industrial environments where security matters. This library implements the full OPC UA security stack (6 security policies, 3 security modes, X.509 certificate authentication). When deploying in production:

- Use `SecurityPolicy::Basic256Sha256` or stronger
- Use `SecurityMode::SignAndEncrypt`
- Provide proper CA-signed certificates (don't rely on auto-generated self-signed certs)
- Keep PHP and OpenSSL up to date

