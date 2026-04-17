<?php

declare(strict_types=1);

use PhpOpcua\Client\Cache\FileCache;
use PhpOpcua\Client\Cache\InMemoryCache;
use PhpOpcua\Client\Client;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Protocol\MessageHeader;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Transport\TcpTransport;
use PhpOpcua\Client\Types\ConnectionState;
use PhpOpcua\Client\Types\NodeId;

class CacheMockTransport extends TcpTransport
{
    private array $responses = [];

    private int $index = 0;

    public array $sent = [];

    public function addResponse(string $data): void
    {
        $this->responses[] = $data;
    }

    public function connect(string $host, int $port, null|float $timeout = null): void
    {
    }

    public function send(string $data): void
    {
        $this->sent[] = $data;
    }

    public function receive(): string
    {
        if ($this->index >= count($this->responses)) {
            throw new PhpOpcua\Client\Exception\ConnectionException('No more mock responses');
        }

        return $this->responses[$this->index++];
    }

    public function close(): void
    {
    }

    public function isConnected(): bool
    {
        return true;
    }
}

function setCacheClientProperty(Client $client, string $name, mixed $value): void
{
    $ref = new ReflectionProperty(Client::class, $name);
    $ref->setValue($client, $value);
}

function callCacheClientMethod(Client $client, string $name, array $args = []): mixed
{
    $ref = new ReflectionMethod($client, $name);

    return $ref->invokeArgs($client, $args);
}

function createCacheClientWithoutConnect(): Client
{
    $ref = new ReflectionClass(Client::class);
    $client = $ref->newInstanceWithoutConstructor();

    setCacheClientProperty($client, 'connectionState', ConnectionState::Disconnected);
    setCacheClientProperty($client, 'securityPolicy', PhpOpcua\Client\Security\SecurityPolicy::None);
    setCacheClientProperty($client, 'securityMode', PhpOpcua\Client\Security\SecurityMode::None);
    setCacheClientProperty($client, 'clientCertPath', null);
    setCacheClientProperty($client, 'clientKeyPath', null);
    setCacheClientProperty($client, 'caCertPath', null);
    setCacheClientProperty($client, 'username', null);
    setCacheClientProperty($client, 'password', null);
    setCacheClientProperty($client, 'userCertPath', null);
    setCacheClientProperty($client, 'userKeyPath', null);
    setCacheClientProperty($client, 'logger', new Psr\Log\NullLogger());
    setCacheClientProperty($client, 'eventDispatcher', new PhpOpcua\Client\Event\NullEventDispatcher());
    setCacheClientProperty($client, 'trustStore', null);
    setCacheClientProperty($client, 'trustPolicy', null);
    setCacheClientProperty($client, 'autoAcceptEnabled', false);
    setCacheClientProperty($client, 'autoAcceptForce', false);
    setCacheClientProperty($client, 'cache', null);
    setCacheClientProperty($client, 'cacheInitialized', false);
    setCacheClientProperty($client, 'timeout', 5.0);
    setCacheClientProperty($client, 'autoRetry', null);
    setCacheClientProperty($client, 'batchSize', null);
    setCacheClientProperty($client, 'serverMaxNodesPerRead', null);
    setCacheClientProperty($client, 'serverMaxNodesPerWrite', null);
    setCacheClientProperty($client, 'defaultBrowseMaxDepth', 10);
    setCacheClientProperty($client, 'autoDetectWriteType', true);
    setCacheClientProperty($client, 'readMetadataCache', false);
    setCacheClientProperty($client, 'extensionObjectRepository', new PhpOpcua\Client\Repository\ExtensionObjectRepository());
    setCacheClientProperty($client, 'enumMappings', []);
    setCacheClientProperty($client, 'transport', new TcpTransport());
    setCacheClientProperty($client, 'session', null);
    $moduleRegistry = new PhpOpcua\Client\Module\ModuleRegistry();
    $moduleRegistry->add(new PhpOpcua\Client\Module\ReadWrite\ReadWriteModule());
    $moduleRegistry->add(new PhpOpcua\Client\Module\Browse\BrowseModule());
    $moduleRegistry->add(new PhpOpcua\Client\Module\Subscription\SubscriptionModule());
    $moduleRegistry->add(new PhpOpcua\Client\Module\History\HistoryModule());
    $moduleRegistry->add(new PhpOpcua\Client\Module\NodeManagement\NodeManagementModule());
    $moduleRegistry->add(new PhpOpcua\Client\Module\TranslateBrowsePath\TranslateBrowsePathModule());
    $moduleRegistry->add(new PhpOpcua\Client\Module\ServerInfo\ServerInfoModule());
    $moduleRegistry->add(new PhpOpcua\Client\Module\TypeDiscovery\TypeDiscoveryModule());
    setCacheClientProperty($client, 'moduleRegistry', $moduleRegistry);
    setCacheClientProperty($client, 'methodHandlers', []);
    setCacheClientProperty($client, 'methodOwners', []);
    setCacheClientProperty($client, 'currentModuleClass', '');
    foreach ($moduleRegistry->getModuleClasses() as $moduleClass) {
        $module = $moduleRegistry->get($moduleClass);
        $module->setKernel($client);
        $module->setClient($client);
        $client->setCurrentModuleClass($moduleClass);
        $module->register();
    }
    setCacheClientProperty($client, 'authenticationToken', null);
    setCacheClientProperty($client, 'secureChannelId', 0);
    setCacheClientProperty($client, 'requestId', 10);
    setCacheClientProperty($client, 'serverCertDer', null);
    setCacheClientProperty($client, 'secureChannel', null);
    setCacheClientProperty($client, 'serverNonce', null);
    setCacheClientProperty($client, 'eccServerEphemeralKey', null);
    setCacheClientProperty($client, 'usernamePolicyId', null);
    setCacheClientProperty($client, 'certificatePolicyId', null);
    setCacheClientProperty($client, 'anonymousPolicyId', null);
    setCacheClientProperty($client, 'lastEndpointUrl', null);

    return $client;
}

