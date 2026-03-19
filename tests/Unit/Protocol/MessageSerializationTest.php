<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Protocol\AcknowledgeMessage;
use Gianfriaur\OpcuaPhpClient\Protocol\HelloMessage;
use Gianfriaur\OpcuaPhpClient\Protocol\MessageHeader;

describe('Message serialization', function () {

    it('serializes and deserializes HelloMessage', function () {
        $hello = new HelloMessage(
            protocolVersion: 0,
            receiveBufferSize: 65535,
            sendBufferSize: 65535,
            maxMessageSize: 0,
            maxChunkCount: 0,
            endpointUrl: 'opc.tcp://localhost:4840',
        );

        $encoded = $hello->encode();
        $decoder = new BinaryDecoder($encoded);

        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('HEL');
        expect($header->getChunkType())->toBe('F');
        expect($header->getMessageSize())->toBe(strlen($encoded));

        $decoded = HelloMessage::decode($decoder);
        expect($decoded->getEndpointUrl())->toBe('opc.tcp://localhost:4840');
        expect($decoded->getReceiveBufferSize())->toBe(65535);
        expect($decoded->getSendBufferSize())->toBe(65535);
    });

    it('serializes MessageHeader correctly', function () {
        $encoder = new BinaryEncoder();
        $header = new MessageHeader('MSG', 'F', 100);
        $header->encode($encoder);

        $buffer = $encoder->getBuffer();
        expect(strlen($buffer))->toBe(8);
        expect(substr($buffer, 0, 3))->toBe('MSG');
        expect(substr($buffer, 3, 1))->toBe('F');

        $decoder = new BinaryDecoder($buffer);
        $decoded = MessageHeader::decode($decoder);
        expect($decoded->getMessageType())->toBe('MSG');
        expect($decoded->getChunkType())->toBe('F');
        expect($decoded->getMessageSize())->toBe(100);
    });

    it('decodes AcknowledgeMessage', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeUInt32(0);     // ProtocolVersion
        $encoder->writeUInt32(65535); // ReceiveBufferSize
        $encoder->writeUInt32(65535); // SendBufferSize
        $encoder->writeUInt32(0);     // MaxMessageSize
        $encoder->writeUInt32(0);     // MaxChunkCount

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $ack = AcknowledgeMessage::decode($decoder);

        expect($ack->getProtocolVersion())->toBe(0);
        expect($ack->getReceiveBufferSize())->toBe(65535);
        expect($ack->getSendBufferSize())->toBe(65535);
        expect($ack->getMaxMessageSize())->toBe(0);
        expect($ack->getMaxChunkCount())->toBe(0);
    });

    it('HelloMessage has correct binary size', function () {
        $hello = new HelloMessage(
            endpointUrl: 'opc.tcp://localhost:4840',
        );

        $encoded = $hello->encode();
        // Header (8) + 5*UInt32 (20) + String length Int32 (4) + URL bytes (24) = 56
        expect(strlen($encoded))->toBe(56);
    });
});
