# OPC UA PHP Client — AI Skills Reference

> Task-oriented recipes for AI coding assistants. Feed this file to your AI (Claude, Cursor, Copilot, GPT, etc.) so it knows how to use the php-opcua ecosystem correctly.

## How to use this file

Add this file to your AI assistant's context:
- **Claude Code**: copy to your project's `CLAUDE.md` or reference via `--add-file`
- **Cursor**: add to `.cursor/rules/` or `.cursorrules`
- **GitHub Copilot**: add to `.github/copilot-instructions.md`
- **Other tools**: paste into system prompt or project context

---

## Ecosystem Overview

| Package | Install | Purpose |
|---------|---------|---------|
| `php-opcua/opcua-client` | `composer require php-opcua/opcua-client` | Core OPC UA client — required |
| `php-opcua/opcua-session-manager` | `composer require php-opcua/opcua-session-manager` | Session persistence daemon — optional, for multi-request apps |
| `php-opcua/laravel-opcua` | `composer require php-opcua/laravel-opcua` | Laravel integration — optional, provides Facade + config |
| `php-opcua/opcua-cli` | `composer require php-opcua/opcua-cli` | CLI tool — optional, for terminal operations |
| `php-opcua/opcua-client-nodeset` | `composer require php-opcua/opcua-client-nodeset` | Pre-built OPC UA companion types — optional |

**Requirements**: PHP >= 8.2, ext-openssl. No other extensions needed.

---

## Skill: Connect to an OPC UA Server

### When to use
The user wants to connect to a PLC, SCADA system, sensor, or any OPC UA-compliant device.

### Code

```php
use PhpOpcua\Client\ClientBuilder;

// Minimal — no security
$client = ClientBuilder::create()
    ->connect('opc.tcp://192.168.1.100:4840');

// ... do operations ...

$client->disconnect();
```

### Important rules
- Always call `disconnect()` when done (or use try/finally)
- The endpoint format is always `opc.tcp://host:port`
- Default OPC UA port is 4840
- `ClientBuilder::create()` is the only entry point — never instantiate `Client` directly
- All configuration must happen BEFORE `connect()` — the client is immutable after connection

---

## Skill: Connect with Security and Authentication

### When to use
The user needs encrypted connections, username/password, or certificate-based authentication.

### Code

```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Security\SecurityMode;

// Username/password with encryption
$client = ClientBuilder::create()
    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
    ->setSecurityMode(SecurityMode::SignAndEncrypt)
    ->setUserCredentials('operator', 'secret')
    ->connect('opc.tcp://192.168.1.100:4840');

// With explicit client certificate
$client = ClientBuilder::create()
    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
    ->setSecurityMode(SecurityMode::SignAndEncrypt)
    ->setClientCertificate('/certs/client.pem', '/certs/client.key', '/certs/ca.pem')
    ->setUserCredentials('operator', 'secret')
    ->connect('opc.tcp://192.168.1.100:4840');

// X.509 certificate authentication (no password)
$client = ClientBuilder::create()
    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
    ->setSecurityMode(SecurityMode::SignAndEncrypt)
    ->setClientCertificate('/certs/client.pem', '/certs/client.key')
    ->setUserCertificate('/certs/user.pem', '/certs/user.key')
    ->connect('opc.tcp://192.168.1.100:4840');
```

### Important rules
- If `setClientCertificate()` is omitted but security policy/mode are set, a self-signed cert is auto-generated in memory (good for testing, not production)
- Available policies: `None`, `Basic128Rsa15`, `Basic256`, `Basic256Sha256`, `Aes128Sha256RsaOaep`, `Aes256Sha256RsaPss`
- Available modes: `None`, `Sign`, `SignAndEncrypt`
- For production use `Basic256Sha256` or `Aes256Sha256RsaPss`
- Auth methods are: anonymous (default), username/password (`setUserCredentials`), X.509 certificate (`setUserCertificate`)

---

## Skill: Read Values from a Server

### When to use
The user wants to read process variables — temperatures, pressures, motor speeds, counters, status values, etc.

### Code