function setupCacheConnectedClient(CacheMockTransport $mock): Client
{
    $client = createCacheClientWithoutConnect();
    $session = new SessionService(1, 1);

    setCacheClientProperty($client, 'transport', $mock);
    setCacheClientProperty($client, 'connectionState', ConnectionState::Connected);
    setCacheClientProperty($client, 'session', $session);
    setCacheClientProperty($client, 'authenticationToken', NodeId::numeric(0, 2));
    setCacheClientProperty($client, 'secureChannelId', 1);
    setCacheClientProperty($client, 'lastEndpointUrl', 'opc.tcp://mock:4840');

    $ref = new ReflectionProperty(Client::class, 'moduleRegistry');
    $moduleRegistry = $ref->getValue($client);
    foreach ($moduleRegistry->getModuleClasses() as $moduleClass) {
        $moduleRegistry->get($moduleClass)->boot($session);
    }

    return $client;
}

function cacheBrowseResponseMsg(): string
{
    $e = new BinaryEncoder();
    (new MessageHeader('MSG', 'F', 0))->encode($e);
    $e->writeUInt32(1);
    $e->writeUInt32(1);
    $e->writeUInt32(1);
    $e->writeUInt32(1);
    $e->writeNodeId(NodeId::numeric(0, 530));
    $e->writeInt64(0);
    $e->writeUInt32(1);
    $e->writeUInt32(0);
    $e->writeByte(0);
    $e->writeInt32(0);
    $e->writeNodeId(NodeId::numeric(0, 0));
    $e->writeByte(0);
    $e->writeInt32(1);
    $e->writeUInt32(0);
    $e->writeByteString(null);
    $e->writeInt32(1);
    $e->writeNodeId(NodeId::numeric(0, 35));
    $e->writeBoolean(true);
    $e->writeExpandedNodeId(NodeId::numeric(0, 2253));
    $e->writeUInt16(0);
    $e->writeString('Server');
    $e->writeByte(0x02);
    $e->writeString('Server');
    $e->writeUInt32(1);
    $e->writeExpandedNodeId(NodeId::numeric(0, 2004));
    $e->writeInt32(0);
    $d = $e->getBuffer();

    return substr($d, 0, 4) . pack('V', strlen($d)) . substr($d, 8);
}

function cacheResolveResponseMsg(): string
{
    $e = new BinaryEncoder();
    (new MessageHeader('MSG', 'F', 0))->encode($e);
    $e->writeUInt32(1);
    $e->writeUInt32(1);
    $e->writeUInt32(1);
    $e->writeUInt32(1);
    $e->writeNodeId(NodeId::numeric(0, 557));
    $e->writeInt64(0);
    $e->writeUInt32(1);
    $e->writeUInt32(0);
    $e->writeByte(0);
    $e->writeInt32(0);
    $e->writeNodeId(NodeId::numeric(0, 0));
    $e->writeByte(0);
    $e->writeInt32(1);
    $e->writeUInt32(0);
    $e->writeInt32(1);
    $e->writeExpandedNodeId(NodeId::numeric(0, 2253));
    $e->writeUInt32(0xFFFFFFFF);
    $e->writeInt32(0);
    $d = $e->getBuffer();

    return substr($d, 0, 4) . pack('V', strlen($d)) . substr($d, 8);
}

