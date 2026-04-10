<?php

declare(strict_types=1);

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Protocol\MessageHeader;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Security\CertificateManager;
use PhpOpcua\Client\Security\MessageSecurity;
use PhpOpcua\Client\Security\SecureChannel;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Types\NodeId;

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
        $header = MessageHeader::decode($decoder);
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

describe('SessionService decodeCreateSessionResponse ECC ephemeral key', function () {

    it('reads eccServerEphemeralKey when remaining bytes exist (non-debug)', function () {
        $session = new SessionService(1, 1);

        $encoder = new BinaryEncoder();
        writeSessionResponsePrefix($encoder, 464);
        writeCreateSessionResponseBody($encoder);
        $encoder->writeByteString('fake-ecc-key-data');

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $session->decodeCreateSessionResponse($decoder);
        expect($result['eccServerEphemeralKey'])->toBe('fake-ecc-key-data');
    });

    it('returns null eccServerEphemeralKey when no remaining bytes', function () {
        $session = new SessionService(1, 1);

        $encoder = new BinaryEncoder();
        writeSessionResponsePrefix($encoder, 464);
        writeCreateSessionResponseBody($encoder);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $session->decodeCreateSessionResponse($decoder);
        expect($result['eccServerEphemeralKey'])->toBeNull();
    });

    it('catches exception when eccServerEphemeralKey read fails', function () {
        $session = new SessionService(1, 1);

        $encoder = new BinaryEncoder();
        writeSessionResponsePrefix($encoder, 464);
        writeCreateSessionResponseBody($encoder);
        $encoder->writeByte(0xFF);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $session->decodeCreateSessionResponse($decoder);
        expect($result)->toHaveKey('eccServerEphemeralKey');
    });
});

describe('SessionService readResponseHeader with AdditionalHeader', function () {

    it('reads additionalHeader body when encoding is 0x01 with string param', function () {
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
        $encoder->writeInt32(0);

        $encoder->writeNodeId(NodeId::numeric(0, 17537));
        $encoder->writeByte(0x01);

        $additionalBody = new BinaryEncoder();
        $additionalBody->writeInt32(1);
        $additionalBody->writeUInt16(0);
        $additionalBody->writeString('SomeKey');
        $additionalBody->writeByte(12);
        $additionalBody->writeString('SomeValue');
        $additionalBodyBytes = $additionalBody->getBuffer();

        $encoder->writeInt32(strlen($additionalBodyBytes));
        $encoder->writeRawBytes($additionalBodyBytes);

        $encoder->writeByteString('nonce');
        $encoder->writeInt32(0);
        $encoder->writeInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $session->decodeActivateSessionResponse($decoder);
        expect(true)->toBeTrue();
    });

    it('parses ECDHKey from AdditionalParameters', function () {
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
        $encoder->writeInt32(0);

        $encoder->writeNodeId(NodeId::numeric(0, 17537));
        $encoder->writeByte(0x01);

        $additionalBody = new BinaryEncoder();
        $additionalBody->writeInt32(1);
        $additionalBody->writeUInt16(0);
        $additionalBody->writeString('ECDHKey');
        $additionalBody->writeByte(22);
        $additionalBody->writeNodeId(NodeId::numeric(0, 17546));
        $additionalBody->writeByte(0x01);
        $extBody = new BinaryEncoder();
        $extBody->writeByteString('fake-public-key');
        $extBody->writeByteString('fake-signature');
        $extBodyBytes = $extBody->getBuffer();
        $additionalBody->writeInt32(strlen($extBodyBytes));
        $additionalBody->writeRawBytes($extBodyBytes);

        $additionalBodyBytes = $additionalBody->getBuffer();
        $encoder->writeInt32(strlen($additionalBodyBytes));
        $encoder->writeRawBytes($additionalBodyBytes);

        $encoder->writeByteString('nonce');
        $encoder->writeInt32(0);
        $encoder->writeInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $session->decodeActivateSessionResponse($decoder);
        expect($session->getLastEccServerEphemeralKey())->toBe('fake-public-key');
    });
});

