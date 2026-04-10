<?php

declare(strict_types=1);

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Protocol\MessageHeader;
use PhpOpcua\Client\Protocol\MonitoredItemService;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Types\NodeId;

function writeMonitoredResponseHeader(BinaryEncoder $encoder, int $statusCode = 0): void
{
    $encoder->writeInt64(0);
    $encoder->writeUInt32(1);
    $encoder->writeUInt32($statusCode);
    $encoder->writeByte(0);
    $encoder->writeInt32(0);
    $encoder->writeNodeId(NodeId::numeric(0, 0));
    $encoder->writeByte(0);
}

function writeMonitoredMessagePrefix(BinaryEncoder $encoder): void
{
    $encoder->writeUInt32(1);
    $encoder->writeUInt32(1);
    $encoder->writeUInt32(1);
}

describe('MonitoredItemService encoding', function () {

    it('encodes a CreateMonitoredItems request', function () {
        $session = new SessionService(1, 1);
        $service = new MonitoredItemService($session);

        $bytes = $service->encodeCreateMonitoredItemsRequest(
            1,
            NodeId::numeric(0, 0),
            42,
            [
                ['nodeId' => NodeId::numeric(1, 100)],
                ['nodeId' => NodeId::numeric(1, 101), 'attributeId' => 1, 'samplingInterval' => 500.0, 'queueSize' => 10],
            ],
        );

        $decoder = new BinaryDecoder($bytes);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('MSG');
        expect(strlen($bytes))->toBeGreaterThan(50);
    });

    it('encodes a CreateMonitoredItems request with custom monitoring mode and client handle', function () {
        $session = new SessionService(1, 1);
        $service = new MonitoredItemService($session);

        $bytes = $service->encodeCreateMonitoredItemsRequest(
            1,
            NodeId::numeric(0, 0),
            10,
            [
                ['nodeId' => NodeId::numeric(1, 200), 'monitoringMode' => 1, 'clientHandle' => 99],
            ],
        );

        expect(strlen($bytes))->toBeGreaterThan(20);
    });

    it('encodes a CreateEventMonitoredItem request', function () {
        $session = new SessionService(1, 1);
        $service = new MonitoredItemService($session);

        $bytes = $service->encodeCreateEventMonitoredItemRequest(
            1,
            NodeId::numeric(0, 0),
            42,
            NodeId::numeric(0, 2253), // Server node
            ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'],
            5,
        );

        $decoder = new BinaryDecoder($bytes);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('MSG');
        expect(strlen($bytes))->toBeGreaterThan(100);
    });

    it('encodes a DeleteMonitoredItems request', function () {
        $session = new SessionService(1, 1);
        $service = new MonitoredItemService($session);

        $bytes = $service->encodeDeleteMonitoredItemsRequest(
            1,
            NodeId::numeric(0, 0),
            42,
            [1, 2, 3],
        );

        $decoder = new BinaryDecoder($bytes);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('MSG');
    });
});

describe('MonitoredItemService decoding', function () {

    it('decodes a CreateMonitoredItemsResponse', function () {
        $session = new SessionService(1, 1);
        $service = new MonitoredItemService($session);

        $encoder = new BinaryEncoder();
        writeMonitoredMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 754)); // CreateMonitoredItemsResponse
        writeMonitoredResponseHeader($encoder);

        // Results: 2 MonitoredItemCreateResult
        $encoder->writeInt32(2);
        // Result 1
        $encoder->writeUInt32(0);         // StatusCode (Good)
        $encoder->writeUInt32(100);       // MonitoredItemId
        $encoder->writeDouble(500.0);     // RevisedSamplingInterval
        $encoder->writeUInt32(1);         // RevisedQueueSize
        // FilterResult (ExtensionObject - no body)
        $encoder->writeNodeId(NodeId::numeric(0, 0));
        $encoder->writeByte(0x00);
        // Result 2
        $encoder->writeUInt32(0);
        $encoder->writeUInt32(101);
        $encoder->writeDouble(1000.0);
        $encoder->writeUInt32(5);
        $encoder->writeNodeId(NodeId::numeric(0, 0));
        $encoder->writeByte(0x00);

        // DiagnosticInfos: empty
        $encoder->writeInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $service->decodeCreateMonitoredItemsResponse($decoder);
        expect($result)->toHaveCount(2);
        expect($result[0]->statusCode)->toBe(0);
        expect($result[0]->monitoredItemId)->toBe(100);
        expect($result[0]->revisedSamplingInterval)->toBe(500.0);
        expect($result[0]->revisedQueueSize)->toBe(1);
        expect($result[1]->monitoredItemId)->toBe(101);
        expect($result[1]->revisedSamplingInterval)->toBe(1000.0);
    });

    it('decodes a DeleteMonitoredItemsResponse', function () {
        $session = new SessionService(1, 1);
        $service = new MonitoredItemService($session);

        $encoder = new BinaryEncoder();
        writeMonitoredMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 784)); // DeleteMonitoredItemsResponse
        writeMonitoredResponseHeader($encoder);

        // Results: 3 status codes
        $encoder->writeInt32(3);
        $encoder->writeUInt32(0);          // Good
        $encoder->writeUInt32(0);          // Good
        $encoder->writeUInt32(0x80700000); // Some error

        // DiagnosticInfos: empty
        $encoder->writeInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $service->decodeDeleteMonitoredItemsResponse($decoder);
        expect($result)->toHaveCount(3);
        expect($result[0])->toBe(0);
        expect($result[2])->toBe(0x80700000);
    });
});

