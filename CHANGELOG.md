# Changelog

## [3.0.0] - 2026-03-X

### Changed

- **`ExtensionObjectRepository` is now instance-level instead of static.** Each `Client` has its own isolated codec registry. Pass it via the constructor (`new Client(extensionObjectRepository: $repo)`) or access it with `$client->getExtensionObjectRepository()`. Codecs registered on one client no longer affect other clients in the same process.
- **Strict return types for all service responses.** The following methods now return typed DTOs with `public readonly` properties instead of associative arrays:
  - `createSubscription()` → `SubscriptionResult` (`->subscriptionId`, `->revisedPublishingInterval`, `->revisedLifetimeCount`, `->revisedMaxKeepAliveCount`)
  - `createMonitoredItems()` → `MonitoredItemResult[]` (`->statusCode`, `->monitoredItemId`, `->revisedSamplingInterval`, `->revisedQueueSize`)
  - `createEventMonitoredItem()` → `MonitoredItemResult`
  - `call()` → `CallResult` (`->statusCode`, `->inputArgumentResults`, `->outputArguments`)
  - `browseWithContinuation()` / `browseNext()` → `BrowseResultSet` (`->references`, `->continuationPoint`)
  - `publish()` → `PublishResult` (`->subscriptionId`, `->sequenceNumber`, `->moreNotifications`, `->notifications`)
  - `translateBrowsePaths()` → `BrowsePathResult[]` (`->statusCode`, `->targets`) with `BrowsePathTarget` (`->targetId`, `->remainingPathIndex`)

- **All existing Type classes now expose `public readonly` properties.** You can access `$ref->nodeId`, `$ref->displayName`, `$variant->type`, `$dv->statusCode`, etc. directly instead of calling getter methods. Affected classes: `NodeId`, `Variant`, `DataValue`, `QualifiedName`, `LocalizedText`, `ReferenceDescription`, `EndpointDescription`, `UserTokenPolicy`, `BrowseNode`.
- **`nodeClassMask` parameter replaced with `nodeClasses` array.** Browse methods (`browse()`, `browseWithContinuation()`, `browseAll()`, `browseRecursive()`) now accept `NodeClass[] $nodeClasses = []` instead of `int $nodeClassMask = 0`. Pass an array of `NodeClass` enum values (e.g., `[NodeClass::Object, NodeClass::Variable]`) instead of a raw bitmask integer. Empty array means all classes (same as the old `0`).
- **Ambiguous `$items` parameters renamed** for named parameter clarity: `readMulti($readItems)`, `writeMulti($writeItems)`, `createMonitoredItems($subscriptionId, $monitoredItems)`.
- PHP 8.5 added to the CI test matrix.

### Added