describe('SessionService skipVariantValue coverage', function () {

    it('skips various variant types in AdditionalParameters', function () {
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
        $encoder->writeInt32(0);

        $encoder->writeNodeId(NodeId::numeric(0, 17537));
        $encoder->writeByte(0x01);

        $additionalBody = new BinaryEncoder();
        $additionalBody->writeInt32(10);

        // Boolean(1), SByte(2), UInt16(4), UInt32(6), Int64(8), Float(10), Double(11), DateTime(13), Guid(14), ByteString(15)
        foreach ([1, 2, 4, 6, 8, 10, 11, 13, 14, 15] as $idx => $type) {
            $additionalBody->writeUInt16(0);
            $additionalBody->writeString("p{$type}");
            $additionalBody->writeByte($type);
            match ($type) {
                1, 2, 3 => $additionalBody->writeByte(1),
                4, 5 => $additionalBody->writeRawBytes(pack('v', 123)),
                6, 7 => $additionalBody->writeUInt32(456),
                8, 9 => $additionalBody->writeRawBytes(str_repeat("\x00", 8)),
                10 => $additionalBody->writeRawBytes(pack('g', 1.0)),
                11 => $additionalBody->writeRawBytes(pack('e', 2.0)),
                13 => $additionalBody->writeRawBytes(str_repeat("\x00", 8)),
                14 => $additionalBody->writeRawBytes(str_repeat("\x00", 16)),
                15, 16 => $additionalBody->writeByteString('bytes'),
            };
        }

        $additionalBodyBytes = $additionalBody->getBuffer();
        $encoder->writeInt32(strlen($additionalBodyBytes));
        $encoder->writeRawBytes($additionalBodyBytes);

        $encoder->writeByteString('nonce');
        $encoder->writeInt32(0);
        $encoder->writeInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $session->decodeActivateSessionResponse($decoder);
        expect(true)->toBeTrue();
    });

    it('skips non-ECDHKey ExtensionObject variant (22)', function () {
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
        $encoder->writeInt32(0);

        $encoder->writeNodeId(NodeId::numeric(0, 17537));
        $encoder->writeByte(0x01);

        $additionalBody = new BinaryEncoder();
        $additionalBody->writeInt32(1);
        $additionalBody->writeUInt16(0);
        $additionalBody->writeString('OtherExtObj');
        $additionalBody->writeByte(22);
        $additionalBody->writeNodeId(NodeId::numeric(0, 999));
        $additionalBody->writeByte(0x01);
        $extBody = new BinaryEncoder();
        $extBody->writeString('some-data');
        $extBodyBytes = $extBody->getBuffer();
        $additionalBody->writeInt32(strlen($extBodyBytes));
        $additionalBody->writeRawBytes($extBodyBytes);

        $additionalBodyBytes = $additionalBody->getBuffer();
        $encoder->writeInt32(strlen($additionalBodyBytes));
        $encoder->writeRawBytes($additionalBodyBytes);

        $encoder->writeByteString('nonce');
        $encoder->writeInt32(0);
        $encoder->writeInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $session->decodeActivateSessionResponse($decoder);
        expect(true)->toBeTrue();
    });
});

describe('SessionService ECC secure paths', function () {

    beforeEach(function () {
        $cm = new CertificateManager();
        $ms = new MessageSecurity();

        $clientResult = $cm->generateSelfSignedCertificate('urn:ecc-client', 'prime256v1');
        $this->clientDer = $clientResult['certDer'];
        $this->clientKey = $clientResult['privateKey'];

        $serverResult = $cm->generateSelfSignedCertificate('urn:ecc-server', 'prime256v1');
        $this->serverDer = $serverResult['certDer'];
        $this->serverKey = $serverResult['privateKey'];

        $this->policy = SecurityPolicy::EccNistP256;
        $this->ms = $ms;
        $this->cm = $cm;
    });

    it('encodeCreateSessionRequest with ECC policy includes EcdhAdditionalHeader', function () {
        $channel = setupEccChannelHelper($this->policy, SecurityMode::Sign, $this->clientDer, $this->clientKey, $this->serverDer, $this->serverKey, $this->ms, $this->cm);
        $session = new SessionService(100, 200, $channel);
        $msg = $session->encodeCreateSessionRequest(1, 'opc.tcp://localhost:4840');
        expect(strlen($msg))->toBeGreaterThan(50);
    });

    it('encodeActivateSessionRequest with ECC policy includes ECDSA client signature', function () {
        $channel = setupEccChannelHelper($this->policy, SecurityMode::Sign, $this->clientDer, $this->clientKey, $this->serverDer, $this->serverKey, $this->ms, $this->cm);
        $serverEphemeral = $this->ms->generateEphemeralKeyPair('prime256v1');
        $serverNonceBytes = substr($serverEphemeral['publicKeyBytes'], 1);

        $session = new SessionService(100, 200, $channel);
        $msg = $session->encodeActivateSessionRequest(
            1,
            NodeId::numeric(0, 2),
            null,
            null,
            null,
            null,
            $serverNonceBytes,
            $serverNonceBytes,
        );
        expect(strlen($msg))->toBeGreaterThan(50);
    });

    it('encodeActivateSessionRequest with ECC and username builds ECC encrypted secret', function () {
        $channel = setupEccChannelHelper($this->policy, SecurityMode::SignAndEncrypt, $this->clientDer, $this->clientKey, $this->serverDer, $this->serverKey, $this->ms, $this->cm);
        $serverEphemeral = $this->ms->generateEphemeralKeyPair('prime256v1');
        $serverNonceBytes = substr($serverEphemeral['publicKeyBytes'], 1);

        $session = new SessionService(100, 200, $channel);
        $msg = $session->encodeActivateSessionRequest(
            1,
            NodeId::numeric(0, 2),
            'admin',
            'password123',
            null,
            null,
            $serverNonceBytes,
            $serverNonceBytes,
        );
        expect(strlen($msg))->toBeGreaterThan(100);
    });
});

