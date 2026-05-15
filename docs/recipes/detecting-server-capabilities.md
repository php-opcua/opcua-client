---
eyebrow: 'Docs · Recipes'
lede:    'Servers advertise their own capabilities at well-known nodes and through service-set probes. Read them once at startup, branch your application on what you find.'

see_also:
  - { href: './service-unsupported.md',                     meta: '4 min' }
  - { href: '../connection/endpoints-and-discovery.md',     meta: '6 min' }
  - { href: '../operations/reading-attributes.md',          meta: '7 min' }

prev: { label: 'Writing typed arrays',  href: './writing-typed-arrays.md' }
next: { label: 'No further page',       href: '#' }
---

# Detecting server capabilities

A single integration often spans servers with very different feature
sets. Capability detection is the discipline of asking *the specific
server in front of you* what it can do, before designing application
logic around assumptions.

Three sources, in order of cheapness:

1. **Built-in metadata.** `getServerBuildInfo()`, `getServerProductName()`,
   the standard server-capability nodes. Free on every connection.
2. **Service-set probes.** Cheap calls into optional service sets;
   `ServiceUnsupportedException` if the server lacks them.
3. **Behavioural probes.** Calls that test specific spec corners
   (does this server honour `BadHistoryOperationUnsupported` per item,
   does it respect deadband filters, …). Reserved for compatibility-
   test scaffolding.

## 1 — Built-in metadata

Every OPC UA server publishes a `ServerCapabilities` object under
`Server.ServerCapabilities` (well-known NodeId `i=2268`). The standard
properties:

| Property                                  | NodeId       | Meaning                                                     |
| ----------------------------------------- | ------------ | ----------------------------------------------------------- |
| `LocaleIdArray`                           | `i=2271`     | Locale strings the server accepts                           |
| `ServerProfileArray`                      | `i=2269`     | Profile URIs the server claims to implement                 |
| `MinSupportedSampleRate`                  | `i=2272`     | Fastest sampling interval the server will negotiate (ms)    |
| `MaxBrowseContinuationPoints`             | `i=2273`     | How many continuation points the server will hold open      |
| `MaxQueryContinuationPoints`              | `i=2274`     | Same for Query service                                      |
| `MaxHistoryContinuationPoints`            | `i=2275`     | Same for History service                                    |
| `SoftwareCertificates`                    | `i=3704`     | List of signed software certs (rarely populated)            |

Plus operational limits at `Server.ServerCapabilities.OperationLimits`:

| Property                                  | NodeId       | What                                            |
| ----------------------------------------- | ------------ | ----------------------------------------------- |
| `MaxNodesPerRead`                         | `i=11565`    | Cap on `readMulti()` per request                |
| `MaxNodesPerWrite`                        | `i=11567`    | Cap on `writeMulti()` per request               |
| `MaxNodesPerMethodCall`                   | `i=11569`    | Cap on `call()` batch                           |
| `MaxNodesPerBrowse`                       | `i=11570`    | Cap on `browse()` per request                   |
| `MaxNodesPerHistoryReadData`              | `i=11571`    | Cap on `historyRead*()` batches                 |
| `MaxNodesPerNodeManagement`               | `i=11576`    | Cap on `addNodes()` / `deleteNodes()` batches   |

The library reads `MaxNodesPerRead` and `MaxNodesPerWrite`
automatically during `connect()` and uses them for auto-batching.
The others are useful for client-side validation:

<!-- @code-block language="php" label="examples/read-operation-limits.php" -->
```php
$limits = $client->readMulti()
    ->node('i=11565')->value()    // MaxNodesPerRead
    ->node('i=11567')->value()    // MaxNodesPerWrite
    ->node('i=11570')->value()    // MaxNodesPerBrowse
    ->execute();

$maxRead   = $limits[0]->getValue();
$maxWrite  = $limits[1]->getValue();
$maxBrowse = $limits[2]->getValue();
```
<!-- @endcode-block -->

For history-specific capabilities, browse
`Server.ServerCapabilities.HistoryServerCapabilities` (`i=2330`) —
which aggregates the server supports, whether it does inserts, etc.

## 2 — Service-set probes

For optional service sets, the cheapest test is an empty call.
`ServiceUnsupportedException` answers in one round-trip:

<!-- @code-block language="php" label="examples/probe-service-sets.php" -->
```php
use PhpOpcua\Client\Exception\ServiceUnsupportedException;

final class Capabilities
{
    public bool $historyRead    = false;
    public bool $nodeManagement = false;
    public bool $subscriptions  = false;
}

function probe($client): Capabilities
{
    $caps = new Capabilities();

    try {
        $client->historyReadRaw(
            nodeId:     'i=2258',
            startTime:  new DateTimeImmutable('-1 second'),
            endTime:    new DateTimeImmutable(),
            numValuesPerNode: 1,
        );
        $caps->historyRead = true;
    } catch (ServiceUnsupportedException) {}

    try {
        $client->deleteNodes([]);
        $caps->nodeManagement = true;
    } catch (ServiceUnsupportedException) {}

    try {
        $sub = $client->createSubscription(publishingInterval: 1000.0);
        $client->deleteSubscription($sub->subscriptionId);
        $caps->subscriptions = true;
    } catch (ServiceUnsupportedException) {}

    return $caps;
}
```
<!-- @endcode-block -->

See [Recipes · Handling unsupported
services](./service-unsupported.md) for the rationale and the cost
model.

## 3 — Endpoint-level capabilities

`getEndpoints()` returns one row per (policy, mode) the server
accepts. That tells you:

- Which security policies the server supports (compare against the
  `SecurityPolicy` enum cases)
- Which authentication tokens the server accepts (`userIdentityTokens`)
- Whether the server publishes a separate "internal" endpoint URL
  different from the discovery URL

<!-- @code-block language="php" label="examples/list-security-options.php" -->
```php
$endpoints = $client->getEndpoints('opc.tcp://plc.local:4840');

$policies = array_unique(array_map(
    fn($e) => basename($e->securityPolicyUri),
    $endpoints,
));

$authModes = array_unique(array_merge(...array_map(
    fn($e) => array_map(fn($p) => $p->tokenType->name, $e->userIdentityTokens),
    $endpoints,
)));

$logger->info('opcua.endpoints', [
    'policies' => $policies,
    'auth'     => $authModes,
]);
```
<!-- @endcode-block -->

For an unfamiliar server, this is the cheapest "what is here?" probe.
Run it before designing security configuration.

## Caching the answers

Probe results don't change without a server restart. Cache them in
your application — not in the OPC UA cache (which expires every 5
minutes by default), but in the application's startup configuration:

<!-- @code-block language="php" label="examples/cache-once.php" -->
```php
final class ServerSnapshot
{
    public function __construct(
        public readonly string  $productName,
        public readonly string  $softwareVersion,
        public readonly int     $maxNodesPerRead,
        public readonly int     $maxNodesPerWrite,
        public readonly bool    $supportsHistory,
        public readonly bool    $supportsNodeManagement,
        public readonly array   $availablePolicies,
    ) {}

    public static function probe($client): self
    {
        // Build from build info, operation limits, probes, endpoints.
        // Run once at worker startup; pass the snapshot around.
    }
}
```
<!-- @endcode-block -->

Pass the snapshot around your application. Branch behaviour on its
fields instead of probing repeatedly.

## When the snapshot lies

Capabilities the server **claims** but does not actually deliver are
real. The library trusts the wire — it does not second-guess. Two
common cases:

- **Operation limits advertised as `0`.** Spec convention: `0` means
  "no client-side cap, the server will enforce its own". The library
  treats `0` as unbounded. If you see `BadTooManyOperations` on a
  large batch, the actual cap was smaller than advertised — narrow
  your batch and update the snapshot.
- **History capability advertised, single-call fails.** Some servers
  advertise the service set but only implement a subset. Probe with
  the specific call shape your application uses, not a generic one.

The cure is the same in both cases: the snapshot is a *hint*, not a
*guarantee*. Code against real responses, not against the snapshot
alone.

## What this does not give you

- **Vendor-specific extensions.** A vendor that exposes "the device
  speed" under `ns=2;s=Special` is invisible to the standard
  capability nodes. Browse for it instead.
- **Per-node permissions.** Reading `AccessLevel` on a specific
  variable tells you whether *that variable* allows writes. Capability
  detection at the server level cannot replace that check.
- **Performance characteristics.** Round-trip latency, sampling
  jitter, subscription density — those need real measurements, not
  declarative metadata.