```php
use PhpOpcua\Client\Types\NodeId;

// Read a single value — string NodeId format
$dv = $client->read('i=2259');              // ServerState
$dv = $client->read('ns=2;i=1001');         // Namespace 2, numeric ID
$dv = $client->read('ns=2;s=Temperature');  // Namespace 2, string ID

// Access the result
echo $dv->getValue();         // The actual value (unwrapped from Variant)
echo $dv->statusCode;         // 0 = Good
echo $dv->sourceTimestamp;    // DateTimeImmutable or null

// Read multiple values — fluent builder (preferred)
$results = $client->readMulti()
    ->node('i=2259')->value()
    ->node('ns=2;i=1001')->displayName()
    ->node('ns=2;s=Temperature')->value()
    ->execute();

foreach ($results as $dv) {
    echo $dv->getValue() . "\n";
}

// Read multiple values — array syntax
$results = $client->readMulti([
    ['nodeId' => 'i=2259'],
    ['nodeId' => 'ns=2;i=1001'],
]);
```

### Important rules
- All methods accept string NodeIds (`'i=2259'`, `'ns=2;s=MyNode'`) OR `NodeId` objects — use strings for simplicity
- `getValue()` unwraps the Variant and returns the PHP-native value
- Always check `$dv->statusCode` — `0` means Good, non-zero means the read failed or the value is uncertain
- Use `StatusCode::isGood($dv->statusCode)` for proper status checking
- Common well-known nodes: `i=2259` (ServerState), `i=2258` (CurrentTime), `i=2256` (ServerStatus), `i=85` (Objects folder)

---

## Skill: Write Values to a Server

### When to use
The user wants to write setpoints, commands, or any value to a PLC or OPC UA server.

### Code

```php
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\StatusCode;

// Auto-detect type (recommended) — reads the node's DataType first, caches it
$status = $client->write('ns=2;i=1001', 42);

// Explicit type — when you know the type and want to skip the extra read
$status = $client->write('ns=2;i=1001', 42, BuiltinType::Int32);

// Check result
if (StatusCode::isGood($status)) {
    echo "Write successful";
}

// Write multiple values — fluent builder
$results = $client->writeMulti()
    ->node('ns=2;i=1001')->int32(42)
    ->node('ns=2;i=1002')->double(3.14)
    ->node('ns=2;s=Label')->string('active')
    ->execute();

// Write multiple values — auto-detect types
$results = $client->writeMulti()
    ->node('ns=2;i=1001')->value(42)
    ->node('ns=2;i=1002')->value(3.14)
    ->execute();
```

### Important rules
- Auto-detect (`write($nodeId, $value)` without type) is the default and recommended approach — it reads the node's DataType, caches it, and writes with the correct type
- If you know the type, pass it explicitly to avoid the extra read: `write($nodeId, $value, BuiltinType::Int32)`
- Common BuiltinType values: `Boolean`, `Int16`, `Int32`, `Int64`, `UInt16`, `UInt32`, `UInt64`, `Float`, `Double`, `String`, `DateTime`, `ByteString`
- The return value is a status code integer — use `StatusCode::isGood()` to check
- Write failures typically return `StatusCode::BadNotWritable` or `StatusCode::BadTypeMismatch`

---

## Skill: Browse the Address Space

### When to use
The user wants to discover what nodes, variables, objects, or methods are available on a server.

### Code

```php
use PhpOpcua\Client\Types\NodeClass;

// Browse a folder
$refs = $client->browse('i=85'); // Objects folder

foreach ($refs as $ref) {
    echo "{$ref->displayName} ({$ref->nodeId}) [{$ref->nodeClass->name}]\n";
}

// Browse with node class filter — only variables
$refs = $client->browse('i=85', nodeClasses: [NodeClass::Variable]);

// Browse with node class filter — only objects and variables
$refs = $client->browse('i=85', nodeClasses: [NodeClass::Object, NodeClass::Variable]);

// Browse all (automatic continuation point handling)
$allRefs = $client->browseAll('i=85');

// Recursive browse — returns a tree
$tree = $client->browseRecursive('i=85', maxDepth: 3);

foreach ($tree as $node) {
    echo $node->reference->displayName . "\n";
    foreach ($node->children as $child) {
        echo "  " . $child->reference->displayName . "\n";
    }
}

// Resolve a human-readable path to a NodeId
$nodeId = $client->resolveNodeId('/Objects/MyPLC/Temperature');
$value = $client->read($nodeId);
```