function setupEccChannelHelper(
    SecurityPolicy $policy,
    SecurityMode $mode,
    string $clientDer,
    OpenSSLAsymmetricKey $clientKey,
    string $serverDer,
    OpenSSLAsymmetricKey $serverKey,
    MessageSecurity $ms,
    CertificateManager $cm,
): SecureChannel {
    $channel = new SecureChannel($policy, $mode, $clientDer, $clientKey, $serverDer);
    $channel->createOpenSecureChannelMessage();

    $serverEphemeral = $ms->generateEphemeralKeyPair('prime256v1');
    $serverNonceBytes = substr($serverEphemeral['publicKeyBytes'], 1);

    $inner = new BinaryEncoder();
    $inner->writeUInt32(1);
    $inner->writeUInt32(1);
    $inner->writeNodeId(NodeId::numeric(0, 449));
    $inner->writeInt64(0);
    $inner->writeUInt32(1);
    $inner->writeUInt32(0);
    $inner->writeByte(0);
    $inner->writeInt32(0);
    $inner->writeNodeId(NodeId::numeric(0, 0));
    $inner->writeByte(0);
    $inner->writeUInt32(0);
    $inner->writeUInt32(100);
    $inner->writeUInt32(200);
    $inner->writeInt64(0);
    $inner->writeUInt32(3600000);
    $inner->writeByteString($serverNonceBytes);

    $secHeader = new BinaryEncoder();
    $secHeader->writeString($policy->value);
    $secHeader->writeByteString($serverDer);
    $secHeader->writeByteString($cm->getThumbprint($clientDer));
    $secHeaderBytes = $secHeader->getBuffer();

    $coordinateSize = 32;
    $signatureSize = $coordinateSize * 2;
    $totalSize = 12 + strlen($secHeaderBytes) + strlen($inner->getBuffer()) + $signatureSize;

    $headerEncoder = new BinaryEncoder();
    $msgHeader = new MessageHeader('OPN', 'F', $totalSize);
    $msgHeader->encode($headerEncoder);
    $headerEncoder->writeUInt32(100);
    $headerBytes = $headerEncoder->getBuffer();

    $dataToSign = $headerBytes . $secHeaderBytes . $inner->getBuffer();
    $derSig = $ms->asymmetricSign($dataToSign, $serverKey, $policy);
    $rawSig = $ms->ecdsaDerToRaw($derSig, $coordinateSize);
    $response = $headerBytes . $secHeaderBytes . $inner->getBuffer() . $rawSig;

    $channel->processOpenSecureChannelResponse($response);

    return $channel;
}

