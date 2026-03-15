<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Exception\ProtocolException;
use Gianfriaur\OpcuaPhpClient\Exception\SecurityException;
use Gianfriaur\OpcuaPhpClient\Protocol\MessageHeader;
use Gianfriaur\OpcuaPhpClient\Security\CertificateManager;
use Gianfriaur\OpcuaPhpClient\Security\MessageSecurity;
use Gianfriaur\OpcuaPhpClient\Security\SecureChannel;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

/**
 * Generates a test cert and key pair for SecureChannel tests.
 */
function generateSecureChannelTestCert(int $bits = 2048): array
{
    $privKey = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['CN' => 'test'], $privKey);
    $cert = openssl_csr_sign($csr, null, $privKey, 365);
    openssl_x509_export($cert, $certPem);

    $cm = new CertificateManager();
    $tmpFile = tempnam(sys_get_temp_dir(), 'opcua_sc_cert_');
    file_put_contents($tmpFile, $certPem);
    $derCert = $cm->loadCertificatePem($tmpFile);
    unlink($tmpFile);

    return [$derCert, $privKey];
}

describe('SecureChannel getters and basic state', function () {

    it('reports correct policy and mode', function () {
        $sc = new SecureChannel(SecurityPolicy::None, SecurityMode::None);
        expect($sc->getPolicy())->toBe(SecurityPolicy::None);
        expect($sc->getMode())->toBe(SecurityMode::None);
    });

    it('isSecurityActive returns false for None/None', function () {
        $sc = new SecureChannel(SecurityPolicy::None, SecurityMode::None);
        expect($sc->isSecurityActive())->toBeFalse();
    });

    it('isSecurityActive returns false for policy None with Sign mode', function () {
        $sc = new SecureChannel(SecurityPolicy::None, SecurityMode::Sign);
        expect($sc->isSecurityActive())->toBeFalse();
    });

    it('isSecurityActive returns false for Basic256Sha256 with None mode', function () {
        $sc = new SecureChannel(SecurityPolicy::Basic256Sha256, SecurityMode::None);
        expect($sc->isSecurityActive())->toBeFalse();
    });

    it('isSecurityActive returns true for Basic256Sha256 with Sign mode', function () {
        [$clientDer, $clientKey] = generateSecureChannelTestCert();
        [$serverDer, ] = generateSecureChannelTestCert();
        $sc = new SecureChannel(SecurityPolicy::Basic256Sha256, SecurityMode::Sign, $clientDer, $clientKey, $serverDer);
        expect($sc->isSecurityActive())->toBeTrue();
    });

    it('returns initial channel and token IDs as 0', function () {
        $sc = new SecureChannel(SecurityPolicy::None, SecurityMode::None);
        expect($sc->getSecureChannelId())->toBe(0);
        expect($sc->getTokenId())->toBe(0);
    });

    it('sequence numbers increment', function () {
        $sc = new SecureChannel(SecurityPolicy::None, SecurityMode::None);
        expect($sc->getNextSequenceNumber())->toBe(1);
        expect($sc->getNextSequenceNumber())->toBe(2);
        expect($sc->getNextSequenceNumber())->toBe(3);
    });

    it('client nonce is initially empty', function () {
        $sc = new SecureChannel(SecurityPolicy::None, SecurityMode::None);
        expect($sc->getClientNonce())->toBe('');
    });

    it('server nonce is initially null', function () {
        $sc = new SecureChannel(SecurityPolicy::None, SecurityMode::None);
        expect($sc->getServerNonce())->toBeNull();
    });

    it('stores and returns client cert and key', function () {
        [$clientDer, $clientKey] = generateSecureChannelTestCert();
        $sc = new SecureChannel(SecurityPolicy::None, SecurityMode::None, $clientDer, $clientKey);
        expect($sc->getClientCertDer())->toBe($clientDer);
        expect($sc->getClientPrivateKey())->toBe($clientKey);
    });

    it('stores and returns server cert', function () {
        [$serverDer, ] = generateSecureChannelTestCert();
        $sc = new SecureChannel(SecurityPolicy::None, SecurityMode::None, null, null, $serverDer);
        expect($sc->getServerCertDer())->toBe($serverDer);
    });

    it('setServerCertDer updates server cert', function () {
        $sc = new SecureChannel(SecurityPolicy::None, SecurityMode::None);
        [$serverDer, ] = generateSecureChannelTestCert();
        $sc->setServerCertDer($serverDer);
        expect($sc->getServerCertDer())->toBe($serverDer);
    });

    it('returns MessageSecurity and CertificateManager instances', function () {
        $sc = new SecureChannel(SecurityPolicy::None, SecurityMode::None);
        expect($sc->getMessageSecurity())->toBeInstanceOf(MessageSecurity::class);
        expect($sc->getCertificateManager())->toBeInstanceOf(CertificateManager::class);
    });
});

