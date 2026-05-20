<?php

declare(strict_types=1);

use PhpOpcua\Client\Event\AggregateComputed;
use PhpOpcua\Client\Event\HistoryDataDeleted;
use PhpOpcua\Client\Event\HistoryDataUpdated;
use PhpOpcua\Client\Event\HistoryEventDeleted;
use PhpOpcua\Client\Event\HistoryEventUpdated;
use PhpOpcua\Client\Module\Aggregate\AggregateFunction;
use PhpOpcua\Client\Module\History\PerformUpdateType;
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\NodeId;

describe('HistoryUpdate + Aggregate events', function () {

    it('HistoryDataUpdated carries client, nodeId, operation, valueCount and operationResults', function () {
        $client = MockClient::create();
        $node = NodeId::numeric(2, 42);
        $event = new HistoryDataUpdated($client, $node, PerformUpdateType::Insert, 3, [0, 0, 0x80B10000]);

        expect($event->client)->toBe($client);
        expect($event->nodeId)->toBe($node);
        expect($event->operation)->toBe(PerformUpdateType::Insert);
        expect($event->valueCount)->toBe(3);
        expect($event->operationResults)->toBe([0, 0, 0x80B10000]);
    });

    it('HistoryDataDeleted distinguishes rawModified vs atTime via $kind', function () {
        $client = MockClient::create();
        $node = NodeId::numeric(2, 42);

        $range = new HistoryDataDeleted($client, $node, 'rawModified', 0, []);
        expect($range->kind)->toBe('rawModified');
        expect($range->statusCode)->toBe(0);
        expect($range->operationResults)->toBe([]);

        $atTime = new HistoryDataDeleted($client, $node, 'atTime', 0, [0, 0x80B10000]);
        expect($atTime->kind)->toBe('atTime');
        expect($atTime->operationResults)->toBe([0, 0x80B10000]);
    });

    it('HistoryEventUpdated carries operation and eventCount', function () {
        $client = MockClient::create();
        $node = NodeId::numeric(2, 42);
        $event = new HistoryEventUpdated($client, $node, PerformUpdateType::Update, 2, [0, 0]);

        expect($event->operation)->toBe(PerformUpdateType::Update);
        expect($event->eventCount)->toBe(2);
    });

    it('HistoryEventDeleted carries eventCount and operationResults', function () {
        $client = MockClient::create();
        $node = NodeId::numeric(2, 42);
        $event = new HistoryEventDeleted($client, $node, 1, [0]);

        expect($event->eventCount)->toBe(1);
        expect($event->operationResults)->toBe([0]);
    });

    it('AggregateComputed carries function, raw input count, interval count and optional nodeId', function () {
        $client = MockClient::create();

        $pure = new AggregateComputed($client, AggregateFunction::Average, 100, 5);
        expect($pure->function)->toBe(AggregateFunction::Average);
        expect($pure->rawInputCount)->toBe(100);
        expect($pure->intervalCount)->toBe(5);
        expect($pure->nodeId)->toBeNull();

        $node = NodeId::numeric(2, 42);
        $withNode = new AggregateComputed($client, AggregateFunction::Maximum, 50, 3, $node);
        expect($withNode->nodeId)->toBe($node);
    });
});
