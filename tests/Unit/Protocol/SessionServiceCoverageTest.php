<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Protocol\SessionService;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

function writeSessionResponsePrefix(BinaryEncoder $encoder, int $typeId): void
{
    $encoder->writeUInt32(1);
    $encoder->writeUInt32(1);
    $encoder->writeUInt32(1);
    $encoder->writeNodeId(NodeId::numeric(0, $typeId));
    $encoder->writeInt64(0);
    $encoder->writeUInt32(1);
    $encoder->writeUInt32(0);
    $encoder->writeByte(0);
    $encoder->writeInt32(0);
    $encoder->writeNodeId(NodeId::numeric(0, 0));
    $encoder->writeByte(0);
}

function writeCreateSessionResponseBody(BinaryEncoder $encoder, int $authTokenId = 2, string $cert = "\x30\x03\x01\x01\xFF"): void
{
    $encoder->writeNodeId(NodeId::numeric(0, 1));
    $encoder->writeNodeId(NodeId::numeric(0, $authTokenId));
    $encoder->writeDouble(120000.0);
    $encoder->writeByteString('server-nonce');
    $encoder->writeByteString($cert);
    $encoder->writeInt32(0);
    $encoder->writeInt32(0);
    $encoder->writeString(null);
    $encoder->writeByteString(null);
    $encoder->writeUInt32(0);
}

describe('SessionService wrapWithSecureChannel (non-secure)', function () {

    it('wraps inner body without secure channel', function () {
        $session = new SessionService(1, 1);

        $inner = new BinaryEncoder();
        $inner->writeNodeId(NodeId::numeric(0, 631));
        $inner->writeNodeId(NodeId::numeric(0, 0));
        $inner->writeInt64(0);
        $inner->writeUInt32(42);
        $inner->writeUInt32(0);
        $inner->writeString(null);
        $inner->writeUInt32(10000);
        $inner->writeNodeId(NodeId::numeric(0, 0));
        $inner->writeByte(0);

        $result = $session->wrapWithSecureChannel($inner->getBuffer());

        expect(substr($result, 0, 3))->toBe('MSG');
        $decoder = new BinaryDecoder($result);
        $header = \Gianfriaur\OpcuaPhpClient\Protocol\MessageHeader::decode($decoder);
        expect($header->getMessageSize())->toBe(strlen($result));
    });

    it('wraps with CLO message type', function () {
        $session = new SessionService(1, 1);

        $inner = new BinaryEncoder();
        $inner->writeNodeId(NodeId::numeric(0, 473));
        $inner->writeNodeId(NodeId::numeric(0, 0));
        $inner->writeInt64(0);
        $inner->writeUInt32(1);
        $inner->writeUInt32(0);
        $inner->writeString(null);
        $inner->writeUInt32(10000);
        $inner->writeNodeId(NodeId::numeric(0, 0));
        $inner->writeByte(0);

        $result = $session->wrapWithSecureChannel($inner->getBuffer(), 'CLO');

        expect(substr($result, 0, 3))->toBe('CLO');
    });
});

describe('SessionService decodeActivateSessionResponse with non-empty string table', function () {

    it('decodes response with non-empty string table', function () {
        $session = new SessionService(1, 1);

        $encoder = new BinaryEncoder();
        $encoder->writeUInt32(1);
        $encoder->writeUInt32(1);
        $encoder->writeUInt32(1);
        $encoder->writeNodeId(NodeId::numeric(0, 470));

        $encoder->writeInt64(0);
        $encoder->writeUInt32(1);
        $encoder->writeUInt32(0);
        $encoder->writeByte(0);
        $encoder->writeInt32(2);
        $encoder->writeString('string1');
        $encoder->writeString('string2');
        $encoder->writeNodeId(NodeId::numeric(0, 0));
        $encoder->writeByte(0);

        $encoder->writeByteString('nonce-data');
        $encoder->writeInt32(0);
        $encoder->writeInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $session->decodeActivateSessionResponse($decoder);
        expect(true)->toBeTrue();
    });
});

describe('SessionService extractLeafCertificate edge cases', function () {

    it('handles certificate chain with short-form length', function () {
        $session = new SessionService(1, 1);

        $encoder = new BinaryEncoder();
        writeSessionResponsePrefix($encoder, 464);
        writeCreateSessionResponseBody($encoder, 2, "\x30\x03\x01\x01\xFF");

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $session->decodeCreateSessionResponse($decoder);
        expect($result['authenticationToken']->getIdentifier())->toBe(2);
    });

    it('handles certificate shorter than 4 bytes', function () {
        $session = new SessionService(1, 1);

        $encoder = new BinaryEncoder();
        writeSessionResponsePrefix($encoder, 464);
        writeCreateSessionResponseBody($encoder, 3, "\xAB\xCD");

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $session->decodeCreateSessionResponse($decoder);
        expect($result['authenticationToken']->getIdentifier())->toBe(3);
    });
});

describe('SessionService skipDiagnosticInfoBody all mask bits', function () {

    it('handles locale mask (0x04)', function () {
        $session = new SessionService(1, 1);

        $encoder = new BinaryEncoder();
        writeSessionResponsePrefix($encoder, 470);
        $encoder->writeByteString('nonce');
        $encoder->writeInt32(0);
        $encoder->writeInt32(1);
        $encoder->writeByte(0x04);
        $encoder->writeInt32(9);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $session->decodeActivateSessionResponse($decoder);
        expect(true)->toBeTrue();
    });

    it('handles innerStatusCode mask (0x10)', function () {
        $session = new SessionService(1, 1);

        $encoder = new BinaryEncoder();
        writeSessionResponsePrefix($encoder, 470);
        $encoder->writeByteString('nonce');
        $encoder->writeInt32(0);
        $encoder->writeInt32(1);
        $encoder->writeByte(0x10);
        $encoder->writeUInt32(0x800A0000);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $session->decodeActivateSessionResponse($decoder);
        expect(true)->toBeTrue();
    });
});
