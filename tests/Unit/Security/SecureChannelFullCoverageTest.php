<?php

declare(strict_types=1);

require_once __DIR__ . '/../Helpers/SecurityTestHelpers.php';

use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Exception\ProtocolException;
use PhpOpcua\Client\Exception\SecurityException;
use PhpOpcua\Client\Protocol\MessageHeader;
use PhpOpcua\Client\Security\CertificateManager;
use PhpOpcua\Client\Security\MessageSecurity;
use PhpOpcua\Client\Security\SecureChannel;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Types\NodeId;

describe('SecureChannel OPN signature verification failure', function () {

    it('throws SecurityException when OPN response has wrong signature (line 283)', function () {
        [$certDer, $privKey] = generateTestCertKeyPair();
        [$otherDer, $otherKey] = generateTestCertKeyPair();
        $policy = SecurityPolicy::Basic256Sha256;

        $channel = new SecureChannel($policy, SecurityMode::SignAndEncrypt, $certDer, $privKey, $certDer);
        $channel->createOpenSecureChannelMessage();

        $response = buildTestOPNResponse($certDer, $otherKey, $certDer, $privKey, $channel->getClientNonce(), random_bytes(32), 1, 1, $policy);

        expect(fn () => $channel->processOpenSecureChannelResponse($response))
            ->toThrow(SecurityException::class, 'signature verification failed');
    });
});

describe('SecureChannel with 4096-bit keys (extraPaddingByte paths)', function () {

    it('OPN round-trip with 4096-bit key covers addAsymmetricPadding extraPaddingByte (lines 549, 558, 575-578)', function () {
        [$certDer, $privKey] = generateTestCertKeyPair(4096);
        $policy = SecurityPolicy::Basic256Sha256;

        $channel = new SecureChannel($policy, SecurityMode::SignAndEncrypt, $certDer, $privKey, $certDer);
        $opnMsg = $channel->createOpenSecureChannelMessage();

        expect(substr($opnMsg, 0, 3))->toBe('OPN');
        expect(strlen($opnMsg))->toBeGreaterThan(200);

        $serverNonce = random_bytes(32);
        $response = buildTestOPNResponse($certDer, $privKey, $certDer, $privKey, $channel->getClientNonce(), $serverNonce, 100, 200, $policy);

        $result = $channel->processOpenSecureChannelResponse($response);
        expect($result['secureChannelId'])->toBe(100);
        expect($result['tokenId'])->toBe(200);
    });
});

describe('SecureChannel getClientKeyLengthBytes null key', function () {

    it('returns 0 when clientPrivateKey is null (line 626)', function () {
        $sc = new SecureChannel(SecurityPolicy::Basic256Sha256, SecurityMode::SignAndEncrypt, null, null, null);

        $ref = new ReflectionMethod($sc, 'getClientKeyLengthBytes');
        $result = $ref->invoke($sc);

        expect($result)->toBe(0);
    });
});

