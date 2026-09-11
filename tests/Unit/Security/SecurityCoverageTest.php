<?php

declare(strict_types=1);

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Exception\SecurityException;
use PhpOpcua\Client\Protocol\MessageHeader;
use PhpOpcua\Client\Security\CertificateManager;
use PhpOpcua\Client\Security\MessageSecurity;
use PhpOpcua\Client\Security\SecureChannel;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Types\NodeId;

require_once __DIR__ . '/SecureChannelTest.php';

function generateCert(int $bits = 2048): array
{
    $privKey = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['CN' => 'test'], $privKey);
    $cert = openssl_csr_sign($csr, null, $privKey, 365);
    openssl_x509_export($cert, $certPem);

    $cm = new CertificateManager();
    $tmp = tempnam(sys_get_temp_dir(), 'opcua_');
    file_put_contents($tmp, $certPem);
    $der = $cm->loadCertificatePem($tmp);
    unlink($tmp);

    return [$der, $privKey];
}

function getPrivateProperty(object $obj, string $name): mixed
{
    $ref = new ReflectionProperty($obj, $name);

    return $ref->getValue($obj);
}

function buildInnerBody(int $requestHandle = 1): string
{
    $inner = new BinaryEncoder();
    $inner->writeNodeId(NodeId::numeric(0, 631));
    $inner->writeNodeId(NodeId::numeric(0, 0));
    $inner->writeInt64(0);
    $inner->writeUInt32($requestHandle);
    $inner->writeUInt32(0);
    $inner->writeString(null);
    $inner->writeUInt32(10000);
    $inner->writeNodeId(NodeId::numeric(0, 0));
    $inner->writeByte(0);

    return $inner->getBuffer();
}

function setupChannelWithKeys(SecurityPolicy $policy, SecurityMode $mode): SecureChannel
{
    [$certDer, $privKey] = generateCert();
    $channel = new SecureChannel($policy, $mode, $certDer, $privKey, $certDer);
    $channel->createOpenSecureChannelMessage();
    $clientNonce = $channel->getClientNonce();
    $serverNonce = random_bytes(32);

    $response = buildEncryptedOPNResponse($certDer, $privKey, $certDer, $privKey, $clientNonce, $serverNonce, 100, 200, $policy);
    $channel->processOpenSecureChannelResponse($response);

    return $channel;
}

function buildFakeServerMsg(SecureChannel $channel, string $innerBody, SecurityMode $mode): string
{
    $ms = $channel->getMessageSecurity();
    $policy = $channel->getPolicy();
    $tokenId = $channel->getTokenId();
    $channelId = $channel->getSecureChannelId();

    $serverSigningKey = getPrivateProperty($channel, 'serverSigningKey');
    $serverEncryptingKey = getPrivateProperty($channel, 'serverEncryptingKey');
    $serverIv = getPrivateProperty($channel, 'serverIv');

    $tokenIdBytes = pack('V', $tokenId);

    $plaintext = new BinaryEncoder();
    $plaintext->writeUInt32(1);
    $plaintext->writeUInt32(1);
    $plaintext->writeRawBytes($innerBody);
    $plaintextBytes = $plaintext->getBuffer();

    $signatureSize = $policy->getSymmetricSignatureSize();
    $blockSize = $policy->getSymmetricBlockSize();

    if ($mode === SecurityMode::SignAndEncrypt) {
        $plaintextLen = strlen($plaintextBytes);
        $overhead = 1 + $signatureSize;
        $totalWithMinPadding = $plaintextLen + $overhead;
        $remainder = $totalWithMinPadding % $blockSize;
        $paddingSize = ($remainder === 0) ? 1 : 1 + ($blockSize - $remainder);
        $paddingByte = chr(($paddingSize - 1) & 0xFF);
        $paddedPlaintext = $plaintextBytes . str_repeat($paddingByte, $paddingSize);

        $encryptedDataLen = strlen($paddedPlaintext) + $signatureSize;
        $messageBody = $tokenIdBytes . str_repeat("\x00", $encryptedDataLen);
        $totalSize = MessageHeader::HEADER_SIZE + 4 + strlen($messageBody);

        $headerEncoder = new BinaryEncoder();
        $h = new MessageHeader('MSG', 'F', $totalSize);
        $h->encode($headerEncoder);
        $headerEncoder->writeUInt32($channelId);
        $headerBytes = $headerEncoder->getBuffer();

        $dataToSign = $headerBytes . $tokenIdBytes . $paddedPlaintext;
        $signature = $ms->symmetricSign($dataToSign, $serverSigningKey, $policy);

        $dataToEncrypt = $paddedPlaintext . $signature;
        $encrypted = $ms->symmetricEncrypt($dataToEncrypt, $serverEncryptingKey, $serverIv, $policy);

        $enc = new BinaryEncoder();
        $enc->writeRawBytes($headerBytes);
        $enc->writeRawBytes($tokenIdBytes);
        $enc->writeRawBytes($encrypted);

        return $enc->getBuffer();
    }

    $messageBody = $tokenIdBytes . $plaintextBytes . str_repeat("\x00", $signatureSize);
    $totalSize = MessageHeader::HEADER_SIZE + 4 + strlen($messageBody);

    $headerEncoder = new BinaryEncoder();
    $h = new MessageHeader('MSG', 'F', $totalSize);
    $h->encode($headerEncoder);
    $headerEncoder->writeUInt32($channelId);
    $headerBytes = $headerEncoder->getBuffer();

    $dataToSign = $headerBytes . $tokenIdBytes . $plaintextBytes;
    $signature = $ms->symmetricSign($dataToSign, $serverSigningKey, $policy);

    $enc = new BinaryEncoder();
    $enc->writeRawBytes($headerBytes);
    $enc->writeRawBytes($tokenIdBytes);
    $enc->writeRawBytes($plaintextBytes);
    $enc->writeRawBytes($signature);

    return $enc->getBuffer();
}