describe('InMemoryCache', function () {

    beforeEach(function () {
        $this->cache = new InMemoryCache(300);
    });

    it('gets and sets values', function () {
        $this->cache->set('key1', 'value1');
        expect($this->cache->get('key1'))->toBe('value1');
    });

    it('returns default when key is missing', function () {
        expect($this->cache->get('nonexistent', 'fallback'))->toBe('fallback');
    });

    it('returns value before TTL expires', function () {
        $this->cache->set('key1', 'value1', 1);
        expect($this->cache->get('key1'))->toBe('value1');
    });

    it('deletes a key', function () {
        $this->cache->set('key1', 'value1');
        $this->cache->delete('key1');
        expect($this->cache->get('key1'))->toBeNull();
    });

    it('clears all keys', function () {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');
        $this->cache->clear();
        expect($this->cache->get('key1'))->toBeNull();
        expect($this->cache->get('key2'))->toBeNull();
    });

    it('checks existence with has', function () {
        $this->cache->set('key1', 'value1');
        expect($this->cache->has('key1'))->toBeTrue();
        expect($this->cache->has('nonexistent'))->toBeFalse();
    });

    it('gets multiple values', function () {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);
        $results = $this->cache->getMultiple(['a', 'b', 'c'], 'default');
        expect($results)->toBe(['a' => 1, 'b' => 2, 'c' => 'default']);
    });

    it('sets multiple values', function () {
        $this->cache->setMultiple(['x' => 10, 'y' => 20]);
        expect($this->cache->get('x'))->toBe(10);
        expect($this->cache->get('y'))->toBe(20);
    });

    it('deletes multiple values', function () {
        $this->cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]);
        $this->cache->deleteMultiple(['a', 'c']);
        expect($this->cache->get('a'))->toBeNull();
        expect($this->cache->get('b'))->toBe(2);
        expect($this->cache->get('c'))->toBeNull();
    });

    it('returns the default TTL', function () {
        expect($this->cache->getDefaultTtl())->toBe(300);
        $custom = new InMemoryCache(600);
        expect($custom->getDefaultTtl())->toBe(600);
    });

    it('supports DateInterval TTL', function () {
        $this->cache->set('interval', 'data', new DateInterval('PT60S'));
        expect($this->cache->get('interval'))->toBe('data');
    });

    it('treats TTL of 0 as no expiration', function () {
        $cache = new InMemoryCache(0);
        $cache->set('forever', 'persistent');
        expect($cache->get('forever'))->toBe('persistent');
    });

    it('expires entries after TTL', function () {
        $cache = new InMemoryCache(300);
        $cache->set('expiring', 'value');

        $ref = new ReflectionClass($cache);
        $prop = $ref->getProperty('store');
        $store = $prop->getValue($cache);
        $store['expiring']['expiresAt'] = microtime(true) - 1;
        $prop->setValue($cache, $store);

        expect($cache->get('expiring', 'gone'))->toBe('gone');
    });

    it('deleteByPrefix removes matching entries', function () {
        $cache = new InMemoryCache(300);
        $cache->set('opcua:abc:browse:i=85', 'data1');
        $cache->set('opcua:abc:browse:i=86', 'data2');
        $cache->set('opcua:xyz:browse:i=85', 'data3');

        $cache->deleteByPrefix('opcua:abc');

        expect($cache->get('opcua:abc:browse:i=85'))->toBeNull();
        expect($cache->get('opcua:abc:browse:i=86'))->toBeNull();
        expect($cache->get('opcua:xyz:browse:i=85'))->toBe('data3');
    });
});

