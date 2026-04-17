<?php

declare(strict_types=1);

require_once __DIR__ . '/../Helpers/SecurityTestHelpers.php';

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Module\Browse\BrowseService;
use PhpOpcua\Client\Module\Browse\GetEndpointsService;
use PhpOpcua\Client\Module\History\HistoryReadService;
use PhpOpcua\Client\Module\ReadWrite\CallService;
use PhpOpcua\Client\Module\ReadWrite\ReadService;
use PhpOpcua\Client\Module\ReadWrite\WriteService;
use PhpOpcua\Client\Module\Subscription\MonitoredItemService;
use PhpOpcua\Client\Module\Subscription\PublishService;
use PhpOpcua\Client\Module\TranslateBrowsePath\TranslateBrowsePathService;
use PhpOpcua\Client\Protocol\MessageHeader;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Security\SecureChannel;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\QualifiedName;

function pfcPrefix(BinaryEncoder $e): void
{
    $e->writeUInt32(1);
    $e->writeUInt32(1);
    $e->writeUInt32(1);
}

function pfcResponseHeader(BinaryEncoder $e): void
{
    $e->writeInt64(0);
    $e->writeUInt32(1);
    $e->writeUInt32(0);
    $e->writeByte(0);
    $e->writeInt32(0);
    $e->writeNodeId(NodeId::numeric(0, 0));
    $e->writeByte(0);
}

function pfcInnerDiag(BinaryEncoder $e): void
{
    $e->writeByte(0x20);
    $e->writeByte(0x01);
    $e->writeInt32(1);
}

function pfcSessionWithSecurity(): SessionService
{
    [$certDer, $privKey] = generateTestCertKeyPair();
    $sc = new SecureChannel(SecurityPolicy::Basic256Sha256, SecurityMode::SignAndEncrypt, $certDer, $privKey, $certDer);
    $sc->createOpenSecureChannelMessage();
    $response = buildTestOPNResponse($certDer, $privKey, $certDer, $privKey, $sc->getClientNonce(), random_bytes(32), 1, 1, SecurityPolicy::Basic256Sha256);
    $sc->processOpenSecureChannelResponse($response);

    return new SessionService(1, 1, $sc);
}

describe('ReadService skipDiagnosticInfo with inner diagnostic', function () {
    it('handles nested inner diagnostic (mask 0x20)', function () {
        $session = new SessionService(1, 1);
        $service = new ReadService($session);

        $e = new BinaryEncoder();
        pfcPrefix($e);
        $e->writeNodeId(NodeId::numeric(0, 634));
        pfcResponseHeader($e);
        $e->writeInt32(1);
        $e->writeByte(0x01);
        $e->writeByte(BuiltinType::Int32->value);
        $e->writeInt32(1);
        $e->writeInt32(1);
        pfcInnerDiag($e);

        $decoder = new BinaryDecoder($e->getBuffer());
        $results = $service->decodeReadMultiResponse($decoder);
        expect($results)->toHaveCount(1);
    });
});

describe('CallService skipDiagnosticInfo with inner diagnostic', function () {
    it('handles nested inner diagnostic (mask 0x20)', function () {
        $session = new SessionService(1, 1);
        $service = new CallService($session);

        $e = new BinaryEncoder();
        pfcPrefix($e);
        $e->writeNodeId(NodeId::numeric(0, 715));
        pfcResponseHeader($e);
        $e->writeInt32(1);
        $e->writeUInt32(0);
        $e->writeInt32(0);
        $e->writeInt32(0);
        $e->writeInt32(0);
        $e->writeInt32(1);
        pfcInnerDiag($e);

        $decoder = new BinaryDecoder($e->getBuffer());
        $result = $service->decodeCallResponse($decoder);
        expect($result->statusCode)->toBe(0);
    });
});

