---
eyebrow: 'Docs · Getting started'
lede:    'Connect, read an attribute, browse a folder, and disconnect — five minutes from a fresh install to a working integration.'

see_also:
  - { href: './thinking-in-opc-ua.md',         meta: '8 min' }
  - { href: '../connection/opening-and-closing.md', meta: '6 min' }
  - { href: '../operations/reading-attributes.md',  meta: '7 min' }

prev: { label: 'Installation',          href: './installation.md' }
next: { label: 'Thinking in OPC UA',    href: './thinking-in-opc-ua.md' }
---

# Quick start

This page builds the smallest useful integration: connect to a server,
read its product name, list the children of the `Objects` folder, and
disconnect. Every later page in these docs assumes you have run code
shaped like this at least once.

## The whole thing

<!-- @code-block language="php" label="examples/quick-start.php" -->
```php
<?php

require __DIR__ . '/vendor/autoload.php';

use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;

$client = ClientBuilder::create()
    ->connect('opc.tcp://localhost:4840');

// Read the server's product-name attribute (a well-known node).
$productName = $client->read(NodeId::numeric(0, 2261));

if (StatusCode::isGood($productName->statusCode)) {
    echo "Connected to: " . $productName->getValue() . "\n";
}

// List the children of the Objects folder.
foreach ($client->browse(NodeId::numeric(0, 85)) as $ref) {
    echo "  • {$ref->displayName->text}  ({$ref->nodeId})\n";
}

$client->disconnect();
```
<!-- @endcode-block -->

Run it. You should see the server's name and a short list of folders
(`Server`, `DeviceSet`, vendor-specific roots, …).

## What just happened

<!-- @steps -->
- **The builder created a client.**

  `ClientBuilder::create()` returns a fresh builder with safe defaults —
  no security, no authentication, a `NullLogger`, a `NullEventDispatcher`,
  an `InMemoryCache`, and the eight built-in service modules wired in.

- **`connect()` opened a secure channel and a session.**

  Internally the client runs a TCP handshake (HEL/ACK), opens an OPC UA
  secure channel (OPN, sign/encrypt as configured), creates a session
  (`CreateSession`), and activates it (`ActivateSession`). The returned
  `Client` is in `ConnectionState::Connected`.

- **`read()` issued a single Read service call.**

  `NodeId::numeric(0, 2261)` builds the standard NodeId for the server's
  product name (namespace 0, identifier 2261). The reply is a
  `DataValue` carrying the value, a `StatusCode`, and timestamps. The
  result is also cached in the in-memory PSR-16 store, keyed by
  endpoint + NodeId + attribute.

- **`browse()` walked the address space one level.**

  Started at the well-known `Objects` folder
  (`NodeId::numeric(0, 85)`), returned an array of
  `ReferenceDescription` objects — one per child node — fully decoded.

- **`disconnect()` closed the session and channel.**

  The client sent `CloseSession` and `CloseSecureChannel`, then closed
  the TCP socket. Subsequent calls would raise `ConnectionException`
  unless you call `reconnect()`.
<!-- @endsteps -->

## NodeId strings

`NodeId::numeric(0, 85)` is the typed factory. The same call accepts a
string shorthand wherever the API takes a `NodeId|string`:

<!-- @code-block language="php" label="equivalent calls" -->
```php
$client->read('i=2261');                        // numeric, namespace 0
$client->read('ns=2;s=Devices/PLC/Speed');      // string identifier, ns 2
$client->read('ns=0;g=72962B91-FA75-4AE6-8D28-B404DC7DAE63'); // GUID
```
<!-- @endcode-block -->

The grammar follows OPC UA Part 6. See [Types · NodeId](../types/node-id.md)
for the full set of factories and accepted formats.

## Next steps

You have run a real OPC UA round-trip. The natural next reads, in order:

1. [Thinking in OPC UA](./thinking-in-opc-ua.md) — twenty minutes on
   the model (sessions, channels, address space, services). The single
   most useful page if you have not worked with OPC UA before.
2. [Connection · Opening and closing](../connection/opening-and-closing.md)
   — the lifecycle in detail, including `reconnect()`.
3. [Operations · Reading attributes](../operations/reading-attributes.md)
   — `read()`, `readMulti()`, the fluent builder, attribute IDs.

<!-- @callout variant="tip" -->
Run with `setLogger(new \Monolog\Logger('opcua'))` while you learn. The
client logs every protocol step at `debug` level — that is how the
mental model takes shape fastest.
<!-- @endcallout -->