describe('FileCache', function () {

    beforeEach(function () {
        $this->cacheDir = sys_get_temp_dir() . '/opcua-test-cache-' . uniqid();
        $this->cache = new FileCache($this->cacheDir, 300);
    });

    afterEach(function () {
        $files = glob($this->cacheDir . DIRECTORY_SEPARATOR . '*.cache');
        if ($files) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        if (is_dir($this->cacheDir)) {
            @rmdir($this->cacheDir);
        }
    });

    it('gets and sets values', function () {
        $this->cache->set('key1', 'value1');
        expect($this->cache->get('key1'))->toBe('value1');
    });

    it('returns default when key is missing', function () {
        expect($this->cache->get('nonexistent', 'fallback'))->toBe('fallback');
    });

    it('returns value before TTL expires', function () {
        $this->cache->set('key1', 'value1', 60);
        expect($this->cache->get('key1'))->toBe('value1');
    });

    it('deletes a key', function () {
        $this->cache->set('key1', 'value1');
        $this->cache->delete('key1');
        expect($this->cache->get('key1'))->toBeNull();
    });

    it('clears all keys', function () {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');
        $this->cache->clear();
        expect($this->cache->get('key1'))->toBeNull();
        expect($this->cache->get('key2'))->toBeNull();
    });

    it('checks existence with has', function () {
        $this->cache->set('key1', 'value1');
        expect($this->cache->has('key1'))->toBeTrue();
        expect($this->cache->has('nonexistent'))->toBeFalse();
    });

    it('gets multiple values', function () {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);
        $results = $this->cache->getMultiple(['a', 'b', 'c'], 'default');
        expect($results)->toBe(['a' => 1, 'b' => 2, 'c' => 'default']);
    });

    it('sets multiple values', function () {
        $this->cache->setMultiple(['x' => 10, 'y' => 20]);
        expect($this->cache->get('x'))->toBe(10);
        expect($this->cache->get('y'))->toBe(20);
    });

    it('deletes multiple values', function () {
        $this->cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]);
        $this->cache->deleteMultiple(['a', 'c']);
        expect($this->cache->get('a'))->toBeNull();
        expect($this->cache->get('b'))->toBe(2);
        expect($this->cache->get('c'))->toBeNull();
    });

    it('returns the default TTL', function () {
        expect($this->cache->getDefaultTtl())->toBe(300);
        $custom = new FileCache($this->cacheDir, 600);
        expect($custom->getDefaultTtl())->toBe(600);
    });

    it('creates the directory if it does not exist', function () {
        $newDir = sys_get_temp_dir() . '/opcua-test-cache-create-' . uniqid();
        expect(is_dir($newDir))->toBeFalse();
        $cache = new FileCache($newDir, 300);
        expect(is_dir($newDir))->toBeTrue();
        @rmdir($newDir);
    });

    it('handles corrupted cache files gracefully', function () {
        $this->cache->set('corrupt', 'good-value');
        $path = $this->cacheDir . DIRECTORY_SEPARATOR . sha1('corrupt') . '.cache';
        file_put_contents($path, 'not-valid-serialized-data');
        expect($this->cache->get('corrupt', 'default-val'))->toBe('default-val');
    });

    it('returns default for non-existent file', function () {
        expect($this->cache->get('never-set', 'missing'))->toBe('missing');
    });

    it('expires entries after TTL', function () {
        $this->cache->set('expiring', 'value', 1);
        $path = $this->cacheDir . DIRECTORY_SEPARATOR . sha1('expiring') . '.cache';
        $entry = unserialize(file_get_contents($path));
        $entry['expiresAt'] = time() - 10;
        file_put_contents($path, serialize($entry));

        expect($this->cache->get('expiring', 'gone'))->toBe('gone');
        expect(file_exists($path))->toBeFalse();
    });

    it('delete returns true for non-existent key', function () {
        expect($this->cache->delete('never-existed'))->toBeTrue();
    });

    it('supports DateInterval TTL', function () {
        $this->cache->set('interval', 'data', new DateInterval('PT60S'));
        expect($this->cache->get('interval'))->toBe('data');
    });
});