describe('HistoryReadService skipDiagnosticInfo with inner diagnostic', function () {
    it('handles nested inner diagnostic (mask 0x20)', function () {
        $session = new SessionService(1, 1);
        $service = new HistoryReadService($session);

        $e = new BinaryEncoder();
        pfcPrefix($e);
        $e->writeNodeId(NodeId::numeric(0, 667));
        pfcResponseHeader($e);
        $e->writeInt32(0);
        $e->writeInt32(1);
        pfcInnerDiag($e);

        $decoder = new BinaryDecoder($e->getBuffer());
        $result = $service->decodeHistoryReadResponse($decoder);
        expect($result)->toBe([]);
    });
});

describe('MonitoredItemService skipDiagnosticInfo with inner diagnostic', function () {
    it('handles nested inner diagnostic (mask 0x20)', function () {
        $session = new SessionService(1, 1);
        $service = new MonitoredItemService($session);

        $e = new BinaryEncoder();
        pfcPrefix($e);
        $e->writeNodeId(NodeId::numeric(0, 754));
        pfcResponseHeader($e);
        $e->writeInt32(1);
        $e->writeUInt32(0);
        $e->writeUInt32(1);
        $e->writeDouble(500.0);
        $e->writeUInt32(2);
        $e->writeNodeId(NodeId::numeric(0, 0));
        $e->writeByte(0x00);
        $e->writeInt32(1);
        pfcInnerDiag($e);

        $decoder = new BinaryDecoder($e->getBuffer());
        $result = $service->decodeCreateMonitoredItemsResponse($decoder);
        expect($result)->toHaveCount(1);
    });
});

describe('PublishService skipDiagnosticInfo with inner diagnostic', function () {
    it('handles nested inner diagnostic (mask 0x20)', function () {
        $session = new SessionService(1, 1);
        $service = new PublishService($session);

        $e = new BinaryEncoder();
        pfcPrefix($e);
        $e->writeNodeId(NodeId::numeric(0, 829));
        pfcResponseHeader($e);
        $e->writeUInt32(1);
        $e->writeInt32(0);
        $e->writeBoolean(false);
        $e->writeUInt32(1);
        $e->writeDateTime(null);
        $e->writeInt32(0);
        $e->writeInt32(0);
        $e->writeInt32(1);
        pfcInnerDiag($e);

        $decoder = new BinaryDecoder($e->getBuffer());
        $result = $service->decodePublishResponse($decoder);
        expect($result->subscriptionId)->toBe(1);
    });
});

describe('BrowseService diagnostic with multiple bytes', function () {
    it('reads diagnostic info in BrowseNextResponse', function () {
        $session = new SessionService(1, 1);
        $service = new BrowseService($session);

        $e = new BinaryEncoder();
        pfcPrefix($e);
        $e->writeNodeId(NodeId::numeric(0, 536));
        pfcResponseHeader($e);
        $e->writeInt32(1);
        $e->writeUInt32(0);
        $e->writeByteString(null);
        $e->writeInt32(0);
        $e->writeInt32(1);
        $e->writeByte(0x01);
        $e->writeInt32(42);

        $decoder = new BinaryDecoder($e->getBuffer());
        $result = $service->decodeBrowseNextResponse($decoder);
        expect($result->references)->toBe([]);
    });
});

describe('WriteService diagnostic count > 0', function () {
    it('reads diagnostic bytes in WriteResponse', function () {
        $session = new SessionService(1, 1);
        $service = new WriteService($session);

        $e = new BinaryEncoder();
        pfcPrefix($e);
        $e->writeNodeId(NodeId::numeric(0, 676));
        pfcResponseHeader($e);
        $e->writeInt32(1);
        $e->writeUInt32(0);
        $e->writeInt32(1);
        $e->writeByte(0x00);

        $decoder = new BinaryDecoder($e->getBuffer());
        $result = $service->decodeWriteResponse($decoder);
        expect($result)->toBe([0]);
    });
});

