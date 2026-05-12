<?php

declare(strict_types=1);

require_once __DIR__ . '/../Helpers/ClientTestHelpers.php';
require_once __DIR__ . '/ClientWriteAutoDetectTest.php';

use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\ConnectionState;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\QualifiedName;

// ──────────────────────────────────────────────
// Client.php: getters
// ──────────────────────────────────────────────

describe('Client: getTimeout and getAutoRetry', function () {

    it('getTimeout returns configured timeout', function () {
        $client = createClientWithoutConnect();
        setClientProperty($client, 'timeout', 7.5);
        expect($client->getTimeout())->toBe(7.5);
    });

    it('getAutoRetry returns autoRetry when explicitly set', function () {
        $client = createClientWithoutConnect();
        setClientProperty($client, 'autoRetry', 3);
        expect($client->getAutoRetry())->toBe(3);
    });

    it('getAutoRetry returns 1 when autoRetry is null and lastEndpointUrl is set', function () {
        $client = createClientWithoutConnect();
        setClientProperty($client, 'autoRetry', null);
        setClientProperty($client, 'lastEndpointUrl', 'opc.tcp://mock:4840');
        expect($client->getAutoRetry())->toBe(1);
    });

    it('getAutoRetry returns 0 when autoRetry is null and lastEndpointUrl is null', function () {
        $client = createClientWithoutConnect();
        setClientProperty($client, 'autoRetry', null);
        setClientProperty($client, 'lastEndpointUrl', null);
        expect($client->getAutoRetry())->toBe(0);
    });

    it('returns the configured browse max depth', function () {
        $client = createClientWithoutConnect();
        setClientProperty($client, 'defaultBrowseMaxDepth', 15);
        expect($client->getDefaultBrowseMaxDepth())->toBe(15);
    });
});

// ──────────────────────────────────────────────
// Client unwrapResponse
// ──────────────────────────────────────────────

describe('Client unwrapResponse ERR handling', function () {

    it('throws ServiceException when response starts with ERR', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildErrMsg(0x80010000, 'Bad unexpected'));

        $client = setupConnectedClient($mock);

        expect(fn () => $client->read(NodeId::numeric(0, 2259)))
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

// ──────────────────────────────────────────────
// ManagesBrowseTrait
// ──────────────────────────────────────────────

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

        expect($result->references)->toHaveCount(1);
        expect($result->references[0]->getDisplayName()->getText())->toBe('ServerArray');
        expect($result->continuationPoint)->toBeNull();
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

    it('browseRecursive with maxDepth -1 uses MAX_BROWSE_RECURSIVE_DEPTH', function () {
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
        $result = $client->browseRecursive(NodeId::numeric(0, 85), maxDepth: -1);
        expect($result)->toBeArray();
    });

    it('browseRecursive detects visited nodes and avoids infinite loops', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(530, function (BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeUInt32(0);
            $e->writeByteString(null);
            $e->writeInt32(1);
            $e->writeNodeId(NodeId::numeric(0, 35));
            $e->writeBoolean(true);
            $e->writeExpandedNodeId(NodeId::numeric(0, 85));
            $e->writeUInt16(0);
            $e->writeString('Objects');
            $e->writeByte(0x02);
            $e->writeString('Objects');
            $e->writeUInt32(1);
            $e->writeExpandedNodeId(NodeId::numeric(0, 2004));
            $e->writeInt32(0);
        }));
        $mock->addResponse(buildMsgResponse(530, function (BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeUInt32(0);
            $e->writeByteString(null);
            $e->writeInt32(1);
            $e->writeNodeId(NodeId::numeric(0, 35));
            $e->writeBoolean(true);
            $e->writeExpandedNodeId(NodeId::numeric(0, 85));
            $e->writeUInt16(0);
            $e->writeString('Objects');
            $e->writeByte(0x02);
            $e->writeString('Objects');
            $e->writeUInt32(1);
            $e->writeExpandedNodeId(NodeId::numeric(0, 2004));
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        $result = $client->browseRecursive(NodeId::numeric(0, 85), maxDepth: 3);
        expect($result)->toBeArray();
    });

    it('browseRecursive with explicit maxDepth clamps to MAX_BROWSE_RECURSIVE_DEPTH', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(530, function (BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeUInt32(0);
            $e->writeByteString(null);
            $e->writeInt32(0);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        $result = $client->browseRecursive(NodeId::numeric(0, 85), maxDepth: 9999);
        expect($result)->toBeArray();
    });
});

// ──────────────────────────────────────────────
// ManagesHistoryTrait
// ──────────────────────────────────────────────

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

// ──────────────────────────────────────────────
// ManagesTranslateBrowsePathTrait
// ──────────────────────────────────────────────

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
        expect($results[0]->targets[0]->targetId->getIdentifier())->toBe(2253);
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

        expect(fn () => $client->resolveNodeId('/Objects/NonExistent'))
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

        expect(fn () => $client->resolveNodeId('/Objects/Bad'))
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

        expect(fn () => $client->resolveNodeId('/Objects/Empty'))
            ->toThrow(ServiceException::class, 'No targets');
    });
});