describe('ManagesCacheTrait / Client integration', function () {

    it('auto-initializes with InMemoryCache', function () {
        $mock = new CacheMockTransport();
        $client = setupCacheConnectedClient($mock);
        $cache = $client->getCache();
        expect($cache)->toBeInstanceOf(InMemoryCache::class);
    });

    it('setCache(null) disables caching', function () {
        $mock = new CacheMockTransport();
        $client = setupCacheConnectedClient($mock);
        setCacheClientProperty($client, 'cache', null);
        setCacheClientProperty($client, 'cacheInitialized', true);
        expect($client->getCache())->toBeNull();
    });

    it('setCache with custom driver', function () {
        $mock = new CacheMockTransport();
        $client = setupCacheConnectedClient($mock);
        $cacheDir = sys_get_temp_dir() . '/opcua-test-cache-' . uniqid();
        $fileCache = new FileCache($cacheDir, 120);
        setCacheClientProperty($client, 'cache', $fileCache);
        setCacheClientProperty($client, 'cacheInitialized', true);
        expect($client->getCache())->toBe($fileCache);
        @array_map('unlink', glob($cacheDir . DIRECTORY_SEPARATOR . '*.cache') ?: []);
        @rmdir($cacheDir);
    });

    it('getCache returns the driver', function () {
        $mock = new CacheMockTransport();
        $client = setupCacheConnectedClient($mock);
        $memCache = new InMemoryCache(60);
        setCacheClientProperty($client, 'cache', $memCache);
        setCacheClientProperty($client, 'cacheInitialized', true);
        expect($client->getCache())->toBe($memCache);
    });

    it('invalidateCache for a specific node', function () {
        $mock = new CacheMockTransport();
        $mock->addResponse(cacheBrowseResponseMsg());
        $client = setupCacheConnectedClient($mock);

        $client->browse(NodeId::numeric(0, 85), useCache: true);
        $client->invalidateCache(NodeId::numeric(0, 85));

        $cache = $client->getCache();
        expect($cache)->toBeInstanceOf(InMemoryCache::class);
    });

    it('flushCache clears everything', function () {
        $mock = new CacheMockTransport();
        $mock->addResponse(cacheBrowseResponseMsg());
        $client = setupCacheConnectedClient($mock);

        $client->browse(NodeId::numeric(0, 85), useCache: true);
        $client->flushCache();

        $cache = $client->getCache();
        expect($cache->has('anything'))->toBeFalse();
    });

    it('browse with useCache=true uses cache on second call', function () {
        $mock = new CacheMockTransport();
        $mock->addResponse(cacheBrowseResponseMsg());
        $client = setupCacheConnectedClient($mock);

        $refs1 = $client->browse(NodeId::numeric(0, 85), useCache: true);
        $refs2 = $client->browse(NodeId::numeric(0, 85), useCache: true);

        expect($refs1)->toHaveCount(1);
        expect($refs2)->toHaveCount(1);
        expect(count($mock->sent))->toBe(1);
    });

    it('browse with useCache=false bypasses cache', function () {
        $mock = new CacheMockTransport();
        $mock->addResponse(cacheBrowseResponseMsg());
        $mock->addResponse(cacheBrowseResponseMsg());
        $client = setupCacheConnectedClient($mock);

        $refs1 = $client->browse(NodeId::numeric(0, 85), useCache: false);
        $refs2 = $client->browse(NodeId::numeric(0, 85), useCache: false);

        expect($refs1)->toHaveCount(1);
        expect($refs2)->toHaveCount(1);
        expect(count($mock->sent))->toBe(2);
    });

    it('browseAll with caching', function () {
        $mock = new CacheMockTransport();
        $mock->addResponse(cacheBrowseResponseMsg());
        $client = setupCacheConnectedClient($mock);

        $refs1 = $client->browseAll(NodeId::numeric(0, 85), useCache: true);
        $refs2 = $client->browseAll(NodeId::numeric(0, 85), useCache: true);

        expect($refs1)->toHaveCount(1);
        expect($refs2)->toHaveCount(1);
        expect(count($mock->sent))->toBe(1);
    });

    it('resolveNodeId with caching', function () {
        $mock = new CacheMockTransport();
        $mock->addResponse(cacheResolveResponseMsg());
        $client = setupCacheConnectedClient($mock);

        $nodeId1 = $client->resolveNodeId('/Objects/Server', useCache: true);
        $nodeId2 = $client->resolveNodeId('/Objects/Server', useCache: true);

        expect($nodeId1->getIdentifier())->toBe(2253);
        expect($nodeId2->getIdentifier())->toBe(2253);
        expect(count($mock->sent))->toBe(1);
    });

    it('invalidateCache with null cache does nothing', function () {
        $mock = new CacheMockTransport();
        $client = setupCacheConnectedClient($mock);
        setCacheClientProperty($client, 'cache', null);
        setCacheClientProperty($client, 'cacheInitialized', true);
        $client->invalidateCache(NodeId::numeric(0, 85));
        expect(true)->toBeTrue();
    });

    it('flushCache with null cache does nothing', function () {
        $mock = new CacheMockTransport();
        $client = setupCacheConnectedClient($mock);
        setCacheClientProperty($client, 'cache', null);
        setCacheClientProperty($client, 'cacheInitialized', true);
        $client->flushCache();
        expect(true)->toBeTrue();
    });

    it('invalidateCache with non-InMemory cache deletes specific keys', function () {
        $cacheDir = sys_get_temp_dir() . '/opcua-test-invalidate-' . uniqid();
        $fileCache = new FileCache($cacheDir, 300);
        $mock = new CacheMockTransport();
        $mock->addResponse(cacheBrowseResponseMsg());
        $client = setupCacheConnectedClient($mock);
        setCacheClientProperty($client, 'cache', $fileCache);
        setCacheClientProperty($client, 'cacheInitialized', true);

        $client->browse(NodeId::numeric(0, 85), useCache: true);
        $client->invalidateCache(NodeId::numeric(0, 85));

        @array_map('unlink', glob($cacheDir . DIRECTORY_SEPARATOR . '*.cache') ?: []);
        @rmdir($cacheDir);
        expect(true)->toBeTrue();
    });

    it('FileCache set creates directory if not exists', function () {
        $newDir = sys_get_temp_dir() . '/opcua-mkdir-test-' . uniqid() . '/nested';
        $cache = new FileCache($newDir, 300);
        $cache->set('testKey', 'testValue');
        expect($cache->get('testKey'))->toBe('testValue');
        @array_map('unlink', glob($newDir . DIRECTORY_SEPARATOR . '*.cache') ?: []);
        @rmdir($newDir);
    });

    it('FileCache readEntry returns null for non-existent path', function () {
        $dir = sys_get_temp_dir() . '/opcua-readentry-test-' . uniqid();
        $cache = new FileCache($dir, 300);
        $method = new ReflectionMethod($cache, 'readEntry');
        $result = $method->invoke($cache, '/non/existent/path/file.cache');
        expect($result)->toBeNull();
        @rmdir($dir);
    });

    it('FileCache readEntry returns null for corrupted file', function () {
        $dir = sys_get_temp_dir() . '/opcua-readentry-corrupt-' . uniqid();
        $cache = new FileCache($dir, 300);
        $path = $dir . '/corrupt.cache';
        file_put_contents($path, 'not-valid-serialized-data');
        $method = new ReflectionMethod($cache, 'readEntry');
        $result = $method->invoke($cache, $path);
        expect($result)->toBeNull();
        expect(file_exists($path))->toBeFalse();
        @rmdir($dir);
    });

    it('FileCache set recreates directory if deleted after construction', function () {
        $dir = sys_get_temp_dir() . '/opcua-rmdir-test-' . uniqid();
        $cache = new FileCache($dir, 300);
        rmdir($dir);
        expect(is_dir($dir))->toBeFalse();
        $cache->set('testKey', 'testValue');
        expect(is_dir($dir))->toBeTrue();
        expect($cache->get('testKey'))->toBe('testValue');
        @array_map('unlink', glob($dir . DIRECTORY_SEPARATOR . '*.cache') ?: []);
        @rmdir($dir);
    });

    it('invalidateByPrefix handles empty store', function () {
        $mock = new CacheMockTransport();
        $client = setupCacheConnectedClient($mock);
        $client->invalidateCache(NodeId::numeric(0, 85));
        expect(true)->toBeTrue();
    });
});

