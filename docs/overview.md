---
eyebrow: 'Docs · Overview'
lede:    'A pure-PHP OPC UA client. It speaks the binary protocol over TCP, manages secure channels and sessions, and ships the cryptography required to talk to industrial servers — no native dependencies beyond ext-openssl.'

see_also:
  - { href: './getting-started/installation.md', meta: '2 min' }
  - { href: './getting-started/thinking-in-opc-ua.md', meta: '8 min' }
  - { href: 'https://opcfoundation.org/specs/part4', meta: 'external', label: 'OPC UA Part 4 — Services' }

prev: { label: 'No previous page', href: '#' }
next: { label: 'Installation',     href: './getting-started/installation.md' }
---

# Overview

`php-opcua/opcua-client` is a client implementation of OPC UA, written
entirely in PHP. It connects to an OPC UA server over `opc.tcp://`, opens
a secure channel and a session, then issues service calls — read, write,
browse, subscribe, history, method call, node management — and returns
typed PHP objects.

The project targets industrial automation use cases where a PHP backend
needs to talk to a PLC, a SCADA server, or any device exposing OPC UA. It
is synchronous, single-threaded, and has no runtime dependency outside
`ext-openssl`.

## When to use it

Reach for this library when you want to:

- Read or write PLC tags from a Laravel / Symfony / plain-PHP application.
- Build an integration layer that browses an industrial address space
  and exposes parts of it as an API, queue producer, or webhook.
- Subscribe to live data changes or alarm events from a long-running
  worker.
- Implement bespoke supervisory tools without leaving PHP.

The library is **not** an OPC UA server, and it is **not** asynchronous —
each call blocks the calling process. For session persistence across
short-lived requests, pair it with the companion
[`opcua-session-manager`](https://github.com/php-opcua/opcua-session-manager)
daemon.

## What is in the box

<!-- @code-block language="text" label="components" -->
```text
ClientBuilder       fluent configuration entry point
   │
   └── Client       proxy to 8 self-contained service modules:
          ReadWrite · Browse · Subscription · History ·
          NodeManagement · TranslateBrowsePath ·
          ServerInfo · TypeDiscovery

SecureChannel       message-level cryptography
Transport           TCP socket I/O
TrustStore          server certificate validation
Cache               browse / resolve / type caching, codec-gated
Event dispatcher    47 PSR-14 events at every lifecycle point
```
<!-- @endcode-block -->

The client speaks **10 security policies** — six RSA (`None`,
`Basic128Rsa15`, `Basic256`, `Basic256Sha256`, `Aes128Sha256RsaOaep`,
`Aes256Sha256RsaPss`) and four ECC (`EccNistP256`, `EccNistP384`,
`EccBrainpoolP256r1`, `EccBrainpoolP384r1`). Authentication covers
anonymous, username/password, and X.509 certificate flows.

<!-- @callout variant="info" -->
ECC support is implemented per OPC UA 1.05 spec but is **experimental**
in practice — no commercial server vendor ships ECC endpoints yet. ECC
has been validated only against the OPC Foundation's UA-.NETStandard
reference implementation. For production deployments, prefer RSA. See
[Security · Policies](./security/policies.md).
<!-- @endcallout -->

## Reading order

This documentation is organised by reader intent, not by class layout:

- **Getting started** walks you from `composer require` to a first read
  in three pages. Read it once.
- **Connection** covers endpoint discovery, the session lifecycle, and
  retry semantics. Come back here when network behaviour is the topic.
- **Operations** is one page per OPC UA service set. Use it as the
  recipe shelf for everyday work.
- **Security**, **Types**, **Observability**, **Testing** are
  topic-specific deep-dives. Reach for them when the task warrants.
- **Extensibility** is for readers wiring custom modules, codecs, or
  IPC layers.
- **Reference** is the alphabetical surface — every public method,
  exception, and enum.
- **Recipes** are short, focused walkthroughs for tasks the code does
  not auto-explain.

## Conventions in these docs

- Code samples are runnable as-is unless they say otherwise.
- `// …` in a sample means "irrelevant code elided".
- Type names use their unqualified form (`NodeId`, not
  `PhpOpcua\Client\Types\NodeId`) when context disambiguates; otherwise
  the full namespace is shown.
- Status codes use their canonical OPC UA names (`BadServiceUnsupported`,
  `Good`) — see [Reference · Enums](./reference/enums.md) for the
  numeric values.

## What this library does not do

- **Asynchronous I/O.** Every call blocks. For non-blocking integration,
  put the client behind a worker or behind `opcua-session-manager`.
- **OPC UA Pub/Sub.** Out of scope; the
  [`opcua-client-ext-pubsub`](https://github.com/php-opcua/opcua-client-ext-pubsub)
  extension is a separate package.
- **An OPC UA server.** Client-only by design — implementing a
  conformant server requires an entirely different architecture (address
  space management, session handling, subscription engine).
- **CLI tools.** Browse / read / write / trust management from the
  terminal lives in
  [`php-opcua/opcua-cli`](https://github.com/php-opcua/opcua-cli).
