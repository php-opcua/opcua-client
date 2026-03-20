# Roadmap

> **A note on versioning:** We're aware of the rapid major releases in a short time frame. This library is under active, full-time development right now — the goal is to reach a production-stable state as quickly as possible. Breaking changes are being bundled and shipped deliberately to avoid dragging them out across many minor releases. Once the API surface settles, major version bumps will become rare. Thanks for your patience.

## v3.0.0

- [X] CodeCoverage
- [X] Improuve Documentation
- [X] `ExtensionObjectRepository`: replace static registry with instance-level dependency
- [X] Strict Return Types
- [X] Named Parameters Everywhere
- [X] Full PHPDoc / Attribute Documentation
- [ ] Fluent / Builder API
- [ ] `TBD` integration by default: wirh opcua-php-client-session-manager
- [X] Browse Filters (`nodeClassMask` → `NodeClass[]`),
- [X] Browse Filters ResultMask → Won't Do (see below)
- [ ] Uman readable & interactable functions
- [X] Full ExtensionObject Type System `OPC UA Part 6 5.2.7`
- [ ] .....
------

This document outlines planned improvements and features for the OPC UA PHP Client library.

## API Redesign

The v3.\*.\* release will introduce **breaking changes** to improve the developer experience across the entire API surface.

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

### Uman readable & interactable functions

write isn't easiest to read or write, 
```php 
$status = $client->read(NodeId::numeric(0, 2259));
```

should become

```php 
$status = $client->read(NodeId::numeric(0, 2259)); // or
$status = $client->read('ns=0;i=2253'); // or
$status = $client->read('i=2253'); // or  ns 0 is assumed
```
all this variant is allowed

also a new specific exception should be designed



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
- **NodeClassMask enum** — Done in v3.0.0. Replaced `int $nodeClassMask` with `NodeClass[] $nodeClasses`.

### Full ExtensionObject Type System
https://reference.opcfoundation.org/Core/Part6/v104/docs/5.2.7

Automatic discovery and deserialization of all server-defined structured types by reading the server's DataType dictionary (`OPC UA Part 6 §5.2.7`). This would eliminate the need for manually implementing codecs.

## Won't Do (by design)

### BuiltinTypes as Codecs
The `ExtensionObjectCodec` system is intentionally limited to `ExtensionObject`. OPC UA `BuiltinType` values (Int32, String, Double, etc.) are protocol-level primitives with a fixed binary encoding — making them pluggable would add complexity without benefit. See the [design rationale](doc/12-extension-object-codecs.md#design-note-why-builtintypes-are-not-codecs).

### Browse ResultMask
The OPC UA `ResultMask` controls which fields of `ReferenceDescription` are returned in browse results (ReferenceType, IsForward, NodeClass, BrowseName, DisplayName, TypeDefinition). Exposing this would require making most `ReferenceDescription` properties nullable, forcing null-checks on every consumer for a marginal bandwidth saving. The default (all fields) is what 99% of use cases need, and the few bytes saved per reference are irrelevant in typical PHP deployment scenarios (local/LAN connections). No mainstream OPC UA client library (node-opcua, opcua-asyncio) exposes this as a public parameter either.

### Full OPC UA Server Implementation (here)
This library is a client-only implementation. Building a server requires a fundamentally different architecture (address space management, session handling, subscription engine, etc.).

---

Have a suggestion? Open an [issue](https://github.com/gianfriaur/opcua-php-client/issues) or check the [contributing guide](CONTRIBUTING.md).
