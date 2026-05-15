---
eyebrow: 'Docs · Operations'
lede:    'Add, delete, and re-reference nodes at runtime. The service set is in the client by default — but most servers do not implement it. Catch ServiceUnsupportedException first, succeed second.'

see_also:
  - { href: '../recipes/service-unsupported.md',  meta: '4 min' }
  - { href: '../types/node-id.md',                meta: '5 min' }
  - { href: '../reference/exceptions.md',         meta: '7 min' }

prev: { label: 'History reads',     href: './history-reads.md' }
next: { label: 'Security overview', href: '../security/overview.md' }
---

# Managing nodes

The OPC UA `NodeManagement` service set lets a client create and
destroy nodes and references at runtime. The library exposes four
methods on the client surface:

| Method             | What it does                                          |
| ------------------ | ----------------------------------------------------- |
| `addNodes()`       | Create one or more nodes under a parent               |
| `deleteNodes()`    | Remove existing nodes                                 |
| `addReferences()`  | Wire arbitrary references between nodes               |
| `deleteReferences()` | Remove references                                   |

These are the **only** OPC UA mutation primitives. There is no
`MoveNode`, no `RenameNode` — composing add + delete is the path.

## Server support

`NodeManagement` is **optional** in the OPC UA spec. Many production
servers (especially PLC-embedded ones) do not implement it. When that
is the case, the very first call to any of the four methods raises:

<!-- @code-block language="php" label="capability detection" -->
```php
use PhpOpcua\Client\Exception\ServiceUnsupportedException;

try {
    $client->addNodes([/* … */]);
} catch (ServiceUnsupportedException $e) {
    // Server does not implement NodeManagement (BadServiceUnsupported 0x800B0000).
    // Fall back to whatever pattern your application uses for static address spaces.
}
```
<!-- @endcode-block -->

`ServiceUnsupportedException` extends `ServiceException`, so callers
that catch the parent class still match. The exception fires on the
**first** unsupported call; subsequent calls behave identically — there
is no in-client capability cache. Wrap the first attempt in a guard
and remember the answer yourself if you call these methods often. See
[Recipes · Handling unsupported
services](../recipes/service-unsupported.md).

<!-- @callout variant="info" -->
The library is tested against `open62541 v1.4.8 (ci_server)` for the
NodeManagement integration suite — open62541 enables this service set
by default. UA-.NETStandard, which powers most of the
`uanetstandard-test-suite` servers, does **not** implement it. Plan
your reference server accordingly.
<!-- @endcallout -->

## addNodes

<!-- @method name="$client->addNodes(array \$nodesToAdd): array" returns="AddNodesResult[]" visibility="public" -->

Creates one or more nodes. Each entry in `$nodesToAdd` describes a
node by its class and parent reference:

<!-- @code-block language="php" label="examples/add-variable.php" -->
```php
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\NodeClass;

$results = $client->addNodes([
    [
        'parentNodeId'     => 'i=85',                    // Objects folder
        'referenceTypeId'  => 'i=35',                    // Organizes
        'requestedNewNodeId' => 'ns=1;s=Counter',        // optional — server may assign
        'browseName'       => '1:Counter',
        'nodeClass'        => NodeClass::Variable,
        'attributes' => [
            'displayName' => 'Counter',
            'description' => 'Boot-counter incremented by AddNodes',
            'dataType'    => 'i=6',                      // Int32
            'value'       => 0,
            'valueRank'   => -1,                         // scalar
            'accessLevel' => 3,                          // CurrentRead | CurrentWrite
        ],
        'typeDefinition' => 'i=63',                      // BaseDataVariableType
    ],
]);

foreach ($results as $r) {
    if ($r->statusCode === 0) {
        echo "Created: " . $r->addedNodeId . "\n";
    } else {
        echo "Failed: " . dechex($r->statusCode) . "\n";
    }
}
```
<!-- @endcode-block -->

`AddNodesResult`:

| Field           | Meaning                                                  |
| --------------- | -------------------------------------------------------- |
| `statusCode`    | Per-node result. `0` = `Good`.                           |
| `addedNodeId`   | The NodeId the server assigned (matches `requestedNewNodeId` when accepted, otherwise server-chosen). |

### Supported node classes

All eight OPC UA node classes are supported. Class-specific attributes
are encoded automatically as the appropriate `ExtensionObject`
(`ObjectAttributes`, `VariableAttributes`, etc.):

| `NodeClass`        | Class-specific attributes (key fields)               |
| ------------------ | ----------------------------------------------------- |
| `Object`           | `eventNotifier`                                       |
| `Variable`         | `dataType`, `value`, `valueRank`, `arrayDimensions`, `accessLevel`, `userAccessLevel`, `minimumSamplingInterval`, `historizing` |
| `Method`           | `executable`, `userExecutable`                        |
| `ObjectType`       | `isAbstract`                                          |
| `VariableType`     | `dataType`, `valueRank`, `value`, `isAbstract`        |
| `ReferenceType`    | `isAbstract`, `symmetric`, `inverseName`              |
| `DataType`         | `isAbstract`                                          |
| `View`             | `containsNoLoops`, `eventNotifier`                    |

Common attributes (`displayName`, `description`, `writeMask`,
`userWriteMask`) apply to every class. Omitting an attribute lets the
server pick its default.

## deleteNodes

<!-- @method name="$client->deleteNodes(array \$nodesToDelete): array" returns="int[]" visibility="public" -->

<!-- @code-block language="php" label="delete with reference cleanup" -->
```php
$statuses = $client->deleteNodes([
    ['nodeId' => 'ns=1;s=Counter', 'deleteTargetReferences' => true],
]);
```
<!-- @endcode-block -->

- `deleteTargetReferences: true` removes references that **point at**
  the deleted node from elsewhere — keeps the address space
  consistent. `false` leaves dangling references and is rarely what
  you want.
- The return is a parallel `int[]` of per-node status codes.

## addReferences

<!-- @method name="$client->addReferences(array \$referencesToAdd): array" returns="int[]" visibility="public" -->

<!-- @code-block language="php" label="link two existing nodes" -->
```php
$client->addReferences([
    [
        'sourceNodeId'     => 'ns=1;s=Counter',
        'referenceTypeId'  => 'i=46',                      // HasProperty
        'targetNodeId'     => 'ns=1;s=Counter.Description',
        'isForward'        => true,
        'targetNodeClass'  => NodeClass::Variable,
    ],
]);
```
<!-- @endcode-block -->

## deleteReferences

<!-- @method name="$client->deleteReferences(array \$referencesToDelete): array" returns="int[]" visibility="public" -->

<!-- @code-block language="php" label="remove a reference" -->
```php
$client->deleteReferences([
    [
        'sourceNodeId'      => 'ns=1;s=Counter',
        'referenceTypeId'   => 'i=46',
        'targetNodeId'      => 'ns=1;s=Counter.Description',
        'isForward'         => true,
        'deleteBidirectional' => true,
    ],
]);
```
<!-- @endcode-block -->

`deleteBidirectional: true` also removes the inverse reference on the
target node — usually what you want.

## Authorisation and audit

Every call runs in the session's authorisation context. A session
authenticated as anonymous on a hardened server will see
`BadUserAccessDenied` per item. The four operations are also commonly
audited server-side — expect a paper trail.

## Failure modes per item

| StatusCode                       | Meaning                                                |
| -------------------------------- | ------------------------------------------------------ |
| `BadParentNodeIdInvalid`         | Parent does not exist                                  |
| `BadReferenceTypeIdInvalid`      | Reference type is unknown                              |
| `BadNodeIdExists`                | `requestedNewNodeId` is taken                          |
| `BadBrowseNameInvalid`           | BrowseName is not unique among siblings                |
| `BadTypeDefinitionInvalid`       | TypeDefinition is missing or wrong                     |
| `BadNodeAttributesInvalid`       | Class-specific attributes are malformed                |
| `BadUserAccessDenied`            | Session is not authorised                              |