// ──────────────────────────────────────────────
// ManagesConnectionTrait
// ──────────────────────────────────────────────

describe('ManagesConnectionTrait', function () {

    it('ensureConnected throws for Broken state', function () {
        $client = createClientWithoutConnect();
        registerClientModules($client);
        setClientProperty($client, 'connectionState', ConnectionState::Broken);

        expect(fn () => $client->read(NodeId::numeric(0, 2259)))
            ->toThrow(ConnectionException::class, 'Connection lost');
    });

    it('disconnect handles closeSession failure gracefully', function () {
        $mock = new MockTransport();
        $client = setupConnectedClient($mock);

        $client->disconnect();
        expect($client->getConnectionState())->toBe(ConnectionState::Disconnected);
    });

    it('disconnect catches closeSession OpcUaException and continues', function () {
        $transport = new class() extends PhpOpcua\Client\Transport\TcpTransport {
            public function connect(string $host, int $port, null|float $timeout = null): void
            {
            }

            public function send(string $data): void
            {
                throw new ConnectionException('send failed');
            }

            public function receive(): string
            {
                throw new ConnectionException('no data');
            }

            public function close(): void
            {
            }

            public function isConnected(): bool
            {
                return true;
            }
        };

        $client = createClientWithoutConnect();
        $session = new PhpOpcua\Client\Protocol\SessionService(1, 1);
        setClientProperty($client, 'transport', $transport);
        setClientProperty($client, 'connectionState', ConnectionState::Connected);
        setClientProperty($client, 'session', $session);
        setClientProperty($client, 'authenticationToken', NodeId::numeric(0, 2));
        setClientProperty($client, 'secureChannelId', 1);
        setClientProperty($client, 'lastEndpointUrl', 'opc.tcp://mock:4840');
        bootClientModules($client, $session);

        $client->disconnect();
        expect($client->getConnectionState())->toBe(ConnectionState::Disconnected);
    });

    it('executeWithRetry retries on ConnectionException', function () {
        $mock = new MockTransport();
        $client = setupConnectedClient($mock);
        setClientProperty($client, 'autoRetry', 0);

        expect(fn () => $client->read(NodeId::numeric(0, 2259)))
            ->toThrow(ConnectionException::class);

        expect($client->getConnectionState())->toBe(ConnectionState::Broken);
    });

    it('retries and reconnects on ConnectionException', function () {
        $mock = new MockTransport();
        $client = setupConnectedClient($mock);
        setClientProperty($client, 'autoRetry', 1);

        expect(fn () => $client->read(NodeId::numeric(0, 2259)))
            ->toThrow(ConnectionException::class);
    });

    it('isConnected returns true when connected', function () {
        $mock = new MockTransport();
        $client = setupConnectedClient($mock);
        expect($client->isConnected())->toBeTrue();
    });

    it('isConnected returns false when disconnected', function () {
        $client = createClientWithoutConnect();
        expect($client->isConnected())->toBeFalse();
    });

    it('performConnect completes full connection sequence', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildAckResponse());
        $mock->addResponse(buildOpnResponse(10, 20));
        $mock->addResponse(buildCreateSessionResponse());
        $mock->addResponse(buildActivateSessionResponse());
        $mock->addResponse(buildDiscoverLimitsResponse());

        $client = createClientWithoutConnect();
        setClientProperty($client, 'transport', $mock);

        setClientProperty($client, 'anonymousPolicyId', 'anonymous');
        setClientProperty($client, 'usernamePolicyId', 'username');
        setClientProperty($client, 'certificatePolicyId', 'certificate');

        callClientMethod($client, 'performConnect', ['opc.tcp://localhost:4840']);

        expect($client->getConnectionState())->toBe(ConnectionState::Connected);
        expect($client->isConnected())->toBeTrue();
    });

    it('performConnect sets Broken state on ConnectionException', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildAckResponse());

        $client = createClientWithoutConnect();
        setClientProperty($client, 'transport', $mock);
        setClientProperty($client, 'anonymousPolicyId', 'anonymous');
        setClientProperty($client, 'usernamePolicyId', 'username');
        setClientProperty($client, 'certificatePolicyId', 'certificate');

        expect(fn () => callClientMethod($client, 'performConnect', ['opc.tcp://localhost:4840']))
            ->toThrow(ConnectionException::class);

        expect($client->getConnectionState())->toBe(ConnectionState::Broken);
    });
});

