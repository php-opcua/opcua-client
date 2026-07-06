# Modules reference

How `ServiceModule` works and how to write/replace one.

## Anatomy of a built-in module

Every module lives under `src/Module/<Name>/`. Minimum surface:

```php
namespace PhpOpcua\Client\Module\MyService;

use PhpOpcua\Client\Module\ServiceModule;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Wire\WireTypeRegistry;

final class MyServiceModule extends ServiceModule
{
    private ?MyService $service = null;

    /** @return array<class-string<ServiceModule>> Other ServiceModule dependencies. */
    public function requires(): array
    {
        return [];                                  // or [BrowseModule::class] etc.
    }

    /**
     * Inject this module's methods onto the Client. Called once at init.
     * Modules are keyed by class name in ModuleRegistry::add() — there is NO name() method.
     */
    public function register(): void
    {
        $this->client->registerMethod('myCustomCall', $this->myCustomCall(...));
    }

    public function myCustomCall(mixed ...$args): MyResult
    {
        // $this->kernel (ClientKernelInterface) is available, injected via setKernel().
        return $this->service?->execute(...$args)
            ?? throw new \PhpOpcua\Client\Exception\ConnectionException('not booted');
    }

    /** Build protocol services once the secure channel + session are established. */
    public function boot(SessionService $session): void
    {
        $this->service = new MyService($session);
    }

    public function reset(): void
    {
        $this->service = null;                      // clear state on disconnect
    }

    public function registerWireTypes(WireTypeRegistry $registry): void
    {
        $registry->register(MyResult::class);       // every DTO returned by your methods
    }
}
```

## Wiring it up

```php
use PhpOpcua\Client\ClientBuilder;

$client = ClientBuilder::create()
    ->addModule(new MyServiceModule())
    ->connect('opc.tcp://...');

// Custom method reachable on the client:
$result = $client->myCustomCall($arg1, $arg2);
// — Client::__call() dispatches to the registered callable
```

`hasMethod()` / `hasModule()` introspect:

```php
if ($client->hasMethod('myCustomCall')) { /* ... */ }
if ($client->hasModule(MyServiceModule::class)) { /* ... */ }
```

## Replacing a built-in

Use `replaceModule()` when you need to override behaviour of a shipped module (e.g. add tracing to every read):

```php
final class TracingReadWriteModule extends ReadWriteModule
{
    public function register(): void
    {
        // Let the parent wire its handlers (read, readMulti, write, writeMulti, call)...
        parent::register();

        // ...then override just 'read' with a tracing wrapper. Bind the parent's
        // read() so we can still delegate to the original logic.
        $parentRead = parent::read(...);

        $this->client->registerMethod('read', function (...$args) use ($parentRead) {
            $this->kernel->log()->info('reading', $this->kernel->logContext(['nodeId' => $args[0]]));

            return $parentRead(...$args);
        });
    }
}

$builder->replaceModule(ReadWriteModule::class, new TracingReadWriteModule());
```

`register()` returns `void` and takes no parameters; handlers are wired via `$this->client->registerMethod()`. The kernel is reached through the inherited `$this->kernel` property (`getLogger()` / `log()` are non-nullable). Because `Client::__call()` dispatches to the last-registered handler for a name, re-registering `read` after `parent::register()` from the same module class is allowed (last-write-wins) and replaces it cleanly.

## Method conflict detection