### Important rules
- `i=85` is the Objects folder — the standard starting point for browsing
- `i=84` is the Root folder (parent of Objects, Types, Views)
- `browse()` returns `ReferenceDescription[]` with properties: `nodeId`, `displayName`, `browseName`, `nodeClass`, `isForward`, `referenceTypeId`, `typeDefinition`
- `browseRecursive()` returns `BrowseNode[]` — each has `reference` and `children`
- Browse results are cached by default — use `useCache: false` to bypass
- `resolveNodeId()` translates paths like `/Objects/Server/ServerStatus` to NodeId objects

---

## Skill: Call Methods on the Server

### When to use
The user wants to invoke OPC UA methods — trigger operations, run diagnostics, execute PLC commands.

### Code

```php
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\StatusCode;

$result = $client->call(
    'i=2253',   // Parent object NodeId (where the method lives)
    'i=11492',  // Method NodeId
    [           // Input arguments as Variant array
        new Variant(BuiltinType::UInt32, 1),
    ],
);

if (StatusCode::isGood($result->statusCode)) {
    echo $result->outputArguments[0]->value; // Access output arguments
}

// Method with multiple arguments
$result = $client->call(
    'ns=2;i=100',
    'ns=2;i=200',
    [
        new Variant(BuiltinType::Double, 3.0),
        new Variant(BuiltinType::Double, 4.0),
    ],
);
```

### Important rules
- The first argument is the parent **object** NodeId, the second is the **method** NodeId
- Input arguments must be wrapped in `Variant` objects with explicit types
- `$result` is a `CallResult` DTO with `statusCode`, `inputArgumentResults`, and `outputArguments`
- Output arguments are `Variant` objects — access values via `->value`

---

## Skill: Subscribe to Real-Time Data Changes

### When to use
The user wants to be notified when sensor values change, monitor variables in real time, or watch for events.

### Code

```php
use PhpOpcua\Client\Types\NodeId;

// 1. Create a subscription
$sub = $client->createSubscription(publishingInterval: 500.0); // 500ms

// 2. Add monitored items
$monitored = $client->createMonitoredItems($sub->subscriptionId, [
    ['nodeId' => 'ns=2;i=1001', 'clientHandle' => 1],
    ['nodeId' => 'ns=2;i=1002', 'clientHandle' => 2],
]);

// Or use the fluent builder
$monitored = $client->createMonitoredItems($sub->subscriptionId)
    ->item('ns=2;i=1001', clientHandle: 1)
    ->item('ns=2;i=1002', clientHandle: 2)
    ->execute();

// 3. Poll for notifications
$response = $client->publish();
foreach ($response->notifications as $notif) {
    echo "Handle {$notif['clientHandle']}: {$notif['dataValue']->getValue()}\n";
}

// 4. Clean up
$client->deleteSubscription($sub->subscriptionId);
```

### Important rules
- Subscriptions require a **polling loop** — call `publish()` repeatedly to receive notifications
- In standard PHP (request/response), the subscription dies with the process. Use `opcua-session-manager` for persistent subscriptions across requests
- `createSubscription()` returns a `SubscriptionResult` with `subscriptionId`
- `createMonitoredItems()` returns `MonitoredItemResult[]` with `monitoredItemId`
- Always `deleteSubscription()` before disconnecting to clean up server resources
- `publishingInterval` is in milliseconds

---

## Skill: Read Historical Data

### When to use
The user wants to pull past values — trend analysis, logs, aggregated statistics from OPC UA historians.

### Code

```php
// Raw historical values
$values = $client->historyReadRaw(
    'ns=2;i=1001',
    startTime: new \DateTimeImmutable('-1 hour'),
    endTime: new \DateTimeImmutable(),
);

foreach ($values as $dv) {
    echo "[{$dv->sourceTimestamp->format('H:i:s')}] {$dv->getValue()}\n";
}

// Aggregated (processed) — e.g., average over 1-minute intervals
$values = $client->historyReadProcessed(
    'ns=2;i=1001',
    startTime: new \DateTimeImmutable('-1 hour'),
    endTime: new \DateTimeImmutable(),
    processingInterval: 60000.0, // 60 seconds in ms
    aggregateType: NodeId::numeric(0, 2341), // Average
);

// Values at specific timestamps
$values = $client->historyReadAtTime('ns=2;i=1001', [
    new \DateTimeImmutable('-30 minutes'),
    new \DateTimeImmutable('-15 minutes'),
    new \DateTimeImmutable('now'),
]);
```

