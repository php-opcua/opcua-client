<?php

declare(strict_types=1);

use PhpOpcua\Client\Event\FileBytesRead;
use PhpOpcua\Client\Event\FileBytesWritten;
use PhpOpcua\Client\Event\FileClosed;
use PhpOpcua\Client\Event\FileOpened;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Kernel\ClientKernelInterface;
use PhpOpcua\Client\Module\FileTransfer\FileTransferModule;
use PhpOpcua\Client\Module\FileTransfer\OpenFileMode;
use PhpOpcua\Client\Module\ReadWrite\CallResult;
use PhpOpcua\Client\Module\TranslateBrowsePath\BrowsePathResult;
use PhpOpcua\Client\Module\TranslateBrowsePath\BrowsePathTarget;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;
use PhpOpcua\Client\Types\Variant;

/**
 * Fake client = MockClient (which implements OpcUaClientInterface) + custom
 * tracking for call()/translateBrowsePaths()/registerMethod() so the module
 * can exercise its plumbing.
 */
class FtFakeClient extends PhpOpcua\Client\Testing\MockClient
{
    /** @var array<string, callable> */
    public array $methodHandlers = [];

    /** @var array<int, array{object: NodeId, method: NodeId, args: Variant[]}> */
    public array $callsMade = [];

    /** @var CallResult[] */
    public array $callResults = [];

    /** @var array<int, BrowsePathResult[]> */
    public array $browsePathResults = [];

    public int $browsePathCallCount = 0;

    public function registerMethod(string $name, callable $handler): void
    {
        $this->methodHandlers[$name] = $handler;
    }

    public function call(NodeId|string $objectId, NodeId|string $methodId, array $inputArguments = []): CallResult
    {
        $object = $objectId instanceof NodeId ? $objectId : NodeId::parse($objectId);
        $method = $methodId instanceof NodeId ? $methodId : NodeId::parse($methodId);
        $this->callsMade[] = ['object' => $object, 'method' => $method, 'args' => $inputArguments];

        if ($this->callResults === []) {
            return new CallResult(StatusCode::Good, [], []);
        }

        return array_shift($this->callResults);
    }

    /**
     * @return BrowsePathResult[]
     */
    public function translateBrowsePaths(?array $browsePaths = null): array
    {
        $this->browsePathCallCount++;
        if ($this->browsePathResults === []) {
            $out = [];
            foreach ($browsePaths ?? [] as $i => $_) {
                $out[] = new BrowsePathResult(StatusCode::Good, [
                    new BrowsePathTarget(NodeId::string(0, "synth-method-{$this->browsePathCallCount}-{$i}"), 0),
                ]);
            }

            return $out;
        }

        return array_shift($this->browsePathResults);
    }
}

/**
 * Concrete fake kernel — implements all 33 methods of ClientKernelInterface
 * with default stubs, overriding only resolveNodeId (passthrough) and
 * dispatch (record into $events).
 */
class FtFakeKernel implements ClientKernelInterface
{
    public ArrayObject $events;

    public Psr\Log\LoggerInterface $logger;

    public Psr\EventDispatcher\EventDispatcherInterface $dispatcher;

    public PhpOpcua\Client\Repository\ExtensionObjectRepository $repo;

    public PhpOpcua\Client\Cache\CacheCodecInterface $codec;

    public function __construct(ArrayObject $events)
    {
        $this->events = $events;
        $this->logger = new Psr\Log\NullLogger();
        $this->dispatcher = new PhpOpcua\Client\Event\NullEventDispatcher();
        $this->repo = new PhpOpcua\Client\Repository\ExtensionObjectRepository();
        $this->codec = new PhpOpcua\Client\Cache\WireCacheCodec(new PhpOpcua\Client\Wire\WireTypeRegistry());
    }

    public function resolveNodeId(NodeId|string $nodeId): NodeId
    {
        return $nodeId instanceof NodeId ? $nodeId : NodeId::parse($nodeId);
    }

    public function dispatch(object $event): void
    {
        $this->events[] = $event instanceof Closure ? $event() : $event;
    }

    public function executeWithRetry(Closure $operation): mixed
    {
        return $operation();
    }

    public function ensureConnected(): void
    {
    }

    public function send(string $data): void
    {
    }

    public function receive(): string
    {
        return '';
    }

    public function nextRequestId(): int
    {
        return 1;
    }

