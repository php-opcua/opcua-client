# Module System

## Why Modules?

Before the module system, adding a new OPC UA service set required changes across 8+ files: a new trait on Client, updates to `initServices()`, `resetConnectionState()`, the interface, MockClient, and more. Each service was tightly coupled to the Client class, making the codebase harder to extend and test.

The module system solves this:

- **Self-contained**: a module is a single directory with its protocol service, DTOs, and logic. Adding a new OPC UA service means adding one directory under `src/Module/` -- zero changes to `Client.php`.
- **Replaceable**: built-in modules can be swapped out via `ClientBuilder::replaceModule()`. All cross-module references automatically use the replacement.
- **Extensible**: external developers can add custom modules without forking the library. Register a module, and its methods appear on the Client.

## How It Works

### Architecture Overview

Four components form the module system:

```
┌─────────────────────────────────────────────────┐
│                  Client                         │
│  Thin proxy: typed one-liners → module handlers │
│  Custom methods dispatch via __call()           │
├─────────────────────────────────────────────────┤
│              ModuleRegistry                     │
│  Lifecycle: dependency sort → boot → reset      │
├──────────────────────┬──────────────────────────┤
│   ServiceModule(s)   │     ClientKernel         │
│  register, boot,     │  executeWithRetry,       │
│  reset, requires     │  send/receive, cache,    │
│                      │  logging, events, etc.   │
└──────────────────────┴──────────────────────────┘
```

**ClientKernel** provides shared infrastructure: transport I/O, retry logic, request/response handling, caching, logging, and event dispatching. Every module receives the kernel via `$this->kernel`.

**ServiceModule** is the abstract base class. Each module implements `register()` to inject methods, `boot()` to create protocol services, `reset()` to clean up on disconnect, and optionally `requires()` to declare dependencies.

**ModuleRegistry** manages the full lifecycle: dependency resolution via topological sort, booting in the correct order, resetting in reverse order on disconnect, and re-booting on reconnect.

**Client** is a thin proxy. Built-in methods are concrete typed one-liners that delegate to `$this->methodHandlers['name']`. Custom module methods dispatch via `__call()`.

### Boot Flow

When you call `ClientBuilder::connect()`, the following happens:

```
ClientBuilder::connect($endpointUrl)
  1. ClientBuilder creates Client with a ModuleRegistry (8 default modules)
  2. Client establishes TCP connection, handshake, secure channel, session
  3. ModuleRegistry::bootAll() is called
     a. Topological sort resolves the dependency graph
     b. For each module (in dependency order):
        - setKernel($kernel)     → inject infrastructure
        - setClient($client)     → inject client for cross-module calls
        - register()             → module registers its methods
        - boot($session)         → module creates its protocol services
  4. Client is ready — all method handlers populated
```

On disconnect, `ModuleRegistry::resetAll()` calls `reset()` on each module in **reverse** boot order. On reconnect, `rebootAll()` calls `boot()` again without re-registering methods.

### Method Injection

Modules register methods on the Client during `register()`:

```php
public function register(): void
{
    $this->client->registerMethod('read', $this->read(...));
    $this->client->registerMethod('readMulti', $this->readMulti(...));
    $this->client->registerMethod('write', $this->write(...));
}
```

The Client stores these in an internal `$methodHandlers` array. Built-in methods like `read()` are concrete typed methods on Client that delegate in one line:

```php
public function read(NodeId|string $nodeId, int $attributeId = AttributeId::Value, bool $refresh = false): DataValue
{
    return ($this->methodHandlers['read'])($nodeId, $attributeId, $refresh);
}
```

Methods from custom modules that have no typed wrapper dispatch via `__call()`:

```php
$client->ping();
```

If two modules attempt to register the same method name, a `ModuleConflictException` is thrown immediately during boot.

### Dependencies Between Modules

Modules can declare dependencies via `requires()`:

```php
class ServerInfoModule extends ServiceModule
{
    public function requires(): array
    {
        return [ReadWriteModule::class];
    }
}
```

The `ModuleRegistry` uses a topological sort (DFS) to determine boot order. This guarantees that `ReadWriteModule` is fully registered and booted before `ServerInfoModule`.

Cross-module calls go through `$this->client`, not `$this->kernel`:

```php
public function getServerProductName(): ?string
{
    $value = $this->client->read(NodeId::numeric(0, 2262), AttributeId::Value)->getValue();

    return is_string($value) ? $value : null;
}
```

Here `ServerInfoModule` calls `$this->client->read()`, which dispatches to the handler registered by `ReadWriteModule`. If `ReadWriteModule` is replaced, `ServerInfoModule` automatically uses the replacement.

