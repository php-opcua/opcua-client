<?php

declare(strict_types=1);

require_once __DIR__ . '/ClientTraitsCoverageTest.php';

use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Module\Subscription\TransferResult;

describe('TransferSubscriptions and Republish via MockTransport', function () {

    it('transferSubscriptions returns TransferResult array', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(844, function (BinaryEncoder $e) {
            $e->writeInt32(2);
            $e->writeUInt32(0);
            $e->writeInt32(2);
            $e->writeUInt32(1);
            $e->writeUInt32(2);
            $e->writeUInt32(0);
            $e->writeInt32(0);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        $results = $client->transferSubscriptions([100, 200]);

        expect($results)->toHaveCount(2);
        expect($results[0])->toBeInstanceOf(TransferResult::class);
        expect($results[0]->statusCode)->toBe(0);
        expect($results[0]->availableSequenceNumbers)->toBe([1, 2]);
        expect($results[1]->statusCode)->toBe(0);
    });

    it('republish returns notification message', function () {
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(835, function (BinaryEncoder $e) {
            $e->writeUInt32(5);
            $e->writeDateTime(null);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        $result = $client->republish(1, 5);

        expect($result['sequenceNumber'])->toBe(5);
        expect($result['notifications'])->toBe([]);
    });

    it('transferSubscriptions throws ConnectionException when not connected', function () {
        $client = createClientWithoutConnect();
        registerClientModules($client);
        expect(fn () => $client->transferSubscriptions([1]))
            ->toThrow(PhpOpcua\Client\Exception\ConnectionException::class);
    });

    it('republish throws ConnectionException when not connected', function () {
        $client = createClientWithoutConnect();
        registerClientModules($client);
        expect(fn () => $client->republish(1, 1))
            ->toThrow(PhpOpcua\Client\Exception\ConnectionException::class);
    });
});