describe('SecureChannel asymmetricSignAndEncrypt (dead code coverage)', function () {

    it('exercises asymmetricSignAndEncrypt via Reflection (lines 507-525)', function () {
        [$certDer, $privKey] = generateTestCertKeyPair();
        $policy = SecurityPolicy::Basic256Sha256;
        $sc = new SecureChannel($policy, SecurityMode::SignAndEncrypt, $certDer, $privKey, $certDer);

        $secHeader = new BinaryEncoder();
        $secHeader->writeString($policy->value);
        $secHeader->writeByteString($certDer);
        $secHeader->writeByteString($sc->getCertificateManager()->getThumbprint($certDer));

        $plainBody = new BinaryEncoder();
        $plainBody->writeUInt32(1);
        $plainBody->writeUInt32(1);
        $plainBody->writeNodeId(NodeId::numeric(0, 446));
        $plainBody->writeNodeId(NodeId::numeric(0, 0));
        $plainBody->writeInt64(0);
        $plainBody->writeUInt32(1);
        $plainBody->writeUInt32(0);
        $plainBody->writeString(null);
        $plainBody->writeUInt32(10000);
        $plainBody->writeNodeId(NodeId::numeric(0, 0));
        $plainBody->writeByte(0);

        $ref = new ReflectionMethod($sc, 'asymmetricSignAndEncrypt');
        $encrypted = $ref->invoke($sc, $secHeader->getBuffer(), $plainBody->getBuffer());

        expect(strlen($encrypted))->toBeGreaterThan(0);
    });

    it('extractKeyLengthBytes throws SecurityException on false input', function () {
        [$certDer, $privKey] = generateTestCertKeyPair();
        $sc = new SecureChannel(
            SecurityPolicy::Basic256Sha256,
            SecurityMode::SignAndEncrypt,
            $certDer,
            $privKey,
            $certDer,
        );

        $method = new ReflectionMethod($sc, 'extractKeyLengthBytes');

        expect(fn () => $method->invoke($sc, false))
            ->toThrow(SecurityException::class, 'Failed to get client private key details');
    });

    it('extractKeyLengthBytes returns correct length for valid key details', function () {
        [$certDer, $privKey] = generateTestCertKeyPair();
        $sc = new SecureChannel(
            SecurityPolicy::Basic256Sha256,
            SecurityMode::SignAndEncrypt,
            $certDer,
            $privKey,
            $certDer,
        );

        $method = new ReflectionMethod($sc, 'extractKeyLengthBytes');
        $details = openssl_pkey_get_details($privKey);
        $result = $method->invoke($sc, $details);

        expect($result)->toBe((int) ($details['bits'] / 8));
    });

    it('addAsymmetricPadding with remainder zero produces paddingSize 1', function () {
        [$certDer, $privKey] = generateTestCertKeyPair();
        $sc = new SecureChannel(
            SecurityPolicy::Basic256Sha256,
            SecurityMode::SignAndEncrypt,
            $certDer,
            $privKey,
            $certDer,
        );

        $method = new ReflectionMethod($sc, 'addAsymmetricPadding');
        $signatureSize = 20;
        $plainTextBlockSize = 16;
        $keyLengthBytes = 128;
        $body = str_repeat('A', 11);

        $result = $method->invoke($sc, $body, $signatureSize, $plainTextBlockSize, $keyLengthBytes);

        expect(strlen($result))->toBe(strlen($body) + 1);
        expect(ord($result[strlen($result) - 1]))->toBe(0);
    });
});

describe('SecureChannel OPN ERR response', function () {

    it('throws ProtocolException for ERR response with status code and reason', function () {
        $sc = new SecureChannel(SecurityPolicy::None, SecurityMode::None);

        $encoder = new BinaryEncoder();
        $header = new MessageHeader('ERR', 'F', 0);
        $header->encode($encoder);
        $encoder->writeUInt32(0x80010000); // statusCode
        $encoder->writeString('Bad session'); // reason

        $response = $encoder->getBuffer();
        $response = substr($response, 0, 4) . pack('V', strlen($response)) . substr($response, 8);

        expect(fn () => $sc->processOpenSecureChannelResponse($response))
            ->toThrow(ProtocolException::class, 'OPN rejected by server');
    });

    it('throws ProtocolException for ERR response with truncated body (catch Throwable)', function () {
        $sc = new SecureChannel(SecurityPolicy::None, SecurityMode::None);

        // Only status code, no reason string — readString() will throw, caught by catch
        $encoder = new BinaryEncoder();
        $header = new MessageHeader('ERR', 'F', 0);
        $header->encode($encoder);
        $encoder->writeUInt32(0x80020000); // statusCode only, no reason

        $response = $encoder->getBuffer();
        $response = substr($response, 0, 4) . pack('V', strlen($response)) . substr($response, 8);

        expect(fn () => $sc->processOpenSecureChannelResponse($response))
            ->toThrow(ProtocolException::class, 'OPN rejected by server');
    });
});