describe('SecureChannel Sign-only MSG round-trip', function () {

    it('round-trips buildMessage and processMessage in Sign mode', function () {
        $channel = setupChannelWithKeys(SecurityPolicy::Basic256Sha256, SecurityMode::Sign);
        $innerBody = buildInnerBody(42);

        $message = $channel->buildMessage($innerBody);
        expect(substr($message, 0, 3))->toBe('MSG');

        $serverMsg = buildFakeServerMsg($channel, $innerBody, SecurityMode::Sign);
        $processed = $channel->processMessage($serverMsg);

        $decoder = new BinaryDecoder($processed);
        $decoder->readUInt32();
        $decoder->readUInt32();
        $decoder->readUInt32();
        $typeId = $decoder->readNodeId();
        expect($typeId->getIdentifier())->toBe(631);
    });
});

describe('SecureChannel SignAndEncrypt MSG round-trip', function () {

    it('round-trips buildMessage and processMessage in SignAndEncrypt mode', function () {
        $channel = setupChannelWithKeys(SecurityPolicy::Basic256Sha256, SecurityMode::SignAndEncrypt);
        $innerBody = buildInnerBody(77);

        $message = $channel->buildMessage($innerBody);
        expect(substr($message, 0, 3))->toBe('MSG');
        expect(strlen($message))->toBeGreaterThan(strlen($innerBody) + 50);

        $serverMsg = buildFakeServerMsg($channel, $innerBody, SecurityMode::SignAndEncrypt);
        $processed = $channel->processMessage($serverMsg);

        $decoder = new BinaryDecoder($processed);
        $decoder->readUInt32();
        $decoder->readUInt32();
        $decoder->readUInt32();
        $typeId = $decoder->readNodeId();
        expect($typeId->getIdentifier())->toBe(631);
    });
});

describe('SecureChannel processMessage rejects bad signature', function () {

    it('throws SecurityException on tampered Sign-only message', function () {
        $channel = setupChannelWithKeys(SecurityPolicy::Basic256Sha256, SecurityMode::Sign);
        $serverMsg = buildFakeServerMsg($channel, buildInnerBody(), SecurityMode::Sign);

        $tampered = $serverMsg;
        $tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 0xFF);

        expect(fn () => $channel->processMessage($tampered))
            ->toThrow(SecurityException::class, 'symmetric signature verification failed');
    });

    it('throws SecurityException on tampered SignAndEncrypt message', function () {
        $channel = setupChannelWithKeys(SecurityPolicy::Basic256Sha256, SecurityMode::SignAndEncrypt);
        $serverMsg = buildFakeServerMsg($channel, buildInnerBody(), SecurityMode::SignAndEncrypt);

        $tampered = $serverMsg;
        $pos = 20;
        $tampered[$pos] = chr(ord($tampered[$pos]) ^ 0xFF);

        expect(fn () => $channel->processMessage($tampered))
            ->toThrow(SecurityException::class);
    });
});

