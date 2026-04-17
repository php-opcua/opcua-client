<?php

declare(strict_types=1);

require_once __DIR__ . '/ClientTraitsCoverageTest.php';

use PhpOpcua\Client\Exception\InvalidNodeIdException;
use PhpOpcua\Client\Types\ConnectionState;
use PhpOpcua\Client\Types\NodeId;

describe('String NodeId parameter support', function () {

    it('read accepts string NodeId', function () {
        $mock = new MockTransport();
        $mock->addResponse(readResponseMsg(42));

        $client = setupConnectedClient($mock);
        $dv = $client->read('i=2259');

        expect($dv->getValue())->toBe(42);
    });

    it('read accepts NodeId object (unchanged behavior)', function () {
        $mock = new MockTransport();
        $mock->addResponse(readResponseMsg(99));

        $client = setupConnectedClient($mock);
        $dv = $client->read(NodeId::numeric(0, 2259));

        expect($dv->getValue())->toBe(99);
    });

    it('read throws InvalidNodeIdException for invalid string', function () {
        $client = createClientWithoutConnect();
        registerClientModules($client);
        setClientProperty($client, 'connectionState', ConnectionState::Connected);

        expect(fn () => $client->read('invalid!!!'))
            ->toThrow(InvalidNodeIdException::class);
    });

    it('browse accepts string NodeId', function () {
        $mock = new MockTransport();
        $mock->addResponse(browseResponseMsg());

        $client = setupConnectedClient($mock);
        $refs = $client->browse('i=85');

        expect($refs)->toHaveCount(1);
    });

    it('call accepts string NodeIds for both parameters', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(715, function (PhpOpcua\Client\Encoding\BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeUInt32(0);
            $e->writeInt32(0);
            $e->writeInt32(0);
            $e->writeInt32(0);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        $result = $client->call('i=2253', 'ns=0;i=11492');

        expect($result->statusCode)->toBe(0);
    });

    it('historyReadRaw accepts string NodeId', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(667, function (PhpOpcua\Client\Encoding\BinaryEncoder $e) {
            $e->writeInt32(0);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        $values = $client->historyReadRaw('ns=2;i=1001');

        expect($values)->toBe([]);
    });

    it('resolveNodeId returns NodeId when given NodeId', function () {
        $client = createClientWithoutConnect();
        $nodeId = NodeId::numeric(0, 85);

        expect($client->resolveNodeId($nodeId))->toBe($nodeId);
    });

    it('resolveNodeId parses string to NodeId', function () {
        $client = createClientWithoutConnect();
        $result = $client->resolveNodeId('ns=2;i=1001');

        expect($result->namespaceIndex)->toBe(2);
        expect($result->identifier)->toBe(1001);
    });

    it('resolveNodeId parses short format i=X', function () {
        $client = createClientWithoutConnect();
        $result = $client->resolveNodeId('i=85');

        expect($result->namespaceIndex)->toBe(0);
        expect($result->identifier)->toBe(85);
    });

    it('resolveNodeId parses string NodeId', function () {
        $client = createClientWithoutConnect();
        $result = $client->resolveNodeId('ns=2;s=MyVariable');

        expect($result->namespaceIndex)->toBe(2);
        expect($result->identifier)->toBe('MyVariable');
    });

    it('resolveNodeId throws on invalid format', function () {
        $client = createClientWithoutConnect();

        expect(fn () => $client->resolveNodeId('garbage'))
            ->toThrow(InvalidNodeIdException::class);
    });

    it('readMulti resolves string NodeIds in items', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(634, function (PhpOpcua\Client\Encoding\BinaryEncoder $e) {
            $e->writeInt32(2);
            $e->writeByte(0x01);
            $e->writeByte(PhpOpcua\Client\Types\BuiltinType::Int32->value);
            $e->writeInt32(10);
            $e->writeByte(0x01);
            $e->writeByte(PhpOpcua\Client\Types\BuiltinType::Int32->value);
            $e->writeInt32(20);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        $results = $client->readMulti([
            ['nodeId' => 'i=2259'],
            ['nodeId' => 'ns=2;i=1001'],
        ]);

        expect($results)->toHaveCount(2);
        expect($results[0]->getValue())->toBe(10);
        expect($results[1]->getValue())->toBe(20);
    });

    it('writeMulti resolves string NodeIds in items', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(676, function (PhpOpcua\Client\Encoding\BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeUInt32(0);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'autoDetectWriteType', false);
        $results = $client->writeMulti([
            ['nodeId' => 'ns=2;i=1001', 'value' => 42, 'type' => PhpOpcua\Client\Types\BuiltinType::Int32],
        ]);

        expect($results)->toBe([0]);
    });

    it('write accepts string NodeId', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(676, function (PhpOpcua\Client\Encoding\BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeUInt32(0);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'autoDetectWriteType', false);
        $status = $client->write('ns=2;i=1001', 42, PhpOpcua\Client\Types\BuiltinType::Int32);

        expect($status)->toBe(0);
    });

    it('browseAll accepts string NodeId', function () {
        $mock = new MockTransport();
        $mock->addResponse(browseResponseMsg());

        $client = setupConnectedClient($mock);
        $refs = $client->browseAll('i=85');

        expect($refs)->toHaveCount(1);
    });

    it('translateBrowsePaths resolves string startingNodeId in items', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(557, function (PhpOpcua\Client\Encoding\BinaryEncoder $e) {
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
                'startingNodeId' => 'i=85',
                'relativePath' => [['targetName' => new PhpOpcua\Client\Types\QualifiedName(0, 'Server')]],
            ],
        ]);

        expect($results)->toHaveCount(1);
        expect($results[0]->targets[0]->targetId->identifier)->toBe(2253);
    });

    it('resolveNodeId accepts string startingNodeId', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(557, function (PhpOpcua\Client\Encoding\BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeUInt32(0);
            $e->writeInt32(1);
            $e->writeExpandedNodeId(NodeId::numeric(0, 2253));
            $e->writeUInt32(0xFFFFFFFF);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        $nodeId = $client->resolveNodeId('Server', 'i=85');

        expect($nodeId->identifier)->toBe(2253);
    });
});
