---
eyebrow: 'Docs · Extensibility'
lede:    'A ServiceModule is a self-contained slice of OPC UA functionality — its own protocol services, DTOs, and methods. Write one when you need a service set the library does not ship.'

see_also:
  - { href: './replacing-modules.md',     meta: '5 min' }
  - { href: './wire-serialization.md',    meta: '6 min' }
  - { href: '../reference/builder-api.md', meta: '6 min' }

prev: { label: 'Built-in types',     href: '../types/built-in-types.md' }
next: { label: 'Replacing modules',  href: './replacing-modules.md' }
---

# Modules

The client's public API surface is built from **service modules**.
Each module bundles three things:

- A class extending `Module\ServiceModule`
- One or more `Protocol\AbstractProtocolService` subclasses doing the
  binary encoding
- Module-local DTOs for the results

The library ships **eight** modules in
`ClientBuilder::defaultModules()`: ReadWrite, Browse, Subscription,
History, NodeManagement, TranslateBrowsePath, ServerInfo,
TypeDiscovery. Together they cover the standard OPC UA service sets
exposed on `OpcUaClientInterface`.

Adding a custom module is how you extend that surface — for an OPC UA
service set the library does not ship (Query, ProgramStateMachine,
…), or for a vendor extension that uses non-standard service NodeIds.

## What a module is

`ServiceModule` is an abstract base. The contract:

<!-- @code-block language="php" label="ServiceModule contract" -->
```php
namespace PhpOpcua\Client\Module;

abstract class ServiceModule
{
    public function setKernel(ClientKernelInterface $kernel): void;
    public function setClient(object $client): void;

    /**
     * Declare modules this module depends on. Returns an array of
     * other ServiceModule class-strings. Topologically sorted at boot.
     */
    public function requires(): array { return []; }

    /**
     * Register the methods this module exposes on the client.
     * Called once per client at boot time.
     */
    abstract public function register(): void;

    /**
     * Called after the session is activated. Use it to do one-off
     * server-side discovery the module needs (e.g. read the namespace
     * array).
     */
    public function boot(SessionService $session): void {}

    /**
     * Called on every disconnect to reset internal state.
     */
    public function reset(): void {}

    /**
     * Hook for declaring DTOs to the Wire registry — only needed if
     * your module is consumed across an IPC boundary.
     */
    public function registerWireTypes(WireTypeRegistry $registry): void {}
}
```
<!-- @endcode-block -->

## Writing a module

A minimal module that exposes a single `getServerStatus()` method:

<!-- @code-block language="php" label="examples/StatusModule.php" -->
```php
namespace App\Opcua;

use PhpOpcua\Client\Module\ServiceModule;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\AttributeId;

final class StatusModule extends ServiceModule
{
    public function register(): void
    {
        $this->kernel->registerMethod(
            owner: self::class,
            name:  'getServerStatus',
            handler: $this->handleGetServerStatus(...),
        );
    }

    public function handleGetServerStatus(): array
    {
        return $this->kernel->executeWithRetry(fn () => [
            'state'         => $this->kernel->getClient()->read(NodeId::numeric(0, 2259), AttributeId::Value)->getValue(),
            'currentTime'   => $this->kernel->getClient()->read(NodeId::numeric(0, 2258), AttributeId::Value)->getValue(),
            'startTime'     => $this->kernel->getClient()->read(NodeId::numeric(0, 2257), AttributeId::Value)->getValue(),
        ]);
    }
}
```
<!-- @endcode-block -->

Register it on the builder:

<!-- @code-block language="php" label="addModule" -->
```php
$client = ClientBuilder::create()
    ->addModule(new \App\Opcua\StatusModule())
    ->connect('opc.tcp://plc.local:4840');

$status = $client->getServerStatus();   // ← via __call dispatch
```
<!-- @endcode-block -->

Methods registered by custom modules are reached through `Client::__call()`.
They do not appear on `OpcUaClientInterface`, so static analysers
won't see them — your application will. Use `$client->hasMethod()` /
`$client->hasModule()` to introspect at runtime.

## The kernel

`ClientKernelInterface` is the surface a module sees. It exposes the
infrastructure the module needs without coupling to the `Client`
concrete:

| Capability                                  | Method                                |
| ------------------------------------------- | ------------------------------------- |
| Wrap a call with auto-retry                 | `executeWithRetry(Closure)`           |
| Ensure the channel is up                    | `ensureConnected()`                   |
| Send raw bytes                              | `send(string)` / `receive()`          |
| Allocate a request ID                       | `nextRequestId()`                     |
| Get the current session auth token          | `getAuthToken()`                      |
| Resolve a `NodeId\|string` via the dispatcher | `resolveNodeId(NodeId\|string)`     |
| Bulk-resolve an array of items in place     | `resolveNodeIdArray(&$items, $key)`   |
| Get a `BinaryDecoder` over a response       | `createDecoder(string)`               |
| Dispatch a PSR-14 event                     | `dispatch(object)`                    |
| Log with the per-call context attached      | `log()`, `logContext()`               |
| Cache lookup + miss handler                 | `cachedFetch(key, fetcher, useCache)` |
| Get the cache codec                         | `getCacheCodec()`                     |
| Get configuration values                    | `getTimeout()`, `getAutoRetry()`, `getEffectiveReadBatchSize()`, … |
| Get the extension-object repository         | `getExtensionObjectRepository()`      |

The kernel is **implemented directly by `Client`** through its
`Manages*Traits`. There is no separate concrete kernel class — the
interface is the contract, and a module that depends on it can be
tested against a stub.

<!-- @callout variant="note" -->
A `ServiceModule::register()` call must use `$this->kernel->registerMethod()`
exactly once per method name. Re-registering the same `(owner, name)`
pair is allowed (it survives `disconnect()` / `reconnect()` cycles).
Registering a name another module already owns raises
`ModuleConflictException`.
<!-- @endcallout -->

## Module lifecycle

The `ModuleRegistry` orchestrates boot and shutdown:

<!-- @steps -->
- **`addModule()` is called on the builder.**

  The module is staged. No method registrations yet.

- **`connect()` runs.**

  Channel opens, session activates. The registry topologically sorts
  modules by `requires()`, calls `setKernel()` and `setClient()` on
  each, then `register()`.

- **`boot()` runs for every module, in dependency order.**

  Optional server-side discovery happens here.

- **`registerWireTypes()` runs once.**

  If a `WireTypeRegistry` is being built (e.g. for IPC consumers), each
  module adds its DTOs.

- **Service calls flow through the registered methods.**

  `Client::__call()` looks up the handler, runs it.

- **`disconnect()` runs.**

  `reset()` is called on every module to drop any per-session state.
  Method registrations survive — the next `reconnect()` reactivates
  them without going through `register()` again.
<!-- @endsteps -->

## Declaring dependencies

A module that needs another to be registered first declares it:

<!-- @code-block language="php" label="module dependencies" -->
```php
public function requires(): array
{
    return [
        \PhpOpcua\Client\Module\Browse\BrowseModule::class,
        \PhpOpcua\Client\Module\ReadWrite\ReadWriteModule::class,
    ];
}
```
<!-- @endcode-block -->

If a required module is missing, the registry raises
`MissingModuleDependencyException` at boot. Module ordering is
topologically sorted before booting; cycles raise an explicit error.

## Built-in module reference

Each shipping module's source is the best reference for the patterns
that work:

| Module                        | Path                                              |
| ----------------------------- | ------------------------------------------------- |
| `ReadWriteModule`             | `src/Module/ReadWrite/ReadWriteModule.php`        |
| `BrowseModule`                | `src/Module/Browse/BrowseModule.php`              |
| `SubscriptionModule`          | `src/Module/Subscription/SubscriptionModule.php`  |
| `HistoryModule`               | `src/Module/History/HistoryModule.php`            |
| `NodeManagementModule`        | `src/Module/NodeManagement/NodeManagementModule.php` |
| `TranslateBrowsePathModule`   | `src/Module/TranslateBrowsePath/TranslateBrowsePathModule.php` |
| `ServerInfoModule`            | `src/Module/ServerInfo/ServerInfoModule.php`      |
| `TypeDiscoveryModule`         | `src/Module/TypeDiscovery/TypeDiscoveryModule.php` |

The Browse module is a particularly readable starting point —
non-trivial protocol encoding, sensible result paging, caching tied to
the kernel surface.

## What to read next

- [Replacing modules](./replacing-modules.md) — swap a built-in for a
  customised one.
- [Wire serialization](./wire-serialization.md) — when your module's
  DTOs need to round-trip through IPC.
