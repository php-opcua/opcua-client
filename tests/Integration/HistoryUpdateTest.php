<?php

declare(strict_types=1);

use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Event\HistoryDataDeleted;
use PhpOpcua\Client\Event\HistoryDataUpdated;
use PhpOpcua\Client\Module\History\PerformUpdateType;
use PhpOpcua\Client\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Tests\Unit\Helpers\InMemoryEventDispatcher;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\StatusCode;
use PhpOpcua\Client\Types\Variant;

/**
 * HistoryUpdate against the open62541-historizing server
 * (php-opcua/extra-test-suite, port 24842). The Data subtypes get strict
 * assertions: Insert returns Good, and the inserted values round-trip
 * through historyReadRaw. The Event subtypes — open62541 1.4 does not
 * implement them — remain protocol-level round trips that only assert
 * the response is parseable.
 */
const HISTORIZING_NODE = 'ns=2;s=Historizing.Counter';

function makeHistDv(float $value, string $offset): DataValue
{
    return new DataValue(
        new Variant(BuiltinType::Double, $value),
        StatusCode::Good,
        new DateTimeImmutable($offset),
    );
}

/**
 * @param DataValue[] $raw
 */
function findNearTimestamp(array $raw, DateTimeImmutable $ts): ?DataValue
{
    // OPC UA encodes timestamps as 100-ns FILETIME ticks, so PHP microsecond
    // precision can drift by 1us across a round-trip. Match within ±10us.
    $target = (float) $ts->format('U.u');
    foreach ($raw as $dv) {
        if ($dv->sourceTimestamp === null) {
            continue;
        }
        if (abs((float) $dv->sourceTimestamp->format('U.u') - $target) < 0.00001) {
            return $dv;
        }
    }

    return null;
}

