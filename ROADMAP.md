# Roadmap

> **A note on versioning:** We're aware of the rapid major releases in a short time frame. This library is under active, full-time development right now — the goal is to reach a production-stable state as quickly as possible. Breaking changes are being bundled and shipped deliberately to avoid dragging them out across many minor releases. Once the API surface settles, major version bumps will become rare. Thanks for your patience.

## v3.0.0

- [X] CodeCoverage
- [X] Improuve Documentation
- [X] `ExtensionObjectRepository`: replace static registry with instance-level dependency
- [X] Strict Return Types
- [X] Named Parameters Everywhere
- [X] Full PHPDoc / Attribute Documentation
- [X] Fluent / Builder API
- [X] Browse Filters (`nodeClassMask` → `NodeClass[]`),
- [X] Browse Filters ResultMask → Won't Do (see below)
- [X] Human-readable NodeId strings (`NodeId|string` union type)
- [X] Full ExtensionObject Type System `OPC UA Part 6 5.2.7`
- [X] PSR-3 Logging (`setLogger()`, NullLogger default)
- [X] MockClient for testing
- [ ] Transfer Subscriptions ( for `gianfriaur/opcua-php-client-session-manager` )
- [ ] Republish  ( for `gianfriaur/opcua-php-client-session-manager` )
- [ ] Cache for browse results 
------

## v4.0.0
- [ ] CLI Tool
- [ ] xml Code Generator
- [ ] `TBD` Telemetry
- [ ] Server Trust Management (also for cli)
- [ ] NodeManagement Services
- [ ] Triggering / ModifyMonitoredItems
- [ ] Symfony integration like Laravel ( `gianfriaur/opcua-symfony-client` ) 


This document outlines planned improvements and features for the OPC UA PHP Client library.


### PSR-3 Logging
Inject any PSR-3 compatible logger (Monolog, etc.) into the `Client` to observe connection events, retry attempts, batch splits, and protocol errors:

```php
$client = new Client();
$client->setLogger($monologInstance);
```

Log levels:
- `DEBUG` — binary encode/decode, sequence numbers
- `INFO` — connect, disconnect, reconnect, batch splits
- `WARNING` — retry attempts, server limit overrides
- `ERROR` — connection failures, security errors

### MockClient for testing
A `MockClient` implementing `OpcUaClientInterface` with configurable responses and no TCP connection, so library consumers can write unit tests without a real OPC UA server:

```php
$mock = MockClient::create()
    ->onRead(NodeId::numeric(2, 1001), fn() => DataValue::ofInt32(42))
    ->onWrite(NodeId::numeric(2, 1001), fn($v) => StatusCode::Good)
    ->onBrowse(NodeId::numeric(0, 85), fn() => [...]);
 
$service = new MyPlcService($mock);
$this->assertEquals(42, $service->readTemperature());
```

### Transfer Subscriptions + Republish
OPC UA services for recovering subscriptions after a session loss:
- `TransferSubscriptions` — move existing subscriptions to a new session without data loss
- `Republish` — re-deliver notifications that were sent but not yet acknowledged

These integrate directly with the `session-manager` daemon to enable zero-data-loss reconnect scenarios.

### PSR-6 / PSR-16 Cache for browse results

Cache `browse`, `browseAll`, and `resolveNodeId` results with a pluggable driver system.
Useful when the address space is large but changes rarely (typical in industrial PLC environments).

**Built-in drivers:**

| Driver | Scope | Dependencies |
|--------|-------|--------------|
| `InMemoryDriver` *(default)* | Per-process | None |
| `FileDriver` | Persistent across requests | None |
| `RedisDriver` | Shared across processes/servers | `ext-redis` or `predis/predis` |
| `MemcachedDriver` | Shared across processes/servers | `ext-memcached` |

**Default TTL:** 300 seconds. Configurable globally on the driver or per-call.

#### Driver interface

```php
interface BrowseCacheDriverInterface
{
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, ?int $ttl = null): void;
    public function has(string $key): bool;
    public function delete(string $key): void;
    public function flush(): void;
    public function getDefaultTtl(): int;
    public function setDefaultTtl(int $seconds): void;
}
```

#### Usage

```php
// In-memory (default, active out of the box — no setup required)
$client->setBrowseCache(new InMemoryDriver(ttl: 300));
 
// File-based (survives PHP process restart)
$client->setBrowseCache(new FileDriver('/tmp/opcua-cache', ttl: 600));
 
// Redis (shared across processes/servers)
$client->setBrowseCache(new RedisDriver($redisConnection, ttl: 3600));
 
// Memcached
$client->setBrowseCache(new MemcachedDriver($memcached, ttl: 3600));
 
// Custom driver
$client->setBrowseCache(new MyDriver(ttl: 1800));
 
// Disable cache entirely
$client->setBrowseCache(null);
```

The cache is **active by default** using `InMemoryDriver` with a 300-second TTL.
Pass `null` to disable it entirely.

#### Per-call cache bypass

All three cached methods accept an optional `useCache` parameter to skip the cache for a single call
and always fetch a fresh result from the server:

