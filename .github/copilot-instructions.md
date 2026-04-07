# OPC UA PHP Client — Copilot Instructions

This repository contains `php-opcua/opcua-client`, a pure PHP OPC UA client that communicates directly over TCP using the OPC UA binary protocol.

## Project context

For a full understanding of this library, read these files in order:

1. **[llms.txt](../llms.txt)** — compact project summary: architecture, classes, API, configuration
2. **[llms-full.txt](../llms-full.txt)** — comprehensive technical reference: every class, method, DTO, encoding, security, protocol services
3. **[llms-skills.md](../llms-skills.md)** — task-oriented recipes: connect, read, write, browse, subscribe, security, testing, Laravel integration

## Architecture

```
ClientBuilder (configuration)
    │
    ▼ connect()
Client (OpcUaClientInterface)
    ├── Transport (TCP socket)
    ├── Protocol (OPC UA service encoding/decoding)
    ├── Encoding (binary serialization)
    ├── Security (secure channel, crypto)
    ├── Types (NodeId, DataValue, Variant, DTOs)
    ├── Repository (ExtensionObject codecs)
    ├── Cache (PSR-16: InMemoryCache, FileCache)
    ├── Event (47 PSR-14 events)
    ├── TrustStore (server certificate trust)
    └── Testing (MockClient)
```

## Key classes

- `src/ClientBuilder.php` — builder / entry point, `ClientBuilder::create()->connect()`
- `src/Client.php` — connected client, implements `OpcUaClientInterface`
- `src/ClientBuilder/` — 8 builder traits (cache, events, timeout, trust store, batching, etc.)
- `src/Client/` — 14 runtime traits (browse, read/write, subscriptions, history, etc.)
- `src/Types/` — all OPC UA data types, enums, and result DTOs (public readonly properties)
- `src/Security/` — secure channel, crypto, 6 security policies
- `src/Testing/MockClient.php` — in-memory test double, no TCP connection
- `src/Repository/ExtensionObjectRepository.php` — per-client codec registry

## Code conventions

- `declare(strict_types=1)` in every file
- Public readonly properties on all DTOs (not getters): `$ref->nodeId`, `$dv->statusCode`
- All methods accepting `NodeId` also accept string format: `'i=2259'`, `'ns=2;s=MyNode'`
- Configuration on `ClientBuilder` before `connect()` — `Client` is immutable after connection
- PHPDoc on every class and public method (`@param`, `@return`, `@throws`, `@see`)
- **No comments inside function bodies** — if code needs a comment, it should be split into well-named methods
- Tests use Pest PHP (not PHPUnit)
- Integration tests grouped with `->group('integration')`
- Coverage target: 99%+

## Dependencies

- `ext-openssl` — the only runtime extension required
- `psr/log` ^3.0, `psr/simple-cache` ^3.0, `psr/event-dispatcher` ^1.0 — interface-only (zero runtime code)
