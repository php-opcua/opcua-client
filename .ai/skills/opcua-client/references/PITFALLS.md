# Pitfalls reference

Mistakes AI coding assistants frequently make with `opcua-client`. Read before generating code.

## 1. Using deprecated getter style instead of public readonly properties

**Wrong** (uses deprecated, still-functional getters):
```php
$id = $ref->getNodeId();
$ts = $dv->getSourceTimestamp();
$sid = $subscriptionResult->getSubscriptionId();
```

**Right** (public readonly):
```php
$id = $ref->nodeId;
$ts = $dv->sourceTimestamp;
$sid = $subscriptionResult->subscriptionId;
```

Exception: `DataValue::getValue()` IS the canonical method — it unwraps the underlying `Variant` AND auto-decodes registered `ExtensionObject` values. Don't replace it with property access (the inner `Variant` is private). To get the OPC UA data type of the value, use the v4.4.0 additions `$dv->type` (public readonly `?BuiltinType`) or the symmetric `$dv->getType()` — both replace the older `$dv->getVariant()->type` chain.

## 2. Treating result DTOs as arrays

**Wrong**:
```php
echo $result['subscriptionId'];          // Error — DTOs are objects, not arrays
echo $publishResult['notifications'][0]['dataValue']->getValue();
```

**Right**:
```php
echo $result->subscriptionId;
foreach ($publishResult->notifications as $notif) {
    echo $notif['dataValue']->getValue();  // BUT $notifications IS an array of notification arrays
}
```

`PublishResult::$notifications` is an `array<int, array{monitoredItemId: int, dataValue: DataValue}>`. Each element is an associative array. The DataValue inside IS an object.

## 3. Constructing NodeIds via string concatenation

**Wrong**:
```php
$nodeId = 'ns=' . $namespace . ';i=' . $id;
$nodeId = "ns={$ns};s=" . urlencode($name);     // urlencode is unnecessary and wrong
```

**Right**:
```php
$nodeId = NodeId::numeric($namespace, $id);     // typed factory
// or
$nodeId = NodeId::string($namespace, $name);
// or, when the user is OK with strings:
$nodeId = "ns={$ns};i={$id}";                    // literal — NodeId::parse() handles it
$nodeId = "ns={$ns};s={$name}";                  // no encoding needed
```

OPC UA NodeId strings do NOT need URL-encoding. Special characters inside the identifier are valid.

## 4. Creating a new ClientBuilder per request

**Wrong**:
```php
function readSensor(string $nodeId): float {
    $client = ClientBuilder::create()
        ->connect('opc.tcp://...');
    $value = $client->read($nodeId)->getValue();
    $client->disconnect();
    return $value;
}
```

Each call does CreateSession + ActivateSession (~150ms minimum). On a busy app this kills throughput.

**Right** (option A — keep the client alive in the request):
```php
class SensorService {
    private OpcUaClientInterface $client;

    public function __construct() {
        $this->client = ClientBuilder::create()->connect('opc.tcp://...');
    }

    public function readSensor(string $nodeId): float {
        return $this->client->read($nodeId)->getValue();
    }

    public function __destruct() {
        // DON'T call disconnect() in __destruct — see pitfall #5.
    }
}
```

**Right** (option B — use the session-manager daemon for cross-request persistence):
```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

$value = Opcua::connect()->read($nodeId)->getValue();
// ManagedClient hands off to a long-running daemon — connection is reused.
```

## 5. Calling `disconnect()` from `__destruct`

**Wrong**:
```php
class Service {
    public function __destruct() {
        $this->client->disconnect();             // PHP destructor order is non-deterministic
    }
}
```

PHP doesn't guarantee destructor ordering. The transport may have been torn down first, or the script may be in shutdown phase where socket I/O fails. You'll see `ConnectionException` in your error log without any actionable info.

**Right**: call `disconnect()` from a `finally` block at the call site, OR rely on the script ending naturally (the OS closes the socket).

```php
$client = ClientBuilder::create()->connect('...');
try {
    // work
} finally {
    $client->disconnect();
}
```

## 6. Ignoring `statusCode` on reads

**Wrong**:
```php
$temp = $client->read('ns=2;s=Temp')->getValue();
if ($temp > 100) { /* alarm */ }                  // What if statusCode is Uncertain?
```

