<?php

declare(strict_types=1);

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Module\History\HistoryReadService;
use PhpOpcua\Client\Protocol\MessageHeader;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\NodeId;

function writeHistoryResponseHeader(BinaryEncoder $encoder, int $statusCode = 0): void
{
    $encoder->writeInt64(0);
    $encoder->writeUInt32(1);
    $encoder->writeUInt32($statusCode);
    $encoder->writeByte(0);
    $encoder->writeInt32(0);
    $encoder->writeNodeId(NodeId::numeric(0, 0));
    $encoder->writeByte(0);
}

function writeHistoryMessagePrefix(BinaryEncoder $encoder): void
{
    $encoder->writeUInt32(1);
    $encoder->writeUInt32(1);
    $encoder->writeUInt32(1);
}

describe('HistoryReadService encoding', function () {

    it('encodes a HistoryReadRaw request', function () {
        $session = new SessionService(1, 1);
        $service = new HistoryReadService($session);

        $bytes = $service->encodeHistoryReadRawRequest(
            1,
            NodeId::numeric(0, 0),
            NodeId::numeric(1, 100),
            new DateTimeImmutable('2024-01-01'),
            new DateTimeImmutable('2024-01-02'),
            100,
            true,
        );

        $decoder = new BinaryDecoder($bytes);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('MSG');
        expect(strlen($bytes))->toBeGreaterThan(50);
    });

    it('encodes a HistoryReadProcessed request', function () {
        $session = new SessionService(1, 1);
        $service = new HistoryReadService($session);

        $bytes = $service->encodeHistoryReadProcessedRequest(
            1,
            NodeId::numeric(0, 0),
            NodeId::numeric(1, 100),
            new DateTimeImmutable('2024-01-01'),
            new DateTimeImmutable('2024-01-02'),
            500.0,
            NodeId::numeric(0, 2341),
        );

        $decoder = new BinaryDecoder($bytes);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('MSG');
    });

    it('encodes a HistoryReadAtTime request', function () {
        $session = new SessionService(1, 1);
        $service = new HistoryReadService($session);

        $bytes = $service->encodeHistoryReadAtTimeRequest(
            1,
            NodeId::numeric(0, 0),
            NodeId::numeric(1, 100),
            [new DateTimeImmutable('2024-01-01 12:00:00'), new DateTimeImmutable('2024-01-01 13:00:00')],
        );

        $decoder = new BinaryDecoder($bytes);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('MSG');
    });
});

describe('HistoryReadService decoding', function () {

    it('decodes a HistoryReadResponse with HistoryData values', function () {
        $session = new SessionService(1, 1);
        $service = new HistoryReadService($session);

        $encoder = new BinaryEncoder();
        writeHistoryMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 667)); // HistoryReadResponse
        writeHistoryResponseHeader($encoder);

        // Results: 1 HistoryReadResult
        $encoder->writeInt32(1);
        $encoder->writeUInt32(0); // StatusCode
        $encoder->writeByteString(null); // ContinuationPoint

        // HistoryData ExtensionObject (TypeId 658)
        $encoder->writeNodeId(NodeId::numeric(0, 658));
        $encoder->writeByte(0x01); // Has body

        // Build the body separately to know its length
        $bodyEncoder = new BinaryEncoder();
        // DataValues array: 2 values
        $bodyEncoder->writeInt32(2);
        // DataValue 1: value only
        $bodyEncoder->writeByte(0x01);
        $bodyEncoder->writeByte(BuiltinType::Double->value);
        $bodyEncoder->writeDouble(23.5);
        // DataValue 2: value only
        $bodyEncoder->writeByte(0x01);
        $bodyEncoder->writeByte(BuiltinType::Double->value);
        $bodyEncoder->writeDouble(24.1);

        $body = $bodyEncoder->getBuffer();
        $encoder->writeInt32(strlen($body));
        $encoder->writeRawBytes($body);

        // DiagnosticInfos: empty
        $encoder->writeInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $service->decodeHistoryReadResponse($decoder);
        expect($result)->toHaveCount(2);
        expect($result[0]->getValue())->toBe(23.5);
        expect($result[1]->getValue())->toBe(24.1);
    });

    it('decodes a HistoryReadResponse with unknown history type (skips body)', function () {
        $session = new SessionService(1, 1);
        $service = new HistoryReadService($session);

        $encoder = new BinaryEncoder();
        writeHistoryMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 667));
        writeHistoryResponseHeader($encoder);

        $encoder->writeInt32(1);
        $encoder->writeUInt32(0);
        $encoder->writeByteString(null);

        // Unknown history type (TypeId 9999)
        $encoder->writeNodeId(NodeId::numeric(0, 9999));
        $encoder->writeByte(0x01);
        $fakeBody = str_repeat("\x00", 20);
        $encoder->writeInt32(strlen($fakeBody));
        $encoder->writeRawBytes($fakeBody);

        $encoder->writeInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $service->decodeHistoryReadResponse($decoder);
        expect($result)->toBe([]);
    });

    it('decodes a HistoryReadResponse with no body encoding', function () {
        $session = new SessionService(1, 1);
        $service = new HistoryReadService($session);

        $encoder = new BinaryEncoder();
        writeHistoryMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 667));
        writeHistoryResponseHeader($encoder);

        // 1 result with no-body extension object
        $encoder->writeInt32(1);
        $encoder->writeUInt32(0);
        $encoder->writeByteString(null);
        $encoder->writeNodeId(NodeId::numeric(0, 0));
        $encoder->writeByte(0x00); // No body

        $encoder->writeInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $service->decodeHistoryReadResponse($decoder);
        expect($result)->toBe([]);
    });

    it('decodes an empty HistoryReadResponse', function () {
        $session = new SessionService(1, 1);
        $service = new HistoryReadService($session);

        $encoder = new BinaryEncoder();
        writeHistoryMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 667));
        writeHistoryResponseHeader($encoder);
        $encoder->writeInt32(0); // 0 results
        $encoder->writeInt32(0); // 0 diagnostics

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $service->decodeHistoryReadResponse($decoder);
        expect($result)->toBe([]);
    });
});