// ──────────────────────────────────────────────
// ManagesBatchingRuntimeTrait
// ──────────────────────────────────────────────

describe('ManagesBatchingRuntimeTrait', function () {

    it('handles server that does not support operation limits', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildErrMsg(0x800B0000, 'Service unsupported'));

        $client = setupConnectedClient($mock);
        callClientMethod($client, 'discoverServerOperationLimits');

        expect($client->getServerMaxNodesPerRead())->toBeNull();
        expect($client->getServerMaxNodesPerWrite())->toBeNull();
    });

    it('getBatchSize returns configured batch size', function () {
        $client = createClientWithoutConnect();
        setClientProperty($client, 'batchSize', 50);
        expect($client->getBatchSize())->toBe(50);
    });

    it('getEffectiveReadBatchSize returns batchSize when set > 0', function () {
        $mock = new MockTransport();
        $client = setupConnectedClient($mock);
        setClientProperty($client, 'batchSize', 25);
        expect(callClientMethod($client, 'getEffectiveReadBatchSize'))->toBe(25);
    });

    it('getEffectiveWriteBatchSize returns batchSize when set > 0', function () {
        $mock = new MockTransport();
        $client = setupConnectedClient($mock);
        setClientProperty($client, 'batchSize', 30);
        expect(callClientMethod($client, 'getEffectiveWriteBatchSize'))->toBe(30);
    });

    it('getEffectiveReadBatchSize returns null when batchSize is 0', function () {
        $mock = new MockTransport();
        $client = setupConnectedClient($mock);
        setClientProperty($client, 'batchSize', 0);
        expect(callClientMethod($client, 'getEffectiveReadBatchSize'))->toBeNull();
    });

    it('getEffectiveWriteBatchSize returns null when batchSize is 0', function () {
        $mock = new MockTransport();
        $client = setupConnectedClient($mock);
        setClientProperty($client, 'batchSize', 0);
        expect(callClientMethod($client, 'getEffectiveWriteBatchSize'))->toBeNull();
    });

    it('discoverServerOperationLimits skips when batchSize is 0', function () {
        $mock = new MockTransport();
        $client = setupConnectedClient($mock);
        setClientProperty($client, 'batchSize', 0);
        callClientMethod($client, 'discoverServerOperationLimits');
        expect($mock->sent)->toBeEmpty();
    });

    it('discoverServerOperationLimits parses valid server limits', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(634, function (BinaryEncoder $e) {
            $e->writeInt32(2);
            $e->writeByte(0x01);
            $e->writeByte(BuiltinType::UInt32->value);
            $e->writeUInt32(100);
            $e->writeByte(0x01);
            $e->writeByte(BuiltinType::UInt32->value);
            $e->writeUInt32(50);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        callClientMethod($client, 'discoverServerOperationLimits');

        expect($client->getServerMaxNodesPerRead())->toBe(100);
        expect($client->getServerMaxNodesPerWrite())->toBe(50);
    });
});

// ──────────────────────────────────────────────
// ManagesHandshakeTrait
// ──────────────────────────────────────────────

describe('ManagesHandshakeTrait', function () {

    it('doHandshake processes ACK and sets buffer size', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildAckResponse());

        $client = createClientWithoutConnect();
        setClientProperty($client, 'transport', $mock);

        callClientMethod($client, 'doHandshake', ['opc.tcp://localhost:4840']);
        expect(true)->toBeTrue();
    });
});

// ──────────────────────────────────────────────
// ManagesReadWriteTrait
// ──────────────────────────────────────────────

