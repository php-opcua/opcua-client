# Roadmap

This document outlines planned improvements and features for the OPC UA PHP Client library.

## v2.0.0 — API Redesign

The 2.0 release will introduce **breaking changes** to improve the developer experience across the entire API surface.

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
- **NodeClassMask enum** — replace the raw bitmask integer with a proper enum or builder (similar to the existing `BrowseDirection` enum)
- **ResultMask** — control which fields are returned in browse results to reduce bandwidth

### Full ExtensionObject Type System
Automatic discovery and deserialization of all server-defined structured types by reading the server's DataType dictionary (`OPC UA Part 6 §5.2.7`). This would eliminate the need for manually implementing codecs.

## Won't Do (by design)

### BuiltinTypes as Codecs
The `ExtensionObjectCodec` system is intentionally limited to `ExtensionObject`. OPC UA `BuiltinType` values (Int32, String, Double, etc.) are protocol-level primitives with a fixed binary encoding — making them pluggable would add complexity without benefit. See the [design rationale](doc/12-extension-object-codecs.md#design-note-why-builtintypes-are-not-codecs).

### Full OPC UA Server Implementation (here)
This library is a client-only implementation. Building a server requires a fundamentally different architecture (address space management, session handling, subscription engine, etc.).

---

Have a suggestion? Open an [issue](https://github.com/gianfriaur/opcua-php-client/issues) or check the [contributing guide](CONTRIBUTING.md).
