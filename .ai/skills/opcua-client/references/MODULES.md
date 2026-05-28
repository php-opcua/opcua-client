# Modules reference

How `ServiceModule` works and how to write/replace one.

## Anatomy of a built-in module

Every module lives under `src/Module/<Name>/`. Minimum surface:

```php
namespace PhpOpcua\Client\Module\MyService;

use PhpOpcua\Client\Module\ServiceModule;
use PhpOpcua\Client\Kernel\ClientKernelInterface;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Wire\WireTypeRegistry;

final class MyServiceModule extends ServiceModule
{
    public function name(): string
    {
        return 'my-service';
    }

    /** @return class-string[] Other ServiceModule dependencies. */
    public function requires(): array
    {
        return [];                                  // or [BrowseModule::class] etc.
    }

    /**
     * Return the method-name → callable map that gets attached to the Client.
     * @return array<string, callable>
     */
    public function register(ClientKernelInterface $kernel, SessionService $session): array
    {
        $service = new MyService($kernel, $session);

        return [
            'myCustomCall' => fn (...$args) => $service->execute(...$args),
        ];
    }

    public function boot(): void { /* post-registration init */ }
    public function reset(): void { /* clear state on reconnect */ }

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
    public function register(ClientKernelInterface $kernel, SessionService $session): array
    {
        $original = parent::register($kernel, $session);

        return [
            ...$original,
            'read' => function (...$args) use ($original, $kernel) {
                $kernel->getLogger()?->info('reading', ['nodeId' => $args[0]]);
                return $original['read'](...$args);
            },
        ];
    }
}

$builder->replaceModule(ReadWriteModule::class, new TracingReadWriteModule());
```

## Method conflict detection

If two modules register the same method name, `ModuleRegistry::register()` throws `ModuleConflictException` at boot. Names are first-write-wins per-module — you can't name two methods `read` in the same module either.

## Module dependencies

`requires(): array` returns class-strings of `ServiceModule` your module depends on. `ModuleRegistry` does a topological sort during `bootAll()`. Missing dependencies raise `MissingModuleDependencyException`.

Example: `TypeDiscoveryModule::requires()` returns `[BrowseModule::class, ReadWriteModule::class]` because type discovery walks the address space and reads `DataTypeDefinition` attributes.

## Protocol service pattern

Most modules delegate wire work to a `Protocol\*Service` class extending `AbstractProtocolService`:

```php
final class MyService extends AbstractProtocolService
{
    public function execute(mixed $input): MyResult
    {
        return $this->kernel->executeWithRetry(function () use ($input) {
            $request = $this->encodeRequest($input);
            $this->kernel->send($request);
            $response = $this->kernel->receive();
            return $this->decodeResponse($response);
        });
    }

    private function encodeRequest(mixed $input): string { /* ... */ }
    private function decodeResponse(string $bytes): MyResult { /* ... */ }
}
```

`AbstractProtocolService` provides:

- `$this->kernel` (`ClientKernelInterface`)
- `$this->session` (`SessionService`)
- `encodeRequestAuto()`, `writeRequestHeader()`, `wrapInMessage()` — framing helpers
- `readResponseMetadata()` — pull `ServiceResult` + diagnostics from the response

## 10 built-in modules

| Module | Class | Methods registered |
| --- | --- | --- |
| `Aggregate` (v4.4) | `Module\Aggregate\AggregateModule` | `aggregate`, `historyAggregate` |
| `Browse` | `Module\Browse\BrowseModule` | `browse`, `browseAll`, `browseTree`, `getEndpoints`, `resolveNodeId` |
| `FileTransfer` (v4.4) | `Module\FileTransfer\FileTransferModule` | `fileOpen`, `fileRead`, `fileWrite`, `fileClose`, `filePosition`, `fileCreate*`, `fileDelete`, etc. |
| `History` | `Module\History\HistoryModule` | `historyReadRaw`, `historyReadProcessed`, `historyReadAtTime`, `historyReadEvents`, + 9 HistoryUpdate methods (v4.4) |
| `NodeManagement` | `Module\NodeManagement\NodeManagementModule` | `addNodes`, `deleteNodes`, `addReferences`, `deleteReferences` |
| `ReadWrite` | `Module\ReadWrite\ReadWriteModule` | `read`, `readMulti`, `write`, `writeMulti`, `callMethod`, metadata helpers |
| `ServerInfo` | `Module\ServerInfo\ServerInfoModule` | `getServerBuildInfo`, `getServerProductName`, etc. |
| `Subscription` | `Module\Subscription\SubscriptionModule` | `createSubscription`, `createMonitoredItems`, `modifyMonitoredItems`, `deleteMonitoredItems`, `deleteSubscriptions`, `publish`, `republish`, `transferSubscriptions` |
| `TranslateBrowsePath` | `Module\TranslateBrowsePath\TranslateBrowsePathModule` | `translateBrowsePath`, `translateBrowsePaths` |
| `TypeDiscovery` | `Module\TypeDiscovery\TypeDiscoveryModule` | `discoverDataTypes` |

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