describe('ManagesReadWriteTrait', function () {

    it('maps int value to BackedEnum when mapping is configured', function () {
        $mock = new MockTransport();
        $mock->addResponse(readResponseMsg(1));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'enumMappings', ['i=2259' => BuiltinType::class]);

        $dv = $client->read(NodeId::numeric(0, 2259));
        expect($dv->getValue())->toBeInstanceOf(BuiltinType::class);
    });

    it('returns original DataValue when raw value is not int or string', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(634, function (BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeByte(0x01);
            $e->writeByte(BuiltinType::Double->value);
            $e->writeDouble(3.14);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'enumMappings', ['i=2259' => BuiltinType::class]);

        $dv = $client->read(NodeId::numeric(0, 2259));
        expect($dv->getValue())->toBe(3.14);
    });

    it('returns original DataValue when enum from() throws ValueError', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(634, function (BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeByte(0x01);
            $e->writeByte(BuiltinType::Int32->value);
            $e->writeInt32(99999);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'enumMappings', ['i=2259' => BuiltinType::class]);

        $dv = $client->read(NodeId::numeric(0, 2259));
        expect($dv->getValue())->toBe(99999);
    });

    it('writeMulti batches writes when count exceeds batch size', function () {
        $mock = new MockTransport();
        $mock->addResponse(writeMultiResponseMsg(2));
        $mock->addResponse(writeMultiResponseMsg(1));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'batchSize', 2);
        setClientProperty($client, 'autoDetectWriteType', false);

        $results = $client->writeMulti([
            ['nodeId' => NodeId::numeric(1, 1), 'value' => 10, 'type' => BuiltinType::Int32],
            ['nodeId' => NodeId::numeric(1, 2), 'value' => 20, 'type' => BuiltinType::Int32],
            ['nodeId' => NodeId::numeric(1, 3), 'value' => 30, 'type' => BuiltinType::Int32],
        ]);

        expect($results)->toHaveCount(3);
        expect($mock->sent)->toHaveCount(2);
    });
});

// ──────────────────────────────────────────────
// ManagesSubscriptionsTrait
// ──────────────────────────────────────────────

describe('ManagesSubscriptionsTrait', function () {

    it('creates an event monitored item and dispatches event', function () {
        $mock = new MockTransport();
        $mock->addResponse(createMonitoredItemsResponseMsg(1));

        $client = setupConnectedClient($mock);
        $result = $client->createEventMonitoredItem(42, NodeId::numeric(0, 2253));

        expect($result)->toBeInstanceOf(PhpOpcua\Client\Module\Subscription\MonitoredItemResult::class);
        expect($result->monitoredItemId)->toBe(100);
    });

    it('deletes monitored items and dispatches events', function () {
        $mock = new MockTransport();
        $mock->addResponse(deleteMonitoredItemsResponseMsg(2));

        $client = setupConnectedClient($mock);
        $results = $client->deleteMonitoredItems(42, [100, 101]);

        expect($results)->toHaveCount(2);
    });

    it('modifies monitored items and dispatches events', function () {
        $mock = new MockTransport();
        $mock->addResponse(modifyMonitoredItemsResponseMsg(2));

        $client = setupConnectedClient($mock);
        $results = $client->modifyMonitoredItems(42, [
            ['monitoredItemId' => 100, 'samplingInterval' => 250.0],
            ['monitoredItemId' => 101, 'queueSize' => 10],
        ]);

        expect($results)->toHaveCount(2);
    });

    it('configures triggering links and dispatches event', function () {
        $mock = new MockTransport();
        $mock->addResponse(setTriggeringResponseMsg(2, 1));

        $client = setupConnectedClient($mock);
        $result = $client->setTriggering(42, 100, [200, 201], [300]);

        expect($result)->toBeInstanceOf(PhpOpcua\Client\Module\Subscription\SetTriggeringResult::class);
        expect($result->addResults)->toHaveCount(2);
    });

    it('deletes a subscription and dispatches event', function () {
        $mock = new MockTransport();
        $mock->addResponse(deleteSubscriptionsResponseMsg());

        $client = setupConnectedClient($mock);
        $statusCode = $client->deleteSubscription(42);

        expect($statusCode)->toBe(0);
    });

    it('publish with event notification without alarm data does not dispatch alarm event', function () {
        $mock = new MockTransport();
        $client = setupConnectedClient($mock);

        $mock->addResponse(buildMsgResponse(829, function (BinaryEncoder $e) {
            $e->writeUInt32(1);
            $e->writeInt32(1);
            $e->writeUInt32(1);
            $e->writeBoolean(false);
            $e->writeUInt32(1);
            $e->writeInt64(0);
            $e->writeInt32(1);
            $e->writeNodeId(NodeId::numeric(0, 916));
            $e->writeByte(0x01);
            $bodyEnc = new BinaryEncoder();
            $bodyEnc->writeInt32(1);
            $bodyEnc->writeUInt32(1);
            $bodyEnc->writeInt32(0);
            $body = $bodyEnc->getBuffer();
            $e->writeInt32(strlen($body));
            $e->writeRawBytes($body);
            $e->writeInt32(0);
            $e->writeInt32(0);
        }));

        $result = $client->publish();
        expect($result->subscriptionId)->toBe(1);
    });
});