describe('SecureChannel OPN (no security)', function () {

    it('creates an OPN message without security', function () {
        $sc = new SecureChannel(SecurityPolicy::None, SecurityMode::None);
        $message = $sc->createOpenSecureChannelMessage();

        $decoder = new BinaryDecoder($message);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('OPN');
        expect($header->getChunkType())->toBe('F');
        expect($header->getMessageSize())->toBe(strlen($message));
    });

    it('processes an OPN response without security', function () {
        $sc = new SecureChannel(SecurityPolicy::None, SecurityMode::None);

        $encoder = new BinaryEncoder();
        // MessageHeader: OPN F
        $header = new MessageHeader('OPN', 'F', 0); // size will be patched
        $header->encode($encoder);
        $encoder->writeUInt32(123); // SecureChannelId

        // Asymmetric security header
        $encoder->writeString(SecurityPolicy::None->value);
        $encoder->writeByteString(null);
        $encoder->writeByteString(null);

        // Sequence header
        $encoder->writeUInt32(1);
        $encoder->writeUInt32(1);

        // TypeId: OpenSecureChannelResponse (449)
        $encoder->writeNodeId(NodeId::numeric(0, 449));

        // ResponseHeader
        $encoder->writeInt64(0);    // Timestamp
        $encoder->writeUInt32(1);   // RequestHandle
        $encoder->writeUInt32(0);   // StatusCode
        $encoder->writeByte(0);     // DiagnosticInfo
        $encoder->writeInt32(0);    // StringTable
        $encoder->writeNodeId(NodeId::numeric(0, 0));
        $encoder->writeByte(0);

        // OpenSecureChannelResponse fields
        $encoder->writeUInt32(0);       // ServerProtocolVersion
        $encoder->writeUInt32(555);     // SecureChannelId
        $encoder->writeUInt32(777);     // TokenId
        $encoder->writeInt64(0);        // CreatedAt
        $encoder->writeUInt32(3600000); // RevisedLifetime
        $encoder->writeByteString(null); // ServerNonce

        $response = $encoder->getBuffer();
        // Patch the message size
        $response = substr($response, 0, 4) . pack('V', strlen($response)) . substr($response, 8);

        $result = $sc->processOpenSecureChannelResponse($response);
        expect($result['secureChannelId'])->toBe(555);
        expect($result['tokenId'])->toBe(777);
        expect($result['revisedLifetime'])->toBe(3600000);
        expect($result['serverNonce'])->toBeNull();
        expect($sc->getSecureChannelId())->toBe(555);
        expect($sc->getTokenId())->toBe(777);
    });

    it('throws ProtocolException for non-OPN response', function () {
        $sc = new SecureChannel(SecurityPolicy::None, SecurityMode::None);

        $encoder = new BinaryEncoder();
        $header = new MessageHeader('MSG', 'F', 12);
        $header->encode($encoder);
        $encoder->writeUInt32(0);

        expect(fn() => $sc->processOpenSecureChannelResponse($encoder->getBuffer()))
            ->toThrow(ProtocolException::class, 'Expected OPN response');
    });
});

