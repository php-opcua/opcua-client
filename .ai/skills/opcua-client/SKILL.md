---
name: opcua-client
description: Connect a PHP application to an OPC UA server (industrial automation protocol) using php-opcua/opcua-client v4.5.0 — read, write, browse, call methods, subscribe to data changes, query history, manage trust, and extend with custom modules. Use this skill whenever a task involves OPC UA, opc.tcp://, opc.https://, PLC / SCADA / sensor / historian / DCS integration, Part 6 / Part 4 OPC UA service sets, or the php-opcua ecosystem.
license: MIT
compatibility: Requires PHP >= 8.2 and ext-openssl. Pure PHP — no C extensions, no FFI, no HTTP gateway.
metadata:
  package: php-opcua/opcua-client
  version: v4.5.0
  ecosystem: php-opcua
---

# php-opcua/opcua-client — v4.5.0 skill

A pure-PHP OPC UA client. Speaks the binary protocol over TCP (and `opc.https://` via the optional [`opcua-client-ext-transport-https`](https://github.com/php-opcua/opcua-client-ext-transport-https) extension). Pluggable transport, security, modular service architecture.

## When to use this skill

Activate when any of these apply:

- The task mentions OPC UA, PLC, SCADA, historian, DCS, sensor data, industrial automation, IIoT, building automation, robotics, machine tools, or MTConnect bridges
- An endpoint URL starts with `opc.tcp://`, `opc.https://`, or `opc.wss://`
- A NodeId is mentioned in the form `i=2259`, `ns=2;s=Temperature`, `ns=3;g=<guid>`, or `ns=4;b=<base64>`
- Service set names appear: `Read`, `Write`, `Browse`, `TranslateBrowsePathsToNodeIds`, `Call`, `CreateSubscription`, `CreateMonitoredItems`, `Publish`, `HistoryRead`, `HistoryUpdate`, `AddNodes`, `DeleteNodes`, `AddReferences`, `DeleteReferences`, `GetEndpoints`, etc.
- The user is using or extending any `php-opcua/*` package

Do NOT activate for: generic PHP work, web frameworks, databases, or anything unrelated to industrial protocols.

## The 60-second mental model

```
ClientBuilder (config) ─► connect() ─► Client (proxy)
                                        │
                                        ▼
                              ClientKernelInterface
                                        │
                                ┌───────┼───────┐
                                ▼               ▼
                          ServiceModules    ClientTransportInterface
                          (10 built-in)     (TcpTransport default)
                          ReadWrite, Browse,
                          Subscription, History,
                          NodeManagement, Aggregate,
                          TranslateBrowsePath,
                          ServerInfo, TypeDiscovery,
                          FileTransfer
```

Three things to know:

1. **One entry point**: `ClientBuilder::create()->connect($endpointUrl)` returns a `Client` (which is `OpcUaClientInterface`). Application code calls service methods on the client.
2. **Service methods accept `NodeId|string`**: `'i=2259'`, `'ns=2;s=Temp'`, or `NodeId::numeric(2, 1001)` all work. **Prefer strings** in user-facing code — they are more readable.
3. **Result DTOs are `public readonly`**: access `$result->subscriptionId` (NOT `$result->getSubscriptionId()` or `$result['subscriptionId']`). Old getter methods are `@deprecated` but still function for back-compat.

## Quick start (90% of use cases fit this shape)

```php
use PhpOpcua\Client\ClientBuilder;

$client = ClientBuilder::create()
    ->connect('opc.tcp://localhost:4840');

// Single read — auto-detect attribute (Value), unwrap value
$temperature = $client->read('ns=2;s=Sensors/Temp')->getValue();

// Multi-read — fluent builder
$results = $client->readMulti()
    ->node('i=2259')->value()                     // Server.ServerStatus.State (Int32)
    ->node('ns=2;s=Sensors/Temp')->value()
    ->node('ns=2;s=Sensors/Temp')->displayName()
    ->execute();

// Write — auto-detect type via read-before-write
$client->write('ns=2;s=Setpoint', 42.5);

// Write — explicit type (faster, no round-trip)
use PhpOpcua\Client\Types\BuiltinType;
$client->write('ns=2;s=Setpoint', 42.5, BuiltinType::Double);

// Browse
foreach ($client->browse('i=85') as $ref) {
    echo "{$ref->displayName} ({$ref->nodeId})\n";
}

// Subscribe to a data change
use PhpOpcua\Client\Module\Subscription\DataChangeNotification;

$sub = $client->createSubscription(publishingInterval: 500.0);
$client->createMonitoredItems($sub->subscriptionId)
    ->add('ns=2;s=Sensors/Temp')->samplingInterval(500.0)
    ->execute();

// PublishResult::$notifications is array<int, DataChangeNotification|EventNotification> — guard by type.
foreach ($client->publish()->notifications as $notif) {
    if ($notif instanceof DataChangeNotification) {
        echo $notif->dataValue->getValue() . "\n";   // DataChangeNotification: ->clientHandle, ->dataValue
    }
    // EventNotification carries ->clientHandle and ->eventFields (Variant[]) instead — no ->dataValue.
}

$client->disconnect();
```

## When to load deeper references

| If the task involves... | Read |
| --- | --- |
| Designing the connection (security policy, auth, certificates, transport, retry, timeouts, trust store) | [`references/CONNECTION.md`](references/CONNECTION.md) |
| Picking the right service method (read, write, browse, call, subscribe, history, node management, aggregates, file transfer) | [`references/OPERATIONS.md`](references/OPERATIONS.md) |
| Understanding why this code is structured the way it is (builder, kernel, modules, traits, registry) | [`references/ARCHITECTURE.md`](references/ARCHITECTURE.md) |
| Writing or replacing a `ServiceModule` (custom service set, override built-in behaviour) | [`references/MODULES.md`](references/MODULES.md) |
| Working with `NodeId`, `Variant`, `DataValue`, `ExtensionObject`, custom OPC UA types | [`references/TYPES.md`](references/TYPES.md) |
| Hooking PSR-14 listeners for observability / audit / metrics | [`references/EVENTS.md`](references/EVENTS.md) |
| Writing tests with `MockClient` | [`references/TESTING.md`](references/TESTING.md) |
| Debugging an unfamiliar error / behaving unexpectedly | [`references/PITFALLS.md`](references/PITFALLS.md) |
| Looking for a complete working example for a specific task | [`assets/recipes.md`](assets/recipes.md) |

## Core API surface (must-know)

`OpcUaClientInterface` (`src/OpcUaClientInterface.php`) is the public contract. Everything `Client` exposes lives here or is reachable via `$client->__call()` for custom modules.

| Group | Methods |
| --- | --- |
| Lifecycle | `connect()` (on builder), `disconnect()`, `reconnect()`, `isConnected()`, `getConnectionState(): ConnectionState` |
| Read | `read(NodeId\|string $id, int $attributeId = AttributeId::Value, bool $refresh = false): DataValue` (`AttributeId` is a class of int constants; `AttributeId::Value = 13`), `readMulti()` (builder) |
| Write | `write(NodeId\|string $id, mixed $value, ?BuiltinType $type = null): int`, `writeMulti(?array $writeItems = null): array\|WriteMultiBuilder` (array shape or fluent builder) |
| Browse | `browse(NodeId\|string $id, BrowseDirection $dir = Forward, ...): ReferenceDescription[]`, `browseAll()` (auto-continuation), `browseRecursive(NodeId\|string $id, BrowseDirection $dir = Forward, ?int $maxDepth = null, ...): BrowseNode[]`, `getEndpoints()`, `resolveNodeId(string $path, NodeId\|string\|null $startingNodeId = null, bool $useCache = true): NodeId` (throws `ServiceException` if the path cannot be resolved) |
| Translate browse paths | `translateBrowsePaths(?array $browsePaths = null): array\|BrowsePathsBuilder` (returns `BrowsePathResult[]`, or a `BrowsePathsBuilder` when called with no args); for a single slash-separated path like `"Objects/MyFolder/MyNode"` use `resolveNodeId(string $path, NodeId\|string\|null $startingNodeId = null, bool $useCache = true): NodeId` |
| Call methods | `call(NodeId\|string $objectId, NodeId\|string $methodId, array $inputArguments = []): CallResult` (`$inputArguments` is `Variant[]`) |
| Subscriptions | `createSubscription(...): SubscriptionResult`, `createMonitoredItems()` (builder), `publish(): PublishResult`, `modifyMonitoredItems()`, `deleteMonitoredItems()`, `deleteSubscription(int $subscriptionId): int`, `transferSubscriptions()`, `republish()` |
| History — Read | `historyReadRaw(NodeId\|string, DateTimeImmutable $start, DateTimeImmutable $end, ...): DataValue[]`, `historyReadProcessed()`, `historyReadAtTime()` |
| History — Update | `historyInsertData()`, `historyReplaceData()`, `historyUpdateData()`, `historyDeleteRawModified()`, `historyDeleteAtTime()`, `historyInsertEvent()`, `historyReplaceEvent()`, `historyUpdateEvent()`, `historyDeleteEvent()` |
| Aggregate | `aggregate(DataValue[], $start, $end, $intervalMs, AggregateFunction, ?AggregateOptions)`, `historyAggregate(NodeId\|string, ...)` |
| Node management | `addNodes()`, `deleteNodes()`, `addReferences()`, `deleteReferences()` — return `AddNodesResult[]` / `int[]` |
| File transfer | `FileTransferModule` (OPC UA Part 5): `openFile(NodeId\|string, OpenFileMode\|int): int`, `readFile()`, `writeFile()`, `closeFile()`, `getFilePosition()`, `setFilePosition()`; FileDirectoryType helpers `createDirectory()`, `createFileInDirectory(): CreateFileResult`, `deleteFileSystemObject()`, `moveOrCopyFileSystemObject(): NodeId` |
| Server info | `getServerBuildInfo(): BuildInfo`, `getServerProductName()`, `getServerSoftwareVersion()`, `getServerBuildNumber()`, `getServerBuildDate()` |
| Type discovery | `discoverDataTypes(?int $namespaceIndex = null, bool $useCache = true): int` — discovers server-defined structured types, registers dynamic codecs, and returns the count discovered (caching entries via the `DiscoveredType` cache) |
| Introspection | `hasMethod(string): bool`, `hasModule(class-string): bool`, `getRegisteredMethods(): string[]`, `getLoadedModules(): class-string[]` |

Every multi-operation method (`readMulti`, `writeMulti`, `createMonitoredItems`, `translateBrowsePaths`) accepts both an **array shape** and a **fluent builder** (chainable) form. The builder is friendlier for code generation; both work identically.

## What v4.5.0 added on top of v4.4

A security-hardening release, interoperability-tested against UA-.NETStandard in None / Sign / SignAndEncrypt, RSA (Basic256Sha256) and ECC (NIST P-256/P-384, Brainpool P256r1/P384r1).

- **Security hardening** — `CreateSessionResponse.serverSignature` is now verified (Part 4 §5.6.2 proof of possession), the ECDH ephemeral-key signature is verified for ECC profiles, incoming secure-channel headers (`channelId`/`tokenId`/strictly-increasing sequence number, anti-replay) are validated in `SecureChannel::processMessage`, and trust-store decisions compare the stored DER via SHA-256 instead of relying on SHA-1 alone. Failures throw `ServiceException` / `SecurityException` / `UntrustedCertificateException`.
- **ApplicationUri binding** — on secure connections the server certificate's SAN `ApplicationUri` must match the endpoint's `ApplicationDescription`. Configurable via `ClientBuilder::verifyApplicationUri(bool $enabled = true)` (default `true`). `EndpointDescription` gains a nullable `applicationUri` property.
- **PHPStan level 9 on `src/`** — run via `composer phpstan`, no baseline and no `@phpstan-ignore` comments (`treatPhpDocTypesAsCertain: false`).
- **Value objects replace internal associative arrays** — `Module\NodeManagement\AddNodeItem`, `Module\TranslateBrowsePath\BrowsePath` / `RelativePathElement`, `Module\TypeDiscovery\DiscoveredType` (wire-serializable cached discovery entries), plus the new `final readonly` notification objects `Module\Subscription\DataChangeNotification` / `EventNotification`. The public `addNodes()` / `translateBrowsePaths()` still accept arrays and convert internally.
- **BREAKING — `PublishResult::$notifications` now holds objects** — `array<int, DataChangeNotification|EventNotification>` instead of `['type' => …, 'clientHandle' => …]` arrays. Discriminate with `instanceof`, access via properties (`$n->clientHandle`, `$n->dataValue`, `$n->eventFields`).
- **BREAKING — the wire DTOs are now `final`** — `NodeId`, `QualifiedName`, `LocalizedText`, `DataValue`, `Variant`, `ExtensionObject`, `EndpointDescription`, `ReferenceDescription`, `BrowseNode`, `StructureDefinition`, `StructureField`, `UserTokenPolicy`, and module result DTOs (`PublishResult`, `CallResult`, `BrowseResultSet`, …). `ClientBuilder::__construct` is `final` to make `ClientBuilder::create()` (`new static`) safe.
- **`Variant::asInt()` / `Variant::asString()`** — typed accessors with validated coercion; throw `EncodingException` when the value cannot be coerced.
- **`addNodes()` items: `value` must be a `?Variant`** — already required at encode time; the PHPDoc shape is now corrected.

See [`references/ARCHITECTURE.md`](references/ARCHITECTURE.md) and [`references/CONNECTION.md`](references/CONNECTION.md) for details.

## Idiomatic patterns AI agents should follow

1. **Use string NodeIds in application code**. `$client->read('ns=2;s=Temp')` reads better than `NodeId::string(2, 'Temp')`. Reserve `NodeId` objects for places that take/return them explicitly.

2. **Prefer the fluent multi-builders for >1 operation**. They're more readable, support per-node attribute selection (`->value()` / `->displayName()`), and are easier to extend. Don't generate the array form unless the user explicitly asks.

3. **Access result properties as `public readonly`**, not via getters. `$sub->subscriptionId`, `$dv->statusCode`, `$dv->sourceTimestamp`. Use `$dv->getValue()` ONLY for unwrapping the underlying variant value (it auto-decodes registered ExtensionObjects).

4. **Don't pass `BuiltinType` when the user doesn't supply one** — the client auto-detects via read-before-write (cached with PSR-16). When they DO supply it, use `BuiltinType::Int32` etc. directly.

5. **Always `disconnect()` in `finally`** unless using `opcua-session-manager` (which keeps sessions alive across requests).

6. **Logs go through PSR-3**: `$builder->setLogger($psr3Logger)`. Don't `error_log()` or `echo` debug info.

7. **Events go through PSR-14**: `$builder->setEventDispatcher($psr14Dispatcher)`. 56 event classes available; default `NullEventDispatcher` (the dispatcher itself, not an event) for zero overhead. See [`references/EVENTS.md`](references/EVENTS.md).

8. **Tests use `MockClient`**, NOT real TCP. `use PhpOpcua\Client\Testing\MockClient;`. See [`references/TESTING.md`](references/TESTING.md).

9. **Never `unserialize()` data from cache or IPC** — the wire serialization pipeline (`Wire\WireTypeRegistry`) uses JSON gated by a `__t` allowlist. Use `WireCacheCodec` (default) for PSR-16 cache values.

10. **Custom service modules extend `ServiceModule`**, register their methods via `register()`, return DTOs implementing `WireSerializable`. See [`references/MODULES.md`](references/MODULES.md).

## Common pitfalls (read before generating code)

Don't write code that:

- Uses `$result->getNodeId()` style getters when a public readonly property exists — **use property access** unless the user explicitly wants the deprecated getter.
- Creates a new `ClientBuilder` per `read()` call — **one client per session**.
- Builds NodeIds with concatenation: `'ns=' . $ns . ';i=' . $i` — use `NodeId::numeric($ns, $i)` or just pass a literal string template.
- Calls `disconnect()` from `__destruct()` of a wrapping class — the client already handles cleanup.
- Uses `array_filter` on returned DTOs without preserving keys — `BrowseResultSet::$references` is an array, mutations may break ordering.
- Writes blocking loops calling `publish()` in tight loop without a sleep — use the subscription's `publishingInterval` instead.
- Ignores `$dataValue->statusCode` — a "Good" read can still hold non-zero status (e.g. `Uncertain*`, `GoodLocalOverride`) that affects business logic.

Full catalog in [`references/PITFALLS.md`](references/PITFALLS.md).

## Related packages in the php-opcua ecosystem

- **`opcua-session-manager`** — ReactPHP daemon that keeps OPC UA sessions alive across PHP requests via local IPC. Drop-in `ManagedClient` for `OpcUaClientInterface`.
- **`opcua-client-nodeset`** — Pre-generated PHP types from 51 OPC Foundation companion specifications (Robotics, MachineTool, BACnet, DI, MTConnect, etc.). One `->loadGeneratedTypes(new RoboticsRegistrar())` call and every read on a structured node returns a typed PHP object.
- **`laravel-opcua`** / **`symfony-opcua`** — Framework integrations (facade / autowiring / config / Artisan / console).
- **`opcua-cli`** — Terminal companion (browse, read, write, watch, trust, dump:nodeset, generate:nodeset).
- **`opcua-client-ext-reverse-connect`** — Listener for OPC UA Reverse Connect (Part 6 §7.1.2.3).
- **`opcua-client-ext-transport-https`** — `opc.https://` wire transport (Part 6 §7.4).
- **`uanetstandard-test-suite`** / **`extra-test-suite`** — Docker-based test servers.

When the user is on Laravel/Symfony, prefer the framework integration over plain `ClientBuilder`. When they need cross-request session persistence, mention `opcua-session-manager`.
