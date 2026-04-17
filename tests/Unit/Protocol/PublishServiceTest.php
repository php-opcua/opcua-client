<?php

declare(strict_types=1);

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Module\Subscription\PublishService;
use PhpOpcua\Client\Protocol\MessageHeader;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\NodeId;

function writePublishResponseHeader(BinaryEncoder $encoder, int $statusCode = 0): void
{
    $encoder->writeInt64(0);
    $encoder->writeUInt32(1);
    $encoder->writeUInt32($statusCode);
    $encoder->writeByte(0);
    $encoder->writeInt32(0);
    $encoder->writeNodeId(NodeId::numeric(0, 0));
    $encoder->writeByte(0);
}

function writePublishMessagePrefix(BinaryEncoder $encoder): void
{
    $encoder->writeUInt32(1);
    $encoder->writeUInt32(1);
    $encoder->writeUInt32(1);
}

describe('PublishService encoding', function () {

    it('encodes a Publish request without acknowledgements', function () {
        $session = new SessionService(1, 1);
        $service = new PublishService($session);

        $bytes = $service->encodePublishRequest(1, NodeId::numeric(0, 0));

        $decoder = new BinaryDecoder($bytes);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('MSG');
    });

    it('encodes a Publish request with acknowledgements', function () {
        $session = new SessionService(1, 1);
        $service = new PublishService($session);

        $bytes = $service->encodePublishRequest(1, NodeId::numeric(0, 0), [
            ['subscriptionId' => 42, 'sequenceNumber' => 1],
            ['subscriptionId' => 42, 'sequenceNumber' => 2],
        ]);

        expect(strlen($bytes))->toBeGreaterThan(40);
    });
});

