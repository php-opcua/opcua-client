---
eyebrow: 'Docs · Recipes'
lede:    'Upgrade to v4.4 in four steps: composer update, optionally wire the new service modules (HistoryUpdate, FileTransfer, Aggregate), sweep your code for the module-count bump (8 → 10), and verify. No cache flush, no API breakage.'

see_also:
  - { href: '../operations/history-writes.md',    meta: '6 min' }
  - { href: '../operations/file-transfer.md',     meta: '5 min' }
  - { href: '../operations/client-side-aggregates.md', meta: '5 min' }
  - { href: '../extensibility/transport.md',      meta: '6 min' }

prev: { label: 'Enums',                          href: '../reference/enums.md' }
next: { label: 'Upgrading to v4.3',              href: './upgrading-to-v4.3.md' }
---

# Upgrading to v4.4

v4.4.0 is a feature release. Three new modules ship by default (`AggregateModule`, `FileTransferModule`, plus `HistoryUpdate` methods on the existing `HistoryModule`), the wire transport became pluggable via `ClientTransportInterface`, and two extension-friendly seams open the door to non-TCP transports (reverse-connect, HTTPS).

**No breaking changes on the public API.** Every method that worked in v4.3 works in v4.4 with the same signature. The migration is essentially "composer update + decide whether to use the new methods".

## Step 1 — Update Composer

<!-- @code-block language="bash" label="terminal" -->
```bash
composer require php-opcua/opcua-client:^4.4
```
<!-- @endcode-block -->

The constraint allows everything from v4.4 forward. Pin tighter (`~4.4.0`) if you want to vet patches before they roll out. Companion packages release in lock-step — bump them together:

<!-- @code-block language="bash" label="terminal — ecosystem bump" -->
```bash
composer require \
    php-opcua/opcua-client:^4.4 \
    php-opcua/opcua-client-nodeset:^4.4 \
    php-opcua/opcua-session-manager:^4.4 \
    php-opcua/opcua-cli:^4.4
# Plus, if you use them:
#   php-opcua/laravel-opcua:^4.4
#   php-opcua/symfony-opcua:^4.4
```
<!-- @endcode-block -->

## Step 2 — No cache flush needed

Unlike the v4.3 upgrade, the cache codec did **not** change between v4.3 and v4.4. `WireCacheCodec` is still the default, the `__t` allowlist gates the same way, and entries written by v4.3 decode cleanly under v4.4. Skip directly to Step 3.

## Step 3 — Adopt the new methods (optional)

Three new module families landed. Adopt the ones your use case needs; the rest stay invisible.

### 3a — `HistoryUpdate` (9 new methods)

The `HistoryModule` gained Insert / Replace / Update / Remove for both data and event timeseries. All 9 are on `OpcUaClientInterface` directly — no extra setup.

<!-- @code-block language="php" label="historyUpdate quick tour" -->
```php
use PhpOpcua\Client\Module\History\PerformUpdateType;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\DataValue;

// Backfill historical samples — fails per-entry if a value already exists at the same timestamp.
$results = $client->historyInsertData('ns=2;s=Sensors/Temp', [
    DataValue::of(22.1, BuiltinType::Double)->withSourceTimestamp($t1),
    DataValue::of(22.3, BuiltinType::Double)->withSourceTimestamp($t2),
]);
// $results: int[] — per-entry status codes

// Upsert (insert-if-missing, replace-if-present)
$client->historyUpdateData('ns=2;s=Sensors/Temp', $values);

// Delete a time range
$client->historyDeleteRawModified('ns=2;s=Sensors/Temp', $start, $end);

// Delete specific timestamps
$client->historyDeleteAtTime('ns=2;s=Sensors/Temp', [$t1, $t2]);

// Event flavours
$client->historyInsertEvent('ns=2;s=AlarmSource', $selectFields, $eventList);
$client->historyDeleteEvent('ns=2;s=AlarmSource', $eventIds);
```
<!-- @endcode-block -->

See [Operations · History writes](../operations/history-writes.md) for the full method list and per-method semantics.

### 3b — `FileTransferModule` (10 new methods, Part 5)

Server-side `FileType` and `FileDirectoryType` management. Loaded by default — no `addModule()` call needed.

