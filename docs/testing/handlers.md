---
eyebrow: 'Docs · Testing'
lede:    'Handlers are how you script MockClient. Register one per node, per method, or per service — the mock dispatches your closure on every matching call.'

see_also:
  - { href: './mock-client.md',   meta: '6 min' }
  - { href: './integration.md',   meta: '5 min' }
  - { href: '../types/data-value-and-variant.md', meta: '6 min' }

prev: { label: 'MockClient',        href: './mock-client.md' }
next: { label: 'Integration tests', href: './integration.md' }
---

# Handlers

A handler is a closure you register on `MockClient` to control what
the mock returns for a specific operation. The mock comes with safe
defaults; handlers customise the cases your test cares about.

## The catalogue

`MockClient` exposes one handler-registration method per service.
Each returns `$this` for chaining:

| Method                                       | Scope                                        |
| -------------------------------------------- | -------------------------------------------- |
| `onRead(NodeId\|string $nodeId, callable $handler)` | Read for that node                     |
| `onWrite(NodeId\|string $nodeId, callable $handler)` | Write for that node                   |
| `onBrowse(NodeId\|string $nodeId, callable $handler)` | Browse from that node                |
| `onCall(NodeId\|string $objectId, NodeId\|string $methodId, callable $handler)` | Call on that method |
| `onResolveNodeId(string $path, callable $handler)` | resolveNodeId for that path           |
| `onGetEndpoints(callable $handler)`          | All getEndpoints calls                       |

All handlers receive the call's typed arguments and return the typed
result the corresponding interface method declares.

## Read handlers

<!-- @code-block language="php" label="examples/read-handler.php" -->
```php
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\NodeId;

$client = MockClient::create();

$client->onRead('ns=2;s=PLC/Speed', function (NodeId $nodeId) {
    return DataValue::ofDouble(42.5);
});

$dv = $client->read('ns=2;s=PLC/Speed');
// $dv->getValue() === 42.5
```
<!-- @endcode-block -->

The handler signature: `function (NodeId $nodeId): DataValue`. The
mock calls it for every `read()` whose NodeId matches.

### DataValue factories

The `DataValue` class ships factories that make handler bodies short:

| Factory                       | Result                                    |
| ----------------------------- | ----------------------------------------- |
| `DataValue::ofBool(bool)`     | `BuiltinType::Boolean` value              |
| `DataValue::ofInt(int)`       | `BuiltinType::Int32`                      |
| `DataValue::ofDouble(float)`  | `BuiltinType::Double`                     |
| `DataValue::ofString(string)` | `BuiltinType::String`                     |
| `DataValue::ofVariant(Variant)` | Arbitrary Variant                       |

All factories accept optional `statusCode` and timestamp arguments
when the test cares about them.

### Simulating bad status

<!-- @code-block language="php" label="return BadNodeIdUnknown" -->
```php
use PhpOpcua\Client\Types\StatusCode;
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\BuiltinType;

$client->onRead('ns=2;s=Missing', fn () =>
    new DataValue(
        value: new Variant(BuiltinType::Null, null),
        statusCode: 0x80340000,   // BadNodeIdUnknown
    )
);
```
<!-- @endcode-block -->

## Write handlers

<!-- @code-block language="php" label="examples/write-handler.php" -->
```php
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\NodeId;

$client->onWrite('ns=2;s=PLC/Setpoint', function (NodeId $nodeId, mixed $value, BuiltinType $type) {
    if ($value < 0 || $value > 100) {
        return 0x803E0000;          // BadOutOfRange
    }
    return 0;                       // Good
});

$status = $client->write('ns=2;s=PLC/Setpoint', 50);   // 0
$bad    = $client->write('ns=2;s=PLC/Setpoint', 200);  // BadOutOfRange
```
<!-- @endcode-block -->

The handler signature: `function (NodeId $nodeId, mixed $value, BuiltinType $type): int`.
Returning a status code is how the mock surfaces it to the call site.

## Browse handlers