describe('PublishService decoding', function () {

    it('decodes a PublishResponse with DataChangeNotification', function () {
        $session = new SessionService(1, 1);
        $service = new PublishService($session);

        $encoder = new BinaryEncoder();
        writePublishMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 829)); // PublishResponse
        writePublishResponseHeader($encoder);

        // SubscriptionId
        $encoder->writeUInt32(42);
        // AvailableSequenceNumbers: 1
        $encoder->writeInt32(1);
        $encoder->writeUInt32(5);
        // MoreNotifications
        $encoder->writeBoolean(false);

        // NotificationMessage
        $encoder->writeUInt32(5); // SequenceNumber
        $encoder->writeDateTime(null); // PublishTime

        // NotificationData: 1 DataChangeNotification (TypeId 811)
        $encoder->writeInt32(1);
        $encoder->writeNodeId(NodeId::numeric(0, 811));
        $encoder->writeByte(0x01); // has body

        // Build DataChangeNotification body
        $bodyEncoder = new BinaryEncoder();
        // MonitoredItems: 2
        $bodyEncoder->writeInt32(2);
        // Item 1
        $bodyEncoder->writeUInt32(1); // clientHandle
        $bodyEncoder->writeByte(0x01); // DataValue mask (value)
        $bodyEncoder->writeByte(BuiltinType::Int32->value);
        $bodyEncoder->writeInt32(100);
        // Item 2
        $bodyEncoder->writeUInt32(2); // clientHandle
        $bodyEncoder->writeByte(0x01);
        $bodyEncoder->writeByte(BuiltinType::Double->value);
        $bodyEncoder->writeDouble(3.14);
        // DiagnosticInfos: empty
        $bodyEncoder->writeInt32(0);

        $body = $bodyEncoder->getBuffer();
        $encoder->writeInt32(strlen($body));
        $encoder->writeRawBytes($body);

        // Results: empty
        $encoder->writeInt32(0);
        // DiagnosticInfos: empty
        $encoder->writeInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $service->decodePublishResponse($decoder);
        expect($result->subscriptionId)->toBe(42);
        expect($result->sequenceNumber)->toBe(5);
        expect($result->moreNotifications)->toBeFalse();
        expect($result->availableSequenceNumbers)->toBe([5]);
        expect($result->notifications)->toHaveCount(2);
        expect($result->notifications[0]['type'])->toBe('DataChange');
        expect($result->notifications[0]['clientHandle'])->toBe(1);
        expect($result->notifications[0]['dataValue']->getValue())->toBe(100);
        expect($result->notifications[1]['clientHandle'])->toBe(2);
        expect($result->notifications[1]['dataValue']->getValue())->toBe(3.14);
    });

    it('decodes a PublishResponse with EventNotificationList', function () {
        $session = new SessionService(1, 1);
        $service = new PublishService($session);

        $encoder = new BinaryEncoder();
        writePublishMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 829));
        writePublishResponseHeader($encoder);

        $encoder->writeUInt32(10); // SubscriptionId
        $encoder->writeInt32(0);   // AvailableSequenceNumbers: 0
        $encoder->writeBoolean(false);

        // NotificationMessage
        $encoder->writeUInt32(1);
        $encoder->writeDateTime(null);

        // NotificationData: 1 EventNotificationList (TypeId 916)
        $encoder->writeInt32(1);
        $encoder->writeNodeId(NodeId::numeric(0, 916));
        $encoder->writeByte(0x01);

        // Build EventNotificationList body
        $bodyEncoder = new BinaryEncoder();
        // Events: 1 EventFieldList
        $bodyEncoder->writeInt32(1);
        $bodyEncoder->writeUInt32(5); // clientHandle
        // EventFields: 2 variants
        $bodyEncoder->writeInt32(2);
        // Field 1: String
        $bodyEncoder->writeByte(BuiltinType::String->value);
        $bodyEncoder->writeString('TestEvent');
        // Field 2: UInt16 (Severity)
        $bodyEncoder->writeByte(BuiltinType::UInt16->value);
        $bodyEncoder->writeUInt16(500);

        $body = $bodyEncoder->getBuffer();
        $encoder->writeInt32(strlen($body));
        $encoder->writeRawBytes($body);

        // Results: empty
        $encoder->writeInt32(0);
        // DiagnosticInfos: empty
        $encoder->writeInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $service->decodePublishResponse($decoder);
        expect($result->subscriptionId)->toBe(10);
        expect($result->notifications)->toHaveCount(1);
        expect($result->notifications[0]['type'])->toBe('Event');
        expect($result->notifications[0]['clientHandle'])->toBe(5);
        expect($result->notifications[0]['eventFields'])->toHaveCount(2);
        expect($result->notifications[0]['eventFields'][0]->getValue())->toBe('TestEvent');
        expect($result->notifications[0]['eventFields'][1]->getValue())->toBe(500);
    });

    it('decodes a PublishResponse with unknown notification type (skips)', function () {
        $session = new SessionService(1, 1);
        $service = new PublishService($session);

        $encoder = new BinaryEncoder();
        writePublishMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 829));
        writePublishResponseHeader($encoder);

        $encoder->writeUInt32(1);
        $encoder->writeInt32(0);
        $encoder->writeBoolean(false);
        $encoder->writeUInt32(1);
        $encoder->writeDateTime(null);

        // NotificationData: 1 unknown type
        $encoder->writeInt32(1);
        $encoder->writeNodeId(NodeId::numeric(0, 9999)); // unknown
        $encoder->writeByte(0x01);
        $fakeBody = str_repeat("\x00", 16);
        $encoder->writeInt32(strlen($fakeBody));
        $encoder->writeRawBytes($fakeBody);

        $encoder->writeInt32(0);
        $encoder->writeInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $service->decodePublishResponse($decoder);
        expect($result->notifications)->toBe([]);
    });

    it('decodes a PublishResponse with no-body notification', function () {
        $session = new SessionService(1, 1);
        $service = new PublishService($session);

        $encoder = new BinaryEncoder();
        writePublishMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 829));
        writePublishResponseHeader($encoder);

        $encoder->writeUInt32(1);
        $encoder->writeInt32(0);
        $encoder->writeBoolean(false);
        $encoder->writeUInt32(1);
        $encoder->writeDateTime(null);

        // NotificationData: 1 with no body
        $encoder->writeInt32(1);
        $encoder->writeNodeId(NodeId::numeric(0, 0));
        $encoder->writeByte(0x00); // no body

        $encoder->writeInt32(0);
        $encoder->writeInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $service->decodePublishResponse($decoder);
        expect($result->notifications)->toBe([]);
    });

    it('decodes an empty PublishResponse (keep-alive)', function () {
        $session = new SessionService(1, 1);
        $service = new PublishService($session);

        $encoder = new BinaryEncoder();
        writePublishMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 829));
        writePublishResponseHeader($encoder);

        $encoder->writeUInt32(42);
        $encoder->writeInt32(0); // no avail seq numbers
        $encoder->writeBoolean(false);
        $encoder->writeUInt32(0); // seq number 0
        $encoder->writeDateTime(null);
        $encoder->writeInt32(0); // no notification data

        $encoder->writeInt32(0);
        $encoder->writeInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $service->decodePublishResponse($decoder);
        expect($result->subscriptionId)->toBe(42);
        expect($result->notifications)->toBe([]);
        expect($result->moreNotifications)->toBeFalse();
    });
});