- `SubscriptionResult`, `MonitoredItemResult`, `CallResult`, `BrowseResultSet`, `PublishResult`, `BrowsePathResult`, `BrowsePathTarget` DTO classes in `Types/`.
- `Client::getExtensionObjectRepository()` method on `Client` and `OpcUaClientInterface`.
- `Client` constructor now accepts an optional `?ExtensionObjectRepository $extensionObjectRepository` parameter.
- `BinaryDecoder` constructor now accepts an optional `?ExtensionObjectRepository` parameter for codec resolution.
- 800+ unit and integration tests with 99.5%+ code coverage.
- **PSR-3 Logging.** Inject any PSR-3 compatible logger (Monolog, Laravel, etc.) via `$client->setLogger($logger)` or the constructor. Logs connection events (INFO), retry attempts (WARNING), batch splits (INFO), failures (ERROR), and protocol details (DEBUG). Uses `NullLogger` by default.
- `psr/log` ^3.0 added as dependency (interface-only package, zero runtime code).
- **PSR-16 Cache for browse results.** Browse, browseAll, and resolveNodeId results are cached by default using an in-memory PSR-16 cache (300s TTL). Pass `useCache: false` to bypass the cache on any call, or plug in any PSR-16 driver (Laravel Cache, Redis, etc.) via `$client->setCache($driver)`. Ships with `InMemoryCache` and `FileCache`. Use `invalidateCache($nodeId)` or `flushCache()` to manage entries.
- `psr/simple-cache` ^3.0 added as dependency (interface-only package, zero runtime code).
- `InMemoryCache` — PSR-16 in-memory cache implementation with configurable TTL.
- `FileCache` — PSR-16 file-based cache implementation that survives process restarts.
- `ManagesCacheTrait` — trait providing `setCache()`, `getCache()`, `invalidateCache()`, `flushCache()` and internal cache key generation.
- `getEndpoints()` results are now cached. Pass `useCache: false` to bypass.
- `discoverDataTypes()` results are now cached. On cache hit, discovered type definitions are replayed from cache (registers codecs without server round-trips). Especially useful with `FileCache` to persist discovered types across PHP process restarts. Pass `useCache: false` to bypass.
- **`MockClient` for testing.** A drop-in `OpcUaClientInterface` implementation with no TCP connection. Register response handlers with `onRead()`, `onWrite()`, `onBrowse()`, `onCall()`, `onResolveNodeId()`. Track calls with `getCalls()`, `callCount()`, `getCallsFor()`.
- **`DataValue` factory methods.** `DataValue::ofInt32(42)`, `ofDouble(3.14)`, `ofString('hello')`, `ofBoolean(true)`, `of($value, BuiltinType)`, `bad(StatusCode)`.
- **Automatic DataType discovery.** `$client->discoverDataTypes()` browses the server's DataType hierarchy, reads `DataTypeDefinition` attributes (OPC UA 1.04+), and automatically creates `DynamicCodec` instances for all server-defined structured types. Eliminates the need to manually implement codecs for custom types. Supports Structure, StructureWithOptionalFields, and Union types.
- `StructureField`, `StructureDefinition` DTOs in `Types/` for representing discovered type definitions.
- `DynamicCodec` — a generic `ExtensionObjectCodec` that decodes/encodes based on a `StructureDefinition`.
- `DataTypeMapping` — maps OPC UA DataType NodeIds to `BuiltinType` enum values.
- **`transferSubscriptions()`** — transfer existing subscriptions to a new session after reconnection without data loss. Returns `TransferResult[]` with status codes and available sequence numbers.
- **`republish()`** — re-request notifications that were sent but not yet acknowledged. Essential for the session manager to recover from session loss.
- `TransferResult` DTO in `Types/`.
- `StructureDefinitionParser` — parses the binary body of `StructureDefinition` ExtensionObjects.
- `BinaryDecoder::readVariantValue()` is now public (was private).
- **Fluent/Builder API** for multi-node operations. `readMulti()`, `writeMulti()`, `createMonitoredItems()`, and `translateBrowsePaths()` now return a fluent builder when called without arguments: `$client->readMulti()->node('i=2259')->value()->node('i=1001')->displayName()->execute()`. The array-based API still works when passing arguments directly.
- **All methods accepting `NodeId` now also accept `string`.** Pass OPC UA string format directly (e.g., `'i=2259'`, `'ns=2;i=1001'`, `'ns=2;s=MyNode'`). Applies to: `read`, `write`, `browse`, `browseAll`, `browseWithContinuation`, `browseRecursive`, `call` (both params), `historyReadRaw`, `historyReadProcessed`, `historyReadAtTime`, `createEventMonitoredItem`, `resolveNodeId` ($startingNodeId). Also works inside arrays for `readMulti`, `writeMulti`, `createMonitoredItems`, `translateBrowsePaths`.

### Deprecated

- Getter methods on Type classes that are now redundant with `public readonly` properties. All existing getters still work but are marked `@deprecated`. Affected methods include `getNodeId()`, `getDisplayName()`, `getBrowseName()`, `getNodeClass()`, `getStatusCode()` (on DataValue), `getSourceTimestamp()`, `getServerTimestamp()`, `getVariant()`, `getType()` (on Variant), `getValue()` (on Variant), `getDimensions()`, `getNamespaceIndex()`, `getIdentifier()`, `getLocale()`, `getText()`, and all getters on `EndpointDescription`, `UserTokenPolicy`, `ReferenceDescription`, `BrowseNode`. Use direct property access (`->property`) instead.

### Breaking Changes

- All service response methods listed above now return typed objects instead of arrays. Code using `$result['key']` must change to `$result->key`.
- `ExtensionObjectRepository` methods (`register`, `get`, `has`, `unregister`, `clear`) are no longer static. Replace `ExtensionObjectRepository::register(...)` with `$repo->register(...)`.
- Browse methods no longer accept `int $nodeClassMask`. Use `array $nodeClasses` with `NodeClass` enum values instead. Replace `nodeClassMask: 3` with `nodeClasses: [NodeClass::Object, NodeClass::Variable]`.
- `readMulti($items)` renamed to `readMulti($readItems)`, `writeMulti($items)` to `writeMulti($writeItems)`, `createMonitoredItems(..., $items)` to `createMonitoredItems(..., $monitoredItems)`. Only affects code using named parameters.

