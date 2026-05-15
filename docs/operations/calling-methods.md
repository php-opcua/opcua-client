---
eyebrow: 'Docs · Operations'
lede:    'OPC UA method calls invoke server-side procedures. The arguments and return values are positional and strongly typed; the call() API surfaces that shape directly.'

see_also:
  - { href: './resolving-paths.md',   meta: '5 min' }
  - { href: '../types/data-value-and-variant.md', meta: '6 min' }
  - { href: '../reference/exceptions.md', meta: '7 min' }

prev: { label: 'Resolving paths', href: './resolving-paths.md' }
next: { label: 'Subscriptions',   href: './subscriptions.md' }
---

# Calling methods

An OPC UA method is a node with `NodeClass::Method`, attached to an
**object** node via a `HasComponent` reference. Invoking it requires
two NodeIds — the object the method belongs to (the receiver) and the
method itself — plus a positional list of `Variant`-typed input
arguments. The server returns a `CallResult` containing per-argument
input status codes and a positional list of output `Variant` values.

## Basic call

<!-- @method name="$client->call(NodeId|string \$objectId, NodeId|string \$methodId, array \$inputArguments = []): CallResult" returns="CallResult" visibility="public" -->

<!-- @code-block language="php" label="examples/call-no-args.php" -->
```php
// A method with no arguments — e.g. /Server/ServerCommands/Reset
$result = $client->call(
    objectId:  'ns=2;s=Devices/PLC',
    methodId:  'ns=2;s=Devices/PLC/Reset',
);

if ($result->statusCode === 0) {
    echo "Reset accepted\n";
}
```
<!-- @endcode-block -->

<!-- @params -->
<!-- @param name="$objectId" type="NodeId|string" required -->
The object node the method belongs to. The server uses this to
disambiguate when the same method NodeId is shared across multiple
objects.
<!-- @endparam -->
<!-- @param name="$methodId" type="NodeId|string" required -->
The method node itself.
<!-- @endparam -->
<!-- @param name="$inputArguments" type="array" default="[]" -->
Positional list of PHP values. Each value is wrapped in a `Variant`
with the type the server declared in its `InputArguments` property. If
auto-detect is off and you need strict control, pass `Variant`
instances directly.
<!-- @endparam -->
<!-- @endparams -->

The returned `CallResult`:

| Property                | Type        | Meaning                                       |
| ----------------------- | ----------- | --------------------------------------------- |
| `statusCode`            | `int`       | Overall call status. `0` = `Good`.            |
| `inputArgumentResults`  | `int[]`     | One status per input argument. All `Good` when overall status is `Good`; useful for diagnosing `BadInvalidArgument`. |
| `outputArguments`       | `Variant[]` | The return values, in declaration order.      |

## With arguments

<!-- @code-block language="php" label="examples/call-with-args.php" -->
```php
// A method declared as: SetTemperature(zone: Int32, target: Double): Boolean
$result = $client->call(
    objectId:       'ns=2;s=Heating',
    methodId:       'ns=2;s=Heating/SetTemperature',
    inputArguments: [3, 22.5],
);

if ($result->statusCode === 0) {
    /** @var bool $ack */
    $ack = $result->outputArguments[0]->value;
}
```
<!-- @endcode-block -->

The PHP type is mapped to the OPC UA `BuiltinType` declared by the
method's `InputArguments` property. The client does not currently read
that property automatically — when a method expects an unusual type
(e.g. `UInt64`, a `String[]` array, or an `ExtensionObject`), pass an
explicit `Variant`:

<!-- @code-block language="php" label="explicit Variant arguments" -->
```php
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\Variant;

$args = [
    new Variant(BuiltinType::String, 'manual'),
    new Variant(BuiltinType::Int32, 3, dimensions: null),   // scalar
    new Variant(BuiltinType::Double, [1.0, 2.0, 3.0]),      // 1-D array
];

$result = $client->call(/* … */, inputArguments: $args);
```
<!-- @endcode-block -->

See [Types · DataValue and Variant](../types/data-value-and-variant.md)
for the array / scalar / multidimensional encoding rules.

## Discovering methods

The simplest path: browse the object, filter for `NodeClass::Method`:

<!-- @code-block language="php" label="list methods on an object" -->
```php
use PhpOpcua\Client\Types\NodeClass;

$methods = $client->browse(
    $objectId,
    nodeClassMask: NodeClass::Method->value,
);

foreach ($methods as $m) {
    echo "{$m->displayName->text}  ({$m->nodeId})\n";
}
```
<!-- @endcode-block -->

To read a method's argument signature, read its `InputArguments` and
`OutputArguments` properties (children with those `BrowseName`s).
Each is an array of `Argument` `ExtensionObject`s — for now, decoded
to raw bytes; extracting the field-level structure requires registering
the `Argument` codec manually. See [Extensibility · Extension object
codecs](../extensibility/extension-object-codecs.md).

## Error handling

`call()` itself raises on transport / channel / session failures (see
[Reference · Exceptions](../reference/exceptions.md)). Semantic
failures are surfaced through `statusCode`:

| StatusCode                       | Meaning                                  |
| -------------------------------- | ---------------------------------------- |
| `BadMethodInvalid`               | `$methodId` is not a method on `$objectId` |
| `BadArgumentsMissing`            | Too few input arguments                  |
| `BadTooManyArguments`            | Too many input arguments                 |
| `BadInvalidArgument`             | One or more inputs failed validation — inspect `inputArgumentResults` |
| `BadUserAccessDenied`            | Session is not authorised                |
| `BadNothingToDo`                 | The method had no effect (server-defined) |

<!-- @callout variant="tip" -->
A `BadInvalidArgument` overall status with `inputArgumentResults`
flagging only the last entry usually means the method signature
expects a type you did not pass — wrap that argument in an explicit
`Variant` with the right `BuiltinType`.
<!-- @endcallout -->

## What about asynchronous methods?

The library only supports the synchronous OPC UA `Call` service. The
asynchronous variant — where a server schedules the method and returns
later via a subscription — is rare in practice and not exposed here.
For long-running operations on the server, prefer:

- A synchronous `call()` that returns a request ID, followed by polling
  a status node via `read()`.
- A subscription on a status node that the server updates as the
  operation progresses. See [Operations ·
  Subscriptions](./subscriptions.md).
