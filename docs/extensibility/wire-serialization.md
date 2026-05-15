---
eyebrow: 'Docs · Extensibility'
lede:    'Wire serialization is the JSON-safe layer the client uses to round-trip DTOs across process boundaries — daemons, queues, anywhere PHP serialize() would be a security liability.'

see_also:
  - { href: '../security/cache-path-hardening.md', meta: '5 min' }
  - { href: './modules.md',                        meta: '8 min' }
  - { href: 'https://github.com/php-opcua/opcua-session-manager', meta: 'external', label: 'opcua-session-manager' }

prev: { label: 'Type discovery', href: './type-discovery.md' }
next: { label: 'Logging',        href: '../observability/logging.md' }
---

# Wire serialization

The `PhpOpcua\Client\Wire\` namespace ships a JSON-based serialization
layer designed to move typed PHP values across an IPC boundary safely.
It is used internally by the cache path (see [Security · Cache path
hardening](../security/cache-path-hardening.md)) and externally by
`opcua-session-manager`'s `ManagedClient` to ferry DTOs between the
daemon and the application.

If your code never crosses a process boundary, you can skip this page.
If you wire a custom module into a daemon or a queue, this is the
contract you sign.

## The three primitives

| Class                | Role                                                          |
| -------------------- | ------------------------------------------------------------- |
| `WireSerializable` (interface) | DTOs declare how to encode and reconstruct themselves |
| `WireTypeRegistry`   | The security gate — encodes values with a `__t` discriminator, rejects unknown ids on decode |
| `CoreWireTypes`      | A helper that installs the cross-cutting core types on a registry |

The contract:

<!-- @code-block language="php" label="WireSerializable" -->
```php
namespace PhpOpcua\Client\Wire;

interface WireSerializable extends \JsonSerializable
{
    /**
     * Stable short identifier for this type. Used as the value of the
     * `__t` discriminator on encoded payloads.
     */
    public static function wireTypeId(): string;

    /**
     * Reconstruct an instance from the array produced by jsonSerialize().
     */
    public static function fromWireArray(array $data): static;
}
```
<!-- @endcode-block -->

`jsonSerialize()` returns an array — the wire payload, **without** the
`__t` discriminator (the registry adds it). `fromWireArray()` is the
inverse, called by the registry after the discriminator has been
checked.

## A worked example

A custom DTO that needs to travel through IPC:

<!-- @code-block language="php" label="examples/SensorReading.php" -->
```php
namespace App\Opcua;

use PhpOpcua\Client\Wire\WireSerializable;

final readonly class SensorReading implements WireSerializable
{
    public function __construct(
        public string  $sensorId,
        public float   $temperatureC,
        public float   $humidity,
        public \DateTimeImmutable $sampledAt,
    ) {}

    public static function wireTypeId(): string
    {
        return 'app.SensorReading';
    }

    public function jsonSerialize(): array
    {
        return [
            'sensorId'      => $this->sensorId,
            'temperatureC'  => $this->temperatureC,
            'humidity'      => $this->humidity,
            'sampledAt'     => $this->sampledAt->format(\DateTimeInterface::RFC3339_EXTENDED),
        ];
    }

    public static function fromWireArray(array $data): static
    {
        return new self(
            sensorId:     $data['sensorId'],
            temperatureC: (float) $data['temperatureC'],
            humidity:     (float) $data['humidity'],
            sampledAt:    new \DateTimeImmutable($data['sampledAt']),
        );
    }
}
```
<!-- @endcode-block -->

Two things to note:

- The `wireTypeId()` is a **stable** string. Once consumers know it,
  do not rename it without coordinating both sides of the IPC.
- The shape returned by `jsonSerialize()` must round-trip through JSON
  — no resources, no `Closure`, no nested PHP-specific types. Use ISO
  strings for `DateTimeImmutable`, base64 for raw bytes.

## Registry use

A consumer (a daemon, a queue worker) builds a `WireTypeRegistry`,
installs the types it expects, and uses it to encode/decode:

<!-- @code-block language="php" label="consumer side" -->
```php
use PhpOpcua\Client\Wire\WireTypeRegistry;
use PhpOpcua\Client\Wire\CoreWireTypes;