```php
// Standard call — uses cache (default)
$refs   = $client->browse(NodeId::numeric(0, 85));
$refs   = $client->browseAll(NodeId::numeric(0, 85));
$nodeId = $client->resolveNodeId('/Objects/MyPLC/Temperature');
 
// Skip cache for this call only
$refs   = $client->browse(NodeId::numeric(0, 85), useCache: false);
$refs   = $client->browseAll(NodeId::numeric(0, 85), useCache: false);
$nodeId = $client->resolveNodeId('/Objects/MyPLC/Temperature', useCache: false);
```

#### Cache invalidation

When you know the address space has changed, you can invalidate selectively or flush everything:

```php
// Invalidate browse results for a specific node
$client->invalidateBrowseCache(NodeId::numeric(0, 85));
 
// Flush the entire browse cache
$client->flushBrowseCache();
```

#### Cache key format

Keys are generated from the endpoint URL, NodeId, and browse parameters.
This ensures that two clients pointing to different servers never collide,
and Redis/Memcached caches can be safely shared across multiple PHP processes:

```
opcua:{endpoint_hash}:browse:{nodeId}:{direction}:{nodeClassMask}
opcua:{endpoint_hash}:browse-all:{nodeId}:{direction}:{nodeClassMask}
opcua:{endpoint_hash}:resolve:{path_hash}:{startingNodeId}
```

### CLI Tool
A standalone command-line tool for exploring OPC UA servers without writing code. Useful for debugging on-site:

```bash
# Browse the address space
php vendor/bin/opcua browse opc.tcp://192.168.1.10:4840 /Objects
 
# Read a node value
php vendor/bin/opcua read opc.tcp://192.168.1.10:4840 "ns=2;i=1001"
 
# Watch a node value in real time (polling)
php vendor/bin/opcua watch opc.tcp://192.168.1.10:4840 "ns=2;i=1001" --interval=500
 
# Discover endpoints
php vendor/bin/opcua endpoints opc.tcp://192.168.1.10:4840
```

### NodeSet2.xml Code Generator
Reads an OPC UA NodeSet2.xml file (companion specifications, PLC-specific information models) and generates typed PHP classes with NodeId constants, DTO classes, and pre-registered codecs — eliminating hardcoded numeric NodeIds from application code:

```bash
php vendor/bin/opcua generate:nodeset path/to/Opc.Ua.Di.NodeSet2.xml --output=src/OpcUa/
```

Output:
```php
// Auto-generated
class DiNodeIds {
    public const DeviceType = NodeId::numeric(1, 1001);
    public const DeviceType_Manufacturer = NodeId::numeric(1, 6005);
}
```

### OpenTelemetry Integration
Distributed tracing and metrics for production monitoring:

- **Spans** on every operation: `opcua.connect`, `opcua.read`, `opcua.write`, `opcua.browse`, `opcua.publish`
- **Attributes**: endpoint URL, node count, batch count, security policy, retry number
- **Metrics**: operation latency histogram, active session count, retry rate, batch size distribution
- Compatible with any OpenTelemetry-compliant backend (Jaeger, Zipkin, Prometheus, Datadog)


### Trust Store Management
Persistent management of trusted/rejected server certificates, instead of accepting every certificate on connect:

Required for certified industrial deployments.
```php
$trustStore = new FileTrustStore('/etc/opcua/trusted/', '/etc/opcua/rejected/');
$client->setTrustStore($trustStore);
// On first connect: server cert is validated against the trust store
// Unknown certs trigger a configurable callback (accept/reject/prompt)
```

### Trust Store Management CLIt
Verify that the server certificate has not been revoked before connecting. Required for certified industrial deployments.

### Query Services
`QueryFirst` / `QueryNext` — structured queries on the address space for servers where browse is too slow due to the size of the node tree.

### NodeManagement Services
`AddNodes`, `DeleteNodes`, `AddReferences` — for OPC UA servers that support dynamic address space modification at runtime.

### Triggering / ModifyMonitoredItems
- `SetTriggering` — configure a node that triggers sampling of other nodes
- `ModifyMonitoredItems` — change sampling interval or queue size on existing monitored items without recreating them


## API Redesign

The v3.\*.\* release will introduce **breaking changes** to improve the developer experience across the entire API surface.

### Fluent / Builder API
Introduce a fluent builder pattern wherever configuration is involved, replacing arrays and positional parameters with expressive, chainable method calls:

```php
// Before (v2.0)
$results = $client->readMulti([
    ['nodeId' => NodeId::numeric(2, 1001), 'attributeId' => 13],
    ['nodeId' => NodeId::numeric(2, 1002), 'attributeId' => 4],
]);

// After
$results = $client->readMulti()
    ->node(NodeId::numeric(2, 1001))->value()
    ->node(NodeId::numeric(2, 1002))->displayName()
    ->execute();
```

