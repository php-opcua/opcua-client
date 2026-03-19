# Changelog

## [1.2.0] - 2026-03-X

### Added

- `Client::setTimeout(float $timeout)` method to configure the timeout (in seconds) for TCP connection and all socket I/O operations. Default remains 5 seconds. The method is fluent and also available on `OpcUaClientInterface`.
- The configured timeout is now passed to `TcpTransport::connect()` both for the main connection and for the server certificate discovery connection.
- `ConnectionState` enum (`Disconnected`, `Connected`, `Broken`) to track the client's connection lifecycle.
- `Client::isConnected()` method returning `true` only when the state is `Connected`.
- `Client::getConnectionState()` method returning the current `ConnectionState`.
- `Client::reconnect()` method to re-establish the connection using the last endpoint URL. Performs a full cleanup + connect cycle. Throws `ConfigurationException` if `connect()` was never called.
- `Client::setAutoRetry(int $maxRetries)` and `Client::getAutoRetry()` methods for configurable automatic reconnect+retry on `ConnectionException` during operations. Default: 0 if never connected, 1 if connected at least once.
- All operations (read, write, browse, call, subscriptions, history, getEndpoints) are wrapped with the auto-retry mechanism.
- `ensureConnected()` private method with state-aware exception messages: `"Not connected: call connect() first"` (Disconnected) and `"Connection lost: call reconnect() or connect() to re-establish"` (Broken).
- `Client::setBatchSize(int $batchSize)` and `Client::getBatchSize(): ?int` methods for configurable automatic batching of `readMulti`/`writeMulti`. When the number of items exceeds the batch size, requests are transparently split and results merged. `setBatchSize(0)` disables batching entirely and skips server operation limits discovery on `connect()`.
- Automatic discovery of server operation limits (`MaxNodesPerRead`, `MaxNodesPerWrite`) after `connect()`. The limits are read from the standard OPC UA nodes (ns=0, i=11705 and i=11707). A server-reported value > 0 is used as the default batch size when `setBatchSize()` is not explicitly called.
- `Client::getServerMaxNodesPerRead(): ?int` and `Client::getServerMaxNodesPerWrite(): ?int` methods to inspect the discovered server limits.
- `BrowseNode` type for representing recursive browse tree nodes, wrapping `ReferenceDescription` with children.
- `Client::browseAll()` method that browses a node and automatically follows all continuation points, returning the complete list of references.
- `Client::browseRecursive(NodeId, direction, maxDepth, ...)` method for recursive address space traversal. Builds a tree of `BrowseNode` objects. Default `maxDepth` is configurable (default: 10), use `-1` for unlimited (hardcoded cap at 256). Includes cycle detection via visited NodeId tracking to prevent infinite loops on circular references.
- `Client::setDefaultBrowseMaxDepth(int)` and `Client::getDefaultBrowseMaxDepth(): int` methods to configure the default `maxDepth` for `browseRecursive()`. Default: 10. Passing `maxDepth` explicitly to `browseRecursive()` overrides the configured default.
- `BrowseDirection` enum (`Forward`, `Inverse`, `Both`) replacing the raw `int $direction` parameter in all browse methods (`browse`, `browseWithContinuation`, `browseAll`, `browseRecursive`, `getBinaryDecoder`). Default is `BrowseDirection::Forward`.
- All new methods are also available on `OpcUaClientInterface`.
- Unit tests for `setTimeout()` and `getTimeout()` covering: default value, setter/getter, fluent chaining, fractional seconds, multiple updates, and `OpcUaClientInterface` compliance.
- Unit tests for `ConnectionState`: enum cases, initial state, disconnect on never-connected client, state-specific exception messages, `reconnect()` without prior connect, and `setAutoRetry` configuration.
- Unit tests for auto-retry: default value, fluent chaining, override, disable, multiple updates, chaining with setTimeout, interface compliance, and no retry when not connected.
- Integration tests for timeout behavior: custom timeout with operations, short but sufficient timeout, connection failure with very short timeout on unreachable host, and timeout persistence across multiple operations.
- Integration tests for connection state transitions: Connected after connect, Disconnected after disconnect, Broken on failed connect, state-specific messages, reconnect recovery, and operations after reconnect.
- Integration tests for auto-retry: default values after connect/disconnect/failed connect, override persistence, operations with retry enabled/disabled, state after retry, and no retry after explicit disconnect.
- Unit tests for batching: default null, fluent chaining, store, disable, update, chaining with other config methods, interface compliance, and server limits null before connect.
- Integration tests for batching: readMulti/writeMulti with and without batching, batch splitting, batchSize=1, result order preservation, server limits discovery, limits reset after disconnect, and setBatchSize override.
- Unit tests for `BrowseNode`: wrapping ReferenceDescription, children management, and nested tree structure.
- Integration tests for `browseAll` and `browseRecursive`: all references, comparison with browse, tree structure, maxDepth 1/2/3, subtree browsing, default maxDepth, configurable default, explicit override, unlimited depth, cycle detection with `BrowseDirection::Both`.
- Unit tests for `setDefaultBrowseMaxDepth` and `getDefaultBrowseMaxDepth`: default value, fluent chaining, store, unlimited, multiple updates, chaining with other config, and interface compliance.
- Unit tests for `BrowseDirection` enum: cases, values, `from()`, and `tryFrom()`.
- Integration test for `BrowseDirection::Both` verifying both forward and inverse references are returned.

### Documentation

- Added "Connection State", "Reconnect", and "Auto-Retry" sections to `doc/02-connection.md` with usage examples and behavior details.
- Added "Timeout Configuration" section to `doc/02-connection.md` with usage examples and tips.
- Added "Configurable Timeout", "Connection State Management", and "Auto-Retry" to the features list in `doc/01-introduction.md` and `README.md`.
- Added `ConnectionState` enum to `doc/08-types.md` types reference.
- Updated `doc/09-error-handling.md` with state-aware `ConnectionException` messages, `ConfigurationException` for reconnect, and recommended error handling pattern with `ConnectionState` check and auto-retry tip.
- Added `ConnectionState.php` to the project structure in `doc/11-architecture.md`.
- Updated disconnection section in `doc/02-connection.md` to document state reset and auto-retry behavior.
- Updated "Full Secure Connection" examples in `doc/02-connection.md` and `README.md` to show `setTimeout()`.
- Added "Automatic Batching" section to `doc/04-reading-writing.md` with server limits discovery, transparent batching, manual batch size, and behavior table.
- Added "Auto-Batching" to the features list in `doc/01-introduction.md` and `README.md`.
- Updated `connect()` step list in `doc/02-connection.md` to include server operation limits discovery.
- Added "Browse All", "Recursive Browse", and `BrowseNode` documentation to `doc/03-browsing.md` with configurable default depth, parameter order (direction before maxDepth), depth limits table, disclaimer for high values, cycle detection explanation, configuration methods table, and `BrowseDirection` enum usage.
- Added `BrowseDirection` enum to `doc/08-types.md`.
- Added `BrowseNode` type to `doc/08-types.md`.
- Added `BrowseNode.php` to the project structure in `doc/11-architecture.md`.
- Updated browse feature description in `doc/01-introduction.md` and `README.md` to include recursive browsing and automatic continuation.
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