### Important rules
- Not all OPC UA servers support history — the server must have a historian configured
- Common aggregate type NodeIds: `2341` (Average), `2342` (Interpolative), `2346` (Minimum), `2347` (Maximum), `2352` (Count)
- `processingInterval` is in milliseconds
- Returns `DataValue[]` — same format as regular reads

---

## Skill: Handle Server Certificate Trust

### When to use
The user connects to a server for the first time and needs to handle certificate trust, or wants TOFU (Trust On First Use).

### Code

```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\TrustStore\FileTrustStore;
use PhpOpcua\Client\TrustStore\TrustPolicy;
use PhpOpcua\Client\Exception\UntrustedCertificateException;

// Auto-accept on first use (TOFU) — good for development
$client = ClientBuilder::create()
    ->setTrustStore(new FileTrustStore())          // ~/.opcua/trusted/
    ->autoAccept(true)
    ->connect('opc.tcp://192.168.1.100:4840');

// Strict trust — reject unknown certificates
$client = ClientBuilder::create()
    ->setTrustStore(new FileTrustStore('/var/opcua/trust'))
    ->setTrustPolicy(TrustPolicy::Fingerprint)
    ->connect('opc.tcp://192.168.1.100:4840');
    // throws UntrustedCertificateException if cert not in store

// Handle untrusted certificate interactively
try {
    $client = ClientBuilder::create()
        ->setTrustStore(new FileTrustStore())
        ->setTrustPolicy(TrustPolicy::Fingerprint)
        ->connect('opc.tcp://192.168.1.100:4840');
} catch (UntrustedCertificateException $e) {
    echo "Fingerprint: " . $e->getFingerprint() . "\n";
    // Trust it programmatically:
    $client->trustCertificate($e->getCertificate());
}

// Disable trust validation entirely
$client = ClientBuilder::create()
    ->setTrustPolicy(null)
    ->connect('opc.tcp://192.168.1.100:4840');
```

### Important rules
- Trust policies: `Fingerprint` (match SHA-256 only), `FingerprintAndExpiry` (+ expiry check), `Full` (full CA chain validation)
- `setTrustPolicy(null)` disables validation — this is the default (no trust store)
- TOFU (`autoAccept(true)`) is vulnerable on first connection — use for dev only
- `autoAccept(true, force: true)` also accepts changed certificates — very insecure

---

## Skill: Keep Sessions Alive Across PHP Requests

### When to use
The user has a web application (Laravel, Symfony, plain PHP) and wants to avoid the 50-200ms OPC UA handshake on every HTTP request.

### Prerequisites
```bash
composer require php-opcua/opcua-session-manager
```

### Code

```bash
# 1. Start the daemon (separate terminal or Supervisor/systemd)
php vendor/bin/opcua-session-manager
```

```php
use PhpOpcua\SessionManager\Client\ManagedClient;

// 2. Use ManagedClient instead of ClientBuilder — same API
$client = new ManagedClient();
$client->connect('opc.tcp://192.168.1.100:4840');

$value = $client->read('i=2259');
echo $value->getValue();

// Do NOT disconnect if you want the session to persist!
// The daemon keeps it alive.

// In the next request — the session is automatically reused (~5ms instead of ~155ms)
$client = new ManagedClient();
$client->connect('opc.tcp://192.168.1.100:4840');
$client->wasSessionReused(); // true
```

### Important rules
- `ManagedClient` implements the same `OpcUaClientInterface` as the direct `Client` — it's a drop-in replacement
- The daemon creates a Unix socket at `/tmp/opcua-session-manager.sock` by default
- If the daemon is not running, `ManagedClient` will throw a `DaemonException` — there is no automatic fallback to direct connections (use `laravel-opcua` for that)
- Sessions expire after `--timeout` seconds of inactivity (default: 600)
- Call `disconnect()` only when you want to explicitly close the session
- For production use Supervisor or systemd to keep the daemon running
- Use `--auth-token` or `OPCUA_AUTH_TOKEN` env var for IPC security

