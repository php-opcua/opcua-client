# Architecture

## Project Structure

```
src/
├── ClientBuilder.php                    # Builder / entry point
├── ClientBuilderInterface.php           # Builder interface
├── Client.php                           # Connected client (proxy to modules)
├── OpcUaClientInterface.php             # Public API interface
│
├── ClientBuilder/                       # Builder traits (configuration)
│   ├── ManagesAutoRetryTrait.php        # Auto-retry configuration
│   ├── ManagesBatchingTrait.php         # Batch size configuration
│   ├── ManagesBrowseDepthTrait.php      # Recursive browse depth config
│   ├── ManagesCacheTrait.php            # PSR-16 cache configuration
│   ├── ManagesEventDispatcherTrait.php  # PSR-14 event dispatcher config
│   ├── ManagesModulesTrait.php          # addModule(), replaceModule()
│   ├── ManagesReadWriteConfigTrait.php  # Read/write config (auto-detect, metadata cache)
│   ├── ManagesTimeoutTrait.php          # Timeout configuration
│   └── ManagesTrustStoreTrait.php       # Trust store configuration
│
├── Kernel/                              # Shared infrastructure for modules
│   ├── ClientKernel.php                 # Kernel implementation
│   ├── ClientKernelInterface.php        # Kernel contract (for modules)
│   └── ModuleRegistry.php              # Module lifecycle, dependency sort, method registry
│
├── Module/                              # Self-contained service modules
│   ├── ServiceModule.php               # Abstract base class (register, boot, reset, requires)
│   │
│   ├── ReadWrite/                       # Read, Write, Call operations
│   │   ├── ReadWriteModule.php          # Module class
│   │   ├── ReadService.php              # Protocol encoding/decoding
│   │   ├── WriteService.php             # Protocol encoding/decoding
│   │   ├── CallService.php              # Protocol encoding/decoding
│   │   └── CallResult.php              # Module-specific DTO
│   │
│   ├── Browse/                          # Browse and GetEndpoints
│   │   ├── BrowseModule.php
│   │   ├── BrowseService.php
│   │   ├── GetEndpointsService.php
│   │   └── BrowseResultSet.php
│   │
│   ├── Subscription/                    # Subscriptions, monitored items, publish
│   │   ├── SubscriptionModule.php
│   │   ├── SubscriptionService.php
│   │   ├── MonitoredItemService.php
│   │   ├── PublishService.php
│   │   ├── SubscriptionResult.php
│   │   ├── MonitoredItemResult.php
│   │   ├── PublishResult.php
│   │   └── TransferResult.php
│   │
│   ├── History/                         # History read operations
│   │   ├── HistoryModule.php
│   │   └── HistoryReadService.php
│   │
│   ├── NodeManagement/                  # Add/delete nodes and references
│   │   ├── NodeManagementModule.php
│   │   ├── NodeManagementService.php
│   │   └── AddNodesResult.php
│   │
│   ├── TranslateBrowsePath/             # Browse path translation
│   │   ├── TranslateBrowsePathModule.php
│   │   ├── TranslateBrowsePathService.php
│   │   └── BrowsePathResult.php
│   │
│   ├── ServerInfo/                      # Server BuildInfo methods
│   │   ├── ServerInfoModule.php
│   │   └── BuildInfo.php
│   │
│   └── TypeDiscovery/                   # Automatic DataType discovery
│       └── TypeDiscoveryModule.php
│
├── Transport/
│   └── TcpTransport.php                # TCP socket I/O
│
├── Encoding/
│   ├── BinaryEncoder.php               # Serialization (write)
│   ├── BinaryDecoder.php               # Deserialization (read)
│   ├── ExtensionObjectCodec.php        # Interface for custom type codecs
│   ├── DynamicCodec.php               # Auto-generated codec from StructureDefinition
│   ├── DataTypeMapping.php            # Maps DataType NodeIds to BuiltinTypes
│   └── StructureDefinitionParser.php  # Parses DataTypeDefinition attributes
│
├── Protocol/                            # Shared protocol infrastructure
│   ├── AbstractProtocolService.php     # Shared encode/decode base class
│   ├── ServiceTypeId.php              # Named constants for OPC UA service NodeIds
│   ├── MessageHeader.php               # OPC UA message framing
│   ├── HelloMessage.php                # HEL message
│   ├── AcknowledgeMessage.php          # ACK message
│   ├── SecureChannelRequest.php        # OPN request
│   ├── SecureChannelResponse.php       # OPN response
│   └── SessionService.php             # CreateSession / ActivateSession (kernel-level)
│
├── Security/
│   ├── SecurityPolicy.php             # Security policy enum + algorithm config
│   ├── SecurityMode.php               # Security mode enum
│   ├── SecureChannel.php              # Secure channel lifecycle
│   ├── MessageSecurity.php            # Cryptographic operations
│   └── CertificateManager.php        # Certificate loading & utilities
│
├── Types/                               # Shared types (used across modules)
│   ├── BuiltinType.php                # OPC UA type enum
│   ├── NodeClass.php                  # Node class enum
│   ├── NodeId.php                     # Node identifier
│   ├── Variant.php                    # Typed value container
│   ├── DataValue.php                  # Value + status + timestamps
│   ├── QualifiedName.php              # Namespace-qualified name
│   ├── LocalizedText.php             # Locale-aware text
│   ├── ReferenceDescription.php      # Browse result item
│   ├── EndpointDescription.php       # Server endpoint info
│   ├── UserTokenPolicy.php           # Authentication policy
│   ├── StatusCode.php                # Status code constants & helpers
│   ├── AttributeId.php               # Attribute ID constants
│   ├── ConnectionState.php           # Connection state enum
│   ├── BrowseDirection.php           # Browse direction enum
│   ├── BrowseNode.php                # Recursive browse tree node DTO
│   ├── BrowsePathTarget.php          # Single resolved browse path target DTO
│   ├── ExtensionObject.php           # Typed ExtensionObject DTO (raw or decoded)
│   ├── StructureField.php            # Field descriptor for structure definitions
│   └── StructureDefinition.php       # Structure layout for dynamic codecs
│
├── Builder/                            # Fluent builders for multi-operations
│   ├── ReadMultiBuilder.php           # Builder for readMulti()
│   ├── WriteMultiBuilder.php          # Builder for writeMulti()
│   ├── MonitoredItemsBuilder.php      # Builder for createMonitoredItems()
│   └── TranslateBrowsePathsBuilder.php # Builder for translateBrowsePaths()
│
├── Event/
│   ├── NullEventDispatcher.php        # No-op PSR-14 dispatcher (default)
│   ├── Client*.php                    # Connection lifecycle events (6)
│   ├── Session*.php                   # Session events (3)
│   ├── Subscription*.php              # Subscription events (4)
│   ├── MonitoredItem*.php             # Monitored item events (2)
│   ├── DataChangeReceived.php         # Data change notification event
│   ├── EventNotificationReceived.php  # Event notification event
│   ├── PublishResponseReceived.php    # Publish response event
│   ├── SubscriptionKeepAlive.php      # Keep-alive event
│   ├── Alarm*.php                     # Alarm events (8)
│   ├── NodeValue*.php                 # Read/Write events (3)
│   ├── NodeBrowsed.php                # Browse event
│   ├── SecureChannel*.php             # Secure channel events (2)
│   ├── DataTypesDiscovered.php        # Type discovery event
│   ├── Cache*.php                     # Cache hit/miss events (2)
│   ├── Retry*.php                     # Retry events (2)
│   └── ServerCertificate*.php         # Trust store events (5)
│
│   # CLI tool moved to separate package: php-opcua/opcua-cli
│
├── TrustStore/
│   ├── TrustStoreInterface.php        # Trust store contract
│   ├── FileTrustStore.php             # File-based implementation (~/.opcua/)
│   ├── TrustPolicy.php               # Validation policy enum
│   └── TrustResult.php               # Validation result DTO
│
├── Cache/
│   ├── InMemoryCache.php              # PSR-16 in-memory cache
│   └── FileCache.php                  # PSR-16 file-based cache
│
├── Repository/
│   └── ExtensionObjectRepository.php  # Per-client codec registry
│
├── Testing/
│   └── MockClient.php                # In-memory test double (no TCP)
│
└── Exception/
    ├── OpcUaException.php             # Base exception
    ├── ConfigurationException.php     # Config errors
    ├── ConnectionException.php        # TCP errors
    ├── EncodingException.php          # Binary codec errors
    ├── InvalidNodeIdException.php     # Malformed NodeId errors
    ├── ModuleConflictException.php    # Two modules register the same method
    ├── MissingModuleDependencyException.php # Module dependency not satisfied
    ├── ProtocolException.php          # Protocol violations
    ├── SecurityException.php          # Crypto errors
    ├── UntrustedCertificateException.php # Untrusted server cert (extends SecurityException)
    └── ServiceException.php           # Server errors (with status code)
```

