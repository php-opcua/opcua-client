# Roadmap

> **A note on versioning:** We're aware of the rapid major releases in a short time frame. This library is under active, full-time development right now — the goal is to reach a production-stable state as quickly as possible. Breaking changes are being bundled and shipped deliberately to avoid dragging them out across many minor releases. Once the API surface settles, major version bumps will become rare. Thanks for your patience.

## v4.0.0

### Features
- [ ] Cache for metadata `read()` (DisplayName, BrowseName, DataType, NodeClass, Description), **`not Value`**
- [ ] CLI Tool
- [ ] xml Code Generator
- [ ] `TBD` Telemetry
- [ ] Server Trust Management (also for cli)
- [ ] NodeManagement Services
- [ ] Triggering / ModifyMonitoredItems
- [ ] Symfony integration like Laravel ( `gianfriaur/opcua-symfony-client` )

### Refactoring
- [ ] Protocol service base class — extract the repeated encode/decode pattern (security check, token/sequence/requestId header, wrapInMessage vs buildMessage) into a shared base class or trait. Currently duplicated identically across all 15 Protocol service classes.
- [ ] Service NodeId constants — replace hard-coded OPC UA service type NodeIds (461, 462, 467, 631, 673, 635, 712, etc.) with named constants in a dedicated `ServiceTypeId` class for readability.
- [ ] Diagnostic info skip helper — extract the repeated `readInt32` + loop + `skipDiagnosticInfo` pattern into a single `skipDiagnosticInfoArray()` method. Currently duplicated in 8+ Protocol service decode methods.
- [ ] Response metadata helper — extract the repeated 5-line response header reading boilerplate (token, sequence, requestId, typeNodeId, readResponseHeader) into a single `readResponseMetadata()` method in SessionService.
- [ ] Break down long methods — split `discoverServerCertificate()` (72 lines), `openSecureChannelWithSecurity()` (68 lines), and `createAndActivateSession()` (56 lines) into smaller focused methods.
- [ ] ExtensionObject class — replace the raw `array|object` return from `BinaryDecoder::readExtensionObject()` with a typed `ExtensionObject` DTO for type safety.


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

### RedisDriver / MemcachedDriver cache drivers
These would require `ext-redis` or `ext-memcached` (or `predis/predis`), breaking the zero-dependency philosophy. The cache system uses PSR-16 `CacheInterface`, so any Redis or Memcached adapter that implements PSR-16 works out of the box — including `illuminate/cache` (Laravel), `symfony/cache`, and `cache/redis-adapter`. There is no reason to bundle drivers that would force all users to install extensions they may not need.

### Full OPC UA Server Implementation (here)
This library is a client-only implementation. Building a server requires a fundamentally different architecture (address space management, session handling, subscription engine, etc.).

---

Have a suggestion? Open an [issue](https://github.com/gianfriaur/opcua-php-client/issues) or check the [contributing guide](CONTRIBUTING.md).

