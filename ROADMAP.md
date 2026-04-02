# Roadmap

## v4.1.0 - 2026-03-X

### Query Services
`QueryFirst` / `QueryNext` — structured queries on the address space for servers where browse is too slow due to the size of the node tree.

### NodeManagement Services
`AddNodes`, `DeleteNodes`, `AddReferences`, `DeleteReferences` — for OPC UA servers that support dynamic address space modification at runtime. Requires adding a custom `HistoryReadRawModified`-style override in the test suite to support these services.

> **Note:** The CLI tool has been extracted to a separate package: [`php-opcua/opcua-cli`](https://github.com/php-opcua/opcua-cli). CLI-related roadmap items are tracked there.

---

## v5.0.0

- [ ] **PHPStan level 5** — static analysis with `phpstan/phpstan` as dev dependency, CI integration, and `composer analyse` script

---

## TODO — outside this repository

- [ ] Symfony integration like Laravel — `php-opcua/symfony-opcua` (right after v4.0.0 release)

---

## Won't Do (by design)

### BuiltinTypes as Codecs
The `ExtensionObjectCodec` system is intentionally limited to `ExtensionObject`. OPC UA `BuiltinType` values (Int32, String, Double, etc.) are protocol-level primitives with a fixed binary encoding — making them pluggable would add complexity without benefit. See the [design rationale](doc/12-extension-object-codecs.md#design-note-why-builtintypes-are-not-codecs).

### Browse ResultMask
The OPC UA `ResultMask` controls which fields of `ReferenceDescription` are returned in browse results (ReferenceType, IsForward, NodeClass, BrowseName, DisplayName, TypeDefinition). Exposing this would require making most `ReferenceDescription` properties nullable, forcing null-checks on every consumer for a marginal bandwidth saving. The default (all fields) is what 99% of use cases need, and the few bytes saved per reference are irrelevant in typical PHP deployment scenarios (local/LAN connections). No mainstream OPC UA client library (node-opcua, opcua-asyncio) exposes this as a public parameter either.

### Session Manager Integration (here)
The session manager ([`php-opcua/opcua-session-manager`](https://github.com/php-opcua/opcua-session-manager)) is intentionally kept as a separate package and will not be merged into this library. The reasons:

- **Cross-platform compatibility.** This client works on Linux, macOS, and Windows. The session manager uses Unix domain sockets for IPC, which are not available on Windows. Integrating it would either break Windows support or leave dead code on that platform.
- **Zero-dependency philosophy.** This library requires only `ext-openssl`. The session manager depends on `react/event-loop` and `react/socket` — pulling those into the client would force every user to install ReactPHP, even if they don't need session persistence.
- **Architectural separation.** The client is a synchronous library. The session manager runs as a separate long-lived daemon process with an async event loop. These are fundamentally different execution models that don't belong in the same package.
- **The daemon is a separate process anyway.** Even if the code lived in the same package, you'd still need to start a separate `php bin/opcua-session-manager` process. It's not middleware you plug in — it's infrastructure you deploy.

The session manager is fully functional as a standalone package. See the [Ecosystem](#ecosystem) section for all related packages.

### PSR-20 Clock
I don't see a valid use case for it in this library.

### RedisDriver / MemcachedDriver cache drivers
These would require `ext-redis` or `ext-memcached` (or `predis/predis`), breaking the zero-dependency philosophy. The cache system uses PSR-16 `CacheInterface`, so any Redis or Memcached adapter that implements PSR-16 works out of the box — including `illuminate/cache` (Laravel), `symfony/cache`, and `cache/redis-adapter`. There is no reason to bundle drivers that would force all users to install extensions they may not need.

### OpenTelemetry Integration (here)
Telemetry (distributed tracing, metrics) belongs in the session manager ([`php-opcua/opcua-session-manager`](https://github.com/php-opcua/opcua-session-manager)), not in this library. The reasons:

- **Short-lived connections make spans meaningless.** This client is synchronous — each PHP request opens a connection, performs a few operations, and disconnects. An OpenTelemetry span wrapping `connect → read → disconnect` in a 50ms request adds no insight you don't already get from APM tools already instrumenting your HTTP layer (Laravel Telescope, Datadog APM, New Relic, etc.).
- **Telemetry shines on long-lived processes.** The session manager runs as a persistent daemon, maintaining connections across hundreds of PHP requests. That's where spans like `opcua.publish`, retry histograms, active session counts, and subscription latency distributions actually provide value — correlating OPC UA operations across time, not within a single request.

Telemetry support will be implemented in `php-opcua/opcua-session-manager` where persistent connections make it meaningful.

### Full OPC UA Server Implementation (here)
This library is a client-only implementation. Building a server requires a fundamentally different architecture (address space management, session handling, subscription engine, etc.).

---

Have a suggestion? Open an [issue](https://github.com/php-opcua/opcua-client/issues) or check the [contributing guide](CONTRIBUTING.md).