describe('SecureChannel OPN response with string table', function () {

    it('processes OPN response with non-empty string table', function () {
        [$certDer, $privKey] = generateCert();
        $channel = new SecureChannel(SecurityPolicy::None, SecurityMode::None);

        $encoder = new BinaryEncoder();
        $header = new MessageHeader('OPN', 'F', 0);
        $header->encode($encoder);
        $encoder->writeUInt32(42);
        $encoder->writeString(SecurityPolicy::None->value);
        $encoder->writeByteString(null);
        $encoder->writeByteString(null);
        $encoder->writeUInt32(1);
        $encoder->writeUInt32(1);
        $encoder->writeNodeId(NodeId::numeric(0, 449));
        $encoder->writeInt64(0);
        $encoder->writeUInt32(1);
        $encoder->writeUInt32(0);
        $encoder->writeByte(0);
        $encoder->writeInt32(2);
        $encoder->writeString('entry1');
        $encoder->writeString('entry2');
        $encoder->writeNodeId(NodeId::numeric(0, 0));
        $encoder->writeByte(0);
        $encoder->writeUInt32(0);
        $encoder->writeUInt32(300);
        $encoder->writeUInt32(400);
        $encoder->writeInt64(0);
        $encoder->writeUInt32(3600000);
        $encoder->writeByteString(null);

        $response = $encoder->getBuffer();
        $response = substr($response, 0, 4) . pack('V', strlen($response)) . substr($response, 8);

        $result = $channel->processOpenSecureChannelResponse($response);
        expect($result['secureChannelId'])->toBe(300);
        expect($result['tokenId'])->toBe(400);
    });
});

describe('MessageSecurity with Aes128Sha256RsaOaep policy', function () {

    it('round-trips asymmetric sign/verify', function () {
        [$derCert, $privKey] = generateCert();
        $ms = new MessageSecurity();
        $data = random_bytes(100);

        $sig = $ms->asymmetricSign($data, $privKey, SecurityPolicy::Aes128Sha256RsaOaep);
        $valid = $ms->asymmetricVerify($data, $sig, $derCert, SecurityPolicy::Aes128Sha256RsaOaep);
        expect($valid)->toBeTrue();
    });

    it('round-trips asymmetric encrypt/decrypt', function () {
        [$derCert, $privKey] = generateCert();
        $ms = new MessageSecurity();
        $plaintext = random_bytes(64);

        $encrypted = $ms->asymmetricEncrypt($plaintext, $derCert, SecurityPolicy::Aes128Sha256RsaOaep);
        $decrypted = $ms->asymmetricDecrypt($encrypted, $privKey, SecurityPolicy::Aes128Sha256RsaOaep);
        expect($decrypted)->toBe($plaintext);
    });

    it('derives correct key lengths', function () {
        $ms = new MessageSecurity();
        $keys = $ms->deriveKeys(random_bytes(32), random_bytes(32), SecurityPolicy::Aes128Sha256RsaOaep);
        expect(strlen($keys['signingKey']))->toBe(32);
        expect(strlen($keys['encryptingKey']))->toBe(16);
        expect(strlen($keys['iv']))->toBe(16);
    });

    it('round-trips symmetric encrypt/decrypt', function () {
        $ms = new MessageSecurity();
        $key = random_bytes(16);
        $iv = random_bytes(16);
        $plaintext = random_bytes(48);

        $encrypted = $ms->symmetricEncrypt($plaintext, $key, $iv, SecurityPolicy::Aes128Sha256RsaOaep);
        $decrypted = $ms->symmetricDecrypt($encrypted, $key, $iv, SecurityPolicy::Aes128Sha256RsaOaep);
        expect($decrypted)->toBe($plaintext);
    });
});