## [2.0.0] - 2026-03-19

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
- `BrowseNode` type for representing recursive br owse tree nodes, wrapping `ReferenceDescription` with children.
- `Client::browseAll()` method that browses a node and automatically follows all continuation points, returning the complete list of references.
- `Client::browseRecursive(NodeId, direction, maxDepth, ...)` method for recursive address space traversal. Builds a tree of `BrowseNode` objects. Default `maxDepth` is configurable (default: 10), use `-1` for unlimited (hardcoded cap at 256). Includes cycle detection via visited NodeId tracking to prevent infinite loops on circular references.
- `Client::setDefaultBrowseMaxDepth(int)` and `Client::getDefaultBrowseMaxDepth(): int` methods to configure the default `maxDepth` for `browseRecursive()`. Default: 10. Passing `maxDepth` explicitly to `browseRecursive()` overrides the configured default.
- `BrowseDirection` enum (`Forward`, `Inverse`, `Both`) replacing the raw `int $direction` parameter in all browse methods (`browse`, `browseWithContinuation`, `browseAll`, `browseRecursive`, `getBinaryDecoder`). Default is `BrowseDirection::Forward`.
- `TranslateBrowsePathService` protocol service implementing the OPC UA `TranslateBrowsePathsToNodeIds` service (request NodeId 554).
- `Client::translateBrowsePaths(array $browsePaths)` method for translating browse paths to NodeIds. Supports multiple paths in a single request with full control over reference types and direction.
- `Client::resolveNodeId(string $path, ?NodeId $startingNodeId)` helper method for resolving human-readable paths like `"/Objects/Server/ServerStatus"` to NodeIds. Supports namespaced segments (`"2:Temperature"`) and custom starting nodes.
- `ExtensionObjectCodec` interface for implementing custom ExtensionObject decoders/encoders with `decode(BinaryDecoder)` and `encode(BinaryEncoder, mixed)` methods.
- `ExtensionObjectRepository` static registry for registering codecs by type NodeId. Supports registration by class name or instance, unregister, has, get, and clear. When a codec is registered, `BinaryDecoder::readExtensionObject()` automatically uses it instead of returning a raw binary blob.
- All new methods are also available on `OpcUaClientInterface`.

### Tests

- Unit tests for `ExtensionObjectRepository`: default empty, register by class/instance, unregister, clear, independent typeIds, string NodeIds.
- Unit tests for ExtensionObject decoding: codec-based decoding, raw fallback without codec, XML encoding fallback, no-body encoding, codec round-trip.
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
- Integration tests for `translateBrowsePaths`: single path, multi-segment path, multiple paths, non-existent path.
- Integration tests for `resolveNodeId`: simple path, without leading slash, deep path, custom starting node, resolve-then-read, non-existent path exception.

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
- Added "Path Resolution" section to `doc/03-browsing.md` with `resolveNodeId()` and `translateBrowsePaths()` documentation, path format, namespaced segments, and advanced usage.
- Added "Path Resolution" to the features list in `doc/01-introduction.md` and `README.md`.
- Added `TranslateBrowsePathService.php` to the project structure in `doc/11-architecture.md`.
- Added "ExtensionObject Codecs" section to `doc/08-types.md` with interface, registry API, usage example, and notes.
- Added "ExtensionObject Codecs" to the features list in `doc/01-introduction.md` and `README.md`.
- Added `ExtensionObjectCodec.php` and `ExtensionObjectRepository.php` to the project structure in `doc/11-architecture.md`.
- Updated `README.md` disclaimer to recommend `gianfriaur/opcua-php-client-session-manager` for session persistence across PHP requests.

### Fixed

- `Variant` now preserves multi-dimensional array dimensions. Previously, `BinaryDecoder::readVariant()` read the dimensions from the OPC UA binary stream but discarded them. Dimensions are now stored in the `Variant` via the new `getDimensions(): ?int[]` and `isMultiDimensional(): bool` methods. `BinaryEncoder::writeVariant()` now writes the dimensions back (flag `0x40`) when present, enabling correct round-trips of multi-dimensional arrays.


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