Current dependency graph:

```
ReadWriteModule  ←── ServerInfoModule
ReadWriteModule  ←── TypeDiscoveryModule
BrowseModule     ←── TypeDiscoveryModule
```

## Built-in Modules

| Module | Class | Methods | Protocol Services | DTOs | Requires |
|---|---|---|---|---|---|
| Read/Write | `ReadWriteModule` | `read`, `readMulti`, `write`, `writeMulti`, `call` | `ReadService`, `WriteService`, `CallService` | `CallResult` | -- |
| Browse | `BrowseModule` | `browse`, `browseAll`, `browseRecursive`, `browseWithContinuation`, `browseNext`, `getEndpoints` | `BrowseService`, `GetEndpointsService` | `BrowseResultSet` | -- |
| Subscription | `SubscriptionModule` | `createSubscription`, `createMonitoredItems`, `createEventMonitoredItem`, `modifyMonitoredItems`, `setTriggering`, `deleteMonitoredItems`, `deleteSubscription`, `publish`, `republish`, `transferSubscriptions` | `SubscriptionService`, `MonitoredItemService`, `PublishService` | `SubscriptionResult`, `MonitoredItemResult`, `MonitoredItemModifyResult`, `PublishResult`, `TransferResult`, `SetTriggeringResult` | -- |
| History | `HistoryModule` | `historyReadRaw`, `historyReadProcessed`, `historyReadAtTime` | `HistoryReadService` | -- | -- |
| Node Management | `NodeManagementModule` | `addNodes`, `deleteNodes`, `addReferences`, `deleteReferences` | `NodeManagementService` | `AddNodesResult` | -- |
| Translate Browse Path | `TranslateBrowsePathModule` | `translateBrowsePaths`, `resolveNodeId` | `TranslateBrowsePathService` | `BrowsePathResult` | -- |
| Server Info | `ServerInfoModule` | `getServerProductName`, `getServerManufacturerName`, `getServerSoftwareVersion`, `getServerBuildNumber`, `getServerBuildDate`, `getServerBuildInfo` | -- | `BuildInfo` | `ReadWriteModule` |
| Type Discovery | `TypeDiscoveryModule` | `discoverDataTypes`, `registerTypeCodec` | -- | -- | `ReadWriteModule`, `BrowseModule` |

## The Kernel API

Every module accesses infrastructure through `$this->kernel`, which is typed to `ClientKernelInterface`. The kernel provides:

**Connection & Retry**

| Method | Purpose |
|---|---|
| `executeWithRetry(Closure $operation)` | Execute an operation with automatic retry on connection failure |
| `ensureConnected()` | Throw `ConnectionException` if the connection is not active |

**Transport**

| Method | Purpose |
|---|---|
| `send(string $data)` | Send raw bytes over TCP |
| `receive()` | Receive raw bytes from TCP |

**Request / Response**

| Method | Purpose |
|---|---|
| `nextRequestId()` | Generate a sequential request ID |
| `getAuthToken()` | Get the current session authentication token |
| `unwrapResponse(string $response)` | Handle ERR messages, decrypt secure channel, strip headers |
| `createDecoder(string $data)` | Create a `BinaryDecoder` for parsing response bodies |

**NodeId Resolution**

| Method | Purpose |
|---|---|
| `resolveNodeId(NodeId\|string $nodeId)` | Parse string NodeIds like `'i=2259'` into NodeId objects |
| `resolveNodeIdArray(array &$items, string $key)` | Resolve string NodeIds in an array of items |

**Logging**

| Method | Purpose |
|---|---|
| `log()` | Get the PSR-3 logger |
| `logContext(array $context)` | Build a log context array with endpoint and session ID prepended |
| `getLogger()` | Get the configured logger instance |

**Events**

| Method | Purpose |
|---|---|
| `dispatch(object $event)` | Dispatch a PSR-14 event (no-op when using `NullEventDispatcher`) |
| `getEventDispatcher()` | Get the PSR-14 event dispatcher |

**Caching**

| Method | Purpose |
|---|---|
| `cachedFetch(string $key, callable $fetcher, bool $useCache)` | Fetch from cache or execute the fetcher and cache the result |
| `buildCacheKey(string $type, NodeId $nodeId, string $paramsSuffix)` | Build a cache key scoped to the endpoint |
| `buildSimpleCacheKey(string $type, string $paramsSuffix)` | Build a cache key without a NodeId |
| `ensureCacheInitialized()` | Ensure the cache backend is initialized |
| `getCache()` | Get the raw PSR-16 cache instance |

**Batching**

