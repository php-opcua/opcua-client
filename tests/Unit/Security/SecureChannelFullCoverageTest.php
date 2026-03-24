<?php

declare(strict_types=1);

require_once __DIR__ . '/../Helpers/SecurityTestHelpers.php';

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Exception\SecurityException;
use Gianfriaur\OpcuaPhpClient\Security\SecureChannel;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

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
