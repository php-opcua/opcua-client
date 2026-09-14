<?php

declare(strict_types=1);

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Module\Subscription\DataChangeNotification;
use PhpOpcua\Client\Module\Subscription\EventNotification;
use PhpOpcua\Client\Module\Subscription\SubscriptionService;
use PhpOpcua\Client\Module\Subscription\TransferResult;
use PhpOpcua\Client\Protocol\MessageHeader;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\Variant;

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

    it('decodes the DataChange and Event notifications carried by a Republish response', function () {
        $service = new SubscriptionService(new SessionService(1, 1));

        $dataChange = new BinaryEncoder();
        $dataChange->writeInt32(2);
        $dataChange->writeUInt32(7);
        $dataChange->writeDataValue(new DataValue(new Variant(BuiltinType::Int32, 100)));
        $dataChange->writeUInt32(8);
        $dataChange->writeDataValue(new DataValue(new Variant(BuiltinType::Double, 3.14)));
        $dataChange->writeInt32(0);

        $events = new BinaryEncoder();
        $events->writeInt32(1);
        $events->writeUInt32(9);
        $events->writeInt32(1);
        $events->writeVariant(new Variant(BuiltinType::String, 'Overheating'));

        $e = new BinaryEncoder();
        trPrefix($e);
        $e->writeNodeId(NodeId::numeric(0, 835));
        trResponseHeader($e);
        $e->writeUInt32(12);
        $e->writeDateTime(null);
        $e->writeInt32(3);
        foreach ([[811, $dataChange->getBuffer()], [820, "\x00\x00\x00\x00"], [916, $events->getBuffer()]] as [$type, $body]) {
            $e->writeNodeId(NodeId::numeric(0, $type));
            $e->writeByte(0x01);
            $e->writeInt32(strlen($body));
            $e->writeRawBytes($body);
        }

        $result = $service->decodeRepublishResponse(new BinaryDecoder($e->getBuffer()));

        expect($result['sequenceNumber'])->toBe(12);
        expect($result['notifications'])->toHaveCount(3);

        [$first, $second, $event] = $result['notifications'];
        expect($first)->toBeInstanceOf(DataChangeNotification::class);
        expect($first->clientHandle)->toBe(7);
        expect($first->dataValue->getValue())->toBe(100);
        expect($second->clientHandle)->toBe(8);
        expect($second->dataValue->getValue())->toBe(3.14);
        expect($event)->toBeInstanceOf(EventNotification::class);
        expect($event->clientHandle)->toBe(9);
        expect($event->eventFields[0]->value)->toBe('Overheating');
    });

    it('skips bodyless, empty and XML-encoded notification entries without losing sync', function () {
        $service = new SubscriptionService(new SessionService(1, 1));

        $dataChange = new BinaryEncoder();
        $dataChange->writeInt32(1);
        $dataChange->writeUInt32(5);
        $dataChange->writeDataValue(new DataValue(new Variant(BuiltinType::Int32, 42)));
        $dataChange->writeInt32(0);

        $xml = '<DataChangeNotification/>';

        $e = new BinaryEncoder();
        trPrefix($e);
        $e->writeNodeId(NodeId::numeric(0, 835));
        trResponseHeader($e);
        $e->writeUInt32(13);
        $e->writeDateTime(null);
        $e->writeInt32(4);

        $e->writeNodeId(NodeId::numeric(0, 811));
        $e->writeByte(0x00);

        $e->writeNodeId(NodeId::numeric(0, 811));
        $e->writeByte(0x01);
        $e->writeInt32(0);

        $e->writeNodeId(NodeId::numeric(0, 811));
        $e->writeByte(0x02);
        $e->writeInt32(strlen($xml));
        $e->writeRawBytes($xml);

        $e->writeNodeId(NodeId::numeric(0, 811));
        $e->writeByte(0x01);
        $e->writeInt32(strlen($dataChange->getBuffer()));
        $e->writeRawBytes($dataChange->getBuffer());

        $result = $service->decodeRepublishResponse(new BinaryDecoder($e->getBuffer()));

        expect($result['notifications'])->toHaveCount(1);
        expect($result['notifications'][0]->clientHandle)->toBe(5);
        expect($result['notifications'][0]->dataValue->getValue())->toBe(42);
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
