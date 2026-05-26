# Roadmap

### IDE helper stub generator

- [ ] A `composer generate-ide-helper` command (or `vendor/bin/opcua-ide-helper`) that auto-generates `_ide_helper_opcua.php` from the registered modules via reflection. The stub file contains PHPDoc `@method` annotations for the `Client` class, covering both built-in and custom module methods. The file is not loaded at runtime — it is only consumed by the IDE for autocomplete and static analysis. Custom modules are included when the generator is re-run after adding them to the builder. The generated file should be added to `.gitignore`.

### PHPStan level 5

- [ ] Static analysis with `phpstan/phpstan` as dev dependency, CI integration, and `composer analyse` script. Target level 5 first; raise in subsequent releases.

### Additional aggregate functions

`AggregateModule` currently implements `Interpolate`, `Minimum`, `Maximum`, `Average`, `Count`. Remaining Part 13 aggregates, ordered by perceived usefulness. Each link points to the canonical OPC UA 1.05 definition.

- [ ] [`TimeAverage`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.6) / [`TimeAverage2`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.7) — time-weighted average (most users actually expect this from `Average`)
- [ ] [`Range`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.14) / [`Range2`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.19) — `max − min`
- [ ] [`Delta`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.27) / [`DeltaBounds`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.30) — difference between first and last (or bounds)
- [ ] [`DurationGood`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.31) / [`DurationBad`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.32) / [`PercentGood`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.33) / [`PercentBad`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.34) — coherent group on quality coverage
- [ ] [`MinimumActualTime`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.12) / [`MaximumActualTime`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.13) / [`Minimum2`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.15) / [`Maximum2`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.16) / [`MinimumActualTime2`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.17) / [`MaximumActualTime2`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.18) — Min/Max variants that report the raw sample's timestamp or include bounding values
- [ ] [`Total`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.8) / [`Total2`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.9) — integral (area sum) — useful for energy/flow
- [ ] [`StandardDeviationSample`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.37) / [`StandardDeviationPopulation`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.39) / [`VarianceSample`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.38) / [`VariancePopulation`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.40)
- [ ] [`Start`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.25) / [`End`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.26) / [`StartBound`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.28) / [`EndBound`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.29) — first/last (or interpolated bounds) of the interval
- [ ] [`NumberOfTransitions`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.24) — value changes (discrete signals)
- [ ] [`DurationInStateZero`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.22) / [`DurationInStateNonZero`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.23) — time spent at 0 / ≠0 (boolean signals)
- [ ] [`AnnotationCount`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.20) — number of annotations in the interval (requires annotation history)
- [ ] [`WorstQuality`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.35) / [`WorstQuality2`](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.36) — worst StatusCode observed

See the [aggregate overview (Part 13 5.4.3)](https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3) for general semantics (StatusCode calculation, Bounding Values, Stepped vs Sloped, percent-data thresholds). The "2" suffix denotes the v1.03+ variants that include StartBound/EndBound in the candidate set and propagate quality more rigorously than the legacy v1 forms.

Each addition is mechanical: new `Calculator/` class + new enum case in `AggregateFunction` + entry in `AggregateModule::$calculators`. No impact on the rest of the codebase.

### HistoryUpdate: Annotation operations

`HistoryModule` currently implements the Data and Event subtypes of [HistoryUpdate (Part 11 §6.9)](https://reference.opcfoundation.org/Core/Part11/v105/docs/6.9). The remaining subtype is:

- [ ] [`UpdateStructureDataDetails`](https://reference.opcfoundation.org/Core/Part11/v105/docs/6.9.4) — Insert / Replace / Update / Remove for structured history entries (Annotations are the canonical use case). Servers that expose `HasAnnotations` references can persist text annotations attached to a specific timestamp on a Variable's history.

### File Transfer · convenience helpers and missing test coverage

`FileTransferModule` (v4.4.0) ships the six `FileType` methods plus the four `FileDirectoryType` methods. Follow-ups intentionally deferred:

- [ ] `readFileContents(NodeId|string $fileNodeId, ?int $chunkSize = null): string` — wraps `Open(Read)` + N×`Read` + `Close`. Deferred until the chunk-size discovery policy stabilises across server families.
- [ ] `writeFileContents(NodeId|string $fileNodeId, string $data, bool $eraseExisting = true): void` — wraps `Open(Write [| EraseExisting])` + `Write` + `Close` with size-aware chunking. Same chunk-size question.
- [ ] Unit tests for the four `FileDirectoryType` wrappers. The six `FileType` methods already have 17 unit tests in `tests/Unit/Module/FileTransfer/FileTransferModuleTest.php`.
- [ ] Integration tests for the four `FileDirectoryType` wrappers and for `ProtectedWritableFile` against `uanetstandard-test-suite` v1.3.0+.

## Blocked

### File Transfer · typed `FileChangeReceived` / `FileAccessReceived` events

**Status:** Blocked by upstream test-server limitation.

**What:** Two PSR-14 event classes dispatched by `FileTransferModule` after the corresponding server-emitted Part 5 §8.2 events arrive over a subscription — `FileChangeReceived` for `FileChangeEventType`, `FileAccessReceived` for `FileAccessEventType`. The decoder would piggy-back on the existing `EventNotificationReceived` flow.

**Why it's blocked:** no test server in the ecosystem currently emits these events. The reference suite `php-opcua/uanetstandard-test-suite` (v1.3.0) attempted to ship them and found that upstream UA-.NETStandard 1.5.378.134 does not include the generated state classes — only the generic `AuditUpdate*EventType` family. See `uanetstandard-test-suite` ROADMAP under **Blocked**. Shipping the client-side typed events without a fixture that emits them would mean no integration coverage and a documented surface no real server feeds.

---

## v5.0.0 (breaking) Estimate 1Q January 2027

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
- [ ] Update `docs/types/overview.md` and any doc block examples that still show the getter syntax.
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
The `ExtensionObjectCodec` system is intentionally limited to `ExtensionObject`. OPC UA `BuiltinType` values (Int32, String, Double, etc.) are protocol-level primitives with a fixed binary encoding — making them pluggable would add complexity without benefit. See the [design rationale](docs/extensibility/extension-object-codecs.md).

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