| Method | Purpose |
|---|---|
| `getEffectiveReadBatchSize()` | Get the read batch size (explicit or server-negotiated) |
| `getEffectiveWriteBatchSize()` | Get the write batch size (explicit or server-negotiated) |

**Configuration**

| Method | Purpose |
|---|---|
| `getTimeout()` | Network timeout in seconds |
| `getAutoRetry()` | Maximum retry count |
| `getBatchSize()` | Configured batch size |
| `getDefaultBrowseMaxDepth()` | Default max depth for recursive browse |
| `isAutoDetectWriteType()` | Whether write type auto-detection is enabled |
| `isReadMetadataCache()` | Whether metadata read caching is enabled |
| `getEnumMappings()` | Registered enum mappings |
| `getExtensionObjectRepository()` | Codec registry for custom ExtensionObjects |

## Extending the Client

### Adding a Custom Module

Create a module that pings the server by reading the ServerStatus node (`i=2259`) and returns `true` if the read succeeds:

```php
<?php

declare(strict_types=1);

namespace App\OpcUa;

use PhpOpcua\Client\Module\ReadWrite\ReadWriteModule;
use PhpOpcua\Client\Module\ServiceModule;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;

class PingModule extends ServiceModule
{
    public function requires(): array
    {
        return [ReadWriteModule::class];
    }

    public function register(): void
    {
        $this->client->registerMethod('ping', $this->ping(...));
    }

    public function ping(): bool
    {
        try {
            $dataValue = $this->client->read(NodeId::numeric(0, 2259));

            return StatusCode::isGood($dataValue->statusCode);
        } catch (\Throwable) {
            return false;
        }
    }
}
```

Register it with the builder:

```php
use PhpOpcua\Client\ClientBuilder;
use App\OpcUa\PingModule;

$client = ClientBuilder::create()
    ->addModule(new PingModule())
    ->connect('opc.tcp://localhost:4840');

$alive = $client->ping();
```

The `ping()` call dispatches via `__call()` to the handler registered by `PingModule`.

### Replacing a Built-in Module

Replace `ReadWriteModule` with a version that logs every read:

```php
<?php

declare(strict_types=1);

namespace App\OpcUa;

use PhpOpcua\Client\Module\ReadWrite\ReadWriteModule;
use PhpOpcua\Client\Types\AttributeId;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\NodeId;

class LoggingReadWriteModule extends ReadWriteModule
{
    public function read(NodeId|string $nodeId, int $attributeId = AttributeId::Value, bool $refresh = false): DataValue
    {
        $this->kernel->log()->info('Reading node {nodeId}', [
            'nodeId' => (string) $this->kernel->resolveNodeId($nodeId),
        ]);

        return parent::read($nodeId, $attributeId, $refresh);
    }
}
```

Use `replaceModule()` on the builder:

```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Module\ReadWrite\ReadWriteModule;
use App\OpcUa\LoggingReadWriteModule;

$client = ClientBuilder::create()
    ->replaceModule(ReadWriteModule::class, new LoggingReadWriteModule())
    ->connect('opc.tcp://localhost:4840');

$client->read('i=2259');
```

The replacement is stored under the `ReadWriteModule::class` key. Modules that depend on `ReadWriteModule` (like `ServerInfoModule` and `TypeDiscoveryModule`) automatically use the replacement through `$this->client->read()`.

### Creating a Module with Protocol Services

For modules that need custom OPC UA request/response encoding, follow the pattern used by all built-in modules:

**1. Create a protocol service extending `AbstractProtocolService`:**

```php
<?php

declare(strict_types=1);

namespace App\OpcUa;

use PhpOpcua\Client\Protocol\AbstractProtocolService;
use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Types\NodeId;

class MyProtocolService extends AbstractProtocolService
{
    public function encodeMyRequest(int $requestId, NodeId $authToken): string
    {
        $encoder = $this->session->createEncoder();
        $this->writeRequestHeader($encoder, $requestId, $authToken, 0x0000);

        return $this->wrapInMessage($requestId);
    }

    public function decodeMyResponse(BinaryDecoder $decoder): MyResult
    {
        $this->readResponseMetadata($decoder);

        return new MyResult($decoder->readUInt32());
    }
}
```

**2. Create DTOs as readonly classes:**

```php
<?php

declare(strict_types=1);

namespace App\OpcUa;

readonly class MyResult
{
    public function __construct(
        public int $statusCode,
    ) {
    }
}
```

**3. Wire them together in the module:**