describe('SecureChannel addSymmetricPadding remainder zero', function () {

    it('produces paddingSize 1 when remainder is zero', function () {
        $sc = new class(SecurityPolicy::Basic256Sha256, SecurityMode::Sign) extends SecureChannel {
            public function callAddSymmetricPadding(string $plaintext, int $signatureSize, int $blockSize): string
            {
                return $this->addSymmetricPadding($plaintext, $signatureSize, $blockSize);
            }
        };

        // blockSize=16, signatureSize=32, overhead=33
        // We need (plaintextLen + 33) % 16 === 0
        // plaintextLen + 33 = N*16 → plaintextLen = N*16 - 33
        // N=3 → 48-33 = 15
        $plaintext = str_repeat('A', 15);
        $result = $sc->callAddSymmetricPadding($plaintext, 32, 16);

        // paddingSize should be 1 (one byte of \x00)
        expect(strlen($result))->toBe(16);
        expect(ord($result[15]))->toBe(0);
    });
});

describe('SecureChannel SignAndEncrypt MSG round-trip', function () {

    it('builds and processes a SignAndEncrypt MSG', function () {
        [$certDer, $privKey] = generateTestCertKeyPair();
        $policy = SecurityPolicy::Basic256Sha256;
        $mode = SecurityMode::SignAndEncrypt;

        $clientChannel = new SecureChannel($policy, $mode, $certDer, $privKey, $certDer);
        $clientChannel->createOpenSecureChannelMessage();
        $clientNonce = $clientChannel->getClientNonce();
        $serverNonce = random_bytes(32);

        $response = buildTestOPNResponse($certDer, $privKey, $certDer, $privKey, $clientNonce, $serverNonce, 100, 200, $policy);
        $clientChannel->processOpenSecureChannelResponse($response);

        // Build a MSG
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
        expect(substr($message, 0, 3))->toBe('MSG');
        expect(strlen($message))->toBeGreaterThan(50);

        // Build a "server" channel with swapped keys to process the message
        $serverChannel = new SecureChannel($policy, $mode, $certDer, $privKey, $certDer);
        $serverChannel->createOpenSecureChannelMessage();

        // Derive server's keys using swapped nonces (server sees clientNonce as remote, serverNonce as local)
        $serverResponse = buildTestOPNResponse($certDer, $privKey, $certDer, $privKey, $serverChannel->getClientNonce(), $clientNonce, 100, 200, $policy);
        $serverChannel->processOpenSecureChannelResponse($serverResponse);
    });
});

