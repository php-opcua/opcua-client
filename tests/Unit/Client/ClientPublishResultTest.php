<?php

declare(strict_types=1);

require_once __DIR__ . '/ClientTraitsCoverageTest.php';

use PhpOpcua\Client\Encoding\BinaryEncoder;

describe('PublishResult publish time and acknowledgement results', function () {

    it('decodes the publish time and the status of every acknowledgement', function () {
        $publishTime = new DateTimeImmutable('2026-09-14T10:15:30+00:00');
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(829, function (BinaryEncoder $e) use ($publishTime) {
            $e->writeUInt32(7);
            $e->writeInt32(0);
            $e->writeBoolean(false);
            $e->writeUInt32(12);
            $e->writeDateTime($publishTime);
            $e->writeInt32(0);
            $e->writeInt32(2);
            $e->writeUInt32(0);
            $e->writeUInt32(0x807A0000);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        $result = $client->publish([
            ['subscriptionId' => 7, 'sequenceNumber' => 11],
            ['subscriptionId' => 7, 'sequenceNumber' => 999],
        ]);

        expect($result->publishTime?->format('c'))->toBe('2026-09-14T10:15:30+00:00');
        expect($result->acknowledgementResults)->toBe([0, 0x807A0000]);
    });
});