describe('SecureChannel MSG (no security)', function () {

    it('builds a plain MSG message', function () {
        $sc = new SecureChannel(SecurityPolicy::None, SecurityMode::None);

        // Build inner body: TypeId + RequestHeader (minimal)
        $inner = new BinaryEncoder();
        $inner->writeNodeId(NodeId::numeric(0, 631)); // ReadRequest
        $inner->writeNodeId(NodeId::numeric(0, 0));    // AuthToken
        $inner->writeInt64(0);                          // Timestamp
        $inner->writeUInt32(42);                        // RequestHandle
        $inner->writeUInt32(0);                          // ReturnDiagnostics
        $inner->writeString(null);                      // AuditEntryId
        $inner->writeUInt32(10000);                     // TimeoutHint
        $inner->writeNodeId(NodeId::numeric(0, 0));
        $inner->writeByte(0);

        $message = $sc->buildMessage($inner->getBuffer());

        $decoder = new BinaryDecoder($message);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('MSG');
        expect($header->getMessageSize())->toBe(strlen($message));
    });

    it('processMessage returns body after header for plain message', function () {
        $sc = new SecureChannel(SecurityPolicy::None, SecurityMode::None);

        // Build inner body
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

        $message = $sc->buildMessage($inner->getBuffer());
        $processed = $sc->processMessage($message);

        // Should contain tokenId + sequenceNumber + requestId + innerBody
        $decoder = new BinaryDecoder($processed);
        $tokenId = $decoder->readUInt32();
        expect($tokenId)->toBe(0); // default tokenId
    });

    it('builds a CLO message type', function () {
        $sc = new SecureChannel(SecurityPolicy::None, SecurityMode::None);

        $inner = new BinaryEncoder();
        $inner->writeNodeId(NodeId::numeric(0, 473)); // CloseSecureChannelRequest
        $inner->writeNodeId(NodeId::numeric(0, 0));
        $inner->writeInt64(0);
        $inner->writeUInt32(1);
        $inner->writeUInt32(0);
        $inner->writeString(null);
        $inner->writeUInt32(10000);
        $inner->writeNodeId(NodeId::numeric(0, 0));
        $inner->writeByte(0);

        $message = $sc->buildMessage($inner->getBuffer(), 'CLO');

        $decoder = new BinaryDecoder($message);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('CLO');
    });

    it('processMessage handles ERR messages in secure mode', function () {
        [$clientDer, $clientKey] = generateSecureChannelTestCert();
        [$serverDer, ] = generateSecureChannelTestCert();
        $sc = new SecureChannel(SecurityPolicy::Basic256Sha256, SecurityMode::SignAndEncrypt, $clientDer, $clientKey, $serverDer);

        // Build an ERR message (never encrypted)
        $encoder = new BinaryEncoder();
        $header = new MessageHeader('ERR', 'F', 20);
        $header->encode($encoder);
        $encoder->writeUInt32(0);  // channel id
        $encoder->writeUInt32(0x80010000); // status code

        $raw = $encoder->getBuffer();
        $result = $sc->processMessage($raw);
        // Should return everything after header + channelId without trying to decrypt
        $decoder = new BinaryDecoder($result);
        $statusCode = $decoder->readUInt32();
        expect($statusCode)->toBe(0x80010000);
    });
});

describe('SecureChannel OPN with security', function () {

    it('creates an OPN message with Basic256Sha256', function () {
        [$clientDer, $clientKey] = generateSecureChannelTestCert();
        [$serverDer, ] = generateSecureChannelTestCert();

        $sc = new SecureChannel(SecurityPolicy::Basic256Sha256, SecurityMode::SignAndEncrypt, $clientDer, $clientKey, $serverDer);
        $message = $sc->createOpenSecureChannelMessage();

        // The message should start with OPN header
        expect(substr($message, 0, 3))->toBe('OPN');
        expect(strlen($message))->toBeGreaterThan(100);

        // Client nonce should be generated
        expect(strlen($sc->getClientNonce()))->toBeGreaterThanOrEqual(32);
    });
});

describe('SecureChannel MSG with Sign mode (symmetric)', function () {

    beforeEach(function () {
        [$this->clientDer, $this->clientKey] = generateSecureChannelTestCert();
        [$this->serverDer, $this->serverKey] = generateSecureChannelTestCert();
    });

    it('builds and processes a Sign-mode MSG round-trip', function () {
        // We need to set up symmetric keys via deriveSymmetricKeys
        // We'll simulate the key exchange by creating two channels and forcing keys

        $policy = SecurityPolicy::Basic256Sha256;
        $mode = SecurityMode::Sign;

        // Create "client" channel
        $clientChannel = new SecureChannel($policy, $mode, $this->clientDer, $this->clientKey, $this->serverDer);

        // To test the symmetric path, we need symmetric keys.
        // Simulate OPN exchange: build a real OPN response with server nonce.
        $clientChannel->createOpenSecureChannelMessage(); // generates clientNonce
        $clientNonce = $clientChannel->getClientNonce();
        $serverNonce = random_bytes(32);

        // Build a fake OPN response for the client to process
        $response = buildEncryptedOPNResponse($this->serverDer, $this->serverKey, $this->clientDer, $this->clientKey, $clientNonce, $serverNonce, 100, 200, $policy);

        $result = $clientChannel->processOpenSecureChannelResponse($response);
        expect($result['secureChannelId'])->toBe(100);
        expect($result['tokenId'])->toBe(200);
        expect($result['serverNonce'])->toBe($serverNonce);

        // Now build a MSG with the client channel
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

        $message = $clientChannel->buildMessage($inner->getBuffer());

        // Message should start with MSG header
        expect(substr($message, 0, 3))->toBe('MSG');
        expect(strlen($message))->toBeGreaterThan(50);

        // For Sign mode, the body after the header is NOT encrypted, just signed.
        // The signature is appended at the end.
    });
});

