<?php

declare(strict_types=1);

use PhpOpcua\Client\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\StatusCode;

describe('PublishResult against a real server', function () {

    it('reports the publish time and the status of every acknowledgement', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $subscriptionId = $client->createSubscription(200.0)->subscriptionId;
            $counter = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'Counter']);
            $client->createMonitoredItems($subscriptionId, [['nodeId' => $counter, 'samplingInterval' => 100.0]]);

            $first = $client->publish();
            expect($first->publishTime)->toBeInstanceOf(DateTimeImmutable::class);
            expect(abs($first->publishTime->getTimestamp() - time()))->toBeLessThanOrEqual(5);
            expect($first->acknowledgementResults)->toBe([]);

            $second = $client->publish([
                ['subscriptionId' => $subscriptionId, 'sequenceNumber' => $first->sequenceNumber],
                ['subscriptionId' => $subscriptionId, 'sequenceNumber' => 999999],
                ['subscriptionId' => 424242, 'sequenceNumber' => 1],
            ]);

            expect($second->acknowledgementResults)->toBe([StatusCode::Good, 0x807A0000, 0x80280000]);
            expect(StatusCode::getName($second->acknowledgementResults[1]))->toBe('BadSequenceNumberUnknown');
            expect(StatusCode::getName($second->acknowledgementResults[2]))->toBe('BadSubscriptionIdInvalid');

            $client->deleteSubscription($subscriptionId);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');
});
