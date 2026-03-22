<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Testing\MockClient;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\CallResult;
use Gianfriaur\OpcuaPhpClient\Types\ConnectionState;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;
use Gianfriaur\OpcuaPhpClient\Types\Variant;

describe('MockClient', function () {

    it('creates via static factory', function () {
        $mock = MockClient::create();
        expect($mock)->toBeInstanceOf(MockClient::class);
        expect($mock->getConnectionState())->toBe(ConnectionState::Disconnected);
    });

    it('tracks connect/disconnect lifecycle', function () {
        $mock = MockClient::create();

        $mock->connect('opc.tcp://localhost:4840');
        expect($mock->isConnected())->toBeTrue();
        expect($mock->getConnectionState())->toBe(ConnectionState::Connected);

        $mock->disconnect();
        expect($mock->isConnected())->toBeFalse();
    });

    it('handles read with registered handler', function () {
        $mock = MockClient::create()
            ->onRead('i=2259', fn() => DataValue::ofInt32(0));

        expect($mock->read('i=2259')->getValue())->toBe(0);
    });

    it('handles read with NodeId object', function () {
        $mock = MockClient::create()
            ->onRead(NodeId::numeric(2, 1001), fn() => DataValue::ofDouble(23.5));

        expect($mock->read(NodeId::numeric(2, 1001))->getValue())->toBe(23.5);
    });

    it('returns empty DataValue for unregistered reads', function () {
        $mock = MockClient::create();
        $dv = $mock->read('i=9999');
        expect($dv->getValue())->toBeNull();
    });

    it('handles write with registered handler', function () {
        $mock = MockClient::create()
            ->onWrite('ns=2;i=1001', fn($v, $t) => $v > 100 ? StatusCode::BadTypeMismatch : StatusCode::Good);

        expect($mock->write('ns=2;i=1001', 42, BuiltinType::Int32))->toBe(StatusCode::Good);
        expect($mock->write('ns=2;i=1001', 999, BuiltinType::Int32))->toBe(StatusCode::BadTypeMismatch);
    });

    it('returns Good for unregistered writes', function () {
        $mock = MockClient::create();
        expect($mock->write('i=1', 1, BuiltinType::Int32))->toBe(0);
    });

    it('handles browse with registered handler', function () {
        $mock = MockClient::create()
            ->onBrowse('i=85', fn() => ['ref1', 'ref2']);

        expect($mock->browse('i=85'))->toBe(['ref1', 'ref2']);
    });

    it('returns empty array for unregistered browses', function () {
        $mock = MockClient::create();
        expect($mock->browse('i=85'))->toBe([]);
    });

    it('handles call with registered handler', function () {
        $mock = MockClient::create()
            ->onCall('i=2253', 'i=11492', fn($args) => new CallResult(0, [], [new Variant(BuiltinType::Int32, 42)]));

        $result = $mock->call('i=2253', 'i=11492', [new Variant(BuiltinType::UInt32, 1)]);
        expect($result->statusCode)->toBe(0);
        expect($result->outputArguments[0]->value)->toBe(42);
    });

    it('handles resolveNodeId with registered handler', function () {
        $mock = MockClient::create()
            ->onResolveNodeId('/Objects/Server', fn() => NodeId::numeric(0, 2253));

        $nodeId = $mock->resolveNodeId('/Objects/Server');
        expect($nodeId->identifier)->toBe(2253);
    });

    it('tracks all calls', function () {
        $mock = MockClient::create();
        $mock->connect('opc.tcp://localhost:4840');
        $mock->read('i=2259');
        $mock->browse('i=85');
        $mock->disconnect();

        expect($mock->getCalls())->toHaveCount(4);
        expect($mock->callCount('read'))->toBe(1);
        expect($mock->callCount('browse'))->toBe(1);
        expect($mock->callCount('connect'))->toBe(1);
    });

    it('getCallsFor filters by method', function () {
        $mock = MockClient::create();
        $mock->read('i=1');
        $mock->read('i=2');
        $mock->browse('i=85');

        $reads = $mock->getCallsFor('read');
        expect($reads)->toHaveCount(2);
    });

    it('resetCalls clears history', function () {
        $mock = MockClient::create();
        $mock->read('i=1');
        $mock->resetCalls();
        expect($mock->getCalls())->toBe([]);
    });

    it('readMulti delegates to individual reads', function () {
        $mock = MockClient::create()
            ->onRead('i=2259', fn() => DataValue::ofInt32(0))
            ->onRead('i=2267', fn() => DataValue::ofInt32(255));

        $results = $mock->readMulti([
            ['nodeId' => 'i=2259'],
            ['nodeId' => 'i=2267'],
        ]);

        expect($results)->toHaveCount(2);
        expect($results[0]->getValue())->toBe(0);
        expect($results[1]->getValue())->toBe(255);
    });

    it('readMulti returns builder when no args', function () {
        $mock = MockClient::create()
            ->onRead('i=2259', fn() => DataValue::ofInt32(42));

        $results = $mock->readMulti()
            ->node('i=2259')->value()
            ->execute();

        expect($results)->toHaveCount(1);
        expect($results[0]->getValue())->toBe(42);
    });

    it('writeMulti delegates to individual writes', function () {
        $mock = MockClient::create()
            ->onWrite('ns=2;i=1001', fn() => 0);

        $results = $mock->writeMulti([
            ['nodeId' => 'ns=2;i=1001', 'value' => 42, 'type' => BuiltinType::Int32],
        ]);

        expect($results)->toBe([0]);
    });

    it('writeMulti returns builder when no args', function () {
        $mock = MockClient::create();
        $builder = $mock->writeMulti();
        expect($builder)->toBeInstanceOf(\Gianfriaur\OpcuaPhpClient\Builder\WriteMultiBuilder::class);
    });

    it('createSubscription returns default result', function () {
        $mock = MockClient::create();
        $sub = $mock->createSubscription(publishingInterval: 500.0);
        expect($sub->subscriptionId)->toBe(1);
        expect($sub->revisedPublishingInterval)->toBe(500.0);
    });

    it('createMonitoredItems returns default results', function () {
        $mock = MockClient::create();
        $results = $mock->createMonitoredItems(1, [
            ['nodeId' => 'i=2259'],
            ['nodeId' => 'i=2267'],
        ]);

        expect($results)->toHaveCount(2);
        expect($results[0]->monitoredItemId)->toBe(1);
        expect($results[1]->monitoredItemId)->toBe(2);
    });

    it('createMonitoredItems returns builder when no items', function () {
        $mock = MockClient::create();
        $builder = $mock->createMonitoredItems(1);
        expect($builder)->toBeInstanceOf(\Gianfriaur\OpcuaPhpClient\Builder\MonitoredItemsBuilder::class);
    });

    it('browseAll delegates to browse', function () {
        $mock = MockClient::create()
            ->onBrowse('i=85', fn() => ['ref']);

        expect($mock->browseAll('i=85'))->toBe(['ref']);
    });

    it('browseWithContinuation wraps in BrowseResultSet', function () {
        $mock = MockClient::create()
            ->onBrowse('i=85', fn() => ['ref']);

        $result = $mock->browseWithContinuation('i=85');
        expect($result->references)->toBe(['ref']);
        expect($result->continuationPoint)->toBeNull();
    });

    it('translateBrowsePaths returns builder when no args', function () {
        $mock = MockClient::create();
        $builder = $mock->translateBrowsePaths();
        expect($builder)->toBeInstanceOf(\Gianfriaur\OpcuaPhpClient\Builder\BrowsePathsBuilder::class);
    });

    it('config methods are fluent and store values', function () {
        $mock = MockClient::create()
            ->setTimeout(10.0)
            ->setAutoRetry(3)
            ->setBatchSize(50)
            ->setDefaultBrowseMaxDepth(20);

        expect($mock->getTimeout())->toBe(10.0);
        expect($mock->getAutoRetry())->toBe(3);
        expect($mock->getBatchSize())->toBe(50);
        expect($mock->getDefaultBrowseMaxDepth())->toBe(20);
    });

    it('reconnect sets state to Connected', function () {
        $mock = MockClient::create();
        $mock->reconnect();
        expect($mock->isConnected())->toBeTrue();
    });

    it('publish returns default result', function () {
        $mock = MockClient::create();
        $result = $mock->publish();
        expect($result->subscriptionId)->toBe(1);
        expect($result->moreNotifications)->toBeFalse();
    });

    it('getEndpoints returns empty array', function () {
        $mock = MockClient::create();
        expect($mock->getEndpoints('opc.tcp://localhost:4840'))->toBe([]);
    });

    it('browseRecursive returns empty array', function () {
        $mock = MockClient::create();
        expect($mock->browseRecursive('i=85'))->toBe([]);
    });

    it('browseNext returns empty BrowseResultSet', function () {
        $mock = MockClient::create();
        $result = $mock->browseNext('cont');
        expect($result->references)->toBe([]);
        expect($result->continuationPoint)->toBeNull();
    });

    it('createEventMonitoredItem returns default result', function () {
        $mock = MockClient::create();
        $result = $mock->createEventMonitoredItem(1, 'i=2253');
        expect($result->statusCode)->toBe(0);
        expect($result->monitoredItemId)->toBe(1);
    });

    it('deleteMonitoredItems returns status codes', function () {
        $mock = MockClient::create();
        $results = $mock->deleteMonitoredItems(1, [1, 2, 3]);
        expect($results)->toBe([0, 0, 0]);
    });

    it('deleteSubscription returns 0', function () {
        $mock = MockClient::create();
        expect($mock->deleteSubscription(1))->toBe(0);
    });

    it('historyReadRaw returns empty array', function () {
        $mock = MockClient::create();
        expect($mock->historyReadRaw('ns=2;i=1001'))->toBe([]);
    });

    it('historyReadProcessed returns empty array', function () {
        $mock = MockClient::create();
        expect($mock->historyReadProcessed('ns=2;i=1001', new DateTimeImmutable(), new DateTimeImmutable(), 3600000.0, NodeId::numeric(0, 2342)))->toBe([]);
    });

    it('historyReadAtTime returns empty array', function () {
        $mock = MockClient::create();
        expect($mock->historyReadAtTime('ns=2;i=1001', [new DateTimeImmutable()]))->toBe([]);
    });

    it('discoverDataTypes returns 0', function () {
        $mock = MockClient::create();
        expect($mock->discoverDataTypes())->toBe(0);
    });

    it('translateBrowsePaths with array returns empty', function () {
        $mock = MockClient::create();
        expect($mock->translateBrowsePaths([]))->toBe([]);
    });

    it('getServerMaxNodesPerRead returns null', function () {
        $mock = MockClient::create();
        expect($mock->getServerMaxNodesPerRead())->toBeNull();
        expect($mock->getServerMaxNodesPerWrite())->toBeNull();
    });

    it('call without handler returns default CallResult', function () {
        $mock = MockClient::create();
        $result = $mock->call('i=1', 'i=2');
        expect($result->statusCode)->toBe(0);
        expect($result->outputArguments)->toBe([]);
    });

    it('resolveNodeId without handler returns default NodeId', function () {
        $mock = MockClient::create();
        $nodeId = $mock->resolveNodeId('/NonExistent/Path');
        expect($nodeId->identifier)->toBe(0);
    });

    it('setLogger is fluent', function () {
        $mock = MockClient::create();
        $result = $mock->setLogger(new \Psr\Log\NullLogger());
        expect($result)->toBe($mock);
    });
});