/**
 * Issue: cachedFetch() stores raw PHP objects in PSR-16 cache.
 * When the cache backend restricts allowed_classes (e.g. Laravel 13
 * defaults to serializable_classes => false), unserialize() restores
 * every object as __PHP_Incomplete_Class and property access throws.
 *
 * These tests simulate the full Laravel 13 roundtrip:
 * 1. Client stores a wrapped string in the PSR-16 cache
 * 2. Laravel serializes it to disk with serialize()
 * 3. Laravel reads it back with unserialize($data, ['allowed_classes' => false])
 * 4. Client reads the (still intact) string and unwraps the original objects
 *
 * @see https://github.com/php-opcua/laravel-opcua/issues/1
 */
describe('Cache serialization with restricted allowed_classes', function () {

    it('browse results survive cache roundtrip with allowed_classes=false', function () {
        $mock = new CacheMockTransport();
        $mock->addResponse(cacheBrowseResponseMsg());
        $client = setupCacheConnectedClient($mock);

        // First browse: cache miss, fetches from server
        $refs = $client->browse(NodeId::numeric(0, 85), useCache: true);
        expect($refs)->toHaveCount(1);

        // Read the raw wrapped value from the cache
        $cache = $client->getCache();
        $key = 'opcua:' . md5('opc.tcp://mock:4840') . ':browse:i=85:0:1:0';
        $raw = $cache->get($key);
        expect($raw)->toBeString();

        // Simulate Laravel 13 roundtrip: serialize to disk, then read back
        // with restricted allowed_classes — this is where the old code broke
        $afterLaravel = unserialize(serialize($raw), ['allowed_classes' => false]);

        // The wrapped string must survive intact (it's a plain string, not an object)
        expect($afterLaravel)->toBe($raw);

        // Put the Laravel-roundtripped value back into cache and browse again
        $cache->set($key, $afterLaravel);
        $refs2 = $client->browse(NodeId::numeric(0, 85), useCache: true);

        expect($refs2)->toHaveCount(1);
        expect($refs2[0])->toBeInstanceOf(PhpOpcua\Client\Types\ReferenceDescription::class);
        expect($refs2[0]->nodeId)->toBeInstanceOf(NodeId::class);
        expect($refs2[0]->browseName->name)->toBe('Server');
        expect($refs2[0]->isForward)->toBeTrue();
    });

    it('resolveNodeId result survives cache roundtrip with allowed_classes=false', function () {
        $mock = new CacheMockTransport();
        $mock->addResponse(cacheResolveResponseMsg());
        $client = setupCacheConnectedClient($mock);

        // First resolve: cache miss, fetches from server
        $nodeId = $client->resolveNodeId('/Objects/Server', useCache: true);
        expect($nodeId->getIdentifier())->toBe(2253);

        // Read the raw wrapped value from the cache
        $endpointHash = md5('opc.tcp://mock:4840');
        $pathHash = md5('Objects/Server');
        $key = "opcua:{$endpointHash}:resolve:i=84:{$pathHash}";
        $cache = $client->getCache();
        $raw = $cache->get($key);
        expect($raw)->toBeString();

        // Simulate Laravel 13 roundtrip
        $afterLaravel = unserialize(serialize($raw), ['allowed_classes' => false]);
        expect($afterLaravel)->toBe($raw);

        // Put the Laravel-roundtripped value back and resolve again from cache
        $cache->set($key, $afterLaravel);
        $nodeId2 = $client->resolveNodeId('/Objects/Server', useCache: true);

        expect($nodeId2)->toBeInstanceOf(NodeId::class);
        expect($nodeId2->getIdentifier())->toBe(2253);
    });

    it('handles corrupted wrapped value gracefully', function () {
        $mock = new CacheMockTransport();
        $mock->addResponse(cacheBrowseResponseMsg());
        $mock->addResponse(cacheBrowseResponseMsg());
        $client = setupCacheConnectedClient($mock);

        // Store a value with the correct prefix but invalid base64 payload
        $cache = $client->getCache();
        $key = 'opcua:' . md5('opc.tcp://mock:4840') . ':browse:i=85:0:1:0';
        $cache->set($key, "\x00opcua\x00" . '!!!not-valid-base64!!!');

        // Browse should treat it as a cache miss and fetch from server
        $refs = $client->browse(NodeId::numeric(0, 85), useCache: true);
        expect($refs)->toHaveCount(1);
        expect($refs[0])->toBeInstanceOf(PhpOpcua\Client\Types\ReferenceDescription::class);
    });

    it('handles legacy unwrapped cached values transparently', function () {
        $mock = new CacheMockTransport();
        $client = setupCacheConnectedClient($mock);

        // Store a legacy (pre-fix) unwrapped value directly in the cache
        $cache = $client->getCache();
        $key = 'opcua:' . md5('opc.tcp://mock:4840') . ':browse:i=85:0:1:0';
        $legacyRefs = [new PhpOpcua\Client\Types\ReferenceDescription(
            NodeId::numeric(0, 35),
            true,
            NodeId::numeric(0, 2253),
            new PhpOpcua\Client\Types\QualifiedName(0, 'Server'),
            new PhpOpcua\Client\Types\LocalizedText(null, 'Server'),
            PhpOpcua\Client\Types\NodeClass::Object,
            NodeId::numeric(0, 2004),
        )];
        $cache->set($key, $legacyRefs);

        // Browse should use the legacy value as-is (backward compatibility)
        $refs = $client->browse(NodeId::numeric(0, 85), useCache: true);
        expect($refs)->toHaveCount(1);
        expect($refs[0])->toBeInstanceOf(PhpOpcua\Client\Types\ReferenceDescription::class);
        expect($refs[0]->browseName->name)->toBe('Server');
    });
});