    public function getAuthToken(): NodeId
    {
        return NodeId::numeric(0, 0);
    }

    public function unwrapResponse(string $response): string
    {
        return $response;
    }

    public function createDecoder(string $data): PhpOpcua\Client\Encoding\BinaryDecoder
    {
        return new PhpOpcua\Client\Encoding\BinaryDecoder($data);
    }

    public function resolveNodeIdArray(array &$items, string $key = 'nodeId'): void
    {
    }

    public function log(): Psr\Log\LoggerInterface
    {
        return $this->logger;
    }

    public function logContext(array $context = []): array
    {
        return $context;
    }

    public function cachedFetch(string $key, callable $fetcher, bool $useCache): mixed
    {
        return $fetcher();
    }

    public function buildCacheKey(string $type, NodeId $nodeId, string $paramsSuffix = ''): string
    {
        return '';
    }

    public function buildSimpleCacheKey(string $type, string $paramsSuffix = ''): string
    {
        return '';
    }

    public function ensureCacheInitialized(): void
    {
    }

    public function getCache(): ?Psr\SimpleCache\CacheInterface
    {
        return null;
    }

    public function getCacheCodec(): PhpOpcua\Client\Cache\CacheCodecInterface
    {
        return $this->codec;
    }

    public function getEffectiveReadBatchSize(): ?int
    {
        return null;
    }

    public function getEffectiveWriteBatchSize(): ?int
    {
        return null;
    }

    public function getLogger(): Psr\Log\LoggerInterface
    {
        return $this->logger;
    }

    public function getEventDispatcher(): Psr\EventDispatcher\EventDispatcherInterface
    {
        return $this->dispatcher;
    }

    public function getTimeout(): float
    {
        return 5.0;
    }

    public function getAutoRetry(): int
    {
        return 0;
    }

    public function getBatchSize(): ?int
    {
        return null;
    }

    public function getServerMaxNodesPerRead(): ?int
    {
        return null;
    }

    public function getServerMaxNodesPerWrite(): ?int
    {
        return null;
    }

    public function getDefaultBrowseMaxDepth(): int
    {
        return 10;
    }

    public function isAutoDetectWriteType(): bool
    {
        return false;
    }

    public function isReadMetadataCache(): bool
    {
        return false;
    }

    public function getExtensionObjectRepository(): PhpOpcua\Client\Repository\ExtensionObjectRepository
    {
        return $this->repo;
    }

    public function getEnumMappings(): array
    {
        return [];
    }
}

/**
 * Build a fresh (module, client, events) trio. The kernel is the fake above.
 *
 * @return array{module: FileTransferModule, client: FtFakeClient, events: ArrayObject}
 */
function ftWire(): array
{
    $client = new FtFakeClient();
    $events = new ArrayObject();
    $kernel = new FtFakeKernel($events);

    $module = new FileTransferModule();
    (new ReflectionProperty($module, 'client'))->setValue($module, $client);
    (new ReflectionProperty($module, 'kernel'))->setValue($module, $kernel);
    $module->register();

    return ['module' => $module, 'client' => $client, 'events' => $events];
}

describe('OpenFileMode enum', function () {

    it('exposes the four Part 5 bit values', function () {
        expect(OpenFileMode::Read->value)->toBe(1);
        expect(OpenFileMode::Write->value)->toBe(2);
        expect(OpenFileMode::EraseExisting->value)->toBe(4);
        expect(OpenFileMode::Append->value)->toBe(8);
    });

    it('toByte() OR-combines multiple cases', function () {
        expect(OpenFileMode::toByte(OpenFileMode::Write, OpenFileMode::EraseExisting))->toBe(6);
        expect(OpenFileMode::toByte(OpenFileMode::Read, OpenFileMode::Write))->toBe(3);
        expect(OpenFileMode::toByte(OpenFileMode::Read))->toBe(1);
        expect(OpenFileMode::toByte())->toBe(0);
    });

});

