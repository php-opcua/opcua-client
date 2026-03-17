# Changelog

## [1.0.2] - 2026-03-17

### Added

- Auto-generation of self-signed client certificates when none are provided. The client now automatically generates an in-memory RSA 2048-bit self-signed X.509 certificate on the fly when a secure connection is requested without calling `setClientCertificate()`. This simplifies initial setup and testing against servers that accept any client certificate (e.g. auto-accept servers).
- `CertificateManager::generateSelfSignedCertificate()` method that generates a self-signed certificate with proper OPC UA extensions (keyUsage, extendedKeyUsage, subjectAltName with application URI and hostname). The certificate and private key are generated entirely in memory without writing permanent files to disk.
- Unit tests for `generateSelfSignedCertificate()` covering: valid DER output, RSA key size, SAN/applicationUri, thumbprint, public key extraction, uniqueness across calls, and no filesystem side effects.
- Integration tests for secure connections using auto-generated certificates (SignAndEncrypt and Sign modes, with and without username/password authentication).

## [1.0.1] - 2026-03-16

### Generalization

- Added OpcUaClientInterface for Client