## Layers

```
┌─────────────────────────────────┐
│       ClientBuilder             │  Configuration & entry point
│  (+ ClientBuilder/*Trait.php)   │  Config traits (cache, events, modules, etc.)
├─────────────────────────────────┤
│           Client                │  Proxy: typed one-liners → module handlers
│         (+ __call)              │  Custom module methods via __call()
├─────────────────────────────────┤
│    ModuleRegistry               │  Module lifecycle, dependency sort, method map
├──────────┬──────────────────────┤
│ Module/* │   ClientKernel       │  Service modules ←→ shared infrastructure
│ (8 built │  (executeWithRetry,  │  Each module = protocol service + DTOs + methods
│  -in)    │   send, receive...)  │
├──────────┴──────────────────────┤
│     Protocol (shared base)      │  AbstractProtocolService, ServiceTypeId, SessionService
├──────────────┬──────────────────┤
│ BinaryEncoder│  BinaryDecoder   │  Binary serialization
├──────────────┴──────────────────┤
│       SecureChannel             │  Message-level security
├─────────────────────────────────┤
│    MessageSecurity              │  Cryptographic operations
│    CertificateManager           │  Certificate handling
├─────────────────────────────────┤
│       TcpTransport              │  TCP socket I/O
└─────────────────────────────────┘
```