```php
// Before (v2.0)
$client->createMonitoredItems($subscriptionId, [
    ['nodeId' => $nodeId, 'samplingInterval' => 500.0, 'queueSize' => 10],
]);

// After 
$client->monitoredItems($subscriptionId)
    ->add($nodeId)->samplingInterval(500.0)->queueSize(10)
    ->execute();
```

```php
// Before (v2.0)
$results = $client->translateBrowsePaths([
    [
        'startingNodeId' => NodeId::numeric(0, 85),
        'relativePath' => [
            ['targetName' => new QualifiedName(0, 'Server')],
        ],
    ],
]);

$results = $client->translateBrowsePaths()
    ->from(NodeId::numeric(0, 85))->path('Server')
    ->execute();
```

### Full PHPDoc / Attribute Documentation
Add comprehensive PHPDoc blocks with `@param`, `@return`, `@throws`, and `@see` annotations to every public method, class, and interface. Add PHP 8.x Attributes where appropriate (e.g., `#[Deprecated]`, custom attributes for OPC UA metadata).

### Strict Return Types
Replace raw arrays with dedicated value objects or DTOs for all service responses:

```php
// Before (v2.0)
$result = $client->createSubscription();
$result['subscriptionId']; // int
$result['revisedPublishingInterval']; // float

// After
$subscription = $client->createSubscription();
$subscription->getId(); // int
$subscription->getPublishingInterval(); // float
```

### Uman readable & interactable functions

write isn't easiest to read or write, 
```php 
$status = $client->read(NodeId::numeric(0, 2259));
```

should become

```php 
$status = $client->read(NodeId::numeric(0, 2259)); // or
$status = $client->read('ns=0;i=2253'); // or
$status = $client->read('i=2253'); // or  ns 0 is assumed
```
all this variant is allowed

also a new specific exception should be designed



### Named Parameters Everywhere
Ensure all public method signatures are designed for PHP 8+ named parameters with clear, non-ambiguous parameter names.

## Planned Features

### Built-in ExtensionObject Codecs
Ship codecs for common OPC UA standard types so users don't have to implement them manually:
- `ServerStatusDataType` (i=864)
- `BuildInfo` (i=340)
- `EUInformation` (Engineering Units)
- `Range`
- `Argument` (method input/output argument descriptions)

### Browse Filters
- **NodeClassMask enum** — Done in v3.0.0. Replaced `int $nodeClassMask` with `NodeClass[] $nodeClasses`.

### Full ExtensionObject Type System
https://reference.opcfoundation.org/Core/Part6/v104/docs/5.2.7

Automatic discovery and deserialization of all server-defined structured types by reading the server's DataType dictionary (`OPC UA Part 6 §5.2.7`). This would eliminate the need for manually implementing codecs.

## Won't Do (by design)

### BuiltinTypes as Codecs
The `ExtensionObjectCodec` system is intentionally limited to `ExtensionObject`. OPC UA `BuiltinType` values (Int32, String, Double, etc.) are protocol-level primitives with a fixed binary encoding — making them pluggable would add complexity without benefit. See the [design rationale](doc/12-extension-object-codecs.md#design-note-why-builtintypes-are-not-codecs).

### Browse ResultMask
The OPC UA `ResultMask` controls which fields of `ReferenceDescription` are returned in browse results (ReferenceType, IsForward, NodeClass, BrowseName, DisplayName, TypeDefinition). Exposing this would require making most `ReferenceDescription` properties nullable, forcing null-checks on every consumer for a marginal bandwidth saving. The default (all fields) is what 99% of use cases need, and the few bytes saved per reference are irrelevant in typical PHP deployment scenarios (local/LAN connections). No mainstream OPC UA client library (node-opcua, opcua-asyncio) exposes this as a public parameter either.

### Session Manager Integration (here)
The session manager ([`gianfriaur/opcua-php-client-session-manager`](https://github.com/GianfriAur/opcua-php-client-session-manager)) is intentionally kept as a separate package and will not be merged into this library. The reasons:

- **Cross-platform compatibility.** This client works on Linux, macOS, and Windows. The session manager uses Unix domain sockets for IPC, which are not available on Windows. Integrating it would either break Windows support or leave dead code on that platform.
- **Zero-dependency philosophy.** This library requires only `ext-openssl`. The session manager depends on `react/event-loop` and `react/socket` — pulling those into the client would force every user to install ReactPHP, even if they don't need session persistence.
- **Architectural separation.** The client is a synchronous library. The session manager runs as a separate long-lived daemon process with an async event loop. These are fundamentally different execution models that don't belong in the same package.
- **The daemon is a separate process anyway.** Even if the code lived in the same package, you'd still need to start a separate `php bin/opcua-session-manager` process. It's not middleware you plug in — it's infrastructure you deploy.

The session manager is fully functional as a standalone package. See the [Ecosystem](#ecosystem) section for all related packages.

### Full OPC UA Server Implementation (here)
This library is a client-only implementation. Building a server requires a fundamentally different architecture (address space management, session handling, subscription engine, etc.).

---

Have a suggestion? Open an [issue](https://github.com/gianfriaur/opcua-php-client/issues) or check the [contributing guide](CONTRIBUTING.md).

