<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Exception\ConnectionException;
use Gianfriaur\OpcuaPhpClient\Exception\ServiceException;
use Gianfriaur\OpcuaPhpClient\Protocol\MessageHeader;
use Gianfriaur\OpcuaPhpClient\Protocol\SessionService;
use Gianfriaur\OpcuaPhpClient\Transport\TcpTransport;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\ConnectionState;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\QualifiedName;
use Gianfriaur\OpcuaPhpClient\Types\Variant;

class MockTransport extends TcpTransport
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
            throw new ConnectionException('No more mock responses');
        }
        return $this->responses[$this->index++];
    }

    public function close(): void {}
    public function isConnected(): bool { return true; }
}

function setClientProperty(Client $client, string $name, mixed $value): void
{
    $ref = new ReflectionProperty($client, $name);
    $ref->setValue($client, $value);
}

function callClientMethod(Client $client, string $name, array $args = []): mixed
{
    $ref = new ReflectionMethod($client, $name);
    return $ref->invokeArgs($client, $args);
}

function buildMsgResponse(int $typeId, Closure $writeBody): string
{
    $e = new BinaryEncoder();
    (new MessageHeader('MSG', 'F', 0))->encode($e);
    $e->writeUInt32(1);
    $e->writeUInt32(1);
    $e->writeUInt32(1);
    $e->writeUInt32(1);
    $e->writeNodeId(NodeId::numeric(0, $typeId));
    $e->writeInt64(0);
    $e->writeUInt32(1);
    $e->writeUInt32(0);
    $e->writeByte(0);
    $e->writeInt32(0);
    $e->writeNodeId(NodeId::numeric(0, 0));
    $e->writeByte(0);
    $writeBody($e);
    $d = $e->getBuffer();
    return substr($d, 0, 4) . pack('V', strlen($d)) . substr($d, 8);
}

function buildErrMsg(int $code = 0x80010000, string $reason = 'Server error'): string
{
    $e = new BinaryEncoder();
    (new MessageHeader('ERR', 'F', 0))->encode($e);
    $e->writeUInt32($code);
    $e->writeString($reason);
    $d = $e->getBuffer();
    return substr($d, 0, 4) . pack('V', strlen($d)) . substr($d, 8);
}

function readResponseMsg(int $value = 42): string
{
    return buildMsgResponse(634, function (BinaryEncoder $e) use ($value) {
        $e->writeInt32(1);
        $e->writeByte(0x01);
        $e->writeByte(BuiltinType::Int32->value);
        $e->writeInt32($value);
        $e->writeInt32(0);
    });
}

function browseResponseMsg(): string
{
    return buildMsgResponse(530, function (BinaryEncoder $e) {
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
    });
}

function browseResponseWithContinuationMsg(): string
{
    return buildMsgResponse(530, function (BinaryEncoder $e) {
        $e->writeInt32(1);
        $e->writeUInt32(0);
        $e->writeByteString('cont-point');
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
    });
}

function browseNextResponseMsg(): string
{
    return buildMsgResponse(536, function (BinaryEncoder $e) {
        $e->writeInt32(1);
        $e->writeUInt32(0);
        $e->writeByteString(null);
        $e->writeInt32(1);
        $e->writeNodeId(NodeId::numeric(0, 35));
        $e->writeBoolean(true);
        $e->writeExpandedNodeId(NodeId::numeric(0, 2254));
        $e->writeUInt16(0);
        $e->writeString('ServerArray');
        $e->writeByte(0x02);
        $e->writeString('ServerArray');
        $e->writeUInt32(2);
        $e->writeExpandedNodeId(NodeId::numeric(0, 68));
        $e->writeInt32(0);
    });
}

function setupConnectedClient(MockTransport $mock): Client
{
    $client = new Client();
    $session = new SessionService(1, 1);

    setClientProperty($client, 'transport', $mock);
    setClientProperty($client, 'connectionState', ConnectionState::Connected);
    setClientProperty($client, 'session', $session);
    setClientProperty($client, 'authenticationToken', NodeId::numeric(0, 2));
    setClientProperty($client, 'secureChannelId', 1);
    setClientProperty($client, 'lastEndpointUrl', 'opc.tcp://mock:4840');
    callClientMethod($client, 'initServices', [$session]);

    return $client;
}