describe('FileTransferModule registration', function () {

    it('registers the six File Transfer methods on the client', function () {
        ['client' => $client] = ftWire();

        expect($client->methodHandlers)->toHaveKey('openFile');
        expect($client->methodHandlers)->toHaveKey('closeFile');
        expect($client->methodHandlers)->toHaveKey('readFile');
        expect($client->methodHandlers)->toHaveKey('writeFile');
        expect($client->methodHandlers)->toHaveKey('getFilePosition');
        expect($client->methodHandlers)->toHaveKey('setFilePosition');
    });

    it('reset() clears the method-resolution cache', function () {
        ['module' => $module, 'client' => $client] = ftWire();

        $client->callResults[] = new CallResult(StatusCode::Good, [], [new Variant(BuiltinType::UInt32, 1)]);
        $client->callResults[] = new CallResult(StatusCode::Good, [], [new Variant(BuiltinType::UInt32, 1)]);

        ($client->methodHandlers['openFile'])('ns=1;s=Files/File1', OpenFileMode::Read);
        $callsBefore = $client->browsePathCallCount;

        $module->reset();
        ($client->methodHandlers['openFile'])('ns=1;s=Files/File1', OpenFileMode::Read);

        expect($client->browsePathCallCount)->toBeGreaterThan($callsBefore);
    });

});

describe('FileTransferModule::openFile', function () {

    it('translates "Open" browse path and Call()s with mode as Byte variant', function () {
        ['client' => $client] = ftWire();

        $client->callResults[] = new CallResult(StatusCode::Good, [], [new Variant(BuiltinType::UInt32, 42)]);

        $handle = ($client->methodHandlers['openFile'])('ns=1;s=Files/Foo', OpenFileMode::Read);

        expect($handle)->toBe(42);
        expect($client->callsMade)->toHaveCount(1);
        expect($client->callsMade[0]['args'][0]->type)->toBe(BuiltinType::Byte);
        expect($client->callsMade[0]['args'][0]->value)->toBe(1);
    });

    it('accepts a pre-combined int mode (via toByte)', function () {
        ['client' => $client] = ftWire();

        $client->callResults[] = new CallResult(StatusCode::Good, [], [new Variant(BuiltinType::UInt32, 7)]);

        ($client->methodHandlers['openFile'])('ns=1;s=Files/Foo', OpenFileMode::toByte(OpenFileMode::Write, OpenFileMode::EraseExisting));

        expect($client->callsMade[0]['args'][0]->value)->toBe(6);
    });

    it('dispatches FileOpened with handle, mode, and fileNodeId', function () {
        ['client' => $client, 'events' => $events] = ftWire();

        $client->callResults[] = new CallResult(StatusCode::Good, [], [new Variant(BuiltinType::UInt32, 9)]);

        ($client->methodHandlers['openFile'])('ns=1;s=Files/Bar', OpenFileMode::Write);

        $event = $events[0] ?? null;
        expect($event)->toBeInstanceOf(FileOpened::class);
        expect($event->fileHandle)->toBe(9);
        expect($event->mode)->toBe(2);
        expect((string) $event->fileNodeId)->toBe('ns=1;s=Files/Bar');
    });

    it('throws ServiceException when Call returns a bad statusCode', function () {
        ['client' => $client] = ftWire();

        $client->callResults[] = new CallResult(StatusCode::BadNotWritable, [], []);

        expect(fn () => ($client->methodHandlers['openFile'])('ns=1;s=Files/Foo', OpenFileMode::Write))
            ->toThrow(ServiceException::class, 'BadNotWritable');
    });

    it('throws ServiceException when translateBrowsePaths returns a bad statusCode', function () {
        ['client' => $client] = ftWire();

        $client->browsePathResults[] = [new BrowsePathResult(StatusCode::BadNodeIdUnknown, [])];

        expect(fn () => ($client->methodHandlers['openFile'])('ns=1;s=Files/Missing', OpenFileMode::Read))
            ->toThrow(ServiceException::class, "Cannot resolve File Transfer method 'Open'");
    });

});

describe('FileTransferModule::closeFile', function () {

    it('Call()s Close with handle as UInt32 and dispatches FileClosed', function () {
        ['client' => $client, 'events' => $events] = ftWire();

        ($client->methodHandlers['closeFile'])('ns=1;s=Files/Foo', 42);

        expect($client->callsMade)->toHaveCount(1);
        expect($client->callsMade[0]['args'][0]->type)->toBe(BuiltinType::UInt32);
        expect($client->callsMade[0]['args'][0]->value)->toBe(42);

        $event = $events[0] ?? null;
        expect($event)->toBeInstanceOf(FileClosed::class);
        expect($event->fileHandle)->toBe(42);
    });

});

