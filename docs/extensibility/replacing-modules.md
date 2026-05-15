---
eyebrow: 'Docs · Extensibility'
lede:    'Swap a built-in module with your own implementation when the default semantics do not fit. The method names stay the same; the implementation changes underneath.'

see_also:
  - { href: './modules.md',              meta: '8 min' }
  - { href: '../testing/mock-client.md', meta: '6 min' }
  - { href: '../reference/builder-api.md', meta: '6 min' }

prev: { label: 'Modules',                  href: './modules.md' }
next: { label: 'Extension object codecs',  href: './extension-object-codecs.md' }
---

# Replacing modules

`ClientBuilder::replaceModule()` is the controlled substitution path.
It swaps a built-in module with a class that registers the **same
method names**, so the existing application code keeps working while
the underlying logic changes.

Reach for it when:

- The default behaviour is wrong for your server. Some PLCs handle
  `browseRecursive()` poorly; you want a depth-first variant.
- You need to instrument every call to a particular service set
  (metrics, audit, rate limiting).
- You are testing a fix against a single service before contributing
  it back to the library.

## The contract

`replaceModule($classToReplace, $replacement)`:

- Removes the module identified by `$classToReplace` from
  `defaultModules()`.
- Installs `$replacement` in its place.
- Validates that the replacement registers a **superset** of the
  original method names — a replacement that drops a method is allowed,
  but the moment any caller hits the dropped name they get a
  `ModuleConflictException` (no other module owns it) or an `Error`.
  Be deliberate.

<!-- @code-block language="php" label="replace BrowseModule" -->
```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Module\Browse\BrowseModule;

$client = ClientBuilder::create()
    ->replaceModule(BrowseModule::class, new \App\Opcua\TracingBrowseModule())
    ->connect('opc.tcp://plc.local:4840');
```
<!-- @endcode-block -->

## Pattern — wrap, don't fork

The robust shape is a decorator: keep the original module's logic and
add instrumentation around it.

<!-- @code-block language="php" label="examples/TracingBrowseModule.php" -->
```php
namespace App\Opcua;

use PhpOpcua\Client\Module\Browse\BrowseModule;
use PhpOpcua\Client\Types\NodeId;

final class TracingBrowseModule extends BrowseModule
{
    public function register(): void
    {
        parent::register();
        // After parent::register(), every browse* method is registered
        // against the original handler. Override the ones you want
        // to instrument by re-registering with a wrapper.

        $this->kernel->registerMethod(
            owner: self::class,
            name: 'browse',
            handler: function (NodeId|string $nodeId, mixed ...$rest) {
                $start = microtime(true);
                try {
                    return parent::handleBrowse($nodeId, ...$rest);
                } finally {
                    $this->kernel->log()->info('browse.timing', [
                        'nodeId' => (string) $nodeId,
                        'ms'     => (microtime(true) - $start) * 1000,
                    ]);
                }
            },
        );
    }
}
```
<!-- @endcode-block -->

The pattern relies on the parent's handlers being public or protected
methods. The built-in modules expose them as `protected` for exactly
this case — extending them is the supported path.

## Pattern — replace the protocol service

For deeper customisation, override the protocol service the module
uses. The module class wires services through the kernel; subclass the
module, point it at your own protocol service:

<!-- @code-block language="text" label="layering" -->
```text
ReadWriteModule       (registers methods on the kernel)
 └── ReadService      (encodes a ReadRequest, decodes ReadResponse)
      └── BinaryEncoder / BinaryDecoder
```
<!-- @endcode-block -->

A replacement that wants to add a per-node retry policy to reads
subclasses `ReadService`, overrides the call site, and swaps the
service in the module's constructor.

## Caveats

- **Caching contracts.** The built-in `BrowseModule` populates the
  cache with specific key shapes (see [Observability ·
  Caching](../observability/caching.md)). A replacement that uses
  different keys works, but mixing the two — e.g. inheriting the
  original keying for some calls and using your own for others — leads
  to stale entries that survive your code but read like the original
  module's state. Pick one keying scheme per module.

- **Event semantics.** The built-in modules dispatch lifecycle events
  (`NodeBrowsed`, `NodeValueRead`, …). A replacement that does not
  dispatch them breaks listener code that relies on them. If you
  inherit, the parent dispatches; if you fork, mirror the dispatch
  calls.

- **Wire type registration.** If you swap a module that ships
  WireSerializable DTOs (Subscription, NodeManagement, …) with one
  that introduces new result types, override `registerWireTypes()` to
  declare them. Otherwise IPC consumers will reject the new payloads
  on decode.

## Replacing more than one module

`replaceModule()` is idempotent and order-independent. Stack as many
as needed:

<!-- @code-block language="php" label="multi-replace" -->
```php
$client = ClientBuilder::create()
    ->replaceModule(BrowseModule::class,    new App\TracingBrowseModule())
    ->replaceModule(ReadWriteModule::class, new App\ValidatedReadWriteModule())
    ->connect('opc.tcp://plc.local:4840');
```
<!-- @endcode-block -->

Conflicts (two modules registering the same method name across
swaps) surface at boot as `ModuleConflictException`.

## Comparing approaches

<!-- @do-dont -->
<!-- @do -->
**Extend the existing module.**

`final class App\TracingBrowseModule extends BrowseModule { … }`

Inherits all the encoding, caching, and event machinery. You only
override what you need.
<!-- @enddo -->
<!-- @dont -->
**Reimplement the module from scratch.**

`final class App\BrowseModule extends ServiceModule { … }`

Doable, but you re-own every protocol-encoding detail, every event
dispatch, every caching contract. Reserve this for cases where the
built-in is fundamentally wrong — not for "I want to add a log line".
<!-- @enddont -->
<!-- @enddo-dont -->

## Testing replacements

`MockClient` (see [Testing · MockClient](../testing/mock-client.md))
does not run modules — it implements the public interface directly.
That means a replaced module is **not** exercised in unit tests against
`MockClient`. Test replacements against a real server (the integration
suite is a good template) or with a `Client` configured to use an
in-memory transport stub.

The library's test suite uses a stubbed transport for unit-testing
modules in isolation; the pattern is in `tests/Unit/Module/`.