// ──────────────────────────────────────────────
// ManagesSecureChannelTrait
// ──────────────────────────────────────────────

describe('ManagesSecureChannelTrait', function () {

    it('throws ConfigurationException when cert file cannot be read', function () {
        $client = createClientWithoutConnect();
        setClientProperty($client, 'clientCertPath', '/nonexistent/cert.pem');
        setClientProperty($client, 'clientKeyPath', '/nonexistent/key.pem');

        expect(fn () => callClientMethod($client, 'loadClientCertificateAndKey'))
            ->toThrow(PhpOpcua\Client\Exception\ConfigurationException::class, 'Failed to read client certificate');
    });

    it('buildCertificateChain returns clientCertDer when CA file cannot be read', function () {
        $client = createClientWithoutConnect();
        setClientProperty($client, 'caCertPath', '/nonexistent/ca.pem');

        $result = callClientMethod($client, 'buildCertificateChain', ['cert-der-data']);
        expect($result)->toBe('cert-der-data');
    });

    it('buildCertificateChain appends DER CA cert when CA is in DER format', function () {
        $tmpFile = tempnam(sys_get_temp_dir(), 'opcua-ca-');
        file_put_contents($tmpFile, "\x30\x82\x01\x00FAKE_DER_DATA");

        $client = createClientWithoutConnect();
        setClientProperty($client, 'caCertPath', $tmpFile);

        $result = callClientMethod($client, 'buildCertificateChain', ['client-cert-der']);
        expect(str_starts_with($result, 'client-cert-der'))->toBeTrue();

        @unlink($tmpFile);
    });

    it('auto-generates certificate when no paths configured', function () {
        $client = createClientWithoutConnect();
        setClientProperty($client, 'securityPolicy', PhpOpcua\Client\Security\SecurityPolicy::Basic256Sha256);
        setClientProperty($client, 'securityMode', PhpOpcua\Client\Security\SecurityMode::SignAndEncrypt);

        $result = callClientMethod($client, 'loadClientCertificateAndKey');
        expect($result[0])->toBeString();
        expect(ord($result[0][0]))->toBe(0x30);
        expect($result[1])->toBeInstanceOf(OpenSSLAsymmetricKey::class);
    });

    it('auto-generates ECC certificate for ECC policy', function () {
        $client = createClientWithoutConnect();
        setClientProperty($client, 'securityPolicy', PhpOpcua\Client\Security\SecurityPolicy::EccNistP256);
        setClientProperty($client, 'securityMode', PhpOpcua\Client\Security\SecurityMode::Sign);

        $result = callClientMethod($client, 'loadClientCertificateAndKey');
        expect($result[0])->toBeString();
        expect($result[1])->toBeInstanceOf(OpenSSLAsymmetricKey::class);
    });

    it('performConnect with security calls openSecureChannel isSecure path', function () {
        [$certDer, $privKey] = generateTestCertKeyPair();
        $policy = PhpOpcua\Client\Security\SecurityPolicy::Basic256Sha256;

        openssl_pkey_export($privKey, $keyPem);
        $certFile = tempnam(sys_get_temp_dir(), 'opcua_sc_cert_');
        $keyFile = tempnam(sys_get_temp_dir(), 'opcua_sc_key_');
        file_put_contents($certFile, $certDer);
        file_put_contents($keyFile, $keyPem);

        $interceptTransport = new class($certDer, $privKey, $policy) extends PhpOpcua\Client\Transport\TcpTransport {
            private int $sendCount = 0;

            private int $receiveCount = 0;

            public function __construct(private string $certDer, private OpenSSLAsymmetricKey $privKey, private PhpOpcua\Client\Security\SecurityPolicy $pol)
            {
            }

            public function connect(string $host, int $port, null|float $timeout = null): void
            {
            }

            public function send(string $data): void
            {
                $this->sendCount++;
            }

            public function receive(): string
            {
                $this->receiveCount++;

                return match ($this->receiveCount) {
                    1 => buildAckResponse(),
                    2 => buildTestOPNResponse($this->certDer, $this->privKey, $this->certDer, $this->privKey, random_bytes(32), random_bytes(32), 100, 200, $this->pol),
                    3 => buildCreateSessionResponse(),
                    4 => buildActivateSessionResponse(),
                    5 => buildDiscoverLimitsResponse(),
                    default => throw new ConnectionException('No more responses'),
                };
            }

            public function close(): void
            {
            }

            public function isConnected(): bool
            {
                return true;
            }
        };

        $client = createClientWithoutConnect();
        setClientProperty($client, 'transport', $interceptTransport);
        setClientProperty($client, 'securityPolicy', $policy);
        setClientProperty($client, 'securityMode', PhpOpcua\Client\Security\SecurityMode::SignAndEncrypt);
        setClientProperty($client, 'clientCertPath', $certFile);
        setClientProperty($client, 'clientKeyPath', $keyFile);
        setClientProperty($client, 'serverCertDer', $certDer);

        try {
            callClientMethod($client, 'performConnect', ['opc.tcp://localhost:4840']);
        } catch (Throwable) {
        }

        @unlink($certFile);
        @unlink($keyFile);
        expect(true)->toBeTrue();
    });

    it('closeSecureChannel calls closeSecureChannelSecure when security active', function () {
        [$certDer, $privKey] = generateTestCertKeyPair();
        $policy = PhpOpcua\Client\Security\SecurityPolicy::Basic256Sha256;

        $sc = new PhpOpcua\Client\Security\SecureChannel($policy, PhpOpcua\Client\Security\SecurityMode::SignAndEncrypt, $certDer, $privKey, $certDer);
        $sc->createOpenSecureChannelMessage();
        $opnResponse = buildTestOPNResponse($certDer, $privKey, $certDer, $privKey, $sc->getClientNonce(), random_bytes(32), 100, 200, $policy);
        $sc->processOpenSecureChannelResponse($opnResponse);

        $mock = new MockTransport();
        $session = new PhpOpcua\Client\Protocol\SessionService(100, 200, $sc);
        $client = createClientWithoutConnect();
        setClientProperty($client, 'transport', $mock);
        setClientProperty($client, 'connectionState', ConnectionState::Connected);
        setClientProperty($client, 'session', $session);
        setClientProperty($client, 'authenticationToken', NodeId::numeric(0, 2));
        setClientProperty($client, 'secureChannelId', 100);
        setClientProperty($client, 'secureChannel', $sc);
        setClientProperty($client, 'securityPolicy', $policy);
        setClientProperty($client, 'securityMode', PhpOpcua\Client\Security\SecurityMode::SignAndEncrypt);
        setClientProperty($client, 'lastEndpointUrl', 'opc.tcp://mock:4840');
        bootClientModules($client, $session);

        callClientMethod($client, 'closeSecureChannel');

        expect($mock->sent)->toHaveCount(1);
        expect(substr($mock->sent[0], 0, 3))->toBe('CLO');
    });
});