---

## Skill: Integrate with Laravel

### When to use
The user has a Laravel application and wants OPC UA with Facade, .env config, named connections, and automatic session manager integration.

### Prerequisites
```bash
composer require php-opcua/laravel-opcua
php artisan vendor:publish --tag=opcua-config
```

### Code

```dotenv
# .env
OPCUA_ENDPOINT=opc.tcp://192.168.1.100:4840
```

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

// Connect and read
$client = Opcua::connect();
$value = $client->read('i=2259');
echo $value->getValue();
$client->disconnect();

// Named connections (defined in config/opcua.php)
$plc1 = Opcua::connect('plc-line-1');
$plc2 = Opcua::connect('plc-line-2');
Opcua::disconnectAll();

// Ad-hoc connection
$client = Opcua::connectTo('opc.tcp://10.0.0.50:4840', [
    'username' => 'operator',
    'password' => 'secret',
]);

// Session manager integration — automatic
// If the daemon is running, connections persist across requests.
// If not, direct connections are used. Zero code changes.
php artisan opcua:session  # start daemon
```

### Important rules
- The `Opcua` Facade proxies to `OpcuaManager` — it manages multiple named connections
- Config follows Laravel conventions: `config/opcua.php` with `.env` variables
- The package auto-detects the session manager daemon — if its socket exists, `ManagedClient` is used; otherwise, direct `Client`
- Named connections work like `config/database.php` — define multiple servers in `connections` array
- Logger and cache are automatically injected from Laravel's service container

---

## Skill: Test OPC UA Code Without a Real Server

### When to use
The user wants to unit test code that uses OPC UA without a physical PLC or server.

### Code

```php
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\StatusCode;

// Create a mock client
$mock = MockClient::create();

// Register handlers
$mock->onRead(function ($nodeId) {
    return match ((string) $nodeId) {
        'ns=2;s=Temperature' => DataValue::ofDouble(23.5),
        'i=2259' => DataValue::ofInt32(0),
        default => DataValue::bad(StatusCode::BadNodeIdUnknown),
    };
});

$mock->onWrite(function ($nodeId, $value) {
    return StatusCode::Good;
});

$mock->onBrowse(function ($nodeId) {
    return []; // empty reference list
});

// Use the same API as a real client
$value = $mock->read('ns=2;s=Temperature');
echo $value->getValue(); // 23.5

// Assert calls
echo $mock->callCount('read'); // 1
$mock->resetCalls();
```

### DataValue factory methods

```php
DataValue::ofBoolean(true);
DataValue::ofInt32(42);
DataValue::ofUInt32(100);
DataValue::ofDouble(3.14);
DataValue::ofFloat(2.5);
DataValue::ofString('hello');
DataValue::of($value, BuiltinType::Int32);
DataValue::bad(StatusCode::BadNodeIdUnknown); // bad status
```

### Important rules
- `MockClient` implements `OpcUaClientInterface` — it can be injected anywhere a real client is expected
- Handlers: `onRead()`, `onWrite()`, `onBrowse()`, `onCall()`, `onResolveNodeId()`, `onGetEndpoints()`
- Call tracking: `getCalls()`, `getCallsFor($method)`, `callCount($method)`, `resetCalls()`
- Fluent builders (`readMulti()`, `writeMulti()`) work with MockClient

---

## Skill: Discover Custom Data Types

### When to use
The user reads structured values from a server and gets raw bytes or arrays instead of typed data.

### Code

```php
// Auto-discover all custom types from the server
$client->discoverDataTypes();

// Now structured reads return decoded values
$point = $client->read('ns=2;s=MyPoint')->getValue();
// ['x' => 1.5, 'y' => 2.5, 'z' => 3.5]

// Or use pre-built companion types (e.g., Robotics, DI, Machinery)
// composer require php-opcua/opcua-client-nodeset

use PhpOpcua\Nodeset\Robotics\RoboticsRegistrar;

$client = ClientBuilder::create()
    ->loadGeneratedTypes(new RoboticsRegistrar())
    ->connect('opc.tcp://192.168.1.100:4840');
