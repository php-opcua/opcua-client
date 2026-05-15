---
eyebrow: 'Docs · Recipes'
lede:    'Some service sets are optional in the OPC UA spec. ServiceUnsupportedException is how the library tells you the server does not implement one. Catch it once, remember the answer.'

see_also:
  - { href: '../operations/managing-nodes.md',  meta: '6 min' }
  - { href: '../operations/history-reads.md',   meta: '6 min' }
  - { href: '../reference/exceptions.md',       meta: '7 min' }

prev: { label: 'Recovering from disconnection', href: './disconnection-recovery.md' }
next: { label: 'Browsing recursively',          href: './browsing-recursively.md' }
---

# Handling unsupported services

OPC UA defines several **optional** service sets — NodeManagement,
HistoryRead, HistoryUpdate, Query, …. Many production servers
(especially PLC-embedded ones) ship without them. When you call into
a service set the server does not implement, the library raises
`Exception\ServiceUnsupportedException`.

This recipe is the right pattern for that case.

## The exception

`ServiceUnsupportedException` extends `ServiceException`. It is
raised when the server's `ServiceFault` carries `BadServiceUnsupported
(0x800B0000)`. Two consequences:

- Code that already catches `ServiceException` still matches it —
  backwards-compatible.
- Code that wants to react specifically can catch the subclass.

The exception fires on the **first** call into the unsupported service
set. The library does **not** cache the answer — every subsequent
call raises the same exception. If you call these methods often,
cache the capability yourself.

## Capability probe at startup

The cleanest pattern: probe each optional service set once at startup
and remember the answer.

<!-- @code-block language="php" label="examples/probe-capabilities.php" -->
```php
use PhpOpcua\Client\Exception\ServiceUnsupportedException;

final class ServerCapabilities
{
    public bool $nodeManagement = false;
    public bool $historyRead    = false;
}

function probe($client): ServerCapabilities
{
    $caps = new ServerCapabilities();

    try {
        // Cheap probe — empty request, no side-effects.
        $client->deleteNodes([]);
        $caps->nodeManagement = true;
    } catch (ServiceUnsupportedException) {
        // Server does not implement NodeManagement.
    }

    try {
        // Cheap probe — request a 1 ms window for a known node.
        $client->historyReadRaw(
            nodeId:     'i=2258',                       // ServerStatus.CurrentTime
            startTime:  new DateTimeImmutable('-1 second'),
            endTime:    new DateTimeImmutable(),
            numValuesPerNode: 1,
        );
        $caps->historyRead = true;
    } catch (ServiceUnsupportedException) {
        // Server does not implement HistoryRead.
    }

    return $caps;
}

$caps = probe($client);

if ($caps->historyRead) {
    enableHistoryFeature();
}
```
<!-- @endcode-block -->

The probes are cheap — empty arrays, known nodes, 1-ms windows. Each
costs one round-trip; one-time at startup is acceptable.

## Per-call guard

If you cannot probe at startup (caller's policy, server appears
later), guard each call site:

<!-- @code-block language="php" label="per-call guard" -->
```php
try {
    $results = $client->addNodes($nodes);
} catch (ServiceUnsupportedException) {
    // Fallback path — write a config file, queue a manual task,
    // raise a warning that the feature is unavailable.
    return fallback();
}
```
<!-- @endcode-block -->

Use this when:

- The unsupported case is rare and your call site is rare too.
- The fallback is genuinely orthogonal — not "retry with different
  arguments" but "do something completely different".

## The cost of repeated calls

The library does **not** cache `BadServiceUnsupported`. Every call
into the service set issues a real request and gets a real
`ServiceFault` back. For a server that does not implement the service
set:

| Pattern                                 | Cost per call |
| --------------------------------------- | ------------- |
| Probed once, cached locally             | ~0 (memory lookup) |
| Caught per-call                         | One round-trip to the server |
| Caught per-call + retry on the exception | Up to `(maxRetries + 1)` round-trips |

The retry case is the trap: `setAutoRetry(3)` plus a server that
returns `BadServiceUnsupported` means four round-trips per call site
hit. `ServiceUnsupportedException` is **not** retried by the library
— there's no transient state to recover — but if your application
catches and retries manually, you pay the round-trips.

<!-- @callout variant="warning" -->
Do not retry `ServiceUnsupportedException`. The server is not going
to start implementing the service set between attempts. Catch it once,
remember the answer.
<!-- @endcallout -->

## What about other "the server can't do that" cases?

`BadServiceUnsupported` is the spec-defined status for an entire
service set being unimplemented. The library raises
`ServiceUnsupportedException` for that one specifically. Adjacent
cases:

| Status                            | What it means                                | Library behaviour                       |
| --------------------------------- | -------------------------------------------- | --------------------------------------- |
| `BadServiceUnsupported`           | Service set not implemented                  | `ServiceUnsupportedException`           |
| `BadHistoryOperationUnsupported`  | HistoryRead exists; this flavour does not    | Bad status in the per-item result       |
| `BadAggregateNotSupported`        | HistoryRead processed: unknown aggregate     | Bad status in the per-item result       |
| `BadOperationNotImplemented`      | Method exists on a node but is a no-op       | Bad status in the per-item result       |

Only the first is a top-level exception. The rest ride as per-item
status codes — check them after the call returns.

## Logging the probe results

Capability probes are useful diagnostic information. Log them at
`info` level so you can grep server inventories later:

<!-- @code-block language="php" label="logged capabilities" -->
```php
$logger->info('opcua.capabilities', [
    'endpoint'       => $url,
    'nodeManagement' => $caps->nodeManagement,
    'historyRead'    => $caps->historyRead,
]);
```
<!-- @endcode-block -->

When a fleet of integrations starts surfacing this, you can see at a
glance which PLCs in your plant support which features without
walking the spec tables.