<!-- @code-block language="php" label="examples/browse-handler.php" -->
```php
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\ReferenceDescription;
use PhpOpcua\Client\Types\QualifiedName;
use PhpOpcua\Client\Types\LocalizedText;

$client->onBrowse('ns=2;s=Devices', function (NodeId $nodeId) {
    return [
        new ReferenceDescription(
            referenceTypeId: NodeId::numeric(0, 47),         // HasComponent
            isForward: true,
            nodeId: NodeId::string(2, 'Devices/PLC1'),
            browseName: new QualifiedName(2, 'PLC1'),
            displayName: new LocalizedText('en', 'PLC 1'),
            nodeClass: NodeClass::Object,
            typeDefinition: NodeId::numeric(0, 58),
        ),
        new ReferenceDescription(
            referenceTypeId: NodeId::numeric(0, 47),
            isForward: true,
            nodeId: NodeId::string(2, 'Devices/PLC2'),
            browseName: new QualifiedName(2, 'PLC2'),
            displayName: new LocalizedText('en', 'PLC 2'),
            nodeClass: NodeClass::Object,
            typeDefinition: NodeId::numeric(0, 58),
        ),
    ];
});
```
<!-- @endcode-block -->

Returns `ReferenceDescription[]` — what `browse()` would have returned
from a real server. The mock does not paginate; the array is delivered
whole.

## Call handlers

<!-- @code-block language="php" label="examples/call-handler.php" -->
```php
use PhpOpcua\Client\Module\ReadWrite\CallResult;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\BuiltinType;

$client->onCall(
    objectId: 'ns=2;s=Heating',
    methodId: 'ns=2;s=Heating/SetTemperature',
    handler: function (NodeId $objectId, NodeId $methodId, array $args) {
        [$zone, $target] = $args;
        return new CallResult(
            statusCode: 0,
            inputArgumentResults: [0, 0],
            outputArguments: [new Variant(BuiltinType::Boolean, true)],
        );
    },
);
```
<!-- @endcode-block -->

The handler receives positional inputs and returns a `CallResult`.

## Path resolution

`onResolveNodeId()` lets you intercept the browse-path translation
path:

<!-- @code-block language="php" label="examples/resolve-handler.php" -->
```php
$client->onResolveNodeId('/Objects/Server/ServerStatus', function (string $path) {
    return NodeId::numeric(0, 2256);
});

$id = $client->resolveNodeId('/Objects/Server/ServerStatus');
// NodeId(ns=0;i=2256)
```
<!-- @endcode-block -->

Useful when application code resolves a known path and you want the
mock to return a stable answer without registering Browse handlers for
every intermediate node.

## Endpoint discovery

`onGetEndpoints()` returns a list of `EndpointDescription` instances —
the same shape a real server's GetEndpoints reply would carry:

<!-- @code-block language="php" label="examples/endpoints-handler.php" -->
```php
use PhpOpcua\Client\Types\EndpointDescription;
use PhpOpcua\Client\Types\UserTokenPolicy;
use PhpOpcua\Client\Security\SecurityMode;

$client->onGetEndpoints(function () {
    return [
        new EndpointDescription(
            endpointUrl: 'opc.tcp://mock:4840',
            securityPolicyUri: 'http://opcfoundation.org/UA/SecurityPolicy#None',
            securityMode: SecurityMode::None,
            serverCertificate: '',
            userIdentityTokens: [
                new UserTokenPolicy('anonymous', /* … */),
            ],
            transportProfileUri: '…UA-TCP UA-SC UA-Binary',
            securityLevel: 0,
        ),
    ];
});
```
<!-- @endcode-block -->

## Call tracking

Every interaction with `MockClient` — handler-registered or not — is
recorded. The accessors:

| Method                                | Returns                                            |
| ------------------------------------- | -------------------------------------------------- |
| `getCalls()`                          | All recorded calls, in order                       |
| `getCallsFor(string $method)`         | Subset matching a method name (`read`, `write`, …) |
| `callCount(string $method)`           | Count for a method                                 |
| `resetCalls()`                        | Drop the recording (handlers stay registered)     |

Each entry: `['method' => 'read', 'args' => [NodeId, 13, false]]`.
Inspect the args array to assert on arguments your test cares about.

<!-- @code-block language="php" label="assertion shape" -->
```php
expect($client->callCount('write'))->toBe(2);

$writes = $client->getCallsFor('write');
expect($writes[0]['args'][1])->toBe(42);   // first write, value argument
expect($writes[1]['args'][1])->toBe(43);   // second write
```
<!-- @endcode-block -->

`resetCalls()` between scenarios is the discipline that keeps test
assertions cheap.

## Patterns to avoid

<!-- @do-dont -->
<!-- @do -->
Register narrow handlers — one node, one method, one path. The mock
default behaviour covers the rest; narrow handlers are clear about
what the test is asserting on.
<!-- @enddo -->
<!-- @dont -->
Avoid generic catch-all handlers that branch on the NodeId inside.
They turn into untested if/else trees; if a test needs that much
mocking, it probably wants an integration test or a real protocol-
level fixture.
<!-- @enddont -->
<!-- @enddo-dont -->
