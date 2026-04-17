<?php

declare(strict_types=1);

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Module\Subscription\SubscriptionService;
use PhpOpcua\Client\Module\Subscription\TransferResult;
use PhpOpcua\Client\Protocol\MessageHeader;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Types\NodeId;

function trPrefix(BinaryEncoder $e): void
{
    $e->writeUInt32(1);
    $e->writeUInt32(1);
    $e->writeUInt32(1);
}

function trResponseHeader(BinaryEncoder $e): void
{
    $e->writeInt64(0);
    $e->writeUInt32(1);
    $e->writeUInt32(0);
    $e->writeByte(0);
    $e->writeInt32(0);
    $e->writeNodeId(NodeId::numeric(0, 0));
    $e->writeByte(0);
}

describe('TransferSubscriptions', function () {

    it('encodes a TransferSubscriptions request', function () {
        $session = new SessionService(1, 1);
        $service = new SubscriptionService($session);

        $bytes = $service->encodeTransferSubscriptionsRequest(
            1,
            NodeId::numeric(0, 0),
            [100, 200, 300],
            true,
        );

        $decoder = new BinaryDecoder($bytes);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('MSG');
        expect(strlen($bytes))->toBeGreaterThan(50);
    });

    it('decodes a TransferSubscriptions response', function () {
        $session = new SessionService(1, 1);
        $service = new SubscriptionService($session);

        $e = new BinaryEncoder();
        trPrefix($e);
        $e->writeNodeId(NodeId::numeric(0, 844));
        trResponseHeader($e);

        $e->writeInt32(2);
        $e->writeUInt32(0);
        $e->writeInt32(3);
        $e->writeUInt32(1);
        $e->writeUInt32(2);
        $e->writeUInt32(3);
        $e->writeUInt32(0x80010000);
        $e->writeInt32(0);

        $e->writeInt32(0);

        $decoder = new BinaryDecoder($e->getBuffer());
        $results = $service->decodeTransferSubscriptionsResponse($decoder);

        expect($results)->toHaveCount(2);
        expect($results[0])->toBeInstanceOf(TransferResult::class);
        expect($results[0]->statusCode)->toBe(0);
        expect($results[0]->availableSequenceNumbers)->toBe([1, 2, 3]);
        expect($results[1]->statusCode)->toBe(0x80010000);
        expect($results[1]->availableSequenceNumbers)->toBe([]);
    });

    it('decodes response with diagnostic infos', function () {
        $session = new SessionService(1, 1);
        $service = new SubscriptionService($session);

        $e = new BinaryEncoder();
        trPrefix($e);
        $e->writeNodeId(NodeId::numeric(0, 844));
        trResponseHeader($e);

        $e->writeInt32(1);
        $e->writeUInt32(0);
        $e->writeInt32(0);

        $e->writeInt32(1);
        $e->writeByte(0x01);
        $e->writeInt32(42);

        $decoder = new BinaryDecoder($e->getBuffer());
        $results = $service->decodeTransferSubscriptionsResponse($decoder);

        expect($results)->toHaveCount(1);
    });
});

describe('Republish', function () {

    it('encodes a Republish request', function () {
        $session = new SessionService(1, 1);
        $service = new SubscriptionService($session);

        $bytes = $service->encodeRepublishRequest(
            1,
            NodeId::numeric(0, 0),
            100,
            5,
        );

        $decoder = new BinaryDecoder($bytes);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('MSG');
        expect(strlen($bytes))->toBeGreaterThan(40);
    });

    it('decodes a Republish response with notifications', function () {
        $session = new SessionService(1, 1);
        $service = new SubscriptionService($session);

        $e = new BinaryEncoder();
        trPrefix($e);
        $e->writeNodeId(NodeId::numeric(0, 835));
        trResponseHeader($e);

        $e->writeUInt32(3);
        $e->writeDateTime(null);

        $e->writeInt32(2);
        $e->writeNodeId(NodeId::numeric(0, 811));
        $e->writeByte(0x01);
        $notifBody = str_repeat("\x00", 20);
        $e->writeInt32(strlen($notifBody));
        $e->writeRawBytes($notifBody);
        $e->writeNodeId(NodeId::numeric(0, 811));
        $e->writeByte(0x01);
        $e->writeInt32(0);

        $decoder = new BinaryDecoder($e->getBuffer());
        $result = $service->decodeRepublishResponse($decoder);

        expect($result['sequenceNumber'])->toBe(3);
    });

    it('decodes a Republish response with empty notification', function () {
        $session = new SessionService(1, 1);
        $service = new SubscriptionService($session);

        $e = new BinaryEncoder();
        trPrefix($e);
        $e->writeNodeId(NodeId::numeric(0, 835));
        trResponseHeader($e);

        $e->writeUInt32(5);
        $e->writeDateTime(null);
        $e->writeInt32(0);

        $decoder = new BinaryDecoder($e->getBuffer());
        $result = $service->decodeRepublishResponse($decoder);

        expect($result['sequenceNumber'])->toBe(5);
        expect($result['notifications'])->toBe([]);
    });
});