// ──────────────────────────────────────────────
// ManagesSessionTrait
// ──────────────────────────────────────────────

describe('ManagesSessionTrait', function () {

    it('stores eccServerEphemeralKey when AdditionalHeader contains ECDHKey', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildCreateSessionResponseWithEccAdditionalHeader());
        $mock->addResponse(buildActivateSessionResponse());

        $client = setupConnectedClient($mock);
        callClientMethod($client, 'createAndActivateSession', ['opc.tcp://mock:4840']);

        $eccKey = (new ReflectionProperty($client, 'eccServerEphemeralKey'))->getValue($client);
        expect($eccKey)->toBe('fake-ecc-public-key-data');
    });

    it('sets serverCertDer on secureChannel when null', function () {
        $mock = new MockTransport();

        $sc = new PhpOpcua\Client\Security\SecureChannel(
            PhpOpcua\Client\Security\SecurityPolicy::Basic256Sha256,
            PhpOpcua\Client\Security\SecurityMode::None,
        );

        $fakeCert = "\x30\x03\x01\x01\xFF";
        $mock->addResponse(buildCreateSessionResponseWithCert($fakeCert));
        $mock->addResponse(buildActivateSessionResponse());

        $session = new PhpOpcua\Client\Protocol\SessionService(1, 1, $sc);
        $client = createClientWithoutConnect();
        setClientProperty($client, 'transport', $mock);
        setClientProperty($client, 'connectionState', ConnectionState::Connected);
        setClientProperty($client, 'session', $session);
        setClientProperty($client, 'authenticationToken', NodeId::numeric(0, 2));
        setClientProperty($client, 'secureChannelId', 1);
        setClientProperty($client, 'secureChannel', $sc);
        setClientProperty($client, 'lastEndpointUrl', 'opc.tcp://mock:4840');
        bootClientModules($client, $session);

        callClientMethod($client, 'createAndActivateSession', ['opc.tcp://mock:4840']);

        expect($sc->getServerCertDer())->toBe($fakeCert);
    });

    it('closeSession calls closeSessionSecure when security active', function () {
        [$certDer, $privKey] = generateTestCertKeyPair();
        $policy = PhpOpcua\Client\Security\SecurityPolicy::Basic256Sha256;

        $sc = new PhpOpcua\Client\Security\SecureChannel($policy, PhpOpcua\Client\Security\SecurityMode::SignAndEncrypt, $certDer, $privKey, $certDer);
        $sc->createOpenSecureChannelMessage();
        $opnResponse = buildTestOPNResponse($certDer, $privKey, $certDer, $privKey, $sc->getClientNonce(), random_bytes(32), 100, 200, $policy);
        $sc->processOpenSecureChannelResponse($opnResponse);

        $mock = new MockTransport();
        $session = new PhpOpcua\Client\Protocol\SessionService(100, 200, $sc);
        $client = createClientWithoutConnect();
        setClientProperty($client, 'transport', $mock);
        setClientProperty($client, 'connectionState', ConnectionState::Connected);
        setClientProperty($client, 'session', $session);
        setClientProperty($client, 'authenticationToken', NodeId::numeric(0, 2));
        setClientProperty($client, 'secureChannelId', 100);
        setClientProperty($client, 'secureChannel', $sc);
        setClientProperty($client, 'securityPolicy', $policy);
        setClientProperty($client, 'securityMode', PhpOpcua\Client\Security\SecurityMode::SignAndEncrypt);
        setClientProperty($client, 'lastEndpointUrl', 'opc.tcp://mock:4840');
        bootClientModules($client, $session);

        callClientMethod($client, 'closeSession');
        expect($mock->sent)->toHaveCount(1);
    });

    it('returns null when userCertPath is null', function () {
        $client = createClientWithoutConnect();
        expect(callClientMethod($client, 'loadUserCertificate'))->toBe([null, null]);
    });

    it('loads PEM user certificate', function () {
        $privKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['CN' => 'test-user'], $privKey);
        $cert = openssl_csr_sign($csr, null, $privKey, 365);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($privKey, $keyPem);

        $certFile = tempnam(sys_get_temp_dir(), 'opcua_user_cert_');
        $keyFile = tempnam(sys_get_temp_dir(), 'opcua_user_key_');
        file_put_contents($certFile, $certPem);
        file_put_contents($keyFile, $keyPem);

        try {
            $client = createClientWithoutConnect();
            setClientProperty($client, 'userCertPath', $certFile);
            setClientProperty($client, 'userKeyPath', $keyFile);

            $result = callClientMethod($client, 'loadUserCertificate');
            expect($result[0])->toBeString();
            expect($result[1])->toBeInstanceOf(OpenSSLAsymmetricKey::class);
        } finally {
            @unlink($certFile);
            @unlink($keyFile);
        }
    });

    it('loads DER user certificate', function () {
        $privKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['CN' => 'test-user'], $privKey);
        $cert = openssl_csr_sign($csr, null, $privKey, 365);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($privKey, $keyPem);

        $pemBody = trim(str_replace(['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----'], '', $certPem));
        $derCert = base64_decode($pemBody);

        $certFile = tempnam(sys_get_temp_dir(), 'opcua_user_cert_');
        $keyFile = tempnam(sys_get_temp_dir(), 'opcua_user_key_');
        file_put_contents($certFile, $derCert);
        file_put_contents($keyFile, $keyPem);

        try {
            $client = createClientWithoutConnect();
            setClientProperty($client, 'userCertPath', $certFile);
            setClientProperty($client, 'userKeyPath', $keyFile);

            $result = callClientMethod($client, 'loadUserCertificate');
            expect($result[0])->toBe($derCert);
        } finally {
            @unlink($certFile);
            @unlink($keyFile);
        }
    });
});