`ClientBuilder` is the entry point for configuration. Calling `connect()` boots the kernel, registers all modules, and returns a `Client` for operations. Each layer only talks to the one directly below it.

## Module System

The Client uses a **module architecture** where each OPC UA service set is a self-contained `ServiceModule`. This replaces the previous trait-based approach.

### How It Works

1. **`ClientKernel`** provides the shared infrastructure that every module needs: retry logic, connection management, request ID generation, send/receive, binary encoding, event dispatching, caching, and logging.

2. **`ServiceModule`** is the abstract base class. Each module implements:
   - `register()` — injects its methods onto the Client via `$this->client->registerMethod('read', $this->read(...))`
   - `boot()` — creates its protocol service instance
   - `reset()` — cleans up on disconnect
   - `requires()` — declares dependencies on other modules (optional)

3. **`ModuleRegistry`** manages the module lifecycle:
   - Resolves dependencies with topological sort
   - Detects method name conflicts between modules
   - Boots modules in dependency order
   - Resets modules on disconnect

### Boot Flow

```
ClientBuilder::connect()
  → creates ClientKernel (transport, security, session)
  → creates ModuleRegistry
  → registers all modules (8 built-in + custom)
  → ModuleRegistry resolves dependencies (topological sort)
  → ModuleRegistry boots all modules in order
  → returns Client (with method handlers populated)
```

### Built-in Modules

| Module | Methods | DTOs |
|---|---|---|
| `ReadWriteModule` | `read`, `readMulti`, `write`, `writeMulti`, `call` | `CallResult` |
| `BrowseModule` | `browse`, `browseAll`, `browseWithContinuation`, `browseNext`, `browseRecursive`, `getEndpoints` | `BrowseResultSet` |
| `SubscriptionModule` | `createSubscription`, `createMonitoredItems`, `createEventMonitoredItem`, `modifyMonitoredItems`, `setTriggering`, `deleteMonitoredItems`, `deleteSubscription`, `publish`, `transferSubscriptions`, `republish` | `SubscriptionResult`, `MonitoredItemResult`, `PublishResult`, `TransferResult` |
| `HistoryModule` | `historyReadRaw`, `historyReadProcessed`, `historyReadAtTime` | (none) |
| `NodeManagementModule` | `addNodes`, `deleteNodes`, `addReferences`, `deleteReferences` | `AddNodesResult` |
| `TranslateBrowsePathModule` | `translateBrowsePaths`, `resolveNodeId` | `BrowsePathResult` |
| `ServerInfoModule` | `getServerBuildInfo`, `getServerProductName`, `getServerManufacturerName`, `getServerSoftwareVersion`, `getServerBuildNumber`, `getServerBuildDate` | `BuildInfo` |
| `TypeDiscoveryModule` | `discoverDataTypes` | (none) |

