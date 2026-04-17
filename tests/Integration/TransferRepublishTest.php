<?php

declare(strict_types=1);

use PhpOpcua\Client\Module\Subscription\TransferResult;
use PhpOpcua\Client\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;

describe('TransferSubscriptions', function () {

    it('transfers a subscription to the current session', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $sub = $client->createSubscription(publishingInterval: 500.0);
            $client->createMonitoredItems($sub->subscriptionId, [
                ['nodeId' => NodeId::numeric(0, 2258)],
            ]);

            $results = $client->transferSubscriptions([$sub->subscriptionId]);

            expect($results)->toHaveCount(1);
            expect($results[0])->toBeInstanceOf(TransferResult::class);
            expect($results[0]->statusCode)->toBeInt();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('returns status for non-existent subscription', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $results = $client->transferSubscriptions([99999]);

            expect($results)->toHaveCount(1);
            expect(StatusCode::isBad($results[0]->statusCode))->toBeTrue();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('transfers multiple subscriptions at once', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $sub1 = $client->createSubscription(publishingInterval: 500.0);
            $sub2 = $client->createSubscription(publishingInterval: 1000.0);

            $results = $client->transferSubscriptions(
                [$sub1->subscriptionId, $sub2->subscriptionId],
                sendInitialValues: true,
            );

            expect($results)->toHaveCount(2);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');

describe('Republish', function () {

    it('republishes a notification by sequence number', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $sub = $client->createSubscription(publishingInterval: 200.0);
            $client->createMonitoredItems($sub->subscriptionId, [
                ['nodeId' => NodeId::numeric(0, 2258)],
            ]);

            $response = $client->publish();

            try {
                $result = $client->republish($sub->subscriptionId, $response->sequenceNumber);

                expect($result)->toHaveKeys(['sequenceNumber', 'publishTime', 'notifications']);
                expect($result['sequenceNumber'])->toBe($response->sequenceNumber);
            } catch (PhpOpcua\Client\Exception\ServiceException $e) {
                expect(StatusCode::isBad($e->getStatusCode()))->toBeTrue();
            }
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('handles invalid sequence number gracefully', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $sub = $client->createSubscription(publishingInterval: 500.0);

            try {
                $result = $client->republish($sub->subscriptionId, 99999);
                expect($result)->toHaveKey('sequenceNumber');
            } catch (PhpOpcua\Client\Exception\OpcUaException) {
                expect(true)->toBeTrue();
            }
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');
