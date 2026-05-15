---
eyebrow: 'Docs · Types'
lede:    'Every node in the address space has a NodeId. It looks like a string but it''s a tuple of namespace, identifier-type, and identifier. Build them with the typed factories; rely on the string shorthand only when ergonomics demand it.'

see_also:
  - { href: './overview.md',                       meta: '4 min' }
  - { href: '../operations/resolving-paths.md',    meta: '5 min' }
  - { href: '../getting-started/thinking-in-opc-ua.md', meta: '8 min' }

prev: { label: 'Types overview',         href: './overview.md' }
next: { label: 'DataValue and Variant',  href: './data-value-and-variant.md' }
---

# NodeId

A `NodeId` is the identity of a node in the address space. It is
**three values**, not one:

- `namespaceIndex` (`int`) — which namespace
- `type` (`IdentifierType` enum) — numeric, string, GUID, or opaque byte string
- `identifier` (`int | string`) — the actual identifier

The string `"ns=2;s=Devices/PLC/Speed"` is a serialisation of that
tuple, not the canonical form.

## Factories

The four factory methods on `NodeId` are the right way to build one:

<!-- @code-block language="php" label="four factories" -->
```php
use PhpOpcua\Client\Types\NodeId;

$a = NodeId::numeric(0, 85);
// namespace 0, identifier type Numeric, identifier 85
// String form: "i=85" (namespace 0 is implicit)

$b = NodeId::string(2, 'Devices/PLC/Speed');
// namespace 2, identifier type String, identifier "Devices/PLC/Speed"
// String form: "ns=2;s=Devices/PLC/Speed"

$c = NodeId::guid(0, '72962B91-FA75-4AE6-8D28-B404DC7DAE63');
// namespace 0, identifier type GUID, identifier (parsed as 16-byte GUID)
// String form: "g=72962B91-FA75-4AE6-8D28-B404DC7DAE63"

$d = NodeId::byteString(3, hex2bin('deadbeef'));
// namespace 3, identifier type ByteString, identifier (raw bytes)
// String form: "ns=3;b=3q2+7w=="   (base64-encoded body)
```
<!-- @endcode-block -->

Factories are total — they accept any value the OPC UA wire format
allows. They do **not** validate that the resulting NodeId exists on
any specific server; that is a server-round-trip concern.

## String shorthand

Wherever the API takes `NodeId|string`, you can pass the string form
directly. The library parses it back into a `NodeId`:

<!-- @code-block language="text" label="grammar" -->
```text
NodeIdString := [namespace] identifier
namespace    := "ns=" <integer> ";"
identifier   := type "=" value
type         := "i" | "s" | "g" | "b"
```
<!-- @endcode-block -->

| Shorthand                                  | Equivalent factory call             |
| ------------------------------------------ | ----------------------------------- |
| `i=85`                                     | `NodeId::numeric(0, 85)`            |
| `ns=2;i=42`                                | `NodeId::numeric(2, 42)`            |
| `s=Hello`                                  | `NodeId::string(0, 'Hello')`        |
| `ns=2;s=Devices/PLC/Speed`                 | `NodeId::string(2, 'Devices/PLC/Speed')` |
| `g=72962B91-FA75-4AE6-8D28-B404DC7DAE63`   | `NodeId::guid(0, '…')`              |
| `ns=3;b=3q2+7w==`                          | `NodeId::byteString(3, hex2bin('deadbeef'))` |

The namespace defaults to `0` when `ns=N;` is omitted.

### When to use the shorthand

- **In application code:** when the NodeId is a constant referenced
  literally. `$client->read('i=2261')` is more readable than
  `NodeId::numeric(0, 2261)`.
- **In hot loops:** avoid it. Each call re-parses the string. Build
  the `NodeId` once.
- **In configuration files:** great. Strings round-trip through
  YAML/JSON, `NodeId` instances do not.

### When **not** to use the shorthand

- The identifier itself contains characters that look like the grammar
  (`/`, `;`, `=`). Use the factory.
- The identifier starts with `/`. The path-vs-NodeId dispatcher
  routes leading slashes to `translateBrowsePaths`, not to NodeId
  parsing.
- The string was supplied by an untrusted source. The factory is
  parse-clean; the shorthand parser is not adversarial.

## The dispatch heuristic

Every API that accepts `NodeId|string` uses the same dispatcher:

<!-- @code-block language="text" label="dispatcher logic" -->
```text
matches /^(ns=\d+;)?[isgb]=/    → parse as NodeId
contains '/'                    → resolveNodeId() (browse path)
else                            → InvalidNodeIdException
```
<!-- @endcode-block -->

That makes `'ns=2;s=Devices/PLC/Speed'` unambiguously a NodeId — the
`s=` prefix wins. And `'/Objects/Server'` is unambiguously a browse
path — there's no `[isgb]=` after the slash. The ambiguous edge case
(a string identifier whose value starts with `/`) is rare; build the
`NodeId` instance and pass the object, not the string.

## Equality and hashing

`NodeId` implements value-based equality through its properties:

<!-- @code-block language="php" label="comparing node ids" -->
```php
$a = NodeId::numeric(2, 1001);
$b = NodeId::numeric(2, 1001);

$a === $b;            // false — different PHP objects
$a == $b;             // true  — value equality

(string) $a;          // "ns=2;i=1001"
(string) $b;          // "ns=2;i=1001"
```
<!-- @endcode-block -->

For hashing or use as an array key, cast to string. The string form is
canonical and round-trips cleanly through the shorthand parser.

## Well-known NodeIds

Namespace 0 reserves identifiers for the OPC UA standard. The ones you
will hit most often:

| Identifier  | NodeId    | What                                |
| ----------- | --------- | ----------------------------------- |
| 84          | `i=84`    | Root folder                         |
| 85          | `i=85`    | Objects folder                      |
| 86          | `i=86`    | Types folder                        |
| 87          | `i=87`    | Views folder                        |
| 2253        | `i=2253`  | Server object                       |
| 2255        | `i=2255`  | Server.NamespaceArray               |
| 2256        | `i=2256`  | Server.ServerStatus                 |
| 2261        | `i=2261`  | Server.ServerStatus.BuildInfo.ProductName |
| 2262        | `i=2262`  | …ManufacturerName                   |
| 2263        | `i=2263`  | …SoftwareVersion                    |
| 2264        | `i=2264`  | …BuildNumber                        |
| 2265        | `i=2265`  | …BuildDate                          |
| 35          | `i=35`    | Organizes reference type            |
| 46          | `i=46`    | HasProperty reference type          |
| 47          | `i=47`    | HasComponent reference type         |
| 63          | `i=63`    | BaseDataVariableType                |
| 2330        | `i=2330`  | HistoryServerCapabilities           |

For the BuildInfo set, the convenience methods on the client
(`getServerProductName()`, …) save you from memorising the IDs. For
arbitrary lookups, the OPC Foundation publishes a CSV with the entire
namespace 0 catalogue.