describe('DataValue factory methods', function () {

    it('ofInt32 creates correct DataValue', function () {
        $dv = DataValue::ofInt32(42);
        expect($dv->getValue())->toBe(42);
        expect($dv->statusCode)->toBe(0);
    });

    it('ofDouble creates correct DataValue', function () {
        $dv = DataValue::ofDouble(3.14);
        expect($dv->getValue())->toBe(3.14);
    });

    it('ofString creates correct DataValue', function () {
        $dv = DataValue::ofString('hello');
        expect($dv->getValue())->toBe('hello');
    });

    it('ofBoolean creates correct DataValue', function () {
        $dv = DataValue::ofBoolean(true);
        expect($dv->getValue())->toBeTrue();
    });

    it('ofFloat creates correct DataValue', function () {
        $dv = DataValue::ofFloat(1.5);
        expect($dv->getValue())->toBe(1.5);
    });

    it('ofUInt32 creates correct DataValue', function () {
        $dv = DataValue::ofUInt32(42);
        expect($dv->getValue())->toBe(42);
    });

    it('ofInt16 creates correct DataValue', function () {
        $dv = DataValue::ofInt16(-100);
        expect($dv->getValue())->toBe(-100);
    });

    it('ofUInt16 creates correct DataValue', function () {
        $dv = DataValue::ofUInt16(100);
        expect($dv->getValue())->toBe(100);
    });

    it('ofInt64 creates correct DataValue', function () {
        $dv = DataValue::ofInt64(999);
        expect($dv->getValue())->toBe(999);
    });

    it('ofUInt64 creates correct DataValue', function () {
        $dv = DataValue::ofUInt64(999);
        expect($dv->getValue())->toBe(999);
    });

    it('ofDateTime creates correct DataValue', function () {
        $now = new DateTimeImmutable();
        $dv = DataValue::ofDateTime($now);
        expect($dv->getValue())->toBe($now);
    });

    it('of creates with custom type', function () {
        $dv = DataValue::of('test', BuiltinType::String, StatusCode::Good);
        expect($dv->getValue())->toBe('test');
        expect($dv->statusCode)->toBe(0);
    });

    it('bad creates DataValue with bad status', function () {
        $dv = DataValue::bad(StatusCode::BadNodeIdUnknown);
        expect($dv->getValue())->toBeNull();
        expect($dv->statusCode)->toBe(StatusCode::BadNodeIdUnknown);
    });
});