```

### Important rules
- Call `discoverDataTypes()` AFTER connecting — it queries the server's type system
- For custom codecs, implement `ExtensionObjectCodec` and register via `ExtensionObjectRepository`
- Each client has its own isolated codec registry — no global state
- `opcua-client-nodeset` provides pre-generated types for 51 OPC Foundation companion specs

---

## Skill: Add Logging and Caching

### When to use
The user wants to debug OPC UA communication or optimize performance with caching.

### Code

```php
use PhpOpcua\Client\ClientBuilder;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Add PSR-3 logging
$logger = new Logger('opcua');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

$client = ClientBuilder::create()
    ->setLogger($logger)
    ->connect('opc.tcp://localhost:4840');
// Logs: handshake, secure channel, reads, retries, errors...

// Cache configuration
use PhpOpcua\Client\Cache\FileCache;

$client = ClientBuilder::create()
    ->setCache(new FileCache('/tmp/opcua-cache', 600)) // 600s TTL
    ->connect('opc.tcp://localhost:4840');

// Per-call cache control
$refs = $client->browse('i=85', useCache: false);  // skip cache
$client->invalidateCache('i=85');                   // clear one node
$client->flushCache();                              // clear all
```

### Important rules
- Any PSR-3 logger works (Monolog, Laravel's logger, etc.)
- Without a logger, a `NullLogger` is used (zero overhead)
- Browse, resolve, endpoint, and discovery results are cached by default (`InMemoryCache`, 300s TTL)
- Any PSR-16 cache driver works (`InMemoryCache`, `FileCache`, Laravel Cache, Redis)
- Read values are NEVER cached — only metadata and browse results
- `setReadMetadataCache(true)` enables caching of node metadata (DataType, DisplayName) — not values

---

## Skill: Listen to OPC UA Events (PSR-14)

### When to use
The user wants to react to OPC UA lifecycle events — log connections, track reads/writes, handle alarms.

### Code

```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Event\DataChangeReceived;
use PhpOpcua\Client\Event\AlarmActivated;
use PhpOpcua\Client\Event\Connected;

$client = ClientBuilder::create()
    ->setEventDispatcher($yourPsr14Dispatcher)
    ->connect('opc.tcp://localhost:4840');

// In your listener:
class HandleDataChange {
    public function __invoke(DataChangeReceived $event): void {
        echo "Value changed on sub {$event->subscriptionId}: "
            . $event->dataValue->getValue() . "\n";
    }
}

// Laravel integration:
// In EventServiceProvider:
protected $listen = [
    \PhpOpcua\Client\Event\AfterRead::class => [LogOpcuaReads::class],
    \PhpOpcua\Client\Event\AlarmActivated::class => [HandleAlarm::class],
];
```

### Important rules
- 47 event types covering: connection, session, subscription, data change, alarms, read/write, browse, cache, retry, trust store
- Zero overhead when no dispatcher is set (`NullEventDispatcher`)
- All events are readonly DTOs with a `$client` property
- Any PSR-14 `EventDispatcherInterface` works

---

## Skill: Configure Timeout, Retry, and Batching

### When to use
The user needs to tune connection behavior — slow networks, unreliable connections, large read/write operations.

### Code

```php
$client = ClientBuilder::create()
    ->setTimeout(10.0)                    // 10 seconds network timeout
    ->setAutoRetry(3)                     // retry up to 3 times on failure
    ->setBatchSize(100)                   // max 100 nodes per read/write batch
    ->setDefaultBrowseMaxDepth(20)        // browseRecursive depth limit
    ->connect('opc.tcp://192.168.1.100:4840');
```

### Important rules
- `setTimeout()` is in seconds (float)
- `setAutoRetry(n)` automatically reconnects and retries on `ConnectionException`
- `setBatchSize(n)` splits large `readMulti`/`writeMulti` operations transparently
- The client auto-discovers server limits (`MaxNodesPerRead`, `MaxNodesPerWrite`) and respects them
- All configuration must happen before `connect()` — immutable after connection

---

## Skill: Endpoint Discovery

### When to use
The user wants to discover what security configurations a server supports before connecting.

### Code

```php
$client = ClientBuilder::create()
    ->connect('opc.tcp://192.168.1.100:4840');

$endpoints = $client->getEndpoints('opc.tcp://192.168.1.100:4840');