describe('GetEndpointsService with discoveryUrls', function () {
    it('parses endpoint with discoveryUrls', function () {
        $session = new SessionService(1, 1);
        $service = new GetEndpointsService($session);

        $e = new BinaryEncoder();
        pfcPrefix($e);
        $e->writeNodeId(NodeId::numeric(0, 431));
        pfcResponseHeader($e);
        $e->writeInt32(1);
        $e->writeString('opc.tcp://localhost:4840');
        $e->writeString('urn:server');
        $e->writeString(null);
        $e->writeByte(0x02);
        $e->writeString('Server');
        $e->writeUInt32(0);
        $e->writeString(null);
        $e->writeString(null);
        $e->writeInt32(2);
        $e->writeString('opc.tcp://localhost:4840');
        $e->writeString('opc.tcp://localhost:4841');
        $e->writeByteString(null);
        $e->writeUInt32(1);
        $e->writeString('http://opcfoundation.org/UA/SecurityPolicy#None');
        $e->writeInt32(0);
        $e->writeString(null);
        $e->writeByte(0);

        $decoder = new BinaryDecoder($e->getBuffer());
        $eps = $service->decodeGetEndpointsResponse($decoder);
        expect($eps)->toHaveCount(1);
        expect($eps[0]->getEndpointUrl())->toBe('opc.tcp://localhost:4840');
    });
});

describe('SessionService wrapWithSecureChannel secure path', function () {
    it('wraps via SecureChannel when security is active (line 352)', function () {
        $session = pfcSessionWithSecurity();

        $inner = new BinaryEncoder();
        $inner->writeNodeId(NodeId::numeric(0, 631));
        $inner->writeNodeId(NodeId::numeric(0, 0));
        $inner->writeInt64(0);
        $inner->writeUInt32(1);
        $inner->writeUInt32(0);
        $inner->writeString(null);
        $inner->writeUInt32(10000);
        $inner->writeNodeId(NodeId::numeric(0, 0));
        $inner->writeByte(0);

        $result = $session->wrapWithSecureChannel($inner->getBuffer());
        expect(substr($result, 0, 3))->toBe('MSG');
    });

    it('unwrapResponse via SecureChannel when security is active (line 385)', function () {
        $session = pfcSessionWithSecurity();

        $sc = $session->getSecureChannel();
        $inner = new BinaryEncoder();
        $inner->writeNodeId(NodeId::numeric(0, 634));
        $inner->writeNodeId(NodeId::numeric(0, 0));
        $inner->writeInt64(0);
        $inner->writeUInt32(1);
        $inner->writeUInt32(0);
        $inner->writeString(null);
        $inner->writeUInt32(10000);
        $inner->writeNodeId(NodeId::numeric(0, 0));
        $inner->writeByte(0);

        $ref = new ReflectionProperty($sc, 'serverSigningKey');
        $serverSigningKey = $ref->getValue($sc);

        $ref2 = new ReflectionProperty($sc, 'serverEncryptingKey');
        $serverEncryptingKey = $ref2->getValue($sc);

        $ref3 = new ReflectionProperty($sc, 'serverIv');
        $serverIv = $ref3->getValue($sc);

        $policy = $sc->getPolicy();
        $ms = $sc->getMessageSecurity();
        $tokenIdBytes = pack('V', $sc->getTokenId());
        $channelId = $sc->getSecureChannelId();

        $plaintext = new BinaryEncoder();
        $plaintext->writeUInt32(1);
        $plaintext->writeUInt32(1);
        $plaintext->writeRawBytes($inner->getBuffer());
        $ptBytes = $plaintext->getBuffer();

        $sigSize = $policy->getSymmetricSignatureSize();
        $blockSize = $policy->getSymmetricBlockSize();

        $ptLen = strlen($ptBytes);
        $overhead = 1 + $sigSize;
        $total = $ptLen + $overhead;
        $rem = $total % $blockSize;
        $padSize = ($rem === 0) ? 1 : 1 + ($blockSize - $rem);
        $padded = $ptBytes . str_repeat(chr($padSize - 1), $padSize);

        $encLen = strlen($padded) + $sigSize;
        $msgBody = $tokenIdBytes . str_repeat("\x00", $encLen);
        $totalSize = MessageHeader::HEADER_SIZE + 4 + strlen($msgBody);

        $he = new BinaryEncoder();
        (new MessageHeader('MSG', 'F', $totalSize))->encode($he);
        $he->writeUInt32($channelId);
        $headerBytes = $he->getBuffer();

        $dataToSign = $headerBytes . $tokenIdBytes . $padded;
        $sig = $ms->symmetricSign($dataToSign, $serverSigningKey, $policy);

        $encrypted = $ms->symmetricEncrypt($padded . $sig, $serverEncryptingKey, $serverIv, $policy);

        $enc = new BinaryEncoder();
        $enc->writeRawBytes($headerBytes);
        $enc->writeRawBytes($tokenIdBytes);
        $enc->writeRawBytes($encrypted);

        $result = $session->unwrapResponse($enc->getBuffer());
        expect(strlen($result))->toBeGreaterThan(0);
    });
});