<!-- @code-block language="php" label="fileTransfer quick tour" -->
```php
use PhpOpcua\Client\Module\FileTransfer\OpenFileMode;

// Read a server-side file
$handle = $client->openFile('ns=2;s=Files/Config', OpenFileMode::Read);
$bytes  = $client->readFile('ns=2;s=Files/Config', $handle, 4096);
$client->closeFile('ns=2;s=Files/Config', $handle);

// Write (truncating)
$handle = $client->openFile('ns=2;s=Files/Upload', OpenFileMode::toByte(OpenFileMode::Write, OpenFileMode::EraseExisting));
$client->writeFile('ns=2;s=Files/Upload', $handle, $data);
$client->closeFile('ns=2;s=Files/Upload', $handle);

// Directory operations
$newDir   = $client->createDirectory('ns=2;s=Files', 'logs');
$created  = $client->createFileInDirectory('ns=2;s=Files', 'capture.bin', requestFileOpen: true);
$client->deleteFileSystemObject('ns=2;s=Files', $oldNode);
$moved    = $client->moveOrCopyFileSystemObject('ns=2;s=Files', $source, $targetDir, createCopy: false);
```
<!-- @endcode-block -->

See [Operations · File transfer](../operations/file-transfer.md) for `OpenFileMode` semantics, chunked I/O, per-method NodeId caching, and failure modes.

### 3c — Client-side `Aggregate` (Part 13)

`AggregateModule` computes time-bucket aggregates (`Interpolate`, `Minimum`, `Maximum`, `Average`, `Count`) over a raw `DataValue[]` buffer. Reachable via `Client::__call()` — typed methods aren't on `OpcUaClientInterface` because the buffer-aggregate flow is opt-in.

<!-- @code-block language="php" label="aggregate quick tour" -->
```php
use PhpOpcua\Client\Module\Aggregate\AggregateFunction;

// Aggregate a buffer you already have
$rawDvs = $client->historyReadRaw('ns=2;s=Sensors/Temp', $start, $end);
$intervals = $client->aggregate(
    $rawDvs, $start, $end,
    processingIntervalMs: 60_000,
    function: AggregateFunction::Average,
);

// Or, fetch + aggregate in one call
$intervals = $client->historyAggregate(
    'ns=2;s=Sensors/Temp', $start, $end,
    processingIntervalMs: 60_000,
    function: AggregateFunction::Interpolate,
);
```
<!-- @endcode-block -->

See [Operations · Client-side aggregates](../operations/client-side-aggregates.md) for `AggregateOptions` (`stepped`, `treatUncertainAsBad`, `useSlopedExtrapolation`, `percentDataBad/Good`) and how the Historian InfoBits propagate.

## Step 4 — Sweep for module-count assertions

The default module count went from **8 to 10** (`AggregateModule` and `FileTransferModule` joined the lineup). Code that hard-codes the count breaks silently:

<!-- @do-dont -->
<!-- @do -->
```php
// Use named lookup or skip the count entirely
assert($client->hasModule(ReadWriteModule::class));
assert($client->hasMethod('historyInsertData'));
```
<!-- @enddo -->
<!-- @dont -->
```php
// Will fail on v4.4 — pre-v4.4 codebases asserted 8
assert(count($client->getLoadedModules()) === 8);
```
<!-- @enddont -->
<!-- @enddo-dont -->

`grep -rn "getLoadedModules.*=== 8\|count.*Modules.*8\|defaultModules.*8" .` will find the references.

If you have a custom `MockClient` subclass or a third-party module registry, also check whether you need to register stubs for the new methods (`historyInsertData`, `historyReplaceData`, `historyUpdateData`, `historyDeleteRawModified`, `historyDeleteAtTime`, `historyInsertEvent`, `historyReplaceEvent`, `historyUpdateEvent`, `historyDeleteEvent`, `openFile`, `closeFile`, `readFile`, `writeFile`, `getFilePosition`, `setFilePosition`, `createDirectory`, `createFileInDirectory`, `deleteFileSystemObject`, `moveOrCopyFileSystemObject`).

## Step 5 — Verify after the upgrade

<!-- @code-block language="php" label="post-upgrade smoke test" -->
```php
use PhpOpcua\Client\ClientBuilder;

$client = ClientBuilder::create()->connect('opc.tcp://plc.local:4840');

// 1. Module count bumped to 10
assert(count($client->getLoadedModules()) === 10);

// 2. The three new module surfaces are reachable
assert($client->hasMethod('historyInsertData'));    // History (expanded)
assert($client->hasMethod('openFile'));             // FileTransfer (new)
assert($client->hasMethod('aggregate'));            // Aggregate (new, via __call)

// 3. Existing API still works
$refs = $client->browse('i=85');
assert(count($refs) > 0);

$client->disconnect();
```
<!-- @endcode-block -->