A "Good" read isn't the same as "the value is reliable". OPC UA defines `Uncertain_*` codes (sensor not yet calibrated, last-known-value being served, etc.). For control logic, check `$dv->statusCode`:

```php
use PhpOpcua\Client\Types\StatusCode;

$dv = $client->read('ns=2;s=Temp');
if (!StatusCode::isGood($dv->statusCode)) {
    // log + fallback
    return;
}
$temp = $dv->getValue();
```

`StatusCode::isGood($code)` returns true only when severity bits == 0. `isUncertain()` and `isBad()` for the other ranges.

## 7. Sending raw subscriptions instead of monitored items

**Wrong** — creating a subscription expecting it to emit values:
```php
$sub = $client->createSubscription();
foreach ($client->publish()->notifications as $n) {   // notifications is always empty
    // ...
}
```

A bare `CreateSubscription` notifies on nothing. You must register at least one `MonitoredItem` against the subscriptionId.

**Right**:
```php
$sub = $client->createSubscription(publishingInterval: 500.0);
$client->createMonitoredItems($sub->subscriptionId)
    ->add('ns=2;s=Temp')
    ->execute();
// THEN publish() returns notifications
```

## 8. Tight publish loop without pacing

**Wrong**:
```php
while (true) {
    $response = $client->publish();
    foreach ($response->notifications as $n) { /* ... */ }
    // No sleep — burns CPU + bandwidth, server may close the channel
}
```

`publish()` is meant to be called paced — typically once per publishing interval. The server processes it as a long-poll: it blocks until either notifications are ready or the configured publishing interval elapses (~typically ~1s).

**Right**:
```php
while ($running) {
    $response = $client->publish();
    foreach ($response->notifications as $n) { /* ... */ }
    if (!$response->moreNotifications) {
        usleep(100_000);                         // 100ms breath
    }
}
```

Or use the session-manager daemon's `auto-publish` mode (Laravel / Symfony integrations expose this).

## 9. Using `unserialize()` on cache values or IPC payloads

**Forbidden by design**:
```php
$obj = unserialize($cache->get($key));            // NEVER — gadget-chain risk
```

The cache codec is `WireCacheCodec` (JSON gated by the wire allowlist). Always use:
```php
$obj = $client->getCacheCodec()->decode($cache->get($key));
```

Or just let the client manage the cache — `setCache(...)` does the right thing automatically.

## 10. Confusing Application Certificate vs TLS Client Certificate

These are different concepts:

| Concept | What it secures | Where set |
| --- | --- | --- |
| OPC UA Application Certificate | The OPC UA secure channel (message-level sign/encrypt + CreateSession ClientCertificate field) | `ClientBuilder::setClientCertificate(certPath, keyPath, caCertPath)` |
| TLS Client Certificate | The TLS handshake when using `opc.https://` (mTLS) | `CurlHttpClient(clientCertPath: ..., clientKeyPath: ...)` in the ext-transport-https package |

A user asking "set up mTLS for my HTTPS connection" wants the second. A user asking "configure security for `opc.tcp://`" wants the first.

## 11. Iterating notifications without checking `moreNotifications`

`PublishResult::$moreNotifications === true` means the server has additional notification messages queued and the next `publish()` will return them immediately (no waiting). When `false`, the next call will block up to the publishing interval.

This matters for batch consumers — call `publish()` in a loop until `moreNotifications === false`, then sleep.

## 12. Hard-coding numeric NodeIds without comments

**Wrong**:
```php
$client->browse('i=85');
$client->read('i=2259');
```

Numbers without context are noise. Either:
- Use a comment: `$client->browse('i=85');  // Objects folder (well-known)`
- Or use the constants in `src/Protocol/ServiceTypeId.php` for service NodeIds, or `Types/StandardNodeIds.php` (if present) for well-known nodes.

Well-known IDs that show up often:
- `i=85` — Objects folder
- `i=86` — Types folder
- `i=2253` — Server (well-known object node)
- `i=2259` — Server.ServerStatus.State (Int32 — 0=Running, 1=Failed, 2=NoConfiguration, 3=Suspended, 4=Shutdown, 5=Test, 6=CommunicationFault, 7=Unknown)

## 13. Mixing AttributeId enum with raw integers

**Wrong**:
```php
$client->read('i=2259', 13);                       // 13 = Value, but reading an int is unclear
```