If two **different** modules register the same method name, `Client::registerMethod()` throws `ModuleConflictException` during boot (`ModuleRegistry::bootAll()` calls each module's `register()`). Ownership is first-registrant-wins across modules: the first module to claim a name owns it, and a second, different module claiming the same name throws. Re-registering a name from the **same** owning module is allowed and simply overwrites the handler (last-write-wins) — this is intentional so handlers survive a disconnect/reconnect re-boot. Naming two methods `read` within a single module therefore does NOT throw.

## Module dependencies

`requires(): array` returns class-strings of `ServiceModule` your module depends on. `ModuleRegistry` does a topological sort during `bootAll()`. Missing dependencies raise `MissingModuleDependencyException`.

Example: `TypeDiscoveryModule::requires()` returns `[ReadWriteModule::class, BrowseModule::class]` because type discovery walks the address space and reads `DataTypeDefinition` attributes.

## Protocol service pattern

Most modules delegate **pure encode/decode** to a `Protocol\*Service` class extending `AbstractProtocolService`. The protocol service only knows about the `SessionService` (for token/sequence/secure-channel access) — it never touches the network. The owning module drives the kernel I/O:

```php
final class MyService extends AbstractProtocolService
{
    public function encodeMyRequest(int $requestId, NodeId $authToken, mixed $input): string
    {
        $body = new BinaryEncoder();
        // ... write request body ...
        return $this->encodeRequestAuto($requestId, $body->getBuffer());
    }

    public function decodeMyResponse(BinaryDecoder $decoder): MyResult
    {
        $this->readResponseMetadata($decoder);
        // ... read fields ...
        return new MyResult(/* ... */);
    }
}
```

The module method (with `$service` constructed in `boot(SessionService $session)`) orchestrates the round-trip through `$this->kernel`:

```php
public function myCustomCall(mixed $input): MyResult
{
    return $this->kernel->executeWithRetry(function () use ($input) {
        $this->kernel->ensureConnected();
        $requestId = $this->kernel->nextRequestId();
        $request = $this->service->encodeMyRequest($requestId, $this->kernel->getAuthToken(), $input);
        $this->kernel->send($request);
        $response = $this->kernel->receive();
        $decoder = $this->kernel->createDecoder($this->kernel->unwrapResponse($response));

        return $this->service->decodeMyResponse($decoder);
    });
}
```

`AbstractProtocolService` provides:

- `$this->session` (`SessionService`) — token/sequence/secure-channel access (its only member)
- `encodeRequest()`, `encodeRequestSecure()`, `encodeRequestAuto()`, `writeRequestHeader()`, `wrapInMessage()` — framing helpers
- `readResponseMetadata()` — reads and discards the response framing/`ResponseHeader`, returns the `int` status code, and throws a `ServiceFault` if the response is a fault

`send()`, `receive()`, `executeWithRetry()`, `ensureConnected()`, `nextRequestId()`, `getAuthToken()`, `createDecoder()`, and `unwrapResponse()` are `ClientKernelInterface` methods reached via the `ServiceModule`'s `$this->kernel` — they are NOT available on a protocol service.

## 10 built-in modules

| Module | Class | Methods registered |
| --- | --- | --- |
| `Aggregate` (v4.4) | `Module\Aggregate\AggregateModule` | `aggregate`, `historyAggregate` |
| `Browse` | `Module\Browse\BrowseModule` | `browse`, `browseAll`, `browseRecursive`, `browseWithContinuation`, `browseNext`, `getEndpoints` |
| `FileTransfer` (v4.4) | `Module\FileTransfer\FileTransferModule` | `openFile`, `closeFile`, `readFile`, `writeFile`, `getFilePosition`, `setFilePosition`, `createDirectory`, `createFileInDirectory`, `deleteFileSystemObject`, `moveOrCopyFileSystemObject` |
| `History` | `Module\History\HistoryModule` | `historyReadRaw`, `historyReadProcessed`, `historyReadAtTime` + 9 HistoryUpdate methods (v4.4): `historyInsertData`, `historyReplaceData`, `historyUpdateData`, `historyDeleteRawModified`, `historyDeleteAtTime`, `historyInsertEvent`, `historyReplaceEvent`, `historyUpdateEvent`, `historyDeleteEvent` |
| `NodeManagement` | `Module\NodeManagement\NodeManagementModule` | `addNodes`, `deleteNodes`, `addReferences`, `deleteReferences` |
| `ReadWrite` | `Module\ReadWrite\ReadWriteModule` | `read`, `readMulti`, `write`, `writeMulti`, `call` |
| `ServerInfo` | `Module\ServerInfo\ServerInfoModule` | `getServerBuildInfo`, `getServerProductName`, etc. |
| `Subscription` | `Module\Subscription\SubscriptionModule` | `createSubscription`, `createMonitoredItems`, `createEventMonitoredItem`, `deleteMonitoredItems`, `modifyMonitoredItems`, `setTriggering`, `deleteSubscription`, `publish`, `republish`, `transferSubscriptions` |
| `TranslateBrowsePath` | `Module\TranslateBrowsePath\TranslateBrowsePathModule` | `translateBrowsePaths`, `resolveNodeId` |
| `TypeDiscovery` | `Module\TypeDiscovery\TypeDiscoveryModule` | `discoverDataTypes`, `registerTypeCodec` |

## WireSerializable contract

Every DTO that crosses an IPC boundary (cache, ManagedClient bridge) MUST implement `WireSerializable`:

```php
use PhpOpcua\Client\Wire\WireSerializable;

final readonly class MyResult implements WireSerializable
{
    public function __construct(
        public int $statusCode,
        public string $details,
    ) {}

    public static function wireTypeId(): string { return 'MyResult'; }

    public function jsonSerialize(): array
    {
        return ['statusCode' => $this->statusCode, 'details' => $this->details];
    }

    public static function fromWireArray(array $data): static
    {
        return new self($data['statusCode'] ?? 0, $data['details'] ?? '');
    }
}
```

Register it from your module's `registerWireTypes()`. Unregistered IDs on the wire raise `CacheCorruptedException` (treated as cache miss) or are rejected by `ManagedClient` decode.