describe('discoverDataTypes caching', function () {

    it('replays discovered types from cache on second call', function () {
        $definition = new PhpOpcua\Client\Types\StructureDefinition(
            PhpOpcua\Client\Types\StructureDefinition::STRUCTURE,
            [
                new PhpOpcua\Client\Types\StructureField('X', NodeId::numeric(0, 11), -1, false),
                new PhpOpcua\Client\Types\StructureField('Y', NodeId::numeric(0, 11), -1, false),
            ],
            NodeId::numeric(2, 5001),
        );

        $encodingId = NodeId::numeric(2, 5001);
        $cachedData = [['encodingId' => $encodingId, 'definition' => $definition]];

        $mock = new CacheMockTransport();
        $client = setupCacheConnectedClient($mock);

        $cache = $client->getCache();
        $cacheKey = 'opcua:' . md5('opc.tcp://mock:4840') . ':dataTypes:all';
        $cache->set($cacheKey, $cachedData);

        $count = $client->discoverDataTypes(useCache: true);

        expect($count)->toBe(1);
        expect($client->getExtensionObjectRepository()->has($encodingId))->toBeTrue();
    });

    it('skips already registered codecs from cache replay', function () {
        $definition = new PhpOpcua\Client\Types\StructureDefinition(
            PhpOpcua\Client\Types\StructureDefinition::STRUCTURE,
            [new PhpOpcua\Client\Types\StructureField('X', NodeId::numeric(0, 11), -1, false)],
            NodeId::numeric(2, 5002),
        );

        $encodingId = NodeId::numeric(2, 5002);
        $cachedData = [['encodingId' => $encodingId, 'definition' => $definition]];

        $mock = new CacheMockTransport();
        $client = setupCacheConnectedClient($mock);

        $client->getExtensionObjectRepository()->register(
            $encodingId,
            new PhpOpcua\Client\Encoding\DynamicCodec($definition),
        );

        $cache = $client->getCache();
        $cacheKey = 'opcua:' . md5('opc.tcp://mock:4840') . ':dataTypes:all';
        $cache->set($cacheKey, $cachedData);

        $count = $client->discoverDataTypes(useCache: true);

        expect($count)->toBe(0);
    });
});

