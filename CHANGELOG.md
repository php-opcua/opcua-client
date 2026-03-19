# Changelog

## [1.2.0] - 2026-03-X

### Added

- `Client::setTimeout(float $timeout)` method to configure the timeout (in seconds) for TCP connection and all socket I/O operations. Default remains 5 seconds. The method is fluent and also available on `OpcUaClientInterface`.
- The configured timeout is now passed to `TcpTransport::connect()` both for the main connection and for the server certificate discovery connection.
- Unit tests for `setTimeout()` and `getTimeout()` covering: default value, setter/getter, fluent chaining, fractional seconds, multiple updates, and `OpcUaClientInterface` compliance.
- Integration tests for timeout behavior: custom timeout with operations, short but sufficient timeout, connection failure with very short timeout on unreachable host, and timeout persistence across multiple operations.

### Documentation

- Added "Timeout Configuration" section to `doc/02-connection.md` with usage examples and tips.
- Added "Configurable Timeout" to the features list in `doc/01-introduction.md` and `README.md`.
- Updated `doc/09-error-handling.md` to reference `setTimeout()` in the `ConnectionException` read timeout description.
- Added `OpcUaClientInterface.php` to the project structure in `doc/11-architecture.md`.
- Updated "Full Secure Connection" examples in `doc/02-connection.md` and `README.md` to show `setTimeout()`.
- Updated `README.md` disclaimer to recommend `gianfriaur/opcua-php-client-session-manager` for session persistence across PHP requests.

## [1.1.1] - 2026-03-18

### Added

- `NodeId::parse(string $nodeIdString)` static method to parse a NodeId from its OPC UA string representation (e.g. `i=85`, `ns=2;i=1001`, `ns=2;s=MyNode`, `ns=0;g=...`, `ns=0;b=...`). Throws `InvalidNodeIdException` on invalid or unknown formats.
- `NodeId::toString()` method to serialize a NodeId back to its canonical OPC UA string form. The namespace prefix (`ns=X;`) is omitted when the namespace index is 0.
- `NodeId::__toString()` magic method for seamless string casting.
- Unit tests for `NodeId::parse()` and `NodeId::toString()` covering: numeric, string, guid, and opaque types, namespace handling, special characters, error cases, and parse/toString roundtrip consistency.

## [1.1.0] - 2026-03-18

### Changed

- Improved `Client` readability by splitting it into focused traits and various minor optimizations.

### Added

- Auto-generation of self-signed client certificates when none are provided. The client now automatically generates an in-memory RSA 2048-bit self-signed X.509 certificate on the fly when a secure connection is requested without calling `setClientCertificate()`. This simplifies initial setup and testing against servers that accept any client certificate (e.g. auto-accept servers).
- `CertificateManager::generateSelfSignedCertificate()` method that generates a self-signed certificate with proper OPC UA extensions (keyUsage, extendedKeyUsage, subjectAltName with application URI and hostname). The certificate and private key are generated entirely in memory without writing permanent files to disk.
- Unit tests for `generateSelfSignedCertificate()` covering: valid DER output, RSA key size, SAN/applicationUri, thumbprint, public key extraction, uniqueness across calls, and no filesystem side effects.
- Integration tests for secure connections using auto-generated certificates (SignAndEncrypt and Sign modes, with and without username/password authentication).

## [1.0.1] - 2026-03-16

### Generalization

- Added `OpcUaClientInterface` for `Client` rappresentation
