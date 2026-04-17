# Roadmap

## v4.2.0 - 2026-04-X

### ~~NodeManagement Services~~ — Done
`AddNodes`, `DeleteNodes`, `AddReferences`, `DeleteReferences` — implemented. See [CHANGELOG.md](CHANGELOG.md).

> **Note:** The CLI tool has been extracted to a separate package: [`php-opcua/opcua-cli`](https://github.com/php-opcua/opcua-cli). CLI-related roadmap items are tracked there.

---

## v5.0.0

- [ ] **PHPStan level 5** — static analysis with `phpstan/phpstan` as dev dependency, CI integration, and `composer analyse` script

- [x] **Kernel + ServiceModule architecture** *(shipped in v4.2.0)* — the trait-based Client was replaced with a modular system where each OPC UA service set is a self-contained `ServiceModule` class. The public API is fully backward compatible — no breaking changes. See [CHANGELOG.md](CHANGELOG.md) and [doc/17-module-system.md](doc/17-module-system.md).

  **Why:** Today, adding a new service set (e.g., NodeManagement) requires touching 8 files: the module itself, Client.php (property + use trait), `initServices()`, `resetConnectionState()`, OpcUaClientInterface, and MockClient. Most of these changes are mechanical boilerplate. The trait-based service modules are already independent of each other — they just share a common infrastructure layer (transport, session, retry, encoding). This refactor makes that separation explicit.

  **How it works:**

  - **`ClientKernel`** — extracts the shared infrastructure that every service module needs into a public API: `executeWithRetry()`, `ensureConnected()`, `nextRequestId()`, `send()`, `receive()`, `unwrapResponse()`, `createDecoder()`, `resolveNodeId()`, `getAuthToken()`, `dispatch()`, `logContext()`. The kernel traits (Connection, Handshake, SecureChannel, Session, EventDispatch, Cache, Batching, TrustStore) remain internal to the kernel.

  - **`ServiceModule`** — abstract base class. Each module receives the kernel and a reference to the Client. It implements `register()` to inject its methods onto the Client via `$this->client->registerMethod('read', $this->read(...))`, `boot(SessionService)` to create its protocol service, and `reset()` to clean up on disconnect. One class = one OPC UA service set, fully self-contained.

  - **Method injection** — modules register their public methods directly on the Client during `register()`. The Client dispatches calls through `__call()` to the registered handlers. If two modules try to register the same method name, a `ModuleConflictException` is thrown at boot time — use `replaceModule()` to intentionally swap a module.

  - **`requires()` dependency declaration** — a module can declare which other modules it depends on (e.g., `ServerInfoModule` requires `ReadWriteModule`). The `ModuleRegistry` resolves the dependency graph with topological sort and registers modules in the correct order. Missing dependencies throw a clear exception at `connect()` time. Cross-module calls go through the Client: `$this->client->read()` — same syntax as today's `$this->read()` in traits.

  - **`Client`** — keeps **all built-in methods as concrete, fully typed one-liners** that delegate to the registered handler: `return ($this->methodHandlers['read'])($nodeId, $attributeId, $refresh)`. PHPStan, IDE autocomplete, and refactoring all work. `__call()` is used **only for custom third-party module methods** not in the interface.

  - **`OpcUaClientInterface`** — stays **complete**. All built-in service methods (read, write, browse, addNodes, etc.) remain as typed signatures. Two new methods added: `hasMethod(string): bool` and `hasModule(string): bool` for module introspection. Zero breaking change for consumers type-hinting the interface.

  - **`MockClient`** — implements the full `OpcUaClientInterface` and keeps its existing `onRead()`/`onWrite()`/`onBrowse()` handler registration system unchanged. Adds only `hasMethod()` and `hasModule()`.

  - **No `removeModule()`** — only `addModule()` and `replaceModule()`. Built-in modules are always present. This avoids the complexity of validating broken dependency chains at runtime.

  **What changes for adding a new built-in service set:**
  - Today: 8 files (DTO, protocol service, trait, Client.php property + use, initServices, resetConnectionState, interface, MockClient).
  - After: 3–4 files (DTO, protocol service, module class, interface signatures + Client one-liners). No more initServices/resetConnectionState/trait wiring.

  **What changes for external developers:**
  - `ClientBuilder::replaceModule(ReadWriteModule::class, MyCustomReadWrite::class)` — swap any built-in module with a custom implementation. All other modules that call `$this->client->read()` automatically use the replacement.
  - `ClientBuilder::addModule(new MyQueryServiceModule())` — add entirely new service sets without forking. Custom methods are accessible via `$client->queryFirst(...)` through `__call()`.

  **Migration for consumers:**
  - `$client->read()`, `$client->browse()`, etc. — **unchanged**, works identically.
  - Type hints: `OpcUaClientInterface` — **unchanged**, still works with all built-in methods.
  - MockClient: **unchanged** API.

  **DTO co-location** — module-specific DTOs move from `src/Types/` into their module's namespace. Types that are used by a single module live alongside it; types shared across multiple modules (NodeId, DataValue, Variant, StatusCode, QualifiedName, etc.) remain in `src/Types/`. Examples:

  | Class | Today | After |
  |-------|-------|-------|
  | `AddNodesResult` | `Types\AddNodesResult` | `Module\NodeManagement\AddNodesResult` |
  | `NodeManagementService` | `Protocol\NodeManagementService` | `Module\NodeManagement\NodeManagementService` |
  | `CallResult` | `Types\CallResult` | `Module\ReadWrite\CallResult` |
  | `ReadService` | `Protocol\ReadService` | `Module\ReadWrite\ReadService` |
  | `WriteService` | `Protocol\WriteService` | `Module\ReadWrite\WriteService` |
  | `CallService` | `Protocol\CallService` | `Module\ReadWrite\CallService` |
  | `SubscriptionResult` | `Types\SubscriptionResult` | `Module\Subscription\SubscriptionResult` |
  | `MonitoredItemResult` | `Types\MonitoredItemResult` | `Module\Subscription\MonitoredItemResult` |
  | `PublishResult` | `Types\PublishResult` | `Module\Subscription\PublishResult` |
  | `TransferResult` | `Types\TransferResult` | `Module\Subscription\TransferResult` |
  | `SubscriptionService` | `Protocol\SubscriptionService` | `Module\Subscription\SubscriptionService` |
  | `MonitoredItemService` | `Protocol\MonitoredItemService` | `Module\Subscription\MonitoredItemService` |
  | `PublishService` | `Protocol\PublishService` | `Module\Subscription\PublishService` |
  | `BrowseResultSet` | `Types\BrowseResultSet` | `Module\Browse\BrowseResultSet` |
  | `BrowseService` | `Protocol\BrowseService` | `Module\Browse\BrowseService` |
  | `GetEndpointsService` | `Protocol\GetEndpointsService` | `Module\Browse\GetEndpointsService` |
  | `BrowsePathResult` | `Types\BrowsePathResult` | `Module\TranslateBrowsePath\BrowsePathResult` |
  | `TranslateBrowsePathService` | `Protocol\TranslateBrowsePathService` | `Module\TranslateBrowsePath\TranslateBrowsePathService` |
  | `HistoryReadService` | `Protocol\HistoryReadService` | `Module\History\HistoryReadService` |
  | `BuildInfo` | `Types\BuildInfo` | `Module\ServerInfo\BuildInfo` |
  | `NodeId`, `DataValue`, `Variant`, ... | `Types\*` | `Types\*` (unchanged — shared) |
  | `AbstractProtocolService` | `Protocol\AbstractProtocolService` | `Protocol\AbstractProtocolService` (unchanged — shared base) |
  | `SessionService` | `Protocol\SessionService` | `Kernel\SessionService` (unchanged — kernel) |
  | `ServiceTypeId` | `Protocol\ServiceTypeId` | `Protocol\ServiceTypeId` (unchanged — shared constants) |

  Each module becomes a fully self-contained package:
  ```
  src/Module/NodeManagement/
  ├── NodeManagementModule.php      ← module class (register, boot, reset, methods)
  ├── NodeManagementService.php     ← protocol encoding/decoding
  └── AddNodesResult.php            ← module-specific DTO
  ```

---

## v5.1.0

- [ ] **IDE helper stub generator** — a `composer generate-ide-helper` command (or `vendor/bin/opcua-ide-helper`) that auto-generates `_ide_helper_opcua.php` from the registered modules via reflection. The stub file contains PHPDoc `@method` annotations for the `Client` class, covering both built-in and custom module methods. The file is not loaded at runtime — it is only consumed by the IDE for autocomplete and static analysis. Replaces the hardcoded `@method` annotations on the `Client` class introduced in v5.0.0, keeping them always in sync with the actual module code. Custom modules are included when the generator is re-run after adding them to the builder. The generated file should be added to `.gitignore`.

---

## v6.0.0

### Query Services

`QueryFirst` / `QueryNext` (OPC UA Part 4, Section 5.9) — structured queries on the server's address space, conceptually similar to a SQL `SELECT` with `WHERE` filters.

**What it does:** Instead of browsing the address space node by node and filtering client-side, Query Services let the client describe a filter (node class, type definition, attribute constraints) and the server returns only the matching nodes. `QueryFirst` executes the query and returns the first page of results; `QueryNext` retrieves subsequent pages using a continuation point — the same pagination pattern as `Browse`/`BrowseNext`.

**Example use case:** "Find all Variable nodes under `ns=2;s=Plant1` whose DataType is Double and DisplayName contains 'Temperature'." With Browse, this requires a recursive walk of potentially thousands of nodes and client-side filtering. With QueryFirst, the server does the work and returns only the matches.

**When it matters:** Large address spaces with tens of thousands of nodes (typical in big industrial plants with hundreds of PLCs) where `browseRecursive` would be too slow or memory-intensive.

**Why deferred:** Very few OPC UA servers implement Query Services in practice — most return `BadServiceUnsupported`. Even the OPC Foundation's UA-.NETStandard reference implementation has limited support. The `browseRecursive()` + client-side filtering approach covers the vast majority of real-world use cases. This will be implemented when server adoption makes it practically useful.

---

## ECC 1.05.4 Compliance

The ECC security policies (ECC_nistP256, ECC_nistP384, ECC_brainpoolP256r1, ECC_brainpoolP384r1) are currently implemented following the OPC UA 1.05.3 specification and tested against [UA-.NETStandard](https://github.com/OPCFoundation/UA-.NETStandard). The implementation works correctly against that reference stack because both sides share the same 1.05.3 behavior.

The 1.05.4 revision (and the consolidated 1.05.06 text) introduced three ECC-specific protocol changes that are **not yet implemented** in this library. They are documented here in detail so the scope is clear.

### 1. LegacySequenceNumbers = FALSE

**Spec reference:** Table 56, SecurityPolicy parameter `LegacySequenceNumbers`.

**What changed:** ECC policies define `LegacySequenceNumbers = FALSE`, which changes the sequence number lifecycle:
- **RSA (TRUE):** starts from a random value < 1024, wraps before `UInt32.MaxValue - 1024` (4,294,966,271), then restarts from a value < 1024.
- **ECC (FALSE):** starts from 0, increments monotonically, wraps at `UInt32.MaxValue` (4,294,967,295), then restarts from 0.

**Current behavior:** The implementation starts from 1 for all policies (`SecureChannel::$sequenceNumber = 1`) and has no wrap logic. This works today because UA-.NETStandard is lenient about the starting value and typical test sessions never approach the wrap threshold.

**Impact:** Only affects extremely long-lived connections (billions of messages). On short/medium sessions the behavior is indistinguishable.

**Fix:** Initialize `$sequenceNumber = 0` when `$policy->isEcc()`, and implement wrap logic differentiating RSA/ECC policies.

### 2. Per-message IV via TokenId + LastSequenceNumber XOR

**Spec reference:** Table 68, `SymmetricEncryptionInitializationVector` for ECC policies.

**What changed:** For ECC policies, the AES-CBC initialization vector must be unique for every message. Instead of using the static base IV derived from HKDF, the spec requires XORing the first 8 bytes of the base IV with two values:
- Bytes 0-3: XOR with `TokenId` (UInt32, little-endian)
- Bytes 4-7: XOR with `SequenceNumber` of the **previous** MessageChunk (UInt32, little-endian; 0 if this is the first chunk on the SecureChannel)

For RSA policies, the base IV is used as-is (unchanged behavior).

**Current behavior:** The implementation uses the static base IV (`$this->clientIv` / `$this->serverIv`) for all messages, both RSA and ECC. This works today because UA-.NETStandard (in the version used for testing) implements the same static-IV behavior.

**Impact:** This is a **cryptographic weakness**, not a protocol error — both sides use the same static IV, so encryption/decryption succeeds. However, reusing the same IV with the same key across multiple AES-CBC messages allows an attacker observing ciphertext to detect when two plaintext blocks at the same position are identical. In practice, OPC UA message payloads vary enough that exploitation is unlikely, but it violates the spec's security intent.

**Fix:** Track `lastSequenceNumber` per-direction (client send, server receive). Before each `symmetricEncrypt` / `symmetricDecrypt`, compute the per-message IV:

```php
$msgIv = $baseIv;
$msgIv[0] = $msgIv[0] ^ chr($tokenId & 0xFF);
$msgIv[1] = $msgIv[1] ^ chr(($tokenId >> 8) & 0xFF);
$msgIv[2] = $msgIv[2] ^ chr(($tokenId >> 16) & 0xFF);
$msgIv[3] = $msgIv[3] ^ chr(($tokenId >> 24) & 0xFF);
$msgIv[4] = $msgIv[4] ^ chr($lastSeqNum & 0xFF);
$msgIv[5] = $msgIv[5] ^ chr(($lastSeqNum >> 8) & 0xFF);
$msgIv[6] = $msgIv[6] ^ chr(($lastSeqNum >> 16) & 0xFF);
$msgIv[7] = $msgIv[7] ^ chr(($lastSeqNum >> 24) & 0xFF);
```

### 3. HKDF salt L value — already conformant

**Spec reference:** Section 6.8.1, key derivation for ECC.

The HKDF salt is defined as: `L | UTF8(label) | ClientNonce | ServerNonce`, where `L` is the derived key material length encoded as a **16-bit little-endian** unsigned integer.

**Current behavior:** `pack('v', $saltKeyLen)` — PHP's `'v'` format is unsigned 16-bit little-endian. **Already conformant.**

### 4. ReceiverCertificateThumbprint — already conformant

**Spec reference:** Table 57, `ReceiverCertificateThumbprint`.

For ECC policies, the thumbprint is always 20 bytes (SHA-1 of the receiver certificate), even when the OPN message is sign-only (not encrypted). For RSA, it can be empty when no encryption is used.

**Current behavior:** `$this->certManager->getThumbprint($this->serverCertDer)` is called for all security-active policies, returning 20 bytes of SHA-1. **Already conformant.**

### Summary

| Requirement | Status | Risk |
|---|---|---|
| ReceiverCertificateThumbprint always 20 bytes | Conformant | None |
| HKDF L as uint16 little-endian | Conformant | None |
| LegacySequenceNumbers = FALSE | Not implemented | Low (long sessions only) |
| Per-message IV via XOR | Not implemented | Medium (cryptographic weakness) |

### Why it works today

Both the client and UA-.NETStandard (the only available ECC counterpart) implement the same 1.05.3 behavior. When both sides use a static IV and sequence numbers starting from 1, encryption and decryption succeed — the data is correct. The issues are a spec-level security improvement (per-message IV) and a wrap-behavior edge case (sequence numbers) that only matter on very long sessions or when one side upgrades to strict 1.05.4 behavior.

When UA-.NETStandard updates its ECC implementation to enforce 1.05.4 semantics, the two fixes above will be needed for interoperability.

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