foreach ($endpoints as $ep) {
    echo "{$ep->securityPolicyUri}\n";
    echo "  Mode: {$ep->securityMode->name}\n";
    echo "  Auth: " . implode(', ', array_map(
        fn($t) => $t->tokenType->name,
        $ep->userIdentityTokens
    )) . "\n";
}
```

---

## Common Mistakes to Avoid

### 1. Configuring after connect
```php
// WRONG — Client is immutable after connect()
$client = ClientBuilder::create()->connect('opc.tcp://...');
$client->setTimeout(10.0); // This method doesn't exist on Client

// CORRECT
$client = ClientBuilder::create()
    ->setTimeout(10.0)
    ->connect('opc.tcp://...');
```

### 2. Forgetting to disconnect
```php
// WRONG — leaks connections
$client = ClientBuilder::create()->connect('opc.tcp://...');
$value = $client->read('i=2259');
// script ends without disconnect

// CORRECT
$client = ClientBuilder::create()->connect('opc.tcp://...');
try {
    $value = $client->read('i=2259');
} finally {
    $client->disconnect();
}
```

### 3. Using arrays instead of DTOs
```php
// WRONG — old array access style
$sub = $client->createSubscription(500.0);
echo $sub['subscriptionId'];

// CORRECT — public readonly properties
$sub = $client->createSubscription(publishingInterval: 500.0);
echo $sub->subscriptionId;
```

### 4. Ignoring status codes
```php
// WRONG — assuming success
$dv = $client->read('ns=99;i=99999');
echo $dv->getValue(); // could be null if node doesn't exist

// CORRECT — check status
$dv = $client->read('ns=99;i=99999');
if (StatusCode::isGood($dv->statusCode)) {
    echo $dv->getValue();
} else {
    echo "Read failed: {$dv->statusCode}";
}
```

### 5. Expecting sessions to persist in plain PHP
```php
// WRONG in a web context — session dies with the request
$client = ClientBuilder::create()->connect('opc.tcp://...');
$sub = $client->createSubscription(500.0);
// next request: subscription is gone

// CORRECT — use session manager for persistent subscriptions
$client = new ManagedClient();
$client->connect('opc.tcp://...');
```

---

## NodeId String Format Reference

| Format | Example | Meaning |
|--------|---------|---------|
| `i=<number>` | `i=2259` | Namespace 0, numeric identifier |
| `ns=<n>;i=<number>` | `ns=2;i=1001` | Namespace n, numeric identifier |
| `s=<string>` | `s=Temperature` | Namespace 0, string identifier |
| `ns=<n>;s=<string>` | `ns=2;s=Temperature` | Namespace n, string identifier |
| `g=<guid>` | `g=12345678-1234-1234-1234-123456789012` | GUID identifier |
| `ns=<n>;g=<guid>` | `ns=2;g=...` | Namespace n, GUID identifier |

All methods accepting `NodeId` also accept these string formats.

---

## BuiltinType Reference

| Type | PHP Type | Common Use |
|------|----------|------------|
| `Boolean` | `bool` | Digital I/O, flags |
| `Int16` | `int` | Small signed integers |
| `Int32` | `int` | Standard integers, counters |
| `Int64` | `int` | Large counters |
| `UInt16` | `int` | Unsigned small integers |
| `UInt32` | `int` | Status codes, handles |
| `UInt64` | `int` | Large unsigned values |
| `Float` | `float` | Single-precision measurements |
| `Double` | `float` | High-precision measurements |
| `String` | `string` | Labels, names, descriptions |
| `DateTime` | `DateTimeImmutable` | Timestamps |
| `ByteString` | `string` | Binary data, certificates |
| `Byte` | `int` | Single byte values |
| `SByte` | `int` | Signed single byte |

---

## Exception Hierarchy

| Exception | When |
|-----------|------|
| `ConnectionException` | Cannot connect, timeout, network error |
| `ServiceException` | Server rejected the request |
| `UntrustedCertificateException` | Server certificate not in trust store |
| `WriteTypeDetectionException` | Auto-detect write type failed |
| `WriteTypeMismatchException` | Detected type doesn't match the value |
| `InvalidNodeIdException` | Invalid NodeId string format |
| `DaemonException` | Session manager daemon communication error (opcua-session-manager) |