/**
 * Builds a mock encrypted OPN response that a SecureChannel can process.
 */
function buildEncryptedOPNResponse(
    string $serverDer,
    OpenSSLAsymmetricKey $serverKey,
    string $clientDer,
    OpenSSLAsymmetricKey $clientKey,
    string $clientNonce,
    string $serverNonce,
    int $channelId,
    int $tokenId,
    SecurityPolicy $policy,
): string {
    $ms = new MessageSecurity();

    // Build the inner plaintext (sequence header + typeId + response header + OPN fields)
    $inner = new BinaryEncoder();
    $inner->writeUInt32(1); // SequenceNumber
    $inner->writeUInt32(1); // RequestId
    $inner->writeNodeId(NodeId::numeric(0, 449));
    // ResponseHeader
    $inner->writeInt64(0);
    $inner->writeUInt32(1);
    $inner->writeUInt32(0);
    $inner->writeByte(0);
    $inner->writeInt32(0);
    $inner->writeNodeId(NodeId::numeric(0, 0));
    $inner->writeByte(0);
    // OPN Response fields
    $inner->writeUInt32(0);
    $inner->writeUInt32($channelId);
    $inner->writeUInt32($tokenId);
    $inner->writeInt64(0);
    $inner->writeUInt32(3600000);
    $inner->writeByteString($serverNonce);

    $plainBody = $inner->getBuffer();

    // Build security header
    $secHeader = new BinaryEncoder();
    $secHeader->writeString($policy->value);
    $secHeader->writeByteString($serverDer);
    $cm = new CertificateManager();
    $secHeader->writeByteString($cm->getThumbprint($clientDer));
    $secHeaderBytes = $secHeader->getBuffer();

    // Calculate padding and encryption
    $keyLengthBytes = $cm->getPublicKeyLength($clientDer);
    $paddingOverhead = $policy->getAsymmetricPaddingOverhead();
    $plainTextBlockSize = $keyLengthBytes - $paddingOverhead;
    $serverKeyDetails = openssl_pkey_get_details($serverKey);
    $signatureSize = (int)($serverKeyDetails['bits'] / 8);

    // Add padding
    $bodyLen = strlen($plainBody);
    $extraPaddingByte = ($keyLengthBytes > 256) ? 1 : 0;
    $overhead = 1 + $extraPaddingByte + $signatureSize;
    $totalWithMinPadding = $bodyLen + $overhead;
    $remainder = $totalWithMinPadding % $plainTextBlockSize;
    $paddingSize = ($remainder === 0) ? 1 : 1 + ($plainTextBlockSize - $remainder);
    $paddingByte = chr($paddingSize - 1);
    $padding = str_repeat($paddingByte, $paddingSize);
    if ($extraPaddingByte) {
        $padding .= chr(($paddingSize - 1) >> 8);
    }
    $bodyWithPadding = $plainBody . $padding;

    // Calculate encrypted size
    $dataToEncryptLen = strlen($bodyWithPadding) + $signatureSize;
    $numBlocks = (int) ceil($dataToEncryptLen / $plainTextBlockSize);
    $encryptedSize = $numBlocks * $keyLengthBytes;

    $totalSize = 12 + strlen($secHeaderBytes) + $encryptedSize;

    // Build header
    $headerEncoder = new BinaryEncoder();
    $msgHeader = new MessageHeader('OPN', 'F', $totalSize);
    $msgHeader->encode($headerEncoder);
    $headerEncoder->writeUInt32($channelId);
    $headerBytes = $headerEncoder->getBuffer();

    // Sign
    $dataToSign = $headerBytes . $secHeaderBytes . $bodyWithPadding;
    $signature = $ms->asymmetricSign($dataToSign, $serverKey, $policy);

    // Encrypt with client's public key (client will decrypt with their private key)
    $dataToEncrypt = $bodyWithPadding . $signature;
    $encrypted = $ms->asymmetricEncrypt($dataToEncrypt, $clientDer, $policy);

    return $headerBytes . $secHeaderBytes . $encrypted;
}
