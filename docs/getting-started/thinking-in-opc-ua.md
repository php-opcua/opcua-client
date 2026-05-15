---
eyebrow: 'Docs · Getting started'
lede:    'OPC UA is a different model from REST, gRPC, or MQTT. Before reaching for the API, internalise the four ideas it stands on — address space, services, sessions, and channels.'

see_also:
  - { href: '../connection/endpoints-and-discovery.md', meta: '6 min' }
  - { href: '../types/node-id.md',                      meta: '5 min' }
  - { href: 'https://opcfoundation.org/specs/part3',    meta: 'external', label: 'OPC UA Part 3 — Address Space Model' }

prev: { label: 'Quick start',                href: './quick-start.md' }
next: { label: 'Endpoints and discovery',    href: '../connection/endpoints-and-discovery.md' }
---

# Thinking in OPC UA

Most PHP developers reach this library after working with REST, gRPC, or
maybe MQTT. OPC UA's mental model is different enough that pattern-
matching from those will mislead you. This page is the twenty-minute
primer. The rest of the documentation assumes you've read it.

## The four ideas

OPC UA is built on four primitives:

1. **Address space** — a typed, hierarchical graph of nodes exposed by
   the server.
2. **Services** — a fixed catalogue of remote operations (Read, Write,
   Browse, Call, CreateSubscription, …) that act on the address space.
3. **Session** — an authenticated, stateful bind to a server. All
   services run inside a session.
4. **Secure channel** — the cryptographic envelope under which the
   session's messages travel.

Every other concept (subscriptions, monitored items, history reads,
events, alarms) is built from those four.

## Address space

An OPC UA server exposes its data as a graph of **nodes**. Each node has
an identity (`NodeId`), a class (`NodeClass`), and a fixed set of
**attributes**. Two examples:

<!-- @code-block language="text" label="two example nodes" -->
```text
NodeId          ns=0;i=2261                       (Server_ServerStatus_BuildInfo_ProductName)
NodeClass       Variable
Attributes
  DisplayName   "ProductName"
  BrowseName    "ProductName"
  DataType      ns=0;i=12     (String)
  Value         "open62541 OPC UA Server"

NodeId          ns=2;s=Devices/PLC/Conveyor1/Speed
NodeClass       Variable
Attributes
  DisplayName   "Speed"
  DataType      ns=0;i=11     (Double)
  Value         42.7
```
<!-- @endcode-block -->

Two things to absorb:

- **A NodeId is not a path.** It is an opaque, server-assigned identity
  shaped as `ns=<namespace>;<type>=<identifier>`. The string segment
  after `s=` can contain slashes that look like a path but are
  meaningful only to the server — they are not a folder hierarchy.
- **The "value" of a node is one of its attributes.** Calling
  `$client->read($nodeId)` returns the `Value` attribute by default;
  calling `$client->read($nodeId, AttributeId::DisplayName)` returns a
  different attribute of the same node. See [Operations · Reading
  attributes](../operations/reading-attributes.md).

Nodes link to other nodes via **references**. A `Variable` node points
at its `DataType` node; an `Object` node points at its children via
`HasComponent` references; type nodes point at their supertypes via
`HasSubtype`. Browsing the address space means walking these references.

### Namespaces

Every NodeId carries a **namespace index**. Namespace `0` is reserved
for the OPC UA standard (the well-known IDs — `Objects = 85`,
`Server_ServerStatus = 2256`, the `BuiltinType` enum at `1`–`25`).
Namespaces `≥ 1` are vendor- or application-defined; a server publishes
its namespace table at startup. The same identifier in different
namespaces refers to **different** nodes.

When you talk to a real device, you will almost always work in a
non-zero namespace. The discovery flow is: connect → browse the
namespace-array node (`i=2255`) → know which index your device lives
under.

<!-- @callout variant="note" -->
The namespace index is **not stable across servers** of the same vendor
unless the server explicitly publishes a deterministic namespace table.
Code that hardcodes `ns=2` is brittle; resolve the index by URI at
startup instead.
<!-- @endcallout -->

## Services

OPC UA does not have arbitrary RPC. It has a **fixed catalogue of
services**, each defined by Part 4 of the specification. The ones this
library exposes:

| Service               | Method                              |
| --------------------- | ----------------------------------- |
| Read                  | `read()`, `readMulti()`             |
| Write                 | `write()`, `writeMulti()`           |
| Browse / BrowseNext   | `browse()`, `browseAll()`, `browseRecursive()` |
| TranslateBrowsePathsToNodeIds | `translateBrowsePaths()`, `resolveNodeId()` |
| Call                  | `call()`                            |
| CreateSubscription / Publish | `createSubscription()`, `publish()` |
| CreateMonitoredItems  | `createMonitoredItems()`            |
| HistoryRead           | `historyReadRaw()`, `historyReadProcessed()`, `historyReadAtTime()` |
| AddNodes / DeleteNodes / AddReferences / DeleteReferences | `addNodes()`, `deleteNodes()`, `addReferences()`, `deleteReferences()` |

Each service maps to one request type encoded as binary. Each returns a
typed PHP DTO with a `StatusCode` and the payload. There is no
free-form query language — if a service set does not cover your need,
the answer is to compose existing services, not to extend the protocol.

## Session

A **session** is the authenticated, stateful context inside which all
service calls run. Two things make sessions important to remember:

- A session has identity. The server tracks it by an opaque
  authentication token returned at session activation; the client sends
  that token with every subsequent request.
- A session has lifetime. The server may expire it on inactivity
  (typically minutes), at which point further requests fail with
  `BadSessionIdInvalid`. Calling `disconnect()` ends the session
  cleanly.

`ClientBuilder::connect()` creates and activates a session for you.
There is no public "open session" call. See [Connection · Opening and
closing](../connection/opening-and-closing.md) for the state machine.

## Secure channel

Underneath the session sits the **secure channel** — the cryptographic
layer. It does three things:

- **Sign** every message (HMAC-SHA256 for Sign mode, ECDSA / HMAC for
  ECC).
- **Encrypt** messages in `SignAndEncrypt` mode (AES-CBC for RSA, AES
  for ECC).
- **Number every message** with a strictly-monotonic sequence. Replay
  is rejected at the channel layer, not at the session layer.

The channel is opened **before** the session and closed **after** it.
You pick a `SecurityPolicy` (algorithm suite) and a `SecurityMode`
(`None` / `Sign` / `SignAndEncrypt`) on the builder; the channel
negotiates the rest with the server during the OPN exchange. See
[Security · Overview](../security/overview.md).

## Putting it together

A round-trip from PHP looks like this:

<!-- @code-block language="text" label="layered call path" -->
```text
$client->read($nodeId)            ← your code
   │
   ▼
Session     · pack ReadRequest, attach auth token
   │
   ▼
Secure channel · sign, encrypt, sequence-number, frame
   │
   ▼
Transport   · TCP socket write / read
   │
   ▼ (server)
   ▲
Transport   · TCP socket read / write
   │
   ▲
Secure channel · verify, decrypt, unframe
   │
   ▲
Session     · unpack ReadResponse → DataValue
   │
$client->read($nodeId) returns DataValue
```
<!-- @endcode-block -->

Everything else this library does is a refinement of those layers.

## What to read next

- [Connection · Endpoints and discovery](../connection/endpoints-and-discovery.md) — how the client finds out which security policies a server supports before opening the channel.
- [Types · NodeId](../types/node-id.md) — the grammar, the factory methods, and the encoding rules.
- [Operations · Browsing](../operations/browsing.md) — the practical face of address-space navigation.