describe('getEndpoints caching', function () {

    it('getEndpoints uses cache on second call', function () {
        $mock = new CacheMockTransport();

        $endpointsResponseBuilder = function () {
            $e = new BinaryEncoder();
            (new MessageHeader('MSG', 'F', 0))->encode($e);
            $e->writeUInt32(1);
            $e->writeUInt32(1);
            $e->writeUInt32(1);
            $e->writeUInt32(1);
            $e->writeNodeId(NodeId::numeric(0, 431));
            $e->writeInt64(0);
            $e->writeUInt32(1);
            $e->writeUInt32(0);
            $e->writeByte(0);
            $e->writeInt32(0);
            $e->writeNodeId(NodeId::numeric(0, 0));
            $e->writeByte(0);
            $e->writeInt32(0);
            $d = $e->getBuffer();

            return substr($d, 0, 4) . pack('V', strlen($d)) . substr($d, 8);
        };

        $mock->addResponse($endpointsResponseBuilder());
        $client = setupCacheConnectedClient($mock);

        $endpoints1 = $client->getEndpoints('opc.tcp://mock:4840', useCache: true);
        $endpoints2 = $client->getEndpoints('opc.tcp://mock:4840', useCache: true);

        expect($endpoints1)->toBe($endpoints2);
        expect(count($mock->sent))->toBe(1);
    });

    it('getEndpoints with useCache=false bypasses cache', function () {
        $mock = new CacheMockTransport();

        $endpointsResponseBuilder = function () {
            $e = new BinaryEncoder();
            (new MessageHeader('MSG', 'F', 0))->encode($e);
            $e->writeUInt32(1);
            $e->writeUInt32(1);
            $e->writeUInt32(1);
            $e->writeUInt32(1);
            $e->writeNodeId(NodeId::numeric(0, 431));
            $e->writeInt64(0);
            $e->writeUInt32(1);
            $e->writeUInt32(0);
            $e->writeByte(0);
            $e->writeInt32(0);
            $e->writeNodeId(NodeId::numeric(0, 0));
            $e->writeByte(0);
            $e->writeInt32(0);
            $d = $e->getBuffer();

            return substr($d, 0, 4) . pack('V', strlen($d)) . substr($d, 8);
        };

        $mock->addResponse($endpointsResponseBuilder());
        $mock->addResponse($endpointsResponseBuilder());
        $client = setupCacheConnectedClient($mock);

        $client->getEndpoints('opc.tcp://mock:4840', useCache: false);
        $client->getEndpoints('opc.tcp://mock:4840', useCache: false);

        expect(count($mock->sent))->toBe(2);
    });
});

describe('MockClient cache', function () {

    it('getCache returns auto-initialized cache', function () {
        $mock = MockClient::create();
        $cache = $mock->getCache();
        expect($cache)->toBeInstanceOf(InMemoryCache::class);
    });

    it('getCache auto-initializes with InMemoryCache', function () {
        $mock = MockClient::create();
        expect($mock->getCache())->toBeInstanceOf(InMemoryCache::class);
    });

    it('invalidateCache records call', function () {
        $mock = MockClient::create();
        $mock->invalidateCache(NodeId::numeric(0, 85));
        expect($mock->callCount('invalidateCache'))->toBe(1);
    });

    it('flushCache records call and clears cache', function () {
        $mock = MockClient::create();
        $cache = $mock->getCache();
        $cache->set('testKey', 'testValue');
        expect($cache->get('testKey'))->toBe('testValue');

        $mock->flushCache();
        expect($mock->callCount('flushCache'))->toBe(1);
        expect($cache->get('testKey'))->toBeNull();
    });

    it('getLogger returns NullLogger by default', function () {
        $mock = MockClient::create();
        expect($mock->getLogger())->toBeInstanceOf(Psr\Log\NullLogger::class);
    });

    it('getExtensionObjectRepository returns repository', function () {
        $mock = MockClient::create();
        expect($mock->getExtensionObjectRepository())->toBeInstanceOf(PhpOpcua\Client\Repository\ExtensionObjectRepository::class);
    });
});