```php
<?php

declare(strict_types=1);

namespace App\OpcUa;

use PhpOpcua\Client\Module\ServiceModule;
use PhpOpcua\Client\Protocol\SessionService;

class MyModule extends ServiceModule
{
    private ?MyProtocolService $service = null;

    public function register(): void
    {
        $this->client->registerMethod('myOperation', $this->myOperation(...));
    }

    public function boot(SessionService $session): void
    {
        $this->service = new MyProtocolService($session);
    }

    public function reset(): void
    {
        $this->service = null;
    }

    public function myOperation(): MyResult
    {
        return $this->kernel->executeWithRetry(function () {
            $this->kernel->ensureConnected();

            $requestId = $this->kernel->nextRequestId();
            $request = $this->service->encodeMyRequest($requestId, $this->kernel->getAuthToken());
            $this->kernel->send($request);

            $response = $this->kernel->receive();
            $responseBody = $this->kernel->unwrapResponse($response);
            $decoder = $this->kernel->createDecoder($responseBody);

            return $this->service->decodeMyResponse($decoder);
        });
    }
}
```

This is the same pattern used by `ReadWriteModule`, `BrowseModule`, `SubscriptionModule`, and all other built-in modules.

## Introspection

Check at runtime whether a method or module is available:

```php
$client->hasMethod('read');
$client->hasMethod('ping');

$client->hasModule(ReadWriteModule::class);
$client->hasModule(PingModule::class);
```

This is useful when working with optional modules:

```php
if ($client->hasMethod('discoverDataTypes')) {
    $client->discoverDataTypes();
}
```

List the live method / module surface (used by IPC peers such as `opcua-session-manager`'s `ManagedClient`):

```php
$client->getRegisteredMethods();  // string[] — every method name registered by loaded modules
$client->getLoadedModules();      // class-string[] — FQCNs of loaded modules
```

## Wire Serialization for Cross-Process IPC

`src/Wire/` provides a JSON-based, gadget-chain-free serialization layer that turns every core and module DTO into a payload with an explicit `__t` type discriminator. It is used by `opcua-session-manager`'s `ManagedClient` to marshal values across the IPC boundary, and is available to any consumer that needs the same guarantee.

**Contract.** A DTO implements `WireSerializable`:

```php
interface WireSerializable extends \JsonSerializable {
    public function jsonSerialize(): array;               // emit payload (no __t)
    public static function fromWireArray(array $data): static;
    public static function wireTypeId(): string;          // stable short id
}
```

**Registry.** `WireTypeRegistry` is the security gate: `encode()` wraps every `WireSerializable`, `BackedEnum`, pure `UnitEnum`, and `DateTimeImmutable` value with `{"__t": "<id>", ...}`; `decode()` rejects any `__t` id that was not explicitly registered.

**Build from loaded modules.** `ModuleRegistry::buildWireTypeRegistry()` returns a registry populated with the cross-cutting core types (via `CoreWireTypes::register()`) plus every loaded module's contribution.

```php
use PhpOpcua\Client\Wire\CoreWireTypes;
use PhpOpcua\Client\Wire\WireTypeRegistry;

$registry = $client->moduleRegistry()->buildWireTypeRegistry();
$wireValue = $registry->encode($someDataValue);            // {"__t": "DataValue", ...}
$json = json_encode($wireValue);
$rehydrated = $registry->decode(json_decode($json, true));  // DataValue instance
```

**Module hook.** A module declares the DTOs it emits by overriding `ServiceModule::registerWireTypes()`:

```php
use PhpOpcua\Client\Module\ServiceModule;
use PhpOpcua\Client\Wire\WireTypeRegistry;

class PingModule extends ServiceModule
{
    public function register(): void
    {
        $this->client->registerMethod('ping', $this->ping(...));
    }

    public function registerWireTypes(WireTypeRegistry $registry): void
    {
        $registry->register(PingResult::class);
    }
}
```

The default implementation is a no-op — modules without custom DTOs do not need to override it.

## Error Handling

Three exceptions relate to the module system:

**`ModuleConflictException`** -- thrown during boot when two modules attempt to register a method with the same name. The exception message identifies which module already owns the method. Use `replaceModule()` instead of `addModule()` when you intend to override a built-in module.

```
Method 'read' is already registered by PhpOpcua\Client\Module\ReadWrite\ReadWriteModule
```

**`MissingModuleDependencyException`** -- thrown during boot when a module declares a dependency (via `requires()`) on a module that is not registered, or when a circular dependency is detected.

```
App\OpcUa\PingModule requires PhpOpcua\Client\Module\ReadWrite\ReadWriteModule, but it is not registered
```

**`BadMethodCallException`** -- thrown at call time when you invoke a method that no module has registered. This is a standard PHP `\BadMethodCallException`.

```
Method 'ping' is not registered. Is the module loaded?
```