describe('HistoryUpdate Data ops (integration vs open62541-historizing)', function () {

    it('historyInsertData returns Good for every entry and the values become readable', function () {
        $client = null;
        try {
            $client = TestHelper::connectForHistorizing();

            $ts1 = new DateTimeImmutable('-15 minutes ' . random_int(1, 600) . ' seconds');
            $ts2 = $ts1->modify('+1 minute');
            $dv1 = new DataValue(new Variant(BuiltinType::Double, 11.0), StatusCode::Good, $ts1);
            $dv2 = new DataValue(new Variant(BuiltinType::Double, 22.0), StatusCode::Good, $ts2);

            $opStatuses = $client->historyInsertData(HISTORIZING_NODE, [$dv1, $dv2]);

            expect($opStatuses)->toHaveCount(2);
            expect(StatusCode::isGood($opStatuses[0]))->toBeTrue();
            expect(StatusCode::isGood($opStatuses[1]))->toBeTrue();

            // Round-trip read: both timestamps should now appear in the raw history.
            $readStart = (clone $ts1)->modify('-1 second');
            $readEnd = (clone $ts2)->modify('+1 second');
            $raw = $client->historyReadRaw(HISTORIZING_NODE, $readStart, $readEnd);

            $values = array_map(fn (DataValue $dv) => $dv->getValue(), $raw);
            expect($values)->toContain(11.0);
            expect($values)->toContain(22.0);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('historyReplaceData updates an existing value', function () {
        $client = null;
        try {
            $client = TestHelper::connectForHistorizing();

            $ts = new DateTimeImmutable('-10 minutes ' . random_int(1, 600) . ' seconds');
            $insertStatus = $client->historyInsertData(HISTORIZING_NODE, [
                new DataValue(new Variant(BuiltinType::Double, 100.0), StatusCode::Good, $ts),
            ]);
            expect(StatusCode::isGood($insertStatus[0]))->toBeTrue();

            $replaceStatus = $client->historyReplaceData(HISTORIZING_NODE, [
                new DataValue(new Variant(BuiltinType::Double, 200.0), StatusCode::Good, $ts),
            ]);
            expect(StatusCode::isGood($replaceStatus[0]))->toBeTrue();

            $raw = $client->historyReadRaw(
                HISTORIZING_NODE,
                (clone $ts)->modify('-1 second'),
                (clone $ts)->modify('+1 second'),
            );
            $match = findNearTimestamp($raw, $ts);
            expect($match)->not->toBeNull();
            expect($match->getValue())->toBe(200.0);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('historyUpdateData inserts when missing, replaces when present', function () {
        $client = null;
        try {
            $client = TestHelper::connectForHistorizing();

            $ts = new DateTimeImmutable('-5 minutes ' . random_int(1, 600) . ' seconds');

            $first = $client->historyUpdateData(HISTORIZING_NODE, [
                new DataValue(new Variant(BuiltinType::Double, 1.0), StatusCode::Good, $ts),
            ]);
            expect(StatusCode::isGood($first[0]))->toBeTrue();

            $second = $client->historyUpdateData(HISTORIZING_NODE, [
                new DataValue(new Variant(BuiltinType::Double, 2.0), StatusCode::Good, $ts),
            ]);
            expect(StatusCode::isGood($second[0]))->toBeTrue();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('historyDeleteAtTime round-trip (open62541 1.4 Memory backend does not actually delete)', function () {
        // The wire format is exercised end-to-end and a parseable response is
        // returned. The Memory backend used by the test container accepts the
        // call but does not remove entries — for a destructive-delete test we
        // would need a server with a SQLite-or-similar history backend.
        $client = null;
        try {
            $client = TestHelper::connectForHistorizing();

            $ts = new DateTimeImmutable('-3 minutes ' . random_int(1, 600) . ' seconds');

            $deleteStatuses = $client->historyDeleteAtTime(HISTORIZING_NODE, [$ts]);

            expect($deleteStatuses)->toBeArray();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('historyDeleteRawModified clears the range', function () {
        $client = null;
        try {
            $client = TestHelper::connectForHistorizing();

            $base = time() - 7200;
            $ts1 = (new DateTimeImmutable('@' . ($base + 100)));
            $ts2 = (new DateTimeImmutable('@' . ($base + 200)));
            $client->historyInsertData(HISTORIZING_NODE, [
                new DataValue(new Variant(BuiltinType::Double, 7.0), StatusCode::Good, $ts1),
                new DataValue(new Variant(BuiltinType::Double, 8.0), StatusCode::Good, $ts2),
            ]);

            $deleteStatus = $client->historyDeleteRawModified(
                HISTORIZING_NODE,
                new DateTimeImmutable('@' . ($base + 50)),
                new DateTimeImmutable('@' . ($base + 250)),
                false,
            );
            expect(StatusCode::isGood($deleteStatus))->toBeTrue();

            $raw = $client->historyReadRaw(
                HISTORIZING_NODE,
                new DateTimeImmutable('@' . ($base + 50)),
                new DateTimeImmutable('@' . ($base + 250)),
            );
            expect($raw)->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');
});

describe('HistoryUpdate dispatches PSR-14 events', function () {

    it('historyInsertData dispatches HistoryDataUpdated', function () {
        $client = null;
        try {
            $dispatcher = new InMemoryEventDispatcher();
            $client = (new ClientBuilder())
                ->setEventDispatcher($dispatcher)
                ->connect(TestHelper::ENDPOINT_HISTORIZING);

            $ts = new DateTimeImmutable('-7 minutes ' . random_int(1, 600) . ' seconds');
            $client->historyInsertData(HISTORIZING_NODE, [
                new DataValue(new Variant(BuiltinType::Double, 3.14), StatusCode::Good, $ts),
            ]);

            $events = $dispatcher->getEventsOfType(HistoryDataUpdated::class);
            expect($events)->toHaveCount(1);
            expect($events[0]->operation)->toBe(PerformUpdateType::Insert);
            expect($events[0]->valueCount)->toBe(1);
            expect($events[0]->operationResults)->toHaveCount(1);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('historyDeleteRawModified dispatches HistoryDataDeleted with kind=rawModified', function () {
        $client = null;
        try {
            $dispatcher = new InMemoryEventDispatcher();
            $client = (new ClientBuilder())
                ->setEventDispatcher($dispatcher)
                ->connect(TestHelper::ENDPOINT_HISTORIZING);

            $client->historyDeleteRawModified(
                HISTORIZING_NODE,
                new DateTimeImmutable('-1 day'),
                new DateTimeImmutable('-23 hours'),
                false,
            );

            $events = $dispatcher->getEventsOfType(HistoryDataDeleted::class);
            expect($events)->toHaveCount(1);
            expect($events[0]->kind)->toBe('rawModified');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');
});

describe('HistoryUpdate Event ops (protocol round-trip only)', function () {

    it('historyInsertEvent round-trip (open62541 returns BadHistoryOperationUnsupported)', function () {
        $client = null;
        try {
            $client = TestHelper::connectForHistorizing();

            $results = $client->historyInsertEvent(
                HISTORIZING_NODE,
                ['EventId', 'Severity', 'Message'],
                [
                    [
                        new Variant(BuiltinType::ByteString, "\x00\x01"),
                        new Variant(BuiltinType::UInt16, 100),
                        new Variant(BuiltinType::String, 'test'),
                    ],
                ],
            );

            expect($results)->toBeArray();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('historyDeleteEvent round-trip', function () {
        $client = null;
        try {
            $client = TestHelper::connectForHistorizing();

            $results = $client->historyDeleteEvent(HISTORIZING_NODE, ["\x00\x01\x02\x03"]);

            expect($results)->toBeArray();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');
});