describe('SessionService OPCUA_ECC_DEBUG branch', function () {

    it('reads eccServerEphemeralKey with OPCUA_ECC_DEBUG env', function () {
        putenv('OPCUA_ECC_DEBUG=1');

        try {
            $session = new SessionService(1, 1);

            $encoder = new BinaryEncoder();
            writeSessionResponsePrefix($encoder, 464);
            writeCreateSessionResponseBody($encoder);
            $encoder->writeByteString('ecc-debug-key');

            $decoder = new BinaryDecoder($encoder->getBuffer());
            $result = $session->decodeCreateSessionResponse($decoder);
            expect($result['eccServerEphemeralKey'])->toBe('ecc-debug-key');
        } finally {
            putenv('OPCUA_ECC_DEBUG');
        }
    });

    it('handles failed readByteString in ECC_DEBUG branch', function () {
        putenv('OPCUA_ECC_DEBUG=1');

        try {
            $session = new SessionService(1, 1);

            $encoder = new BinaryEncoder();
            writeSessionResponsePrefix($encoder, 464);
            writeCreateSessionResponseBody($encoder);
            // Append truncated data that will fail readByteString
            $encoder->writeByte(0xFF);

            $decoder = new BinaryDecoder($encoder->getBuffer());
            $result = $session->decodeCreateSessionResponse($decoder);
            expect($result)->toHaveKey('eccServerEphemeralKey');
        } finally {
            putenv('OPCUA_ECC_DEBUG');
        }
    });

    it('readResponseHeader logs ECC debug for AdditionalHeader', function () {
        putenv('OPCUA_ECC_DEBUG=1');

        try {
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
            $encoder->writeInt32(0);

            $encoder->writeNodeId(NodeId::numeric(0, 17537));
            $encoder->writeByte(0x01);

            $additionalBody = new BinaryEncoder();
            $additionalBody->writeInt32(1);
            $additionalBody->writeUInt16(0);
            $additionalBody->writeString('TestKey');
            $additionalBody->writeByte(12);
            $additionalBody->writeString('TestVal');
            $additionalBodyBytes = $additionalBody->getBuffer();
            $encoder->writeInt32(strlen($additionalBodyBytes));
            $encoder->writeRawBytes($additionalBodyBytes);

            $encoder->writeByteString('nonce');
            $encoder->writeInt32(0);
            $encoder->writeInt32(0);

            $decoder = new BinaryDecoder($encoder->getBuffer());
            $session->decodeActivateSessionResponse($decoder);
            expect(true)->toBeTrue();
        } finally {
            putenv('OPCUA_ECC_DEBUG');
        }
    });
});

describe('SessionService skipVariantValue default branch', function () {

    it('skips unknown variant type gracefully', function () {
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
        $encoder->writeInt32(0);

        $encoder->writeNodeId(NodeId::numeric(0, 17537));
        $encoder->writeByte(0x01);

        $additionalBody = new BinaryEncoder();
        $additionalBody->writeInt32(1);
        $additionalBody->writeUInt16(0);
        $additionalBody->writeString('UnknownType');
        $additionalBody->writeByte(0); // type 0 → default => null

        $additionalBodyBytes = $additionalBody->getBuffer();
        $encoder->writeInt32(strlen($additionalBodyBytes));
        $encoder->writeRawBytes($additionalBodyBytes);

        $encoder->writeByteString('nonce');
        $encoder->writeInt32(0);
        $encoder->writeInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $session->decodeActivateSessionResponse($decoder);
        expect(true)->toBeTrue();
    });
});

describe('SessionService ECC short password padding', function () {

    it('buildEccEncryptedSecret adds extra block for short password', function () {
        $cm = new CertificateManager();
        $ms = new MessageSecurity();

        $clientResult = $cm->generateSelfSignedCertificate('urn:ecc-client', 'prime256v1');
        $serverResult = $cm->generateSelfSignedCertificate('urn:ecc-server', 'prime256v1');

        $channel = setupEccChannelHelper(
            SecurityPolicy::EccNistP256,
            SecurityMode::SignAndEncrypt,
            $clientResult['certDer'],
            $clientResult['privateKey'],
            $serverResult['certDer'],
            $serverResult['privateKey'],
            $ms,
            $cm,
        );

        $serverEphemeral = $ms->generateEphemeralKeyPair('prime256v1');
        $serverNonceBytes = substr($serverEphemeral['publicKeyBytes'], 1);

        $session = new SessionService(100, 200, $channel);
        // Very short password to trigger paddingSize += blockSize
        $msg = $session->encodeActivateSessionRequest(
            1,
            NodeId::numeric(0, 2),
            'u',
            'p',
            null,
            null,
            $serverNonceBytes,
            $serverNonceBytes,
        );
        expect(strlen($msg))->toBeGreaterThan(100);
    });
});