All four pass: the upgrade is clean.

## Transport seam — for module authors and extension users

`ClientTransportInterface` (the wire-transport contract) grew from 6 to 8 methods in v4.4. The two new ones:

| Method | Purpose |
| --- | --- |
| `createProbe(): self` | Returns a fresh sibling transport for the discovery probe (`GetEndpoints`). The probe used to be hardcoded to `new TcpTransport()`, which broke any non-TCP transport. |
| `isSecureChannelExternal(): bool` | When `true`, the client skips the OPC UA `OpenSecureChannel` exchange because the transport already wraps the wire in a confidential, authenticated channel (TLS for `opc.https://`, etc.). The `ManagesSecureChannelTrait::openSecureChannelExternal()` branch initialises `SessionService` with synthetic channel/token IDs. |

**You only need to care** if you:

- Wrote a custom `ClientTransportInterface` implementation — you must add both methods. `TcpTransport` returns `new self()` and `false` respectively; mirror those defaults unless your transport says otherwise. See [Extensibility · Transport](../extensibility/transport.md) and the `tests/Unit/Helpers/InMemoryTransport.php` worked example.
- Use `opcua-client-ext-reverse-connect` or `opcua-client-ext-transport-https` — these extensions plug into the new seams and require `opcua-client ^4.4`.

For the default case (plain `opc.tcp://` via `TcpTransport`), nothing to do.

## What did not change

- **Public method names and signatures on `OpcUaClientInterface`.** Every call you make on a `Client` still works. The 9 + 10 new methods are additive.
- **`ClientBuilder` setters.** No setter renamed, none removed. `setTransport()` was already in v4.3; the contract on what you pass to it expanded.
- **Cache codec.** Still `WireCacheCodec`. v4.3 cache entries decode cleanly under v4.4.
- **Exception class hierarchy.** New status codes (`BadFileHandleInvalid`, `BadFileNotOpened`, `BadInvalidState`, `BadAggregateInvalidInputs`, `BadAggregateNotSupported`, `BadAggregateConfigurationRejected`, `UncertainDataSubNormal`) — none of these rename existing ones.
- **Default `SecurityPolicy` enum values and order.** Same 10 cases.
- **Trust store policy enum and defaults.** Same as v4.3.

## What's new and worth wiring

- **`historyAggregate(NodeId|string, $start, $end, $intervalMs, AggregateFunction)`** — one-liner fetch-and-aggregate. Replaces hand-rolled `historyReadRaw` + manual bucket math.
- **`PerformUpdateType` enum** — Insert / Replace / Update / Remove. The `HistoryUpdate` service emits events that carry this so listeners can route on update kind.
- **`OpenFileMode` enum** + `OpenFileMode::toByte(...)` for OR-combining (`Write|EraseExisting`, etc.).
- **`StatusCode::withDataValueInfoBits()`** — apply Historian InfoBits (`Calculated`, `Interpolated`, `Partial`, `ExtraData`, `MultiValue`) when you synthesize DataValues from aggregate output.
- **5 new history-update events** (`HistoryDataUpdated`, `HistoryDataDeleted`, `HistoryEventUpdated`, `HistoryEventDeleted`, `AggregateComputed`) and **4 new file-transfer events** (`FileOpened`, `FileClosed`, `FileBytesRead`, `FileBytesWritten`). Hook them via your PSR-14 dispatcher for audit / metrics. Event count goes from 47 to 57.

## Rollback

The API surface is backward-compatible. Rolling back to v4.3 is safe **if** you have not started using:

- Any of the 9 HistoryUpdate methods
- Any of the 10 File Transfer methods
- The `aggregate()` / `historyAggregate()` calls
- The companion extensions (`opcua-client-ext-reverse-connect`, `opcua-client-ext-transport-https`)

<!-- @code-block language="bash" label="terminal — rollback" -->
```bash
composer require php-opcua/opcua-client:^4.3
```
<!-- @endcode-block -->

Persistent caches survive the rollback because the codec did not change between v4.3 and v4.4.