### Extending with Custom Modules

Add a custom module:

```php
$client = ClientBuilder::create()
    ->addModule(new MyQueryServiceModule())
    ->connect('opc.tcp://localhost:4840');

$client->queryFirst(...); // accessible via __call()
```

Replace a built-in module:

```php
$client = ClientBuilder::create()
    ->replaceModule(ReadWriteModule::class, new MyCustomReadWriteModule())
    ->connect('opc.tcp://localhost:4840');

$client->read(...); // uses your custom implementation
```

### Dependency Declaration

Modules can declare dependencies on other modules:

```php
class ServerInfoModule extends ServiceModule
{
    public function requires(): array
    {
        return [ReadWriteModule::class];
    }
}
```

The `ModuleRegistry` resolves the dependency graph and boots modules in the correct order. Missing dependencies throw `MissingModuleDependencyException` at connect time.

### Introspection

```php
$client->hasMethod('read');                    // true
$client->hasModule(ReadWriteModule::class);    // true
$client->hasModule(MyCustomModule::class);     // false (unless added)
```

## Dependencies

The library has three Composer dependencies (all interface-only, zero runtime code):

- **`psr/log`** — PSR-3 logger interface. The client accepts any `Psr\Log\LoggerInterface` implementation (Monolog, Laravel, etc.) and defaults to `NullLogger` when none is provided.
- **`psr/simple-cache`** — PSR-16 cache interface. The client uses `CacheInterface` for browse result caching. Ships with `InMemoryCache` (default) and `FileCache`. Any PSR-16 compatible driver (Laravel Cache, Symfony Cache, etc.) can be plugged in.
- **`psr/event-dispatcher`** — PSR-14 event dispatcher interface. The client dispatches 47 granular events at lifecycle points. Defaults to `NullEventDispatcher` (zero overhead). Any PSR-14 compatible dispatcher (Laravel, Symfony, etc.) can be injected.

The only PHP extension required is `ext-openssl`.

## Service Pattern

All protocol services follow the same structure:

1. Each wraps a `SessionService` instance
2. Separate `encode` and `decode` methods per operation
3. Both secure and non-secure code paths
4. Inner body construction is factored out for reuse

```
encodeFooRequest()
  ├── (no security) → write headers + body → wrapInMessage()
  └── (security)    → write body → secureChannel->buildMessage()

decodeFooResponse()
  └── read headers → readResponseHeader() → parse result fields
```

Adding a new OPC UA service means writing one class with `encode*Request()` and `decode*Response()` methods. The security, framing, and transport layers handle everything else.

## Message Format

### Non-Secure

```
┌──────────┬─────────────┬──────────┬───────────┬─────────────┐
│ MSG/F    │ MessageSize │ ChannelId│ TokenId   │ Sequence    │
│ (3+1 B)  │ (4 B)       │ (4 B)    │ (4 B)     │ Num + ReqId │
├──────────┴─────────────┴──────────┴───────────┴─────────────┤
│                     Service Body                             │
│  (TypeId + RequestHeader + Service-specific fields)          │
└──────────────────────────────────────────────────────────────┘
```

### Secure

```
┌──────────┬─────────────┬──────────┐
│ MSG/F    │ MessageSize │ ChannelId│
├──────────┴─────────────┴──────────┤
│ TokenId │ SequenceNum │ RequestId │
├─────────┴─────────────┴───────────┤
│          Encrypted Body           │  ← AES-CBC
│   (TypeId + Headers + Fields)     │
│   + Padding + PaddingByte         │
├───────────────────────────────────┤
│          HMAC Signature           │  ← HMAC-SHA1/SHA256
└───────────────────────────────────┘
```

## Binary Encoding Notes

The library implements OPC UA Binary encoding (Part 6 of the spec):

- **Little-endian** byte order for all integers
- **Length-prefixed strings** -- `Int32` length + UTF-8 bytes, `-1` for null
- **NodeId compact encoding** -- TwoByte / FourByte / Numeric / String / Guid / Opaque, chosen automatically based on namespace and identifier
- **Variant** -- type byte with optional array dimension flag
- **DataValue** -- bitmask header indicating which optional fields are present (value, status, timestamps)
- **DateTime** -- 100-nanosecond intervals since 1601-01-01 UTC