describe('FileTransferModule::readFile', function () {

    it('Call()s Read with (UInt32, Int32), returns bytes, dispatches FileBytesRead', function () {
        ['client' => $client, 'events' => $events] = ftWire();

        $client->callResults[] = new CallResult(StatusCode::Good, [], [new Variant(BuiltinType::ByteString, 'hello')]);

        $bytes = ($client->methodHandlers['readFile'])('ns=1;s=Files/Foo', 42, 1024);

        expect($bytes)->toBe('hello');
        expect($client->callsMade[0]['args'][0]->type)->toBe(BuiltinType::UInt32);
        expect($client->callsMade[0]['args'][1]->type)->toBe(BuiltinType::Int32);
        expect($client->callsMade[0]['args'][1]->value)->toBe(1024);

        $event = $events[0] ?? null;
        expect($event)->toBeInstanceOf(FileBytesRead::class);
        expect($event->bytesRead)->toBe(5);
        expect($event->requestedLength)->toBe(1024);
    });

    it('returns empty string when server returns no payload (EOF case)', function () {
        ['client' => $client] = ftWire();

        $client->callResults[] = new CallResult(StatusCode::Good, [], [new Variant(BuiltinType::ByteString, null)]);

        expect(($client->methodHandlers['readFile'])('ns=1;s=Files/Foo', 42, 100))->toBe('');
    });

});

describe('FileTransferModule::writeFile', function () {

    it('Call()s Write with (UInt32, ByteString) and dispatches FileBytesWritten', function () {
        ['client' => $client, 'events' => $events] = ftWire();

        ($client->methodHandlers['writeFile'])('ns=1;s=Files/Foo', 42, 'payload');

        expect($client->callsMade[0]['args'][1]->type)->toBe(BuiltinType::ByteString);
        expect($client->callsMade[0]['args'][1]->value)->toBe('payload');

        $event = $events[0] ?? null;
        expect($event)->toBeInstanceOf(FileBytesWritten::class);
        expect($event->bytesWritten)->toBe(7);
    });

});

describe('FileTransferModule position', function () {

    it('getFilePosition returns the UInt64 output of GetPosition', function () {
        ['client' => $client] = ftWire();

        $client->callResults[] = new CallResult(StatusCode::Good, [], [new Variant(BuiltinType::UInt64, 1234)]);

        expect(($client->methodHandlers['getFilePosition'])('ns=1;s=Files/Foo', 42))->toBe(1234);
    });

    it('setFilePosition Call()s SetPosition with (UInt32, UInt64)', function () {
        ['client' => $client] = ftWire();

        ($client->methodHandlers['setFilePosition'])('ns=1;s=Files/Foo', 42, 999);

        expect($client->callsMade[0]['args'][0]->type)->toBe(BuiltinType::UInt32);
        expect($client->callsMade[0]['args'][1]->type)->toBe(BuiltinType::UInt64);
        expect($client->callsMade[0]['args'][1]->value)->toBe(999);
    });

});

describe('FileTransferModule method-resolution cache', function () {

    it('translates each (fileNode, method) only once across multiple calls', function () {
        ['client' => $client] = ftWire();

        $client->callResults[] = new CallResult(StatusCode::Good, [], [new Variant(BuiltinType::UInt32, 1)]);
        $client->callResults[] = new CallResult(StatusCode::Good, [], [new Variant(BuiltinType::UInt32, 2)]);
        $client->callResults[] = new CallResult(StatusCode::Good, [], [new Variant(BuiltinType::UInt32, 3)]);

        ($client->methodHandlers['openFile'])('ns=1;s=Files/Foo', OpenFileMode::Read);
        ($client->methodHandlers['openFile'])('ns=1;s=Files/Foo', OpenFileMode::Read);
        ($client->methodHandlers['openFile'])('ns=1;s=Files/Foo', OpenFileMode::Read);

        expect($client->browsePathCallCount)->toBe(1);
    });

    it('keeps separate cache entries for different file nodes', function () {
        ['client' => $client] = ftWire();

        $client->callResults[] = new CallResult(StatusCode::Good, [], [new Variant(BuiltinType::UInt32, 1)]);
        $client->callResults[] = new CallResult(StatusCode::Good, [], [new Variant(BuiltinType::UInt32, 2)]);

        ($client->methodHandlers['openFile'])('ns=1;s=Files/A', OpenFileMode::Read);
        ($client->methodHandlers['openFile'])('ns=1;s=Files/B', OpenFileMode::Read);

        expect($client->browsePathCallCount)->toBe(2);
    });

});
