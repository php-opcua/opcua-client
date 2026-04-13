# Changelog

## [v4.1.0] - 2026-04-13

### Added

- **ECC security policies: `ECC_nistP256`, `ECC_nistP384`, `ECC_brainpoolP256r1`, and `ECC_brainpoolP384r1`.** Full Elliptic Curve Cryptography support for OPC UA secure channels, including (see [ECC disclaimer](doc/10-security.md) and [1.05.4 compliance roadmap](ROADMAP.md#ecc-1054-compliance)):
  - ECDSA signatures (SHA-256 / SHA-384) for OpenSecureChannel (sign-only, no asymmetric encryption)
  - ECDH ephemeral key agreement for symmetric key derivation
  - HKDF-SHA256 / HKDF-SHA384 key derivation with mode-dependent salt (replaces P_SHA for ECC)
  - HMAC-SHA256 / HMAC-SHA384 symmetric signing for MSG messages
  - AES-128-CBC (P-256) / AES-256-CBC (P-384) symmetric encryption
  - Auto-generated ECC certificates when no client certificate is provided (NIST P-256/P-384 or Brainpool P-256/P-384)
  - Username/password authentication via `EccEncryptedSecret` protocol (ECDH + AES + ECDSA signature)
  - `ECDHPolicyUri` request in CreateSession `AdditionalHeader` to obtain server ephemeral key
  - Parsing of `ECDHKey` (EphemeralKeyType) from server's `AdditionalHeader` response
  - Raw R||S ECDSA signature format conversion (DER to/from raw) for OPN, ActivateSession, and EncryptedSecret
- **New `SecurityPolicy` enum cases:** `EccNistP256`, `EccNistP384`, `EccBrainpoolP256r1`, `EccBrainpoolP384r1` with methods `isEcc()`, `getEcdhCurveName()`, `getEphemeralKeyLength()`.
- **New `MessageSecurity` methods:** `computeEcdhSharedSecret()`, `deriveKeysHkdf()`, `generateEphemeralKeyPair()`, `loadEcPublicKeyFromBytes()`, `ecdsaDerToRaw()`, `ecdsaRawToDer()`.
- **New `CertificateManager` methods:** `getKeyType()`, ECC certificate generation via optional `$eccCurveName` parameter on `generateSelfSignedCertificate()`.
- **7 new NIST ECC integration tests** against the `uanetstandard-test-suite` ECC server (port 4848): P-256 Sign, P-256 SignAndEncrypt (anonymous + admin + read), P-384 SignAndEncrypt (anonymous + admin), P-384 Sign.
- **7 new Brainpool ECC integration tests** against the `uanetstandard-test-suite` Brainpool server (port 4849): brainpoolP256r1 Sign, brainpoolP256r1 SignAndEncrypt (anonymous + admin + read), brainpoolP384r1 SignAndEncrypt (anonymous + admin), brainpoolP384r1 Sign.

- **5 new granular exception classes** for more precise error handling (all backward-compatible — extend existing exceptions):
  - `OpenSslException` (extends `SecurityException`) — thrown when an OpenSSL function returns false.
  - `SignatureVerificationException` (extends `SecurityException`) — thrown when OPN or MSG signature verification fails.
  - `UnsupportedCurveException` (extends `SecurityException`) — thrown for unsupported ECC curves, with `$curveName` property.
  - `MessageTypeException` (extends `ProtocolException`) — thrown when the server responds with an unexpected message type, with `$expected` and `$actual` properties.
  - `HandshakeException` (extends `ProtocolException`) — thrown when the HEL/ACK handshake fails with a server error, with `$errorCode` property.
- **`CertificateParseException`** (extends `SecurityException`) — thrown for missing fields in parsed certificates.
- **ECC disclaimer** in README and Security documentation noting that no commercial OPC UA vendor supports ECC endpoints yet, and the implementation is tested exclusively against UA-.NETStandard.
- **ECC 1.05.4 compliance section** in ROADMAP with detailed analysis of `LegacySequenceNumbers` and per-message IV requirements.

### Changed

- **Unit test coverage raised to 99%+.** Added 200+ unit tests across all source files. Every `src/` class and trait now has dedicated coverage — Client traits, Security (SecureChannel, MessageSecurity, CertificateManager), Protocol (SessionService, MonitoredItemService), TrustStore (FileTrustStore), Cache (FileCache), and Types (DataValue).
- **Test infrastructure reorganized.** Eliminated `*BoostTest.php` files. Extracted shared test helpers into `tests/Unit/Helpers/ClientTestHelpers.php`. Each source class now has its tests in the matching path (`src/Foo/Bar.php` → `tests/Unit/Foo/BarTest.php`).
- **Exception hierarchy granularized.** Generic `SecurityException` and `ProtocolException` throws replaced with specific subclasses (`OpenSslException`, `SignatureVerificationException`, `UnsupportedCurveException`, `MessageTypeException`, `HandshakeException`). All existing `catch (SecurityException)` and `catch (ProtocolException)` code continues to work unchanged.
- **`EnsuresOpenSslSuccess` trait.** Extracted the duplicated `ensureNotFalse()` method from `CertificateManager` and `MessageSecurity` into a shared trait in `src/Security/EnsuresOpenSslSuccess.php`. Now throws `OpenSslException` instead of generic `SecurityException`.
- **`MessageSecurity::getCoordinateSize()`.** Extracted duplicated EC coordinate size match expression into a reusable private method.
- **Diagnostic info parsing in `BrowseService` and `WriteService`.** Replaced manual byte-reading loops with `$decoder->skipDiagnosticInfoArray()` for correct OPC UA DiagnosticInfo format parsing.
- **Removed all inline comments from function bodies** per CONTRIBUTING.md guidelines. Extracted `SessionService::readEccServerEphemeralKey()` to replace commented ECC key parsing logic.
- **Removed `glob() === false` dead branches** in `FileTrustStore` and `FileCache` — `glob()` never returns `false` on Linux with a valid pattern.
- **`FileTrustStore::parseCertificateInfo` changed from `private` to `protected`** to enable proper subclass-based testing.
- **`FileTrustStore::throwCertificateParseExceptionIfNull`** — new protected method replacing nullable ternary operators for certificate date fields, with dedicated `CertificateParseException`.

## [v4.0.3] - 2026-04-07

### Added

- **AI-Ready documentation.** Added `llms-skills.md` with 15 task-oriented recipes for AI coding assistants (connect, read, write, browse, subscribe, security, session manager, Laravel, testing, history, methods, types, logging, events, troubleshooting). Designed to be fed to Claude, Cursor, Copilot, ChatGPT, and other AI tools so they can generate correct code for the php-opcua ecosystem.
- Added AI-Ready section to README with instructions for integrating with Claude Code, Cursor, GitHub Copilot, and other AI tools.

## [v4.0.2] - 2026-04-02

### Changed

- **Migrated test infrastructure from `opcua-test-suite` to `uanetstandard-test-suite`.** Integration tests now run against the [OPC Foundation UA-.NETStandard](https://github.com/OPCFoundation/UA-.NETStandard) reference implementation instead of node-opcua. This is the de facto standard OPC UA stack, maintained by the same organization that defines the specification.
- Updated GitHub Actions workflow to use `php-opcua/uanetstandard-test-suite@v1.1.0`.
- Updated certificate paths in `TestHelper.php` to point to the new test suite.

### Added

- **Certificate validation integration tests (`CertificateValidationTest.php`).** New tests that verify real certificate validation against the strict server (port 4842, no auto-accept):
  - Trusted client certificate connects successfully.
  - Untrusted self-signed certificate is rejected.
  - Anonymous connection without credentials is rejected.
  - Self-signed certificate without OPC UA SAN is rejected even on auto-accept server.

### Fixed

- Fixed `TrustedCertsDir` path in the test server — client certificates were not being loaded into the server's trust store, meaning certificate validation on port 4842 was never actually enforced.

## [v4.0.1] - 2026-03-30

### Added

- **Comprehensive debug logging for all OPC UA service calls.** Every request sent to and response received from the server is now logged at `DEBUG` level via PSR-3, enabling full observability of the client–server communication. Previously, only a few operations (connection lifecycle, type discovery, retry logic) were logged. The following traits now include request/response logging:
  - **ManagesBrowseTrait** — `GetEndpoints`, `Browse`, `BrowseNext`.
  - **ManagesHandshakeTrait** — `HEL/ACK` handshake, discovery `GetEndpoints`, discovery `OPN`.
  - **ManagesHistoryTrait** — `HistoryReadRaw`, `HistoryReadProcessed`, `HistoryReadAtTime`.
  - **ManagesSecureChannelTrait** — `OpenSecureChannel` (with and without security), `CloseSecureChannel`.
  - **ManagesSessionTrait** — `CreateSession`, `ActivateSession`, `CloseSession`.
  - **ManagesSubscriptionsTrait** — `CreateSubscription`, `CreateMonitoredItems`, `CreateEventMonitoredItem`, `DeleteMonitoredItems`, `ModifyMonitoredItems`, `SetTriggering`, `DeleteSubscription`, `Publish`, `TransferSubscriptions`, `Republish`.
  - **ManagesTranslateBrowsePathTrait** — `TranslateBrowsePaths`.
  - **ManagesReadWriteTrait** — `Read`, `ReadMulti`, `Write`, `WriteMulti` (including batched), `Call`.
- Each log entry includes contextual data (NodeId, subscription ID, item count, status codes, channel ID, etc.) for effective filtering and debugging.
- **`endpoint` and `session_id` in every log context.** All log messages now include `endpoint` (the connected OPC UA endpoint URL) and `session_id` (the authentication token) in the PSR-3 context array. These fields are not part of the log message text, but are available for structured logging pipelines (e.g. Monolog processors for Graylog/ELK). A new `logContext()` helper method in `Client` centralizes this enrichment.

## [v4.0.0] - 2026-03-26

### Removed

- **CLI tool extracted to [`php-opcua/opcua-cli`](https://github.com/php-opcua/opcua-cli).** The entire `src/Cli/` directory, `bin/opcua-cli`, CLI tests, `doc/15-cli.md`, and `https://github.com/php-opcua/opcua-cli/blob/master/doc/03-code-generation.md` have been moved to a standalone package. Install it with `composer require php-opcua/opcua-cli`. All 10 commands (`browse`, `read`, `write`, `endpoints`, `watch`, `generate:nodeset`, `dump:nodeset`, `trust`, `trust:list`, `trust:remove`), the NodeSet2.xml code generator, and all CLI documentation now live in the new repository.
- Removed `"bin"` entry from `composer.json`.
- Renamed `doc/16-trust-store.md` → `doc/15-trust-store.md` (CLI sections replaced with a link to the new package).

### Added

- **CLI `dump:nodeset` command.** Export a live server's address space to a NodeSet2.xml file: `opcua-cli dump:nodeset opc.tcp://server:4840 --output=MyPLC.NodeSet2.xml [--namespace=2]`. Browses the entire address space recursively, reads node attributes (DataType, ValueRank, IsAbstract, Symmetric), discovers structured DataType definitions and enumerations, and produces a valid NodeSet2.xml that can be fed directly to `generate:nodeset`. Filters by namespace index (default: all non-zero). Full security support.
- **NodeSet2.xml Code Generator.** New `generate:nodeset` CLI command reads OPC UA NodeSet2.xml files (companion specifications, PLC information models) and generates five types of PHP classes:
  - **NodeId constants** — one class per file with all node IDs as string constants, usable with `read()`, `write()`, `browse()`.
  - **PHP enums** — `BackedEnum` classes for every OPC UA enumeration type in the file.
  - **Typed DTOs** — `readonly` classes with typed properties for structured DataTypes. Enum fields are typed with the generated enum class. Array fields (`ValueRank >= 0`) use `array`. Optional fields (`IsOptional`) are nullable.
  - **Binary codecs** — `ExtensionObjectCodec` implementations that decode into DTOs and encode from DTOs. Array fields use `readArray`/`writeArray` helpers. Enum fields cast via `::from()`.
  - **Registrar** — implements `GeneratedTypeRegistrar` with `registerCodecs()`, `getEnumMappings()`, and `dependencyRegistrars()`. Uses NodeId constants (not raw strings) for codec registration.
  - Parses `<UAObject>`, `<UAVariable>`, `<UAMethod>`, `<UAObjectType>`, `<UAVariableType>`, `<UAReferenceType>`, `<UADataType>` with struct and enum `<Definition>`. Resolves `<Aliases>` and `HasEncoding` references. Sanitizes field names and class names (handles special characters and numeric prefixes).
  - Usage: `opcua-cli generate:nodeset path/to/File.NodeSet2.xml --output=src/Generated/ --namespace=App\\OpcUa [--base-namespace=PhpOpcua\\Nodeset]`.
  - No server connection required — works entirely from the local XML file.
  - See [Code Generation](https://github.com/php-opcua/opcua-cli/blob/master/doc/03-code-generation.md) for full documentation.
- **Generated Type Loading and Automatic Dependency Resolution.**
  - `loadGeneratedTypes(GeneratedTypeRegistrar $registrar)` — registers codecs and enum mappings with the builder (called before `connect()`). After loading, `read()` on enum nodes returns PHP `BackedEnum` instances instead of raw `int`, and structured types return typed DTO objects with property access (`$snapshot->Temperature_C` instead of `$data['Temperature_C']`).
  - **Automatic dependency resolution**: each Registrar declares its NodeSet dependencies via `dependencyRegistrars()`. When loaded, dependencies are resolved recursively — e.g. loading `MachineToolRegistrar` automatically loads Machinery, DI, and IA.
  - **`only: true`**: skip dependency loading when you need only the specification itself: `new MachineToolRegistrar(only: true)`.
  - Stackable — call `loadGeneratedTypes()` multiple times for different NodeSet files. Duplicate registrations are handled gracefully.
  - Zero impact if not used — full backward compatibility, no changes to existing behavior.
  - Companion package [`php-opcua/opcua-client-nodeset`](https://github.com/php-opcua/opcua-client-nodeset) provides pre-generated types for all 51 OPC Foundation companion specifications (807 PHP files, 338 enums, 191 DTOs, 191 codecs).
- **ModifyMonitoredItems.** Change sampling interval, queue size, and other parameters on existing monitored items without recreating them. `$client->modifyMonitoredItems($subId, [...])` returns `MonitoredItemModifyResult[]` with revised parameters. Dispatches `MonitoredItemModified` event per item.
- **SetTriggering.** Configure a monitored item as a trigger for other items — linked items are only sampled when the trigger changes. `$client->setTriggering($subId, $triggerId, $linksToAdd, $linksToRemove)` returns `SetTriggeringResult` with per-link status codes. Dispatches `TriggeringConfigured` event.
- **Read Metadata Cache.** Non-Value attributes (DisplayName, BrowseName, DataType, NodeClass, Description, etc.) can now be cached via PSR-16 to avoid redundant server reads. Opt-in via `setReadMetadataCache(true)`. The Value attribute (id 13) is never cached. Use `read($nodeId, $attributeId, refresh: true)` to bypass the cache and re-read from the server. `invalidateCache($nodeId)` clears all cached metadata for a node.
- **Write Type Auto-Detection.** The `write()` method no longer requires an explicit `BuiltinType`. When omitted, the client reads the node first to determine the correct type, then caches it via PSR-16 for subsequent writes to the same node.
  - `setAutoDetectWriteType(bool)` — enable/disable the feature (default: enabled).
  - When auto-detect is on and an explicit type is provided, it is validated against the detected node type.
  - `WriteTypeDetectionException` — thrown when the type cannot be determined (no value on node, or auto-detect disabled without explicit type).
  - `WriteTypeMismatchException` — thrown when the explicit type does not match the detected type. Carries `$nodeId`, `$expectedType`, `$givenType`.
  - Two new events: `WriteTypeDetecting` (before detection), `WriteTypeDetected` (after detection, with `$detectedType` and `$fromCache`).
  - `WriteMultiBuilder::value(mixed)` — new builder method for writing without specifying a type.
  - `invalidateCache()` now also clears cached write types.
- **PSR-14 Event Dispatcher.** The client now dispatches 38 granular events at every key lifecycle point. Inject any PSR-14 `EventDispatcherInterface` via `$builder->setEventDispatcher($dispatcher)` on the `ClientBuilder`. Events cover:
  - **Connection** (6): `ClientConnecting`, `ClientConnected`, `ConnectionFailed`, `ClientReconnecting`, `ClientDisconnecting`, `ClientDisconnected`
  - **Session** (3): `SessionCreated`, `SessionActivated`, `SessionClosed`
  - **Secure Channel** (2): `SecureChannelOpened`, `SecureChannelClosed`
  - **Subscription** (9): `SubscriptionCreated`, `SubscriptionDeleted`, `SubscriptionTransferred`, `MonitoredItemCreated`, `MonitoredItemDeleted`, `DataChangeReceived`, `EventNotificationReceived`, `PublishResponseReceived`, `SubscriptionKeepAlive`
  - **Alarms — generic** (1): `AlarmEventReceived`
  - **Alarms — specific** (8): `AlarmActivated`, `AlarmDeactivated`, `AlarmAcknowledged`, `AlarmConfirmed`, `AlarmShelved`, `AlarmSeverityChanged`, `LimitAlarmExceeded`, `OffNormalAlarmTriggered`
  - **Read/Write/Browse** (4): `NodeValueRead`, `NodeValueWritten`, `NodeValueWriteFailed`, `NodeBrowsed`
  - **Type Discovery** (1): `DataTypesDiscovered`
  - **Cache** (2): `CacheHit`, `CacheMiss`
  - **Retry** (2): `RetryAttempt`, `RetryExhausted`
- `NullEventDispatcher` — no-op PSR-14 dispatcher used by default. Zero overhead: event objects are lazily instantiated via closures and never allocated when no real dispatcher is set.
- `ManagesEventDispatcherTrait` — trait providing `setEventDispatcher()`, `getEventDispatcher()`, and the internal `dispatch()` helper with lazy closure support.
- `psr/event-dispatcher` ^1.0 added as dependency (interface-only package, zero runtime code).
- All event classes carry an `$client` property referencing the `OpcUaClientInterface` instance that emitted them.
- Alarm-specific events are automatically deduced from event notification fields (ActiveState, AckedState, ConfirmedState, ShelvingState, Severity, EventType). Known LimitAlarm and OffNormalAlarm type NodeIds are recognized.
- `MockClient` updated with `setEventDispatcher()` / `getEventDispatcher()` support.
- Unit tests for the event system: NullEventDispatcher, custom dispatcher, event properties, alarm event classes.
- Documentation: [Events](doc/14-events.md) chapter with full event reference, Laravel integration, and practical examples.
- **Code style enforcement.** Added `friendsofphp/php-cs-fixer` with Laravel-style rules (PSR-12 + opinionated). Run `composer format` before committing. `.editorconfig` included for IDE support.
- **CLI `write` command.** Write a value to a node from the terminal: `opcua-cli write <endpoint> <nodeId> <value> [--type=Int32]`. The `--type` flag is optional — when omitted, the type is auto-detected from the node. Supports all scalar types (Boolean, Int32, Double, String, etc.) with automatic value casting.
- **CLI Tool** (`bin/opcua-cli`). Five commands: `browse` (flat + recursive tree), `read` (any attribute), `endpoints` (discover security), `watch` (subscription or polling). Full security, JSON output, debug logging. Zero additional dependencies. Documentation: [CLI Tool](https://github.com/php-opcua/opcua-cli).
- `MockClient::onGetEndpoints()` handler for mocking endpoint discovery results.
- **Server Trust Store.** Persistent server certificate validation for industrial-grade deployments.
  - `FileTrustStore` — file-based trust store (`~/.opcua/trusted/` default, configurable path). Stores trusted and rejected certificates as DER files.
  - `TrustPolicy` enum — three validation levels: `Fingerprint` (presence in trust store), `FingerprintAndExpiry` (+ certificate expiration check), `Full` (+ CA chain verification).
  - `setTrustPolicy(null)` — disables trust validation entirely (default — backward compatible, behaves like before).
  - `autoAccept(true)` — TOFU (Trust On First Use): automatically trusts new certificates and saves them to the store.
  - `autoAccept(true, force: true)` — also accepts and updates changed certificates (replaces the stored cert).
  - `autoAccept(true)` without `force` — rejects changed certificates even with auto-accept enabled (security protection against MITM).
  - `trustCertificate(string $certDer)` — manually trust a certificate programmatically.
  - `untrustCertificate(string $fingerprint)` — remove a certificate from the trust store programmatically.
  - `UntrustedCertificateException` — thrown when a server certificate is rejected. Carries `$fingerprint` and `$certDer` for programmatic handling.
  - Five new events: `ServerCertificateTrusted` (cert passed validation), `ServerCertificateRejected` (cert rejected), `ServerCertificateAutoAccepted` (cert auto-accepted via TOFU), `ServerCertificateManuallyTrusted` (cert added via `trustCertificate()`), `ServerCertificateRemoved` (cert removed via `untrustCertificate()`).
  - PSR-3 logging: DEBUG for trusted certs, INFO for auto-accepted and manual trust/remove, WARNING for rejected certs.
  - CLI commands: `trust <endpoint>` (download and trust), `trust:list` (list trusted certs), `trust:remove <fingerprint>` (remove cert).
  - CLI options: `--trust-store=<path>` (custom store path), `--trust-policy=<policy>` (set validation level), `--no-trust-policy` (disable trust for single command).
  - CLI shows helpful guidance when `UntrustedCertificateException` is caught — suggests `trust` command and `--no-trust-policy` flag.

### Refactored

- **ClientBuilder/Client split.** The `Client` class has been split into `ClientBuilder` (configuration, entry point) and `Client` (connected operations). `ClientBuilder::create()` is the new preferred entry point. Configuration setters (`setSecurityPolicy`, `setEventDispatcher`, `setTrustStore`, `loadGeneratedTypes`, etc.) live on `ClientBuilder`; operation methods (`read`, `write`, `browse`, etc.) live on `Client`. `connect()` on the builder returns a `Client`. `ClientBuilder` implements `ClientBuilderInterface`, `Client` implements `OpcUaClientInterface`. Builder traits live in `src/ClientBuilder/`, client traits in `src/Client/`.
- **`discoverServerCertificate()`** (72 lines) split into `discoverServerCertificate()`, `performDiscoveryHandshake()`, `extractServerCertificateFromEndpoints()`, and `extractTokenPolicies()`.
- **`openSecureChannelWithSecurity()`** (68 lines) split into `openSecureChannelWithSecurity()`, `loadClientCertificateAndKey()`, and `buildCertificateChain()`.
- **`createAndActivateSession()`** (56 lines) split into `createAndActivateSession()`, `createSession()`, `activateSession()`, and `loadUserCertificate()`.
- **Diagnostic info skip helper.** Extracted duplicated `skipDiagnosticInfo()` from 8 Protocol service classes into `BinaryDecoder::skipDiagnosticInfo()`, `skipDiagnosticInfoBody()`, and `skipDiagnosticInfoArray()`.
- **Protocol service base class.** Introduced `AbstractProtocolService` with shared `encodeRequestAuto()`, `writeRequestHeader()`, `readResponseMetadata()`, and `wrapInMessage()`. All 10 Protocol service classes now extend it, eliminating ~400 lines of duplicated encode/decode boilerplate.
- **Service NodeId constants.** Introduced `ServiceTypeId` class with named constants for all OPC UA service type IDs, well-known nodes, identity tokens, event filter encodings, and server limit nodes. Replaced all hard-coded `NodeId::numeric(0, N)` magic numbers across Protocol and Client layers.
- **`ExtensionObject` DTO.** `BinaryDecoder::readExtensionObject()` now returns a typed `ExtensionObject` readonly class instead of `array|object`. Properties: `$typeId` (NodeId), `$encoding` (int), `$body` (?string, raw bytes), `$value` (mixed, decoded). Helpers: `isDecoded()`, `isRaw()`. `DataValue::getValue()` auto-extracts the decoded value when a codec is registered — no change needed for decoded access. `BinaryEncoder::writeExtensionObject()` now accepts `ExtensionObject` only (no array).

### Breaking Changes

- **ClientBuilder/Client split.** `new Client()` is replaced by `ClientBuilder::create()` (or `new ClientBuilder()`). Configuration methods (`setSecurityPolicy`, `setSecurityMode`, `setClientCertificate`, `setUserCredentials`, `setEventDispatcher`, `setTrustStore`, `setTrustPolicy`, `autoAccept`, `loadGeneratedTypes`, `setTimeout`, `setAutoRetry`, `setBatchSize`, `setCache`, `setAutoDetectWriteType`, `setReadMetadataCache`, `setDefaultBrowseMaxDepth`) are on `ClientBuilder`, not `Client`. `connect()` now returns a `Client` instance: `$client = ClientBuilder::create()->connect('...')`. `Client` constructor is no longer public.
- `BinaryDecoder::readExtensionObject()` returns `ExtensionObject` instead of `array`. Code accessing `$result['typeId']` must change to `$result->typeId`, `$result['body']` to `$result->body`.
- `BinaryEncoder::writeExtensionObject()` no longer accepts `array` — pass `ExtensionObject` instances.
- `DataValue::getValue()` for raw ExtensionObjects (no codec) now returns `ExtensionObject` DTO instead of `array`. Decoded ExtensionObjects (with codec) are unchanged — auto-extracted.

## [3.0.0] - 2026-03-22

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
- Updated `README.md` disclaimer to recommend `php-opcua/opcua-session-manager` for session persistence across PHP requests.

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
