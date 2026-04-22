# Roadmap

> **Note:** The CLI tool has been extracted to a separate package: [`php-opcua/opcua-cli`](https://github.com/php-opcua/opcua-cli). CLI-related roadmap items are tracked there.

## Next minor releases

### NodeManagement Services — completed (v4.3.0)

All four items that historically kept `NodeManagementModule` out of the defaults are
now resolved. The module is in `ClientBuilder::defaultModules()` again; integration
coverage runs on every CI run against an `open62541 v1.4.8` server built with
`UA_ENABLE_NODEMANAGEMENT=ON` (`.github/opcua-nodemanagement/Dockerfile`, wired into
the `integration` workflow's PHP 8.5 matrix leg via `OPCUA_NODE_MANAGEMENT_ENDPOINT`).

- [x] Stand up a reference server with working NodeManagement (open62541 `ci_server`).
- [x] Remove the `->skip()` calls from `tests/Integration/NodeManagementTest.php` and
      gate the tests on `OPCUA_NODE_MANAGEMENT_ENDPOINT` with `beforeEach`.
- [x] Implement client-side `ServiceFault` detection (`Protocol\ServiceFault::throwIf`
      invoked from `AbstractProtocolService::readResponseMetadata()` and from both
      `SessionService` decoders with dedicated read paths).
- [x] Re-enable `NodeManagementModule::class` in `ClientBuilder::defaultModules()`.
      The builder does not probe the server at connect time — zero overhead for users
      who never touch NodeManagement. Users targeting a server that does not implement
      the service set (e.g. UA-.NETStandard) receive `ServiceUnsupportedException`
      (subclass of `ServiceException`, `ServiceResult = 0x800B0000`) the first time
      they call `addNodes()` / `deleteNodes()` / `addReferences()` / `deleteReferences()`.

### IDE helper stub generator

- [ ] A `composer generate-ide-helper` command (or `vendor/bin/opcua-ide-helper`) that auto-generates `_ide_helper_opcua.php` from the registered modules via reflection. The stub file contains PHPDoc `@method` annotations for the `Client` class, covering both built-in and custom module methods. The file is not loaded at runtime — it is only consumed by the IDE for autocomplete and static analysis. Custom modules are included when the generator is re-run after adding them to the builder. The generated file should be added to `.gitignore`.

### PHPStan level 5

- [ ] Static analysis with `phpstan/phpstan` as dev dependency, CI integration, and `composer analyse` script. Target level 5 first; raise in subsequent releases.

---

## v5.0.0 (breaking)

### Remove deprecated accessor methods on Types DTOs

All readonly DTOs in `src/Types/` currently ship with **38 `@deprecated` getter methods** across 9 classes, each delegating to the public readonly property of the same name. They exist for backwards compatibility with pre-v3 API consumers that used `->getNodeId()`, `->getIdentifier()`, `->getNamespaceIndex()`, etc., before the types were migrated to public readonly properties.

**Affected classes:**

| File | Deprecated getters |
|---|---|
| `Types/NodeId.php` | 3 (`getNamespaceIndex`, `getIdentifier`, `getType`) |
| `Types/QualifiedName.php` | 2 (`getNamespaceIndex`, `getName`) |
| `Types/LocalizedText.php` | 2 (`getLocale`, `getText`) |
| `Types/DataValue.php` | 4 (`getValue`, `getStatusCode`, `getSourceTimestamp`, `getServerTimestamp`) |
| `Types/Variant.php` | 3 (`getType`, `getValue`, `getDimensions`) |
| `Types/ReferenceDescription.php` | 7 (`getReferenceTypeId`, `getIsForward`, `getNodeId`, `getBrowseName`, `getDisplayName`, `getNodeClass`, `getTypeDefinition`) |
| `Types/BrowseNode.php` | 5 (`getReference`, `getNodeId`, `getDisplayName`, `getBrowseName`, `getNodeClass`) |
| `Types/EndpointDescription.php` | 7 (`getEndpointUrl`, `getServerCertificate`, `getSecurityMode`, `getSecurityPolicyUri`, `getUserIdentityTokens`, `getTransportProfileUri`, `getSecurityLevel`) |
| `Types/UserTokenPolicy.php` | 5 (`getPolicyId`, `getTokenType`, `getIssuedTokenType`, `getIssuerEndpointUrl`, `getSecurityPolicyUri`) |

**Migration for users:** purely mechanical — every `->getFoo()` call becomes `->foo`. Since the properties are `public readonly`, behaviour is identical.

**Tasks:**

- [ ] Remove all 38 deprecated getter methods from the 9 DTOs listed above.
- [ ] Update `doc/08-types.md` and any doc block examples that still show the getter syntax.
- [ ] Update `llms-full.txt` if it references the getters.
- [ ] CHANGELOG entry under "Removed" with a migration snippet.
- [ ] Scan `tests/` for internal usages and convert (should be near-zero — most tests already use properties).

### Query Services

`QueryFirst` / `QueryNext` (OPC UA Part 4, Section 5.9) — structured queries on the server's address space, conceptually similar to a SQL `SELECT` with `WHERE` filters.

**What it does:** Instead of browsing the address space node by node and filtering client-side, Query Services let the client describe a filter (node class, type definition, attribute constraints) and the server returns only the matching nodes. `QueryFirst` executes the query and returns the first page of results; `QueryNext` retrieves subsequent pages using a continuation point — the same pagination pattern as `Browse`/`BrowseNext`.

**Example use case:** "Find all Variable nodes under `ns=2;s=Plant1` whose DataType is Double and DisplayName contains 'Temperature'." With Browse, this requires a recursive walk of potentially thousands of nodes and client-side filtering. With QueryFirst, the server does the work and returns only the matches.

**When it matters:** Large address spaces with tens of thousands of nodes (typical in big industrial plants with hundreds of PLCs) where `browseRecursive` would be too slow or memory-intensive.

**Why deferred:** Very few OPC UA servers implement Query Services in practice — most return `BadServiceUnsupported`. Even the OPC Foundation's UA-.NETStandard reference implementation has limited support. The `browseRecursive()` + client-side filtering approach covers the vast majority of real-world use cases. This will be implemented when server adoption makes it practically useful.

---

## ECC 1.05.4 compliance

The ECC security policies (`ECC_nistP256`, `ECC_nistP384`, `ECC_brainpoolP256r1`, `ECC_brainpoolP384r1`) have historically been implemented following the OPC UA 1.05.3 specification and tested against [UA-.NETStandard](https://github.com/OPCFoundation/UA-.NETStandard).

UA-.NETStandard itself is moving to strict 1.05.4 semantics: master commit [`d188383`](https://github.com/OPCFoundation/UA-.NETStandard/commit/d188383) (merged 2026-04-16, not yet on NuGet as of this writing — latest published is `1.5.378.134`) adds the strict ECC sequence number check at `UaSCBinaryChannel.cs:341-349` (first received sequence number for ECC **must be 0**). The next NuGet release will ship this change, at which point any client sending ECC sequence numbers starting from 1 will be rejected at the first message.

To keep the interop pinning under explicit control, [`uanetstandard-test-suite`](https://github.com/php-opcua/uanetstandard-test-suite) now pins NuGet `1.5.378.134` (was `1.5.*`). See its CHANGELOG for the rationale.

### Table 56 — LegacySequenceNumbers = FALSE for ECC — **implemented (v4.3.x)**

**Spec:** OPC UA Part 6 §6.7.2.4. For ECC policies, `LegacySequenceNumbers = FALSE`:
- **RSA (TRUE):** may start from any value < 1024; wraps at `UInt32.MaxValue - 1024`; post-wrap value is again < 1024.
- **ECC (FALSE):** starts from 0; increments monotonically; wraps at `UInt32.MaxValue`; post-wrap restarts from 0.

**Fix landed:** `SecureChannel::$sequenceNumber` is now initialized to `0` when `$policy->isEcc()` and to `1` otherwise. `getNextSequenceNumber()` implements policy-dependent wrap logic with `RSA_MAX_SEQUENCE_NUMBER = 0xFFFFFBFF` and `ECC_MAX_SEQUENCE_NUMBER = 0xFFFFFFFF`. Covered by `tests/Unit/Security/SecureChannelSequenceNumberTest.php` (12 test cases, 22 assertions). The fix is compatible with both lenient (`≤ 1.5.378.134`) and strict (post-`d188383`) UA-.NETStandard servers: strict servers require 0, lenient servers accept 0.

---

## New ECC AEAD policies (future, v5.x or later)

UA-.NETStandard master commit [`d188383`](https://github.com/OPCFoundation/UA-.NETStandard/commit/d188383) also registers eight new security policy variants using AEAD ciphers:

- `ECC_nistP256_AesGcm`, `ECC_nistP384_AesGcm`, `ECC_brainpoolP256r1_AesGcm`, `ECC_brainpoolP384r1_AesGcm`
- `ECC_nistP256_ChaChaPoly`, `ECC_nistP384_ChaChaPoly`, `ECC_brainpoolP256r1_ChaChaPoly`, `ECC_brainpoolP384r1_ChaChaPoly`

These policies use AES-128/256-GCM or ChaCha20-Poly1305 instead of AES-CBC + HMAC. They are genuinely different crypto — not a tweak of the existing ECC code — and require:

- [ ] New `SecurityPolicy` enum cases and policy metadata
- [ ] `MessageSecurity::symmetricEncrypt`/`symmetricDecrypt` AEAD code paths (PHP has `openssl_encrypt` with `aes-128-gcm` / `aes-256-gcm`; ChaCha20-Poly1305 is available via `chacha20-poly1305` from OpenSSL 1.1.1+)
- [ ] **Per-message IV via XOR** (`TokenId | LastSequenceNumber`) — mandatory for AEAD because IV reuse under the same key breaks the security guarantee
- [ ] Tracking of `lastSequenceNumber` per direction on `SecureChannel`
- [ ] Integration test coverage against a server that ships these policies (depends on an upstream NuGet release that includes commit `d188383` **and** a `uanetstandard-test-suite` bump)

**Deferred** until at least one of: (a) an OPC Foundation NuGet release ships the AEAD variants as enabled endpoints, (b) a user requests them for a specific server target, (c) the test suite adds an endpoint for them.

---

## Won't do (by design)

### BuiltinTypes as codecs
The `ExtensionObjectCodec` system is intentionally limited to `ExtensionObject`. OPC UA `BuiltinType` values (Int32, String, Double, etc.) are protocol-level primitives with a fixed binary encoding — making them pluggable would add complexity without benefit. See the [design rationale](doc/12-extension-object-codecs.md#design-note-why-builtintypes-are-not-codecs).

### Browse ResultMask
The OPC UA `ResultMask` controls which fields of `ReferenceDescription` are returned in browse results (ReferenceType, IsForward, NodeClass, BrowseName, DisplayName, TypeDefinition). Exposing this would require making most `ReferenceDescription` properties nullable, forcing null-checks on every consumer for a marginal bandwidth saving. The default (all fields) is what 99% of use cases need, and the few bytes saved per reference are irrelevant in typical PHP deployment scenarios (local/LAN connections). No mainstream OPC UA client library (node-opcua, opcua-asyncio) exposes this as a public parameter either.

### Session Manager integration (here)
The session manager ([`php-opcua/opcua-session-manager`](https://github.com/php-opcua/opcua-session-manager)) is intentionally kept as a separate package and will not be merged into this library. The reasons:

- **Cross-platform compatibility.** This client works on Linux, macOS, and Windows. The session manager uses Unix domain sockets for IPC, which are not available on Windows. Integrating it would either break Windows support or leave dead code on that platform.
- **Zero-dependency philosophy.** This library requires only `ext-openssl`. The session manager depends on `react/event-loop` and `react/socket` — pulling those into the client would force every user to install ReactPHP, even if they don't need session persistence.
- **Architectural separation.** The client is a synchronous library. The session manager runs as a separate long-lived daemon process with an async event loop. These are fundamentally different execution models that don't belong in the same package.
- **The daemon is a separate process anyway.** Even if the code lived in the same package, you'd still need to start a separate `php bin/opcua-session-manager` process. It's not middleware you plug in — it's infrastructure you deploy.

### PSR-20 Clock
No valid use case identified in this library.

### RedisDriver / MemcachedDriver cache drivers
These would require `ext-redis` or `ext-memcached` (or `predis/predis`), breaking the zero-dependency philosophy. The cache system uses PSR-16 `CacheInterface`, so any Redis or Memcached adapter that implements PSR-16 works out of the box — including `illuminate/cache` (Laravel), `symfony/cache`, and `cache/redis-adapter`. There is no reason to bundle drivers that would force all users to install extensions they may not need.

### OpenTelemetry integration (here)
Telemetry (distributed tracing, metrics) belongs in the session manager ([`php-opcua/opcua-session-manager`](https://github.com/php-opcua/opcua-session-manager)), not in this library. The reasons:

- **Short-lived connections make spans meaningless.** This client is synchronous — each PHP request opens a connection, performs a few operations, and disconnects. An OpenTelemetry span wrapping `connect → read → disconnect` in a 50ms request adds no insight you don't already get from APM tools already instrumenting your HTTP layer (Laravel Telescope, Datadog APM, New Relic, etc.).
- **Telemetry shines on long-lived processes.** The session manager runs as a persistent daemon, maintaining connections across hundreds of PHP requests. That's where spans like `opcua.publish`, retry histograms, active session counts, and subscription latency distributions actually provide value — correlating OPC UA operations across time, not within a single request.

### Full OPC UA server implementation (here)
This library is a client-only implementation. Building a server requires a fundamentally different architecture (address space management, session handling, subscription engine, etc.).

---

Have a suggestion? Open an [issue](https://github.com/php-opcua/opcua-client/issues) or check the [contributing guide](CONTRIBUTING.md).
