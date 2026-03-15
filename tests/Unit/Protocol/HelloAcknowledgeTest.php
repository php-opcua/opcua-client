<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Protocol\AcknowledgeMessage;
use Gianfriaur\OpcuaPhpClient\Protocol\HelloMessage;
use Gianfriaur\OpcuaPhpClient\Protocol\MessageHeader;

describe('HelloMessage', function () {

    it('encodes and decodes a hello message', function () {
        $hello = new HelloMessage(
            protocolVersion: 0,
            receiveBufferSize: 65535,
            sendBufferSize: 65535,
            maxMessageSize: 0,
            maxChunkCount: 0,
            endpointUrl: 'opc.tcp://localhost:4840',
        );

        $bytes = $hello->encode();

        // Should start with HEL header
        expect(substr($bytes, 0, 3))->toBe('HEL');
        expect($bytes[3])->toBe('F');

        // Decode header + body
        $decoder = new BinaryDecoder($bytes);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('HEL');
        expect($header->getMessageSize())->toBe(strlen($bytes));

        $decoded = HelloMessage::decode($decoder);
        expect($decoded->getEndpointUrl())->toBe('opc.tcp://localhost:4840');
        expect($decoded->getReceiveBufferSize())->toBe(65535);
        expect($decoded->getSendBufferSize())->toBe(65535);
    });

    it('encodes with default values', function () {
        $hello = new HelloMessage();
        $bytes = $hello->encode();
        expect(strlen($bytes))->toBeGreaterThan(MessageHeader::HEADER_SIZE);

        $decoder = new BinaryDecoder($bytes);
        MessageHeader::decode($decoder);
        $decoded = HelloMessage::decode($decoder);
        expect($decoded->getEndpointUrl())->toBe('');
    });
});

describe('AcknowledgeMessage', function () {

    it('decodes an acknowledge message', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeUInt32(0);     // protocolVersion
        $encoder->writeUInt32(65535); // receiveBufferSize
        $encoder->writeUInt32(65535); // sendBufferSize
        $encoder->writeUInt32(0);     // maxMessageSize
        $encoder->writeUInt32(0);     // maxChunkCount

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $ack = AcknowledgeMessage::decode($decoder);

        expect($ack->getProtocolVersion())->toBe(0);
        expect($ack->getReceiveBufferSize())->toBe(65535);
        expect($ack->getSendBufferSize())->toBe(65535);
        expect($ack->getMaxMessageSize())->toBe(0);
        expect($ack->getMaxChunkCount())->toBe(0);
    });
});

describe('MessageHeader', function () {

    it('round-trips a message header', function () {
        $encoder = new BinaryEncoder();
        $header = new MessageHeader('MSG', 'F', 100);
        $header->encode($encoder);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $decoded = MessageHeader::decode($decoder);
        expect($decoded->getMessageType())->toBe('MSG');
        expect($decoded->getChunkType())->toBe('F');
        expect($decoded->getMessageSize())->toBe(100);
    });

    it('HEADER_SIZE is 8', function () {
        expect(MessageHeader::HEADER_SIZE)->toBe(8);
    });
});