**Right**:
```php
use PhpOpcua\Client\Types\AttributeId;

$client->read('i=2259', AttributeId::Value);
$client->read('i=2259', AttributeId::DisplayName);
$client->read('i=2259', AttributeId::DataType);
```

## 14. Building a transport instance directly when configuration is meant for the builder

**Wrong**:
```php
$transport = new TcpTransport();
$transport->setReceiveBufferSize(65535);
$builder->setTransport($transport);
$builder->setTimeout(30.0);                        // Doesn't propagate to the transport you already built
```

Timeout and buffer size are configured by the builder during `connect()`. Don't pre-build a transport unless you're using one of the extensions (`HttpsTransport`, reverse-connect bridge) — those have their own constructor args.

**Right** for default TCP — don't call `setTransport()` at all:
```php
$client = ClientBuilder::create()
    ->setTimeout(30.0)
    ->connect('opc.tcp://...');                    // Builder constructs TcpTransport internally
```

## 15. Testing against real TCP instead of MockClient

**Wrong** (in a unit test):
```php
public function test_reads_temperature(): void {
    $client = ClientBuilder::create()->connect('opc.tcp://localhost:4840');
    // Requires a running server — fragile, slow, env-dependent.
}
```

**Right**:
```php
use PhpOpcua\Client\Testing\MockClient;

public function test_reads_temperature(): void {
    $client = MockClient::create()
        ->onRead('ns=2;s=Temp', new DataValue(new Variant(BuiltinType::Double, 22.5)));

    $value = $client->read('ns=2;s=Temp')->getValue();
    $this->assertEquals(22.5, $value);
    $this->assertCount(1, $client->getReadCalls());
}
```

See `references/TESTING.md`. Integration tests are tagged `->group('integration')` and require `uanetstandard-test-suite` running.

## 16. Forgetting `discoverDataTypes()` when reading custom structures

Servers expose proprietary structures via OPC UA's `DataType` system (since 1.04). Without `discoverDataTypes()`, reading such nodes returns a raw `ExtensionObject` (binary blob).

**Wrong**:
```php
$result = $client->read('ns=2;s=ComplexStruct')->getValue();
// $result is a raw ExtensionObject — body is the binary payload
```

**Right**:
```php
$client->discoverDataTypes();                        // Once per connection (cached)
$result = $client->read('ns=2;s=ComplexStruct')->getValue();
// $result is a typed PHP object with decoded fields
```

For non-1.04 servers or non-spec types, register a codec manually:
```php
$client->getRepository()->register(new MyCodec());
```

## 17. Generating code that imports module-specific types from the wrong namespace

The DTOs are colocated with their modules:

- `SubscriptionResult`, `MonitoredItemResult`, `PublishResult`, `TransferResult` → `PhpOpcua\Client\Module\Subscription\`
- `CallResult` → `PhpOpcua\Client\Module\ReadWrite\`
- `BrowseResultSet` → `PhpOpcua\Client\Module\Browse\`
- `BrowsePathResult`, `BrowsePathTarget` → `PhpOpcua\Client\Module\TranslateBrowsePath\`
- `AddNodesResult` → `PhpOpcua\Client\Module\NodeManagement\`
- `BuildInfo` → `PhpOpcua\Client\Module\ServerInfo\`
- `HistoryUpdateResult`, `PerformUpdateType` → `PhpOpcua\Client\Module\History\`
- `AggregateFunction`, `AggregateOptions`, `Interval` → `PhpOpcua\Client\Module\Aggregate\`

Shared types (NodeId, Variant, DataValue, ExtensionObject, etc.) live in `PhpOpcua\Client\Types\`. Don't put module DTOs there.

## 18. Mixing v4.4.0 features with v4.3.x signature assumptions

If the user's `composer.json` constrains `php-opcua/opcua-client: ^4.3`, do NOT generate code that uses:
- `AggregateModule` methods (`aggregate()`, `historyAggregate()`)
- `HistoryUpdate` methods (`historyInsertData()`, `historyReplaceData()`, etc.)
- `FileTransfer` methods
- `ClientTransportInterface::createProbe()` / `isSecureChannelExternal()` (these are v4.4.0)
- The ext packages (`opcua-client-ext-reverse-connect`, `opcua-client-ext-transport-https`) — these require ^4.4

Check the user's `composer.json` first, OR explicitly ask which v4.x they're on.
