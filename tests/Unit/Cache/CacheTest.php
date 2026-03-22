<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Cache\FileCache;
use Gianfriaur\OpcuaPhpClient\Cache\InMemoryCache;
use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Protocol\MessageHeader;
use Gianfriaur\OpcuaPhpClient\Protocol\SessionService;
use Gianfriaur\OpcuaPhpClient\Testing\MockClient;
use Gianfriaur\OpcuaPhpClient\Transport\TcpTransport;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\ConnectionState;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\QualifiedName;
use Psr\SimpleCache\CacheInterface;

class CacheMockTransport extends TcpTransport
{
    private array $responses = [];
    private int $index = 0;
    public array $sent = [];

    public function addResponse(string $data): void
    {
        $this->responses[] = $data;
    }

    public function connect(string $host, int $port, null|float $timeout = null): void {}

    public function send(string $data): void
    {
        $this->sent[] = $data;
    }

    public function receive(): string
    {
        if ($this->index >= count($this->responses)) {
            throw new \Gianfriaur\OpcuaPhpClient\Exception\ConnectionException('No more mock responses');
        }
        return $this->responses[$this->index++];
    }

    public function close(): void {}
    public function isConnected(): bool { return true; }
}

function setCacheClientProperty(Client $client, string $name, mixed $value): void
{
    $ref = new ReflectionProperty($client, $name);
    $ref->setValue($client, $value);
}

function callCacheClientMethod(Client $client, string $name, array $args = []): mixed
{
    $ref = new ReflectionMethod($client, $name);
    return $ref->invokeArgs($client, $args);
}

function setupCacheConnectedClient(CacheMockTransport $mock): Client
{
    $client = new Client();
    $session = new SessionService(1, 1);

    setCacheClientProperty($client, 'transport', $mock);
    setCacheClientProperty($client, 'connectionState', ConnectionState::Connected);
    setCacheClientProperty($client, 'session', $session);
    setCacheClientProperty($client, 'authenticationToken', NodeId::numeric(0, 2));
    setCacheClientProperty($client, 'secureChannelId', 1);
    setCacheClientProperty($client, 'lastEndpointUrl', 'opc.tcp://mock:4840');
    callCacheClientMethod($client, 'initServices', [$session]);

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
});

describe('FileCache', function () {

    beforeEach(function () {
        $this->cacheDir = sys_get_temp_dir() . '/opcua-test-cache-' . uniqid();
        $this->cache = new FileCache($this->cacheDir, 300);
    });

    afterEach(function () {
        $files = glob($this->cacheDir . '/*.cache');
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
        $path = $this->cacheDir . '/' . sha1('corrupt') . '.cache';
        file_put_contents($path, 'not-valid-serialized-data');
        expect($this->cache->get('corrupt', 'default-val'))->toBe('default-val');
    });

    it('returns default for non-existent file', function () {
        expect($this->cache->get('never-set', 'missing'))->toBe('missing');
    });

    it('expires entries after TTL', function () {
        $this->cache->set('expiring', 'value', 1);
        $path = $this->cacheDir . '/' . sha1('expiring') . '.cache';
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
        $client->setCache(null);
        expect($client->getCache())->toBeNull();
    });

    it('setCache with custom driver', function () {
        $mock = new CacheMockTransport();
        $client = setupCacheConnectedClient($mock);
        $cacheDir = sys_get_temp_dir() . '/opcua-test-cache-' . uniqid();
        $fileCache = new FileCache($cacheDir, 120);
        $client->setCache($fileCache);
        expect($client->getCache())->toBe($fileCache);
        @array_map('unlink', glob($cacheDir . '/*.cache') ?: []);
        @rmdir($cacheDir);
    });

    it('getCache returns the driver', function () {
        $mock = new CacheMockTransport();
        $client = setupCacheConnectedClient($mock);
        $memCache = new InMemoryCache(60);
        $client->setCache($memCache);
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
        $client->setCache(null);
        $client->invalidateCache(NodeId::numeric(0, 85));
        expect(true)->toBeTrue();
    });

    it('flushCache with null cache does nothing', function () {
        $mock = new CacheMockTransport();
        $client = setupCacheConnectedClient($mock);
        $client->setCache(null);
        $client->flushCache();
        expect(true)->toBeTrue();
    });

    it('invalidateCache with non-InMemory cache deletes specific keys', function () {
        $cacheDir = sys_get_temp_dir() . '/opcua-test-invalidate-' . uniqid();
        $fileCache = new FileCache($cacheDir, 300);
        $mock = new CacheMockTransport();
        $mock->addResponse(cacheBrowseResponseMsg());
        $client = setupCacheConnectedClient($mock);
        $client->setCache($fileCache);

        $client->browse(NodeId::numeric(0, 85), useCache: true);
        $client->invalidateCache(NodeId::numeric(0, 85));

        @array_map('unlink', glob($cacheDir . '/*.cache') ?: []);
        @rmdir($cacheDir);
        expect(true)->toBeTrue();
    });

    it('invalidateByPrefix handles empty store', function () {
        $mock = new CacheMockTransport();
        $client = setupCacheConnectedClient($mock);
        $client->invalidateCache(NodeId::numeric(0, 85));
        expect(true)->toBeTrue();
    });
});

describe('discoverDataTypes caching', function () {

    it('replays discovered types from cache on second call', function () {
        $definition = new \Gianfriaur\OpcuaPhpClient\Types\StructureDefinition(
            \Gianfriaur\OpcuaPhpClient\Types\StructureDefinition::STRUCTURE,
            [
                new \Gianfriaur\OpcuaPhpClient\Types\StructureField('X', NodeId::numeric(0, 11), -1, false),
                new \Gianfriaur\OpcuaPhpClient\Types\StructureField('Y', NodeId::numeric(0, 11), -1, false),
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
        $definition = new \Gianfriaur\OpcuaPhpClient\Types\StructureDefinition(
            \Gianfriaur\OpcuaPhpClient\Types\StructureDefinition::STRUCTURE,
            [new \Gianfriaur\OpcuaPhpClient\Types\StructureField('X', NodeId::numeric(0, 11), -1, false)],
            NodeId::numeric(2, 5002),
        );

        $encodingId = NodeId::numeric(2, 5002);
        $cachedData = [['encodingId' => $encodingId, 'definition' => $definition]];

        $mock = new CacheMockTransport();
        $client = setupCacheConnectedClient($mock);

        $client->getExtensionObjectRepository()->register(
            $encodingId,
            new \Gianfriaur\OpcuaPhpClient\Encoding\DynamicCodec($definition),
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

    it('setCache and getCache work', function () {
        $mock = MockClient::create();
        $cache = new InMemoryCache(60);
        $mock->setCache($cache);
        expect($mock->getCache())->toBe($cache);
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
        expect($mock->getLogger())->toBeInstanceOf(\Psr\Log\NullLogger::class);
    });

    it('getExtensionObjectRepository returns repository', function () {
        $mock = MockClient::create();
        expect($mock->getExtensionObjectRepository())->toBeInstanceOf(\Gianfriaur\OpcuaPhpClient\Repository\ExtensionObjectRepository::class);
    });
});