describe('MessageSecurity with Basic256 policy', function () {

    it('round-trips asymmetric sign/verify', function () {
        [$derCert, $privKey] = generateCert();
        $ms = new MessageSecurity();
        $data = random_bytes(100);

        $sig = $ms->asymmetricSign($data, $privKey, SecurityPolicy::Basic256);
        $valid = $ms->asymmetricVerify($data, $sig, $derCert, SecurityPolicy::Basic256);
        expect($valid)->toBeTrue();
    });

    it('round-trips asymmetric encrypt/decrypt', function () {
        [$derCert, $privKey] = generateCert();
        $ms = new MessageSecurity();
        $plaintext = random_bytes(64);

        $encrypted = $ms->asymmetricEncrypt($plaintext, $derCert, SecurityPolicy::Basic256);
        $decrypted = $ms->asymmetricDecrypt($encrypted, $privKey, SecurityPolicy::Basic256);
        expect($decrypted)->toBe($plaintext);
    });

    it('derives correct key lengths', function () {
        $ms = new MessageSecurity();
        $keys = $ms->deriveKeys(random_bytes(16), random_bytes(16), SecurityPolicy::Basic256);
        expect(strlen($keys['signingKey']))->toBe(20);
        expect(strlen($keys['encryptingKey']))->toBe(32);
        expect(strlen($keys['iv']))->toBe(16);
    });
});

describe('CertificateManager getApplicationUri edge cases', function () {

    it('returns null for cert without SAN', function () {
        $privKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['CN' => 'no-san'], $privKey);
        $cert = openssl_csr_sign($csr, null, $privKey, 365);
        openssl_x509_export($cert, $pem);

        $cm = new CertificateManager();
        $tmp = tempnam(sys_get_temp_dir(), 'opcua_');
        file_put_contents($tmp, $pem);
        $der = $cm->loadCertificatePem($tmp);
        unlink($tmp);

        expect($cm->getApplicationUri($der))->toBeNull();
    });

    it('returns null for cert with SAN but no URI', function () {
        $privKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        $configContent = "[req]\ndistinguished_name=req_dn\nx509_extensions=v3\nprompt=no\n"
            . "[req_dn]\nCN=dns-only\n"
            . "[v3]\nsubjectAltName=DNS:example.com\n";
        $tmpCfg = tempnam(sys_get_temp_dir(), 'opcua_cfg_');
        file_put_contents($tmpCfg, $configContent);

        $csr = openssl_csr_new(['CN' => 'dns-only'], $privKey, ['config' => $tmpCfg]);
        $cert = openssl_csr_sign($csr, null, $privKey, 365, ['config' => $tmpCfg, 'x509_extensions' => 'v3']);
        openssl_x509_export($cert, $pem);
        unlink($tmpCfg);

        $cm = new CertificateManager();
        $tmp = tempnam(sys_get_temp_dir(), 'opcua_');
        file_put_contents($tmp, $pem);
        $der = $cm->loadCertificatePem($tmp);
        unlink($tmp);

        expect($cm->getApplicationUri($der))->toBeNull();
    });

    it('returns null for invalid DER data', function () {
        $cm = new CertificateManager();
        expect($cm->getApplicationUri('not-a-cert'))->toBeNull();
    });
});

describe('CertificateManager loadPrivateKeyPem', function () {

    it('loads a valid PEM private key', function () {
        $privKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($privKey, $pem);

        $tmp = tempnam(sys_get_temp_dir(), 'opcua_key_');
        file_put_contents($tmp, $pem);

        $cm = new CertificateManager();
        $loaded = $cm->loadPrivateKeyPem($tmp);
        unlink($tmp);

        expect($loaded)->toBeInstanceOf(OpenSSLAsymmetricKey::class);
    });

    it('throws SecurityException for invalid PEM key', function () {
        $tmp = tempnam(sys_get_temp_dir(), 'opcua_bad_');
        file_put_contents($tmp, 'not-a-key');

        $cm = new CertificateManager();
        expect(fn () => $cm->loadPrivateKeyPem($tmp))
            ->toThrow(SecurityException::class, 'Failed to parse private key');

        unlink($tmp);
    });
});

describe('CertificateManager getPublicKeyLength', function () {

    it('returns correct length for 2048-bit key', function () {
        [$der] = generateCert(2048);
        $cm = new CertificateManager();
        expect($cm->getPublicKeyLength($der))->toBe(256);
    });
});