describe('Client unwrapResponse ERR handling', function () {

    it('throws ServiceException when response starts with ERR', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildErrMsg(0x80010000, 'Bad unexpected'));

        $client = setupConnectedClient($mock);

        expect(fn() => $client->read(NodeId::numeric(0, 2259)))
            ->toThrow(ServiceException::class, 'Bad unexpected');
    });

    it('throws ServiceException with error code from ERR response', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildErrMsg(0x80340000, 'Node not found'));

        $client = setupConnectedClient($mock);

        try {
            $client->read(NodeId::numeric(0, 2259));
            expect(false)->toBeTrue();
        } catch (ServiceException $e) {
            expect($e->getStatusCode())->toBe(0x80340000);
        }
    });
});

describe('ManagesBrowseTrait operations', function () {

    it('getEndpoints returns endpoints from server', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(431, function (BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeString('opc.tcp://localhost:4840');
            $e->writeString('urn:server');
            $e->writeString(null);
            $e->writeByte(0x02);
            $e->writeString('Server');
            $e->writeUInt32(0);
            $e->writeString(null);
            $e->writeString(null);
            $e->writeInt32(0);
            $e->writeByteString(null);
            $e->writeUInt32(1);
            $e->writeString('http://opcfoundation.org/UA/SecurityPolicy#None');
            $e->writeInt32(1);
            $e->writeString('anonymous');
            $e->writeUInt32(0);
            $e->writeString(null);
            $e->writeString(null);
            $e->writeString(null);
            $e->writeString(null);
            $e->writeByte(0);
        }));

        $client = setupConnectedClient($mock);
        $endpoints = $client->getEndpoints('opc.tcp://localhost:4840');

        expect($endpoints)->toHaveCount(1);
        expect($endpoints[0]->getEndpointUrl())->toBe('opc.tcp://localhost:4840');
    });

    it('browseNext returns references', function () {
        $mock = new MockTransport();
        $mock->addResponse(browseNextResponseMsg());

        $client = setupConnectedClient($mock);
        $result = $client->browseNext('cont-point');

        expect($result['references'])->toHaveCount(1);
        expect($result['references'][0]->getDisplayName()->getText())->toBe('ServerArray');
        expect($result['continuationPoint'])->toBeNull();
    });

    it('browseAll follows continuation points', function () {
        $mock = new MockTransport();
        $mock->addResponse(browseResponseWithContinuationMsg());
        $mock->addResponse(browseNextResponseMsg());

        $client = setupConnectedClient($mock);
        $refs = $client->browseAll(NodeId::numeric(0, 85));

        expect($refs)->toHaveCount(2);
    });

    it('browseRecursive builds tree', function () {
        $mock = new MockTransport();
        $mock->addResponse(browseResponseMsg());

        $mock->addResponse(buildMsgResponse(530, function (BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeUInt32(0);
            $e->writeByteString(null);
            $e->writeInt32(0);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        $tree = $client->browseRecursive(NodeId::numeric(0, 85), maxDepth: 2);

        expect($tree)->toHaveCount(1);
        expect($tree[0]->getDisplayName()->getText())->toBe('Server');
    });
});

describe('ManagesHistoryTrait operations', function () {

    it('historyReadProcessed returns data values', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(667, function (BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeUInt32(0);
            $e->writeByteString(null);
            $e->writeNodeId(NodeId::numeric(0, 658));
            $e->writeByte(0x01);
            $bodyEncoder = new BinaryEncoder();
            $bodyEncoder->writeInt32(1);
            $bodyEncoder->writeByte(0x01);
            $bodyEncoder->writeByte(BuiltinType::Double->value);
            $bodyEncoder->writeDouble(23.5);
            $body = $bodyEncoder->getBuffer();
            $e->writeInt32(strlen($body));
            $e->writeRawBytes($body);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        $values = $client->historyReadProcessed(
            NodeId::numeric(2, 1001),
            new DateTimeImmutable('2024-01-01'),
            new DateTimeImmutable('2024-01-02'),
            3600000.0,
            NodeId::numeric(0, 2342),
        );

        expect($values)->toHaveCount(1);
        expect($values[0]->getValue())->toBe(23.5);
    });

    it('historyReadAtTime returns data values', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(667, function (BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeUInt32(0);
            $e->writeByteString(null);
            $e->writeNodeId(NodeId::numeric(0, 658));
            $e->writeByte(0x01);
            $bodyEncoder = new BinaryEncoder();
            $bodyEncoder->writeInt32(1);
            $bodyEncoder->writeByte(0x01);
            $bodyEncoder->writeByte(BuiltinType::Int32->value);
            $bodyEncoder->writeInt32(77);
            $body = $bodyEncoder->getBuffer();
            $e->writeInt32(strlen($body));
            $e->writeRawBytes($body);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        $values = $client->historyReadAtTime(
            NodeId::numeric(2, 1001),
            [new DateTimeImmutable('2024-01-01 12:00:00')],
        );

        expect($values)->toHaveCount(1);
        expect($values[0]->getValue())->toBe(77);
    });

    it('historyReadRaw returns data values', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(667, function (BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeUInt32(0);
            $e->writeByteString(null);
            $e->writeNodeId(NodeId::numeric(0, 658));
            $e->writeByte(0x01);
            $bodyEncoder = new BinaryEncoder();
            $bodyEncoder->writeInt32(2);
            $bodyEncoder->writeByte(0x01);
            $bodyEncoder->writeByte(BuiltinType::Double->value);
            $bodyEncoder->writeDouble(10.0);
            $bodyEncoder->writeByte(0x01);
            $bodyEncoder->writeByte(BuiltinType::Double->value);
            $bodyEncoder->writeDouble(20.0);
            $body = $bodyEncoder->getBuffer();
            $e->writeInt32(strlen($body));
            $e->writeRawBytes($body);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        $values = $client->historyReadRaw(
            NodeId::numeric(2, 1001),
            new DateTimeImmutable('-1 hour'),
            new DateTimeImmutable(),
        );

        expect($values)->toHaveCount(2);
    });
});

describe('ManagesTranslateBrowsePathTrait operations', function () {

    it('translateBrowsePaths returns results', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(557, function (BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeUInt32(0);
            $e->writeInt32(1);
            $e->writeExpandedNodeId(NodeId::numeric(0, 2253));
            $e->writeUInt32(0xFFFFFFFF);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        $results = $client->translateBrowsePaths([
            [
                'startingNodeId' => NodeId::numeric(0, 85),
                'relativePath' => [['targetName' => new QualifiedName(0, 'Server')]],
            ],
        ]);

        expect($results)->toHaveCount(1);
        expect($results[0]['targets'][0]['targetId']->getIdentifier())->toBe(2253);
    });

    it('resolveNodeId returns NodeId', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(557, function (BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeUInt32(0);
            $e->writeInt32(1);
            $e->writeExpandedNodeId(NodeId::numeric(0, 2253));
            $e->writeUInt32(0xFFFFFFFF);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        $nodeId = $client->resolveNodeId('/Objects/Server');

        expect($nodeId->getIdentifier())->toBe(2253);
    });

    it('resolveNodeId throws on empty results', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(557, function (BinaryEncoder $e) {
            $e->writeInt32(0);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);

        expect(fn() => $client->resolveNodeId('/Objects/NonExistent'))
            ->toThrow(ServiceException::class, 'no results');
    });

    it('resolveNodeId throws on bad status code', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(557, function (BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeUInt32(0x80340000);
            $e->writeInt32(0);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);

        expect(fn() => $client->resolveNodeId('/Objects/Bad'))
            ->toThrow(ServiceException::class, 'Failed to resolve');
    });

    it('resolveNodeId throws on empty targets', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(557, function (BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeUInt32(0);
            $e->writeInt32(0);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);

        expect(fn() => $client->resolveNodeId('/Objects/Empty'))
            ->toThrow(ServiceException::class, 'No targets');
    });
});

describe('ManagesConnectionTrait retry and disconnect', function () {

    it('executeWithRetry retries on ConnectionException', function () {
        $mock = new MockTransport();
        $client = setupConnectedClient($mock);
        $client->setAutoRetry(0);

        expect(fn() => $client->read(NodeId::numeric(0, 2259)))
            ->toThrow(ConnectionException::class);

        expect($client->getConnectionState())->toBe(ConnectionState::Broken);
    });
});

describe('ManagesBatchingTrait operation limits', function () {

    it('handles server that does not support operation limits', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildErrMsg(0x800B0000, 'Service unsupported'));

        $client = setupConnectedClient($mock);
        callClientMethod($client, 'discoverServerOperationLimits');

        expect($client->getServerMaxNodesPerRead())->toBeNull();
        expect($client->getServerMaxNodesPerWrite())->toBeNull();
    });
});