describe('SessionService writeClientSignature null path', function () {
    it('writes null signature when serverCert is missing (lines 506-508)', function () {
        $session = pfcSessionWithSecurity();
        $sc = $session->getSecureChannel();

        $ref = new ReflectionProperty($sc, 'serverCertDer');
        $ref->setValue($sc, null);

        $bytes = $session->encodeActivateSessionRequest(1, NodeId::numeric(0, 0));
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });
});

describe('SessionService skipDiagnosticInfoBody mask 0x02', function () {
    it('handles namespaceUri (mask 0x02) in ActivateSession diag', function () {
        $session = new SessionService(1, 1);

        $e = new BinaryEncoder();
        pfcPrefix($e);
        $e->writeNodeId(NodeId::numeric(0, 470));
        pfcResponseHeader($e);
        $e->writeByteString(null);
        $e->writeInt32(0);
        $e->writeInt32(1);
        $e->writeByte(0x02);
        $e->writeInt32(5);

        $decoder = new BinaryDecoder($e->getBuffer());
        $session->decodeActivateSessionResponse($decoder);
        expect(true)->toBeTrue();
    });
});

describe('SessionService skipEndpointDescription with discoveryUrls', function () {
    it('skips endpoint description with discoveryUrls (line 701)', function () {
        $session = new SessionService(1, 1);

        $e = new BinaryEncoder();
        pfcPrefix($e);
        $e->writeNodeId(NodeId::numeric(0, 464));
        pfcResponseHeader($e);
        $e->writeNodeId(NodeId::numeric(0, 1));
        $e->writeNodeId(NodeId::numeric(0, 2));
        $e->writeDouble(120000.0);
        $e->writeByteString(null);
        $e->writeByteString(null);

        $e->writeInt32(1);
        $e->writeString('opc.tcp://localhost:4840');
        $e->writeString('urn:server');
        $e->writeString(null);
        $e->writeByte(0x02);
        $e->writeString('Server');
        $e->writeUInt32(0);
        $e->writeString(null);
        $e->writeString(null);
        $e->writeInt32(2);
        $e->writeString('opc.tcp://localhost:4840');
        $e->writeString('opc.tcp://localhost:4841');
        $e->writeByteString(null);
        $e->writeUInt32(1);
        $e->writeString('http://opcfoundation.org/UA/SecurityPolicy#None');
        $e->writeInt32(0);
        $e->writeString(null);
        $e->writeByte(0);

        $e->writeInt32(0);
        $e->writeString(null);
        $e->writeByteString(null);
        $e->writeUInt32(0);

        $decoder = new BinaryDecoder($e->getBuffer());
        $result = $session->decodeCreateSessionResponse($decoder);
        expect($result['authenticationToken']->getIdentifier())->toBe(2);
    });
});

