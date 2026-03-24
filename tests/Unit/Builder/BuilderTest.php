<?php

declare(strict_types=1);

require_once __DIR__ . '/../Client/ClientTraitsCoverageTest.php';

use Gianfriaur\OpcuaPhpClient\Builder\BrowsePathsBuilder;
use Gianfriaur\OpcuaPhpClient\Builder\MonitoredItemsBuilder;
use Gianfriaur\OpcuaPhpClient\Builder\ReadMultiBuilder;
use Gianfriaur\OpcuaPhpClient\Builder\WriteMultiBuilder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Testing\MockClient;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

describe('ReadMultiBuilder', function () {

    it('reads multiple nodes with fluent API', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(634, function (BinaryEncoder $e) {
            $e->writeInt32(2);
            $e->writeByte(0x01);
            $e->writeByte(BuiltinType::Int32->value);
            $e->writeInt32(42);
            $e->writeByte(0x01);
            $e->writeByte(BuiltinType::String->value);
            $e->writeString('Server');
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        $results = $client->readMulti()
            ->node('i=2259')->value()
            ->node('ns=2;i=1001')->displayName()
            ->execute();

        expect($results)->toHaveCount(2);
        expect($results[0]->getValue())->toBe(42);
        expect($results[1]->getValue())->toBe('Server');
    });

    it('readMulti still works with array parameter', function () {
        $mock = new MockTransport();
        $mock->addResponse(readResponseMsg(99));

        $client = setupConnectedClient($mock);
        $results = $client->readMulti([['nodeId' => 'i=2259']]);

        expect($results)->toHaveCount(1);
    });

    it('supports all attribute shortcuts', function () {
        $mockClient = MockClient::create();
        $builder = new ReadMultiBuilder($mockClient);

        $builder->node('i=1')->value()
            ->node('i=2')->displayName()
            ->node('i=3')->browseName()
            ->node('i=4')->nodeClass()
            ->node('i=5')->description()
            ->node('i=6')->dataType()
            ->node('i=7')->attribute(17);

        $builder->execute();

        expect($mockClient->callCount('readMulti'))->toBe(1);
    });
});

describe('WriteMultiBuilder', function () {

    it('writes multiple nodes with fluent API', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(676, function (BinaryEncoder $e) {
            $e->writeInt32(2);
            $e->writeUInt32(0);
            $e->writeUInt32(0);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        $results = $client->writeMulti()
            ->node('ns=2;i=1001')->int32(42)
            ->node('ns=2;i=1002')->double(3.14)
            ->execute();

        expect($results)->toBe([0, 0]);
    });

    it('supports all type shortcuts', function () {
        $mockClient = MockClient::create();
        $builder = new WriteMultiBuilder($mockClient);

        $builder->node('i=1')->boolean(true)
            ->node('i=2')->sbyte(-1)
            ->node('i=3')->byte(255)
            ->node('i=4')->int16(-100)
            ->node('i=5')->uint16(100)
            ->node('i=6')->int32(42)
            ->node('i=7')->uint32(42)
            ->node('i=8')->int64(999)
            ->node('i=9')->uint64(999)
            ->node('i=10')->float(1.5)
            ->node('i=11')->double(3.14)
            ->node('i=12')->string('hello')
            ->node('i=13')->typed(42, BuiltinType::Int32);

        $builder->execute();
        expect($mockClient->callCount('writeMulti'))->toBe(1);
    });
});

describe('BrowsePathsBuilder', function () {

    it('translates paths with fluent API', function () {
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
        $results = $client->translateBrowsePaths()
            ->from('i=85')->path('Server')
            ->execute();

        expect($results)->toHaveCount(1);
        expect($results[0]->targets[0]->targetId->identifier)->toBe(2253);
    });

    it('supports multi-segment paths', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(557, function (BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeUInt32(0);
            $e->writeInt32(1);
            $e->writeExpandedNodeId(NodeId::numeric(0, 2259));
            $e->writeUInt32(0xFFFFFFFF);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        $results = $client->translateBrowsePaths()
            ->from('i=85')->path('Server', 'ServerStatus', 'State')
            ->execute();

        expect($results)->toHaveCount(1);
    });

    it('supports namespaced segments', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(557, function (BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeUInt32(0);
            $e->writeInt32(1);
            $e->writeExpandedNodeId(NodeId::numeric(2, 1001));
            $e->writeUInt32(0xFFFFFFFF);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        $results = $client->translateBrowsePaths()
            ->from('i=85')->path('2:MyPLC', '2:Temperature')
            ->execute();

        expect($results)->toHaveCount(1);
    });

    it('auto-adds root node when from() is not called', function () {
        $mockClient = MockClient::create();
        $builder = new BrowsePathsBuilder($mockClient);

        $builder->path('Objects', 'Server')->execute();

        expect($mockClient->callCount('translateBrowsePaths'))->toBe(1);
    });

    it('supports explicit QualifiedName segments', function () {
        $mockClient = MockClient::create();
        $builder = new BrowsePathsBuilder($mockClient);

        $builder->from('i=85')
            ->segment(new Gianfriaur\OpcuaPhpClient\Types\QualifiedName(2, 'Custom'))
            ->execute();

        expect($mockClient->callCount('translateBrowsePaths'))->toBe(1);
    });
});

describe('MonitoredItemsBuilder', function () {

    it('returns builder when createMonitoredItems is called without items', function () {
        $client = new Gianfriaur\OpcuaPhpClient\Client();
        $builder = $client->createMonitoredItems(1);

        expect($builder)->toBeInstanceOf(MonitoredItemsBuilder::class);
    });

    it('builds monitored items with fluent API', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(754, function (BinaryEncoder $e) {
            $e->writeInt32(2);
            $e->writeUInt32(0);
            $e->writeUInt32(1);
            $e->writeDouble(500.0);
            $e->writeUInt32(2);
            $e->writeNodeId(NodeId::numeric(0, 0));
            $e->writeByte(0x00);
            $e->writeUInt32(0);
            $e->writeUInt32(2);
            $e->writeDouble(1000.0);
            $e->writeUInt32(2);
            $e->writeNodeId(NodeId::numeric(0, 0));
            $e->writeByte(0x00);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        $results = $client->createMonitoredItems(1)
            ->add('i=2258')->samplingInterval(500.0)->queueSize(10)->clientHandle(1)
            ->add('ns=2;i=1001')->attributeId(13)
            ->execute();

        expect($results)->toHaveCount(2);
    });

    it('segment without prior from auto-creates root starting node', function () {
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
        $results = $client->translateBrowsePaths()
            ->segment(new Gianfriaur\OpcuaPhpClient\Types\QualifiedName(0, 'Server'))
            ->execute();

        expect($results)->toHaveCount(1);
    });

    it('supports segment with explicit QualifiedName in BrowsePathsBuilder', function () {
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
        $results = $client->translateBrowsePaths()
            ->from('i=85')
            ->segment(new Gianfriaur\OpcuaPhpClient\Types\QualifiedName(0, 'Server'))
            ->execute();

        expect($results)->toHaveCount(1);
    });
});
