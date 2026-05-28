# Architecture reference

How `opcua-client` v4.4.0 is wired internally. Read this when:

- Designing a custom module
- Debugging unexpected method dispatch
- Understanding why a change in one place affects another
- Reviewing a PR that touches kernel / builder / module surface

## Layered model

```
┌───────────────────────────────────────────────────────────┐
│ Application code                                          │
│   $client->read(), $client->browse(), $client->callMethod()│
└───────────────────────┬───────────────────────────────────┘
                        │
                  OpcUaClientInterface (contract)
                        │
┌───────────────────────▼───────────────────────────────────┐
│ Client (src/Client.php)                                   │
│   Proxy. Implements OpcUaClientInterface.                 │
│   Composes Manages*Traits (Connection, SecureChannel,    │
│   Session, Handshake, Batching, Cache, Reading, Writing,  │
│   Browsing, Calling, Subscription, etc.)                  │
│   Implements ClientKernelInterface directly via the traits│
│   __call() dispatches custom module methods               │
└───────────────────────┬───────────────────────────────────┘
                        │
                  ClientKernelInterface
                  (executeWithRetry, ensureConnected,
                   send, receive, createDecoder,
                   dispatch, logContext, getCacheCodec, ...)
                        │
┌───────────────────────▼───────────────────────────────────┐
│ ModuleRegistry (src/Kernel/ModuleRegistry.php)            │
│   Lifecycle: register → boot → reset                      │
│   Topological dependency sort (requires(): class-string[])│
│   Method conflict detection (ModuleConflictException)     │
│   Missing dependency detection                            │
└───────────────────────┬───────────────────────────────────┘
                        │
┌───────────────────────▼───────────────────────────────────┐
│ 10 built-in ServiceModules (src/Module/*/)                │
│   ReadWrite, Browse, Subscription, History, Aggregate,    │
│   NodeManagement, TranslateBrowsePath, ServerInfo,        │
│   TypeDiscovery, FileTransfer                             │
│   Each module owns its protocol service(s) + DTOs         │
└───────────────────────┬───────────────────────────────────┘
                        │
                  ClientTransportInterface
                        │
┌───────────────────────▼───────────────────────────────────┐
│ Wire transport (src/Transport/)                           │
│   TcpTransport — default `opc.tcp://`                     │
│   v4.4 seams: createProbe(), isSecureChannelExternal()    │
│   Extensions plug in via ClientBuilder::setTransport():   │
│     - opcua-client-ext-reverse-connect (server dials back)│
│     - opcua-client-ext-transport-https (opc.https://)     │
└───────────────────────────────────────────────────────────┘
```

## Key classes

### Entry point

- **`PhpOpcua\Client\ClientBuilder`** (implements `ClientBuilderInterface`)
  - `ClientBuilder::create(): self` static factory
  - Config via traits in `src/ClientBuilder/`: cache, events, timeout, trust store, batching, modules, certificates, credentials, transport
  - `addModule(ServiceModule)` / `replaceModule(class-string, ServiceModule)` — extension points
  - `connect(string $endpointUrl): Client` — terminal method

### Connected client

- **`PhpOpcua\Client\Client`** (implements `OpcUaClientInterface` and `ClientKernelInterface`)
  - Proxy: delegates `read()` / `write()` / `browse()` / etc. to the registered module that owns that method
  - `__call($name, $args)` dispatches custom module methods
  - `hasMethod(string): bool` / `hasModule(class-string): bool` / `getRegisteredMethods(): string[]` / `getLoadedModules(): class-string[]` for introspection

### Kernel (modules' contract on the client)

- **`PhpOpcua\Client\Kernel\ClientKernelInterface`** — what every `ServiceModule` is allowed to call on the client:
  - `executeWithRetry(callable $op, ?int $maxAttempts = null)` — auto-reconnect wrapper
  - `ensureConnected(): void` — guards
  - `send(string $data): void` / `receive(): string` — wire-level I/O via the transport
  - `createDecoder(string $payload): BinaryDecoder` / encoder counterparts
  - `dispatch(callable $eventFactory): void` — PSR-14 event lazy dispatch (zero overhead when `NullEventDispatcher`)
  - `logContext(array $extra = []): array` — PSR-3 structured context
  - `getCacheCodec(): CacheCodecInterface` — PSR-16 cache encode/decode (default `WireCacheCodec`, JSON gated by Wire allowlist)
  - `getSessionService(): SessionService` — kernel-level session bookkeeping
  - `getSecureChannelId()` / `getServerNonce()` / `getServerCertDer()` — secure channel state
  - `getLogger()` / `getDispatcher()` / `getModuleRegistry()` — accessors

- **No separate concrete kernel class exists** — the kernel surface is implemented directly by `Client` via composed traits. This is deliberate: modules depend on the interface, not on `Client`.

- **`PhpOpcua\Client\Kernel\ModuleRegistry`** — module lifecycle manager
  - `register(ServiceModule): void`
  - `bootAll(Client, ClientKernelInterface, SessionService): void`
  - Topologically sorts by `requires(): class-string[]`
  - Throws `ModuleConflictException` / `MissingModuleDependencyException`

### Modules

- **`PhpOpcua\Client\Module\ServiceModule`** (abstract) — base class for every module. Subclasses override:
  - `name(): string` — module identifier
  - `requires(): array` — list of other ServiceModule class-strings this depends on
  - `register(ClientKernelInterface $kernel, SessionService $session): array` — returns `['methodName' => callable]` map
  - `boot(): void` — hook for post-registration init
  - `reset(): void` — clear state on reconnect
  - `registerWireTypes(WireTypeRegistry): void` — register DTOs for IPC / cache

10 built-in modules under `src/Module/*`:

| Module | DTOs | v4.4.0 status |
| --- | --- | --- |
| `ReadWrite` | `CallResult` | unchanged |
| `Browse` | `BrowseResultSet`, `ReferenceDescription` | unchanged |
| `Subscription` | `SubscriptionResult`, `MonitoredItemResult`, `PublishResult`, `TransferResult` | unchanged |
| `History` | + `HistoryUpdateResult` | **expanded** — 9 new HistoryUpdate methods |
| `Aggregate` | `AggregateFunction` (enum), `AggregateOptions`, `Interval` | **new in v4.4.0** |
| `NodeManagement` | `AddNodesResult` | unchanged |
| `TranslateBrowsePath` | `BrowsePathResult`, `BrowsePathTarget` | unchanged |
| `ServerInfo` | `BuildInfo` | unchanged |
| `TypeDiscovery` | (uses `Repository\ExtensionObjectRepository`) | unchanged |
| `FileTransfer` | various Part 5 DTOs | **new in v4.4.0** |

### Transport

- **`PhpOpcua\Client\Transport\ClientTransportInterface`** — 8 methods (v4.4.0)
  - `connect(string $host, int $port, ?float $timeout = null): void`
  - `send(string $data): void` / `receive(): string`
  - `setReceiveBufferSize(int): void`
  - `close(): void` / `isConnected(): bool`
  - **v4.4.0 seams**:
    - `createProbe(): self` — fresh sibling transport for the discovery probe (no longer hardcoded to `new TcpTransport()`)
    - `isSecureChannelExternal(): bool` — when `true`, `ManagesSecureChannelTrait::openSecureChannel()` branches to `openSecureChannelExternal()` which skips the OPC UA OpenSecureChannel exchange (TLS / equivalent provides the channel)

- **`PhpOpcua\Client\Transport\TcpTransport`** — default impl. Plain `opc.tcp://` socket. `isSecureChannelExternal()` returns `false`. `TcpTransport::fromConnectedSocket()` wraps a pre-accepted socket for reverse-connect.

- **`PhpOpcua\Client\Transport\InMemoryTransport`** (under `tests/Unit/Helpers/`) — test double. Records sent frames, replays queued responses. Doubles as the worked example for implementing the contract.

### Types

Under `src/Types/`:

- `NodeId` — public readonly `namespaceIndex`, `identifier` (`int|string`), `type` (`'numeric'|'string'|'guid'|'opaque'`). Factories: `numeric()`, `string()`, `guid()`, `opaque()`. Parser: `NodeId::parse('ns=2;s=Temp')`. `toString()` returns canonical form.
- `Variant` — public readonly `type` (`BuiltinType`), `value`, `dimensions` (`?int[]`).
- `DataValue` — public readonly `statusCode`, `sourceTimestamp`, `serverTimestamp`, `type` (`?BuiltinType` derived from the inner Variant). Methods: `getValue()` (unwraps + auto-decodes registered ExtensionObjects), `getType()` (symmetric with `getValue()`, returns the inner Variant's `BuiltinType`), `getVariant()` (deprecated). Factories `ofInt32()`, `ofDouble()`, etc.
- `ExtensionObject` — public readonly `typeId`, `encoding` (`Binary|Xml`), `body`, `value` (decoded). `isDecoded()` / `isRaw()`.
- `BuiltinType` — enum of 25 OPC UA built-in types (Boolean, SByte, …, Variant, DiagnosticInfo).
- `NodeClass`, `BrowseDirection`, `AttributeId` — typed enums.
- `ReferenceDescription`, `BrowseNode` — browse results.
- `EndpointDescription`, `UserTokenPolicy` — discovery results.
- `LocalizedText`, `QualifiedName`, `StatusCode` — primitives.

### Cross-cutting

- **`src/Wire/`** — JSON-safe IPC serialization
  - `WireSerializable` interface (every cache/IPC-eligible DTO implements it)
  - `WireTypeRegistry` — encoder/decoder + `__t` discriminator allowlist (security gate: no `unserialize()`, no gadget chain by construction)
  - `CoreWireTypes::register()` — registers built-in types (NodeId, Variant, DataValue, ExtensionObject, BrowseNode, ReferenceDescription, EndpointDescription, UserTokenPolicy, BuiltinType, NodeClass, BrowseDirection, ConnectionState)

- **`src/Cache/`**
  - `CacheCodecInterface` — encode/decode contract for PSR-16 cache values
  - `WireCacheCodec` — default impl. Wraps every value in `__t`-tagged JSON, rejects unknown types with `CacheCorruptedException` (treated as cache miss)
  - `InMemoryCache` / `FileCache` — PSR-16 drivers

- **`src/Security/`**
  - `SecurityPolicy` enum (10 cases: 6 RSA + 4 ECC)
  - `SecurityMode` enum (None / Sign / SignAndEncrypt)
  - `SecureChannel`, `MessageSecurity`, `CertificateManager` — internal crypto + handshake

- **`src/TrustStore/`** — server certificate trust management
  - `FileTrustStore` (default ~/.opcua/), `TrustPolicy` enum (Fingerprint / FingerprintAndExpiry / Full), `TrustResult`
  - 5 trust events

- **`src/Protocol/`** — shared protocol primitives
  - `AbstractProtocolService` — base for every per-module service
  - `ServiceTypeId` — named constants for OPC UA service NodeIds and well-known nodes
  - `SessionService` — kernel-level session bookkeeping (channel/token IDs, sequence numbers, request IDs)
  - `MessageHeader`, `HelloMessage`, `AcknowledgeMessage`, `SecureChannelRequest`, `SecureChannelResponse`

- **`src/Event/`** — 57 PSR-14 event classes + `NullEventDispatcher`. See `references/EVENTS.md`.

- **`src/Testing/`** — `MockClient`: in-memory `OpcUaClientInterface` impl (no TCP). Handler registration, call tracking. See `references/TESTING.md`.

- **`src/Exception/`** — typed exception hierarchy. Notable:
  - `ServiceException` — wraps an OPC UA `ServiceFault` with `StatusCode`
  - `ServiceUnsupportedException` — server returned `BadServiceUnsupported`
  - `ConnectionException` / `ProtocolException` / `HandshakeException` / `SecurityException`
  - `ModuleConflictException` / `MissingModuleDependencyException`
  - `WriteTypeDetectionException` / `WriteTypeMismatchException`
  - `CacheCorruptedException`

## How a service call flows

Take `$client->read('i=2259')`:

1. `Client::read()` is provided by `ReadWriteModule::register()` (returned a callable mapped to `'read'`)
2. The callable internally:
   - Coerces the string to `NodeId`
   - Builds a `ReadRequest` via `ReadService::encodeReadRequest()`
   - Calls `$kernel->executeWithRetry(fn () => ...)` — auto-reconnect wrapper
   - Inside: `$kernel->send($request)` → transport POSTs to wire
   - `$kernel->receive()` → returns response bytes
   - `ReadService::decodeReadResponse()` → `DataValue[]`
   - Dispatches `NodeValueRead` event via `$kernel->dispatch()`
   - Returns the first `DataValue`
3. `Client::read()` returns the `DataValue` to the caller

The kernel surface is intentionally minimal — modules can `send()` / `receive()` but never touch the socket, never see the secure channel directly. This makes alternative transports (HTTPS, reverse-connect) drop-in: the kernel call sites are identical regardless of wire.

## Why the architecture is shaped this way

- **Modules are isolated**: write a new module without touching the core. Plug via `ClientBuilder::addModule()`. Conflict detection prevents accidental method shadowing.
- **Module dependencies are explicit**: `requires(): class-string[]` makes the boot order topological. `MissingModuleDependencyException` fails fast on misconfig.
- **Transport is pluggable**: the kernel never sees a socket. Reverse-connect and HTTPS are external packages, not core changes.
- **Cache & IPC are JSON-only**: `unserialize()` is forbidden on these paths. The `__t` allowlist in `WireTypeRegistry` is the security gate — unknown payloads are silently rejected, not deserialized.
- **Events are zero-cost when unused**: `dispatch(fn () => new SomeEvent(...))` — the lambda is invoked only if a real dispatcher is configured. `NullEventDispatcher` short-circuits.
