---
eyebrow: 'Docs · Getting started'
lede:    'Install the package with Composer, verify the runtime requirements, and confirm that PHP can reach an OPC UA endpoint.'

see_also:
  - { href: './quick-start.md',          meta: '4 min' }
  - { href: './thinking-in-opc-ua.md',   meta: '8 min' }
  - { href: '../security/certificates.md', meta: '6 min' }

prev: { label: 'Overview',    href: '../overview.md' }
next: { label: 'Quick start', href: './quick-start.md' }
---

# Installation

`php-opcua/opcua-client` is distributed through Packagist. Its only
runtime requirement is `ext-openssl`, which is bundled with every
mainstream PHP distribution.

## Requirements

- **PHP** ≥ 8.2 (tested against 8.2, 8.3, 8.4, 8.5)
- **`ext-openssl`** — used for certificate parsing, signing, hashing,
  AES, and ECDH key agreement
- **Operating system** — Linux, macOS, and Windows. CI runs the unit
  suite on all three; integration tests run on Linux (the OPC UA test
  servers ship as Docker images)

## Install

<!-- @code-block language="bash" label="terminal" -->
```bash
composer require php-opcua/opcua-client
```
<!-- @endcode-block -->

That is the only step. There is no service provider, no facade
registration, no PHP configuration to edit.

## Verify

<!-- @code-block language="php" label="check.php" -->
```php
<?php

require __DIR__ . '/vendor/autoload.php';

use PhpOpcua\Client\ClientBuilder;

$client = ClientBuilder::create()->connect('opc.tcp://localhost:4840');

echo $client->getServerProductName() . "\n";

$client->disconnect();
```
<!-- @endcode-block -->

If the script prints the server product name (something like
`UaServerCpp` or `open62541 OPC UA Server`), the install is complete and
the network path to the server is open. The endpoint
`opc.tcp://localhost:4840` is the OPC UA convention — port 4840 is
IANA-assigned, and `opc.tcp` is the binary scheme.

<!-- @callout variant="note" -->
The default builder selects `SecurityPolicy::None` and
`SecurityMode::None`. That is sufficient for the verification step
above. Move on to [Security · Overview](../security/overview.md) before
exposing real data over the wire.
<!-- @endcallout -->

## No server handy?

Pull one of the OPC UA reference servers used by this library's
integration suite:

<!-- @tabs labels="open62541, UA-.NETStandard" -->
<!-- @tab index="0" -->
```bash
docker run --rm -d \
  --name opcua-test \
  -p 4840:4840 \
  open62541/open62541:latest
```
<!-- @endtab -->
<!-- @tab index="1" -->
```bash
# uanetstandard-test-suite ships a docker-compose stack on
# ports 4840 / 4841 / 4842 / … each covering a security policy variant.
git clone https://github.com/php-opcua/uanetstandard-test-suite.git
cd uanetstandard-test-suite
docker compose up -d
```
<!-- @endtab -->
<!-- @endtabs -->

Both expose an unsecured endpoint on `opc.tcp://localhost:4840`. Reach
for `uanetstandard-test-suite` when you also need secured endpoints,
username/password servers, or the ECC variants.

## Optional dependencies

The library is **zero-runtime-dependency** beyond `ext-openssl`. The
following PSR contracts are interfaces only — you supply the
implementation when you want the feature, and otherwise pay nothing:

| Contract              | What it enables                                          |
| --------------------- | -------------------------------------------------------- |
| `Psr\Log\LoggerInterface`         | Structured diagnostics (Monolog, Laravel, Symfony) |
| `Psr\EventDispatcher\EventDispatcherInterface` | Listening to the 47 lifecycle events |
| `Psr\SimpleCache\CacheInterface`               | Browse / resolve result caching     |

Wire them on the builder; see [Observability ·
Logging](../observability/logging.md),
[Observability · Events](../observability/events.md), and
[Observability · Caching](../observability/caching.md).