describe('SecureChannel ECC OPN', function () {

    it('creates OPN message with ECC policy and generates ephemeral nonce', function () {
        $cm = new CertificateManager();
        $result = $cm->generateSelfSignedCertificate('urn:ecc-test', 'prime256v1');
        $certDer = $result['certDer'];
        $privKey = $result['privateKey'];

        $serverResult = $cm->generateSelfSignedCertificate('urn:ecc-server', 'prime256v1');
        $serverDer = $serverResult['certDer'];

        $sc = new SecureChannel(SecurityPolicy::EccNistP256, SecurityMode::Sign, $certDer, $privKey, $serverDer);
        $message = $sc->createOpenSecureChannelMessage();

        expect(substr($message, 0, 3))->toBe('OPN');
        // ECC nonce = X+Y coordinates (64 bytes for P-256)
        expect(strlen($sc->getClientNonce()))->toBe(64);
    });

    it('ECC OPN round-trip with sign and key derivation', function () {
        $cm = new CertificateManager();
        $ms = new MessageSecurity();

        $clientResult = $cm->generateSelfSignedCertificate('urn:ecc-client', 'prime256v1');
        $clientDer = $clientResult['certDer'];
        $clientKey = $clientResult['privateKey'];

        $serverResult = $cm->generateSelfSignedCertificate('urn:ecc-server', 'prime256v1');
        $serverDer = $serverResult['certDer'];
        $serverKey = $serverResult['privateKey'];

        $policy = SecurityPolicy::EccNistP256;
        $mode = SecurityMode::Sign;

        // Client creates OPN
        $clientChannel = new SecureChannel($policy, $mode, $clientDer, $clientKey, $serverDer);
        $opnMessage = $clientChannel->createOpenSecureChannelMessage();
        $clientNonce = $clientChannel->getClientNonce();

        // Server generates its own ephemeral key pair for the response
        $serverEphemeral = $ms->generateEphemeralKeyPair('prime256v1');
        $serverNonceBytes = substr($serverEphemeral['publicKeyBytes'], 1); // strip 0x04

        // Build an ECC OPN response (signed, not encrypted)
        $inner = new BinaryEncoder();
        $inner->writeUInt32(1); // SequenceNumber
        $inner->writeUInt32(1); // RequestId
        $inner->writeNodeId(NodeId::numeric(0, 449));
        $inner->writeInt64(0);
        $inner->writeUInt32(1);
        $inner->writeUInt32(0);
        $inner->writeByte(0);
        $inner->writeInt32(0);
        $inner->writeNodeId(NodeId::numeric(0, 0));
        $inner->writeByte(0);
        $inner->writeUInt32(0);
        $inner->writeUInt32(500);    // channelId
        $inner->writeUInt32(600);    // tokenId
        $inner->writeInt64(0);
        $inner->writeUInt32(3600000);
        $inner->writeByteString($serverNonceBytes);

        $plainBody = $inner->getBuffer();

        $secHeader = new BinaryEncoder();
        $secHeader->writeString($policy->value);
        $secHeader->writeByteString($serverDer);
        $secHeader->writeByteString($cm->getThumbprint($clientDer));
        $secHeaderBytes = $secHeader->getBuffer();

        // Signature: coordinateSize * 2 for ECDSA raw
        $coordinateSize = 32; // P-256
        $signatureSize = $coordinateSize * 2;
        $totalSize = 12 + strlen($secHeaderBytes) + strlen($plainBody) + $signatureSize;

        $headerEncoder = new BinaryEncoder();
        $msgHeader = new MessageHeader('OPN', 'F', $totalSize);
        $msgHeader->encode($headerEncoder);
        $headerEncoder->writeUInt32(500);
        $headerBytes = $headerEncoder->getBuffer();

        $dataToSign = $headerBytes . $secHeaderBytes . $plainBody;
        $derSignature = $ms->asymmetricSign($dataToSign, $serverKey, $policy);
        $rawSignature = $ms->ecdsaDerToRaw($derSignature, $coordinateSize);

        $response = $headerBytes . $secHeaderBytes . $plainBody . $rawSignature;

        $result = $clientChannel->processOpenSecureChannelResponse($response);
        expect($result['secureChannelId'])->toBe(500);
        expect($result['tokenId'])->toBe(600);
        expect($result['serverNonce'])->toBe($serverNonceBytes);
    });

    it('throws SecurityException for ECC OPN response with wrong signature', function () {
        $cm = new CertificateManager();
        $ms = new MessageSecurity();

        $clientResult = $cm->generateSelfSignedCertificate('urn:ecc-client', 'prime256v1');
        $clientDer = $clientResult['certDer'];
        $clientKey = $clientResult['privateKey'];

        $serverResult = $cm->generateSelfSignedCertificate('urn:ecc-server', 'prime256v1');
        $serverDer = $serverResult['certDer'];

        // Use a DIFFERENT key to sign (not the server's key)
        $otherResult = $cm->generateSelfSignedCertificate('urn:ecc-other', 'prime256v1');
        $otherKey = $otherResult['privateKey'];

        $policy = SecurityPolicy::EccNistP256;
        $mode = SecurityMode::Sign;

        $clientChannel = new SecureChannel($policy, $mode, $clientDer, $clientKey, $serverDer);
        $clientChannel->createOpenSecureChannelMessage();

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
        $inner->writeUInt32(500);
        $inner->writeUInt32(600);
        $inner->writeInt64(0);
        $inner->writeUInt32(3600000);
        $inner->writeByteString($serverNonceBytes);
        $plainBody = $inner->getBuffer();

        $secHeader = new BinaryEncoder();
        $secHeader->writeString($policy->value);
        $secHeader->writeByteString($serverDer);
        $secHeader->writeByteString($cm->getThumbprint($clientDer));
        $secHeaderBytes = $secHeader->getBuffer();

        $coordinateSize = 32;
        $signatureSize = $coordinateSize * 2;
        $totalSize = 12 + strlen($secHeaderBytes) + strlen($plainBody) + $signatureSize;

        $headerEncoder = new BinaryEncoder();
        $msgHeader = new MessageHeader('OPN', 'F', $totalSize);
        $msgHeader->encode($headerEncoder);
        $headerEncoder->writeUInt32(500);
        $headerBytes = $headerEncoder->getBuffer();

        $dataToSign = $headerBytes . $secHeaderBytes . $plainBody;
        // Sign with WRONG key
        $derSignature = $ms->asymmetricSign($dataToSign, $otherKey, $policy);
        $rawSignature = $ms->ecdsaDerToRaw($derSignature, $coordinateSize);

        $response = $headerBytes . $secHeaderBytes . $plainBody . $rawSignature;

        expect(fn () => $clientChannel->processOpenSecureChannelResponse($response))
            ->toThrow(SecurityException::class, 'ECC signature verification failed');
    });

    it('ECC OPN with SignAndEncrypt derives keys with totalLen salt', function () {
        $cm = new CertificateManager();
        $ms = new MessageSecurity();

        $clientResult = $cm->generateSelfSignedCertificate('urn:ecc-client', 'prime256v1');
        $clientDer = $clientResult['certDer'];
        $clientKey = $clientResult['privateKey'];

        $serverResult = $cm->generateSelfSignedCertificate('urn:ecc-server', 'prime256v1');
        $serverDer = $serverResult['certDer'];
        $serverKey = $serverResult['privateKey'];

        $policy = SecurityPolicy::EccNistP256;
        $mode = SecurityMode::SignAndEncrypt;

        $clientChannel = new SecureChannel($policy, $mode, $clientDer, $clientKey, $serverDer);
        $clientChannel->createOpenSecureChannelMessage();

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
        $inner->writeUInt32(500);
        $inner->writeUInt32(600);
        $inner->writeInt64(0);
        $inner->writeUInt32(3600000);
        $inner->writeByteString($serverNonceBytes);
        $plainBody = $inner->getBuffer();

        $secHeader = new BinaryEncoder();
        $secHeader->writeString($policy->value);
        $secHeader->writeByteString($serverDer);
        $secHeader->writeByteString($cm->getThumbprint($clientDer));
        $secHeaderBytes = $secHeader->getBuffer();

        $coordinateSize = 32;
        $signatureSize = $coordinateSize * 2;
        $totalSize = 12 + strlen($secHeaderBytes) + strlen($plainBody) + $signatureSize;

        $headerEncoder = new BinaryEncoder();
        $msgHeader = new MessageHeader('OPN', 'F', $totalSize);
        $msgHeader->encode($headerEncoder);
        $headerEncoder->writeUInt32(500);
        $headerBytes = $headerEncoder->getBuffer();

        $dataToSign = $headerBytes . $secHeaderBytes . $plainBody;
        $derSignature = $ms->asymmetricSign($dataToSign, $serverKey, $policy);
        $rawSignature = $ms->ecdsaDerToRaw($derSignature, $coordinateSize);

        $response = $headerBytes . $secHeaderBytes . $plainBody . $rawSignature;

        $result = $clientChannel->processOpenSecureChannelResponse($response);
        expect($result['secureChannelId'])->toBe(500);
        expect($result['tokenId'])->toBe(600);
    });
});