describe('MonitoredItemService ModifyMonitoredItems', function () {

    it('encodes a ModifyMonitoredItems request', function () {
        $session = new SessionService(1, 1);
        $service = new MonitoredItemService($session);

        $bytes = $service->encodeModifyMonitoredItemsRequest(
            1,
            NodeId::numeric(0, 0),
            42,
            [
                ['monitoredItemId' => 100, 'samplingInterval' => 250.0, 'queueSize' => 5],
                ['monitoredItemId' => 101, 'clientHandle' => 10, 'discardOldest' => false],
            ],
        );

        $decoder = new BinaryDecoder($bytes);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('MSG');
        expect(strlen($bytes))->toBeGreaterThan(50);
    });

    it('encodes a ModifyMonitoredItems request with defaults', function () {
        $session = new SessionService(1, 1);
        $service = new MonitoredItemService($session);

        $bytes = $service->encodeModifyMonitoredItemsRequest(
            1,
            NodeId::numeric(0, 0),
            10,
            [
                ['monitoredItemId' => 200],
            ],
        );

        expect(strlen($bytes))->toBeGreaterThan(20);
    });

    it('decodes a ModifyMonitoredItemsResponse', function () {
        $session = new SessionService(1, 1);
        $service = new MonitoredItemService($session);

        $encoder = new BinaryEncoder();
        writeMonitoredMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 769));
        writeMonitoredResponseHeader($encoder);

        $encoder->writeInt32(2);
        // Result 1
        $encoder->writeUInt32(0);         // StatusCode
        $encoder->writeDouble(250.0);     // RevisedSamplingInterval
        $encoder->writeUInt32(5);         // RevisedQueueSize
        $encoder->writeNodeId(NodeId::numeric(0, 0));
        $encoder->writeByte(0x00);
        // Result 2
        $encoder->writeUInt32(0x80730000); // Bad status
        $encoder->writeDouble(1000.0);
        $encoder->writeUInt32(1);
        $encoder->writeNodeId(NodeId::numeric(0, 0));
        $encoder->writeByte(0x00);

        $encoder->writeInt32(0); // DiagnosticInfos

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $service->decodeModifyMonitoredItemsResponse($decoder);
        expect($result)->toHaveCount(2);
        expect($result[0]->statusCode)->toBe(0);
        expect($result[0]->revisedSamplingInterval)->toBe(250.0);
        expect($result[0]->revisedQueueSize)->toBe(5);
        expect($result[1]->statusCode)->toBe(0x80730000);
    });
});

describe('MonitoredItemService SetTriggering', function () {

    it('encodes a SetTriggering request with add and remove links', function () {
        $session = new SessionService(1, 1);
        $service = new MonitoredItemService($session);

        $bytes = $service->encodeSetTriggeringRequest(
            1,
            NodeId::numeric(0, 0),
            42,
            100,
            [200, 201],
            [300],
        );

        $decoder = new BinaryDecoder($bytes);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('MSG');
        expect(strlen($bytes))->toBeGreaterThan(30);
    });

    it('encodes a SetTriggering request with empty arrays', function () {
        $session = new SessionService(1, 1);
        $service = new MonitoredItemService($session);

        $bytes = $service->encodeSetTriggeringRequest(
            1,
            NodeId::numeric(0, 0),
            10,
            50,
        );

        expect(strlen($bytes))->toBeGreaterThan(20);
    });

    it('decodes a SetTriggering response with remove results and trailing diagnostics', function () {
        $session = new SessionService(1, 1);
        $service = new MonitoredItemService($session);

        $encoder = new BinaryEncoder();
        writeMonitoredMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 778));
        writeMonitoredResponseHeader($encoder);

        // Add results
        $encoder->writeInt32(2);
        $encoder->writeUInt32(0);
        $encoder->writeUInt32(0);

        // Add diagnostics
        $encoder->writeInt32(0);

        // Remove results
        $encoder->writeInt32(1);
        $encoder->writeUInt32(0);

        // Remove diagnostics
        $encoder->writeInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $service->decodeSetTriggeringResponse($decoder);
        expect($result->addResults)->toHaveCount(2);
        expect($result->removeResults)->toHaveCount(1);
    });
});
