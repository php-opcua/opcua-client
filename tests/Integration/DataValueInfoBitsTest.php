<?php

declare(strict_types=1);

use PhpOpcua\Client\Module\Subscription\DataChangeNotification;
use PhpOpcua\Client\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\DataValueLimit;
use PhpOpcua\Client\Types\StatusCode;

/**
 * @return array<int, array<int, DataChangeNotification>>
 */
function collectFastCounterBatches(int $queueSize, int $publishes): array
{
    $client = null;
    try {
        $client = TestHelper::connectNoSecurity();
        $subscription = $client->createSubscription(publishingInterval: 1000.0);
        $client->createMonitoredItems($subscription->subscriptionId, [[
            'nodeId' => TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'FastCounter']),
            'clientHandle' => 1,
            'samplingInterval' => 100.0,
            'queueSize' => $queueSize,
        ]]);

        $batches = [];
        $acks = [];
        for ($i = 0; $i < $publishes; $i++) {
            $publish = $client->publish($acks);
            $batches[] = array_values(array_filter(
                $publish->notifications,
                fn (object $notification): bool => $notification instanceof DataChangeNotification,
            ));
            $acks = [['subscriptionId' => $publish->subscriptionId, 'sequenceNumber' => $publish->sequenceNumber]];
        }

        $client->deleteSubscription($subscription->subscriptionId);

        return $batches;
    } finally {
        TestHelper::safeDisconnect($client);
    }
}

describe('DataValue InfoBits', function () {

    it('reads the LimitBits a server sets on a value', function (string $node, DataValueLimit $expected) {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $dataValue = $client->read(TestHelper::browseToNode($client, ['TestServer', 'InfoBits', $node]));

            expect(StatusCode::isGood($dataValue->statusCode))->toBeTrue();
            expect($dataValue->limit())->toBe($expected);
            expect($dataValue->isOverflow())->toBeFalse();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->with([
        'no limit' => ['NoLimit', DataValueLimit::None],
        'low' => ['LimitLow', DataValueLimit::Low],
        'high' => ['LimitHigh', DataValueLimit::High],
        'constant' => ['LimitConstant', DataValueLimit::Constant],
    ])->group('integration');

    it('flags the first value delivered after a queue overflow', function () {
        $flaggedPositions = [];
        foreach (collectFastCounterBatches(queueSize: 2, publishes: 4) as $batch) {
            foreach ($batch as $position => $notification) {
                if ($notification->dataValue->isOverflow()) {
                    $flaggedPositions[] = $position;
                }
            }
        }

        expect($flaggedPositions)->not->toBeEmpty();
        expect(array_values(array_unique($flaggedPositions)))->toBe([0]);
    })->group('integration');

    it('never flags overflow on a single-slot queue even when values are skipped', function () {
        $values = [];
        foreach (collectFastCounterBatches(queueSize: 1, publishes: 4) as $batch) {
            foreach ($batch as $notification) {
                expect($notification->dataValue->isOverflow())->toBeFalse();
                $values[] = $notification->dataValue->getValue();
            }
        }

        $gaps = array_map(
            fn (int $previous, int $next): int => $next - $previous,
            array_slice($values, 0, -1),
            array_slice($values, 1),
        );

        expect(max($gaps))->toBeGreaterThan(1);
    })->group('integration');

});