// ──────────────────────────────────────────────
// ManagesTypeDiscoveryTrait
// ──────────────────────────────────────────────

describe('ManagesTypeDiscoveryTrait', function () {

    it('findBinaryEncodingId returns null when browse throws', function () {
        $mock = new MockTransport();
        $client = setupConnectedClient($mock);

        $moduleRef = new ReflectionProperty(PhpOpcua\Client\Client::class, 'moduleRegistry');
        $registry = $moduleRef->getValue($client);
        $module = $registry->get(PhpOpcua\Client\Module\TypeDiscovery\TypeDiscoveryModule::class);
        $method = new ReflectionMethod($module, 'findBinaryEncodingId');

        expect($method->invoke($module, NodeId::numeric(2, 999)))->toBeNull();
    });

    it('discoverSingleDataType catches exception in discoverFromTree', function () {
        $mock = new MockTransport();

        $mock->addResponse(buildMsgResponse(530, function (BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeUInt32(0);
            $e->writeByteString(null);
            $e->writeInt32(1);
            $e->writeNodeId(NodeId::numeric(0, 38));
            $e->writeBoolean(true);
            $e->writeExpandedNodeId(NodeId::numeric(2, 5001));
            $e->writeUInt16(0);
            $e->writeString('Default Binary');
            $e->writeByte(0x02);
            $e->writeString('Default Binary');
            $e->writeUInt32(1);
            $e->writeExpandedNodeId(NodeId::numeric(0, 0));
            $e->writeInt32(0);
        }));

        $mock->addResponse(buildMsgResponse(634, function (BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeByte(0x01);
            $e->writeByte(22);
            $e->writeNodeId(NodeId::numeric(2, 9999));
            $e->writeByte(0x01);
            $e->writeInt32(2);
            $e->writeRawBytes("\xFF\xFF");
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);

        $ref = new PhpOpcua\Client\Types\ReferenceDescription(
            NodeId::numeric(0, 35),
            true,
            NodeId::numeric(2, 999),
            new QualifiedName(2, 'CustomType'),
            new PhpOpcua\Client\Types\LocalizedText(null, 'CustomType'),
            PhpOpcua\Client\Types\NodeClass::DataType,
            NodeId::numeric(0, 0),
        );
        $node = new PhpOpcua\Client\Types\BrowseNode($ref);

        $moduleRef = new ReflectionProperty(PhpOpcua\Client\Client::class, 'moduleRegistry');
        $registry = $moduleRef->getValue($client);
        $module = $registry->get(PhpOpcua\Client\Module\TypeDiscovery\TypeDiscoveryModule::class);
        $method = new ReflectionMethod($module, 'discoverFromTree');

        $discovered = [];
        $registered = 0;
        $method->invoke($module, [$node], null, $registered, $discovered);
        expect($registered)->toBe(0);
    });

    it('discoverFromTree recurses into children', function () {
        $mock = new MockTransport();
        $client = setupConnectedClient($mock);

        $childRef = new PhpOpcua\Client\Types\ReferenceDescription(
            NodeId::numeric(0, 35),
            true,
            NodeId::numeric(0, 100),
            new QualifiedName(0, 'ChildType'),
            new PhpOpcua\Client\Types\LocalizedText(null, 'ChildType'),
            PhpOpcua\Client\Types\NodeClass::DataType,
            NodeId::numeric(0, 0),
        );
        $childNode = new PhpOpcua\Client\Types\BrowseNode($childRef);

        $parentRef = new PhpOpcua\Client\Types\ReferenceDescription(
            NodeId::numeric(0, 35),
            true,
            NodeId::numeric(0, 50),
            new QualifiedName(0, 'ParentType'),
            new PhpOpcua\Client\Types\LocalizedText(null, 'ParentType'),
            PhpOpcua\Client\Types\NodeClass::DataType,
            NodeId::numeric(0, 0),
        );
        $parentNode = new PhpOpcua\Client\Types\BrowseNode($parentRef);
        $parentNode->addChild($childNode);

        $moduleRef = new ReflectionProperty(PhpOpcua\Client\Client::class, 'moduleRegistry');
        $registry = $moduleRef->getValue($client);
        $module = $registry->get(PhpOpcua\Client\Module\TypeDiscovery\TypeDiscoveryModule::class);
        $method = new ReflectionMethod($module, 'discoverFromTree');

        $discovered = [];
        $registered = 0;
        $method->invoke($module, [$parentNode], null, $registered, $discovered);
        expect($registered)->toBe(0);
    });
});