$registry = new WireTypeRegistry();
CoreWireTypes::register($registry);
$registry->register('app.SensorReading', App\Opcua\SensorReading::class);

$wire = $registry->encode(new SensorReading('s-42', 23.1, 41.2, new DateTimeImmutable()));
// → ['__t' => 'app.SensorReading', 'sensorId' => 's-42', …]

$json = json_encode($wire);
// Send across the IPC boundary.

// Receiver:
$received = $registry->decode(json_decode($json, true));
// → SensorReading instance
```
<!-- @endcode-block -->

`CoreWireTypes::register()` installs the cross-cutting OPC UA types
(`NodeId`, `DataValue`, `Variant`, `LocalizedText`, `QualifiedName`,
`BrowseNode`, `ReferenceDescription`, `EndpointDescription`,
`UserTokenPolicy`, plus enums `BuiltinType`, `NodeClass`,
`BrowseDirection`, `ConnectionState`). Use it as the baseline, then
add module-specific or application-specific DTOs.

`CoreWireTypes::registerForCache()` installs the smaller subset the
client itself caches — useful when wiring a registry for cache
storage rather than IPC.

## Module integration

A `ServiceModule` that ships DTOs (`SubscriptionResult`,
`AddNodesResult`, …) declares them via `registerWireTypes()`:

<!-- @code-block language="php" label="ServiceModule hook" -->
```php
public function registerWireTypes(WireTypeRegistry $registry): void
{
    $registry->register(SensorReading::wireTypeId(), SensorReading::class);
    $registry->register(SensorBatch::wireTypeId(),   SensorBatch::class);
}
```
<!-- @endcode-block -->

The library composes registries automatically via
`ModuleRegistry::buildWireTypeRegistry()`, which seeds with the core
types and walks every loaded module's hook. Consumers of the library
(`ManagedClient`, an IPC layer you write) use the same composed
registry on the receiving end.

## The security guarantee

`WireTypeRegistry::decode()` raises `EncodingException` (or, on the
cache path, `CacheCorruptedException`) when:

- The payload's `__t` discriminator is missing.
- The discriminator is not in the registry's allowlist.

There is **no `unserialize()` call anywhere in the registry**. The
worst an attacker who controls the wire bytes can do is craft a
payload whose `__t` is one of your registered types, with field values
that pass JSON parsing. Whether the constructor of that type does
something interesting with those values is the application's concern;
gadget-chain object instantiation across the autoload graph is not
possible.

This is the same property the cache path relies on. The threat model
and rationale are detailed in [Security · Cache path
hardening](../security/cache-path-hardening.md).

## Built-in DTO coverage

Every shipping module's result DTO implements `WireSerializable`. As
of v4.3.x:

`SubscriptionResult`, `MonitoredItemResult`, `MonitoredItemModifyResult`,
`PublishResult`, `SetTriggeringResult`, `TransferResult`, `CallResult`,
`BrowsePathResult`, `BrowsePathTarget`, `BrowseResultSet`,
`AddNodesResult`, `BuildInfo`. Plus the core value types via
`CoreWireTypes::register()`. Together, they cover everything
`OpcUaClientInterface` returns.

## Limitations

- **JSON, not binary.** The registry uses JSON because most IPC paths
  in PHP ecosystems are text-friendly (Redis, queues, HTTP). It is
  not the most compact wire format; for tight loops, batch payloads.
- **Backed enums + pure unit enums.** Both are supported. Pure enums
  are name-scanned (`cases()`), backed enums use `::from($scalar)`.
- **`DateTimeImmutable`** is a built-in special case — encoded as
  `{"__t": "DateTime", "v": "<ISO 8601 with microseconds>"}`. Other
  date types must implement `WireSerializable` themselves.
- **No round-trip for `Closure`, resources, `__sleep` magic.** If
  your DTO needs them, the wire layer is the wrong tool.