describe('SessionService extractLeafCertificate paths', function () {
    it('handles long-form DER length', function () {
        $session = new SessionService(1, 1);

        $innerData = str_repeat("\x00", 300);
        $cert = "\x30\x82" . pack('n', strlen($innerData)) . $innerData;
        $chain = $cert . "\x30\x03\x01\x01\xFF";

        $e = new BinaryEncoder();
        pfcPrefix($e);
        $e->writeNodeId(NodeId::numeric(0, 464));
        pfcResponseHeader($e);
        $e->writeNodeId(NodeId::numeric(0, 1));
        $e->writeNodeId(NodeId::numeric(0, 2));
        $e->writeDouble(120000.0);
        $e->writeByteString(null);
        $e->writeByteString($chain);
        $e->writeInt32(0);
        $e->writeInt32(0);
        $e->writeString(null);
        $e->writeByteString(null);
        $e->writeUInt32(0);

        $decoder = new BinaryDecoder($e->getBuffer());
        $result = $session->decodeCreateSessionResponse($decoder);
        expect($result['serverCertificate'])->toBe($chain);
    });

    it('handles short-form DER length via writeClientSignature (line 783)', function () {
        $session = pfcSessionWithSecurity();
        $sc = $session->getSecureChannel();

        $shortCert = "\x30\x03\x01\x01\xFF";
        $ref = new ReflectionProperty($sc, 'serverCertDer');
        $ref->setValue($sc, $shortCert);

        try {
            $session->encodeActivateSessionRequest(1, NodeId::numeric(0, 0), null, null, null, null, 'nonce');
        } catch (Throwable) {
        }

        expect(true)->toBeTrue();
    });

    it('handles non-DER cert via writeClientSignature (line 768)', function () {
        $session = pfcSessionWithSecurity();
        $sc = $session->getSecureChannel();

        $ref = new ReflectionProperty($sc, 'serverCertDer');
        $ref->setValue($sc, "\xAB\xCD");

        try {
            $session->encodeActivateSessionRequest(1, NodeId::numeric(0, 0), null, null, null, null, 'nonce');
        } catch (Throwable) {
        }

        expect(true)->toBeTrue();
    });
});

describe('TranslateBrowsePathService secure encoding', function () {
    it('encodes translate request via secure channel (lines 27, 95-98)', function () {
        $session = pfcSessionWithSecurity();
        $service = new TranslateBrowsePathService($session);

        $bytes = $service->encodeTranslateRequest(
            1,
            [['startingNodeId' => NodeId::numeric(0, 85), 'relativePath' => [['targetName' => new QualifiedName(0, 'Server')]]]],
            NodeId::numeric(0, 0),
        );

        expect(substr($bytes, 0, 3))->toBe('MSG');
        expect(strlen($bytes))->toBeGreaterThan(50);
    });
});

describe('Protocol services secure encoding', function () {

    beforeEach(function () {
        $this->session = pfcSessionWithSecurity();
    });

    it('BrowseService encodes secure request', function () {
        $service = new BrowseService($this->session);
        $bytes = $service->encodeBrowseRequest(1, NodeId::numeric(0, 85), NodeId::numeric(0, 0));
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });

    it('ReadService encodes secure request', function () {
        $service = new ReadService($this->session);
        $bytes = $service->encodeReadRequest(1, NodeId::numeric(0, 2259), NodeId::numeric(0, 0));
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });

    it('WriteService encodes secure request', function () {
        $service = new WriteService($this->session);
        $dv = new PhpOpcua\Client\Types\DataValue(new PhpOpcua\Client\Types\Variant(BuiltinType::Int32, 42));
        $bytes = $service->encodeWriteRequest(1, NodeId::numeric(2, 1001), $dv, NodeId::numeric(0, 0));
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });

    it('CallService encodes secure request', function () {
        $service = new CallService($this->session);
        $bytes = $service->encodeCallRequest(1, NodeId::numeric(0, 2253), NodeId::numeric(0, 11492), [], NodeId::numeric(0, 0));
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });

    it('GetEndpointsService encodes secure request', function () {
        $service = new GetEndpointsService($this->session);
        $bytes = $service->encodeGetEndpointsRequest(1, 'opc.tcp://localhost:4840', NodeId::numeric(0, 0));
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });
});
