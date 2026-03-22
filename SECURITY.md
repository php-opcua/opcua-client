# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 3.x     | Yes       |
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

This policy covers the `gianfriaur/opcua-php-client` library itself. For vulnerabilities in dependencies or related packages, please report them to the respective maintainers:

- [opcua-php-client-session-manager](https://github.com/GianfriAur/opcua-php-client-session-manager)
- [opcua-laravel-client](https://github.com/GianfriAur/opcua-laravel-client)
- [opcua-test-server-suite](https://github.com/GianfriAur/opcua-test-server-suite)

## Security Considerations

OPC UA is used in industrial environments where security matters. This library implements the full OPC UA security stack (6 security policies, 3 security modes, X.509 certificate authentication). When deploying in production:

- Use `SecurityPolicy::Basic256Sha256` or stronger
- Use `SecurityMode::SignAndEncrypt`
- Provide proper CA-signed certificates (don't rely on auto-generated self-signed certs)
- Keep PHP and OpenSSL up to date
