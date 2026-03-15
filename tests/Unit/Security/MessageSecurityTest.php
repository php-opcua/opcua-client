<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Security\CertificateManager;
use Gianfriaur\OpcuaPhpClient\Security\MessageSecurity;
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;

/**
 * Generates a self-signed certificate and private key for testing.
 * Returns [derCert, privateKey].
 */
function generateTestCertAndKey(int $bits = 2048): array
{
    $privKey = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['CN' => 'test'], $privKey);
    $cert = openssl_csr_sign($csr, null, $privKey, 365);
    openssl_x509_export($cert, $certPem);

    $cm = new CertificateManager();
    $tmpFile = tempnam(sys_get_temp_dir(), 'opcua_test_cert_');
    file_put_contents($tmpFile, $certPem);
    $derCert = $cm->loadCertificatePem($tmpFile);
    unlink($tmpFile);

    return [$derCert, $privKey];
}

describe('MessageSecurity asymmetric operations', function () {

    beforeEach(function () {
        [$this->derCert, $this->privKey] = generateTestCertAndKey();
        $this->ms = new MessageSecurity();
    });

    it('returns empty string for asymmetricSign with None policy', function () {
        $sig = $this->ms->asymmetricSign('data', $this->privKey, SecurityPolicy::None);
        expect($sig)->toBe('');
    });

    it('returns true for asymmetricVerify with None policy', function () {
        $result = $this->ms->asymmetricVerify('data', 'fake-sig', $this->derCert, SecurityPolicy::None);
        expect($result)->toBeTrue();
    });

    it('returns data unchanged for asymmetricEncrypt with None policy', function () {
        $data = 'hello world';
        $result = $this->ms->asymmetricEncrypt($data, $this->derCert, SecurityPolicy::None);
        expect($result)->toBe($data);
    });

    it('returns data unchanged for asymmetricDecrypt with None policy', function () {
        $data = 'hello world';
        $result = $this->ms->asymmetricDecrypt($data, $this->privKey, SecurityPolicy::None);
        expect($result)->toBe($data);
    });

    it('round-trips asymmetric sign/verify with Basic256Sha256', function () {
        $data = random_bytes(100);
        $signature = $this->ms->asymmetricSign($data, $this->privKey, SecurityPolicy::Basic256Sha256);

        expect(strlen($signature))->toBe(256); // 2048-bit key → 256-byte signature

        $valid = $this->ms->asymmetricVerify($data, $signature, $this->derCert, SecurityPolicy::Basic256Sha256);
        expect($valid)->toBeTrue();
    });

    it('asymmetricVerify returns false for tampered data', function () {
        $data = random_bytes(100);
        $signature = $this->ms->asymmetricSign($data, $this->privKey, SecurityPolicy::Basic256Sha256);

        $tampered = $data . "\x00";
        $valid = $this->ms->asymmetricVerify($tampered, $signature, $this->derCert, SecurityPolicy::Basic256Sha256);
        expect($valid)->toBeFalse();
    });

    it('round-trips asymmetric encrypt/decrypt with Basic256Sha256', function () {
        $plaintext = random_bytes(64);
        $encrypted = $this->ms->asymmetricEncrypt($plaintext, $this->derCert, SecurityPolicy::Basic256Sha256);

        expect($encrypted)->not->toBe($plaintext);
        expect(strlen($encrypted))->toBeGreaterThan(0);

        $decrypted = $this->ms->asymmetricDecrypt($encrypted, $this->privKey, SecurityPolicy::Basic256Sha256);
        expect($decrypted)->toBe($plaintext);
    });

    it('round-trips asymmetric encrypt/decrypt with multi-block data', function () {
        // Data larger than one RSA block (256 - 42 = 214 bytes plaintext block size for OAEP)
        $plaintext = random_bytes(400);
        $encrypted = $this->ms->asymmetricEncrypt($plaintext, $this->derCert, SecurityPolicy::Basic256Sha256);

        expect(strlen($encrypted))->toBeGreaterThan(strlen($plaintext));

        $decrypted = $this->ms->asymmetricDecrypt($encrypted, $this->privKey, SecurityPolicy::Basic256Sha256);
        expect($decrypted)->toBe($plaintext);
    });

    it('round-trips asymmetric sign/verify with Basic128Rsa15', function () {
        $data = random_bytes(50);
        $signature = $this->ms->asymmetricSign($data, $this->privKey, SecurityPolicy::Basic128Rsa15);
        $valid = $this->ms->asymmetricVerify($data, $signature, $this->derCert, SecurityPolicy::Basic128Rsa15);
        expect($valid)->toBeTrue();
    });

    it('round-trips asymmetric encrypt/decrypt with Basic128Rsa15', function () {
        $plaintext = random_bytes(64);
        $encrypted = $this->ms->asymmetricEncrypt($plaintext, $this->derCert, SecurityPolicy::Basic128Rsa15);
        $decrypted = $this->ms->asymmetricDecrypt($encrypted, $this->privKey, SecurityPolicy::Basic128Rsa15);
        expect($decrypted)->toBe($plaintext);
    });
});

describe('MessageSecurity symmetric operations', function () {

    beforeEach(function () {
        $this->ms = new MessageSecurity();
    });

    it('returns empty string for symmetricSign with None policy', function () {
        $sig = $this->ms->symmetricSign('data', 'key', SecurityPolicy::None);
        expect($sig)->toBe('');
    });

    it('returns true for symmetricVerify with None policy', function () {
        $result = $this->ms->symmetricVerify('data', 'fake', 'key', SecurityPolicy::None);
        expect($result)->toBeTrue();
    });

    it('returns data unchanged for symmetricEncrypt with None policy', function () {
        $result = $this->ms->symmetricEncrypt('data', 'key', 'iv', SecurityPolicy::None);
        expect($result)->toBe('data');
    });

    it('returns data unchanged for symmetricDecrypt with None policy', function () {
        $result = $this->ms->symmetricDecrypt('data', 'key', 'iv', SecurityPolicy::None);
        expect($result)->toBe('data');
    });

    it('round-trips symmetric sign/verify with Basic256Sha256', function () {
        $key = random_bytes(32);
        $data = random_bytes(100);

        $signature = $this->ms->symmetricSign($data, $key, SecurityPolicy::Basic256Sha256);
        expect(strlen($signature))->toBe(32); // SHA-256 HMAC = 32 bytes

        $valid = $this->ms->symmetricVerify($data, $signature, $key, SecurityPolicy::Basic256Sha256);
        expect($valid)->toBeTrue();
    });

    it('symmetricVerify returns false for tampered data', function () {
        $key = random_bytes(32);
        $data = random_bytes(100);

        $signature = $this->ms->symmetricSign($data, $key, SecurityPolicy::Basic256Sha256);
        $valid = $this->ms->symmetricVerify($data . "\x00", $signature, $key, SecurityPolicy::Basic256Sha256);
        expect($valid)->toBeFalse();
    });

    it('symmetricVerify returns false for wrong key', function () {
        $key1 = random_bytes(32);
        $key2 = random_bytes(32);
        $data = random_bytes(100);

        $signature = $this->ms->symmetricSign($data, $key1, SecurityPolicy::Basic256Sha256);
        $valid = $this->ms->symmetricVerify($data, $signature, $key2, SecurityPolicy::Basic256Sha256);
        expect($valid)->toBeFalse();
    });

    it('round-trips symmetric encrypt/decrypt with Basic256Sha256', function () {
        $key = random_bytes(32); // AES-256
        $iv = random_bytes(16);  // AES block size
        // Data must be multiple of block size (16)
        $plaintext = random_bytes(64);

        $encrypted = $this->ms->symmetricEncrypt($plaintext, $key, $iv, SecurityPolicy::Basic256Sha256);
        expect($encrypted)->not->toBe($plaintext);
        expect(strlen($encrypted))->toBe(64);

        $decrypted = $this->ms->symmetricDecrypt($encrypted, $key, $iv, SecurityPolicy::Basic256Sha256);
        expect($decrypted)->toBe($plaintext);
    });

    it('round-trips symmetric encrypt/decrypt with Basic128Rsa15', function () {
        $key = random_bytes(16); // AES-128
        $iv = random_bytes(16);
        $plaintext = random_bytes(48);

        $encrypted = $this->ms->symmetricEncrypt($plaintext, $key, $iv, SecurityPolicy::Basic128Rsa15);
        $decrypted = $this->ms->symmetricDecrypt($encrypted, $key, $iv, SecurityPolicy::Basic128Rsa15);
        expect($decrypted)->toBe($plaintext);
    });

    it('round-trips symmetric sign/verify with Basic128Rsa15 (SHA-1)', function () {
        $key = random_bytes(20);
        $data = random_bytes(100);

        $signature = $this->ms->symmetricSign($data, $key, SecurityPolicy::Basic128Rsa15);
        expect(strlen($signature))->toBe(20); // SHA-1 HMAC = 20 bytes

        $valid = $this->ms->symmetricVerify($data, $signature, $key, SecurityPolicy::Basic128Rsa15);
        expect($valid)->toBeTrue();
    });
});

describe('MessageSecurity key derivation', function () {

    beforeEach(function () {
        $this->ms = new MessageSecurity();
    });

    it('returns empty keys for None policy', function () {
        $keys = $this->ms->deriveKeys('secret', 'seed', SecurityPolicy::None);
        expect($keys['signingKey'])->toBe('');
        expect($keys['encryptingKey'])->toBe('');
        expect($keys['iv'])->toBe('');
    });

    it('derives correct key lengths for Basic256Sha256', function () {
        $secret = random_bytes(32);
        $seed = random_bytes(32);

        $keys = $this->ms->deriveKeys($secret, $seed, SecurityPolicy::Basic256Sha256);
        expect(strlen($keys['signingKey']))->toBe(32);    // DerivedSignatureKeyLength
        expect(strlen($keys['encryptingKey']))->toBe(32);  // DerivedKeyLength (AES-256)
        expect(strlen($keys['iv']))->toBe(16);             // SymmetricBlockSize
    });

    it('derives correct key lengths for Basic128Rsa15', function () {
        $secret = random_bytes(16);
        $seed = random_bytes(16);

        $keys = $this->ms->deriveKeys($secret, $seed, SecurityPolicy::Basic128Rsa15);
        expect(strlen($keys['signingKey']))->toBe(20);     // SHA-1 key
        expect(strlen($keys['encryptingKey']))->toBe(16);  // AES-128
        expect(strlen($keys['iv']))->toBe(16);
    });

    it('derives deterministic keys from same inputs', function () {
        $secret = random_bytes(32);
        $seed = random_bytes(32);

        $keys1 = $this->ms->deriveKeys($secret, $seed, SecurityPolicy::Basic256Sha256);
        $keys2 = $this->ms->deriveKeys($secret, $seed, SecurityPolicy::Basic256Sha256);

        expect($keys1)->toBe($keys2);
    });

    it('derives different keys from different inputs', function () {
        $secret = random_bytes(32);
        $seed1 = random_bytes(32);
        $seed2 = random_bytes(32);

        $keys1 = $this->ms->deriveKeys($secret, $seed1, SecurityPolicy::Basic256Sha256);
        $keys2 = $this->ms->deriveKeys($secret, $seed2, SecurityPolicy::Basic256Sha256);

        expect($keys1['signingKey'])->not->toBe($keys2['signingKey']);
    });

    it('derived keys can be used for symmetric encrypt/decrypt round-trip', function () {
        $serverNonce = random_bytes(32);
        $clientNonce = random_bytes(32);

        $keys = $this->ms->deriveKeys($serverNonce, $clientNonce, SecurityPolicy::Basic256Sha256);

        // Use derived keys for a symmetric round-trip
        $plaintext = random_bytes(64); // Must be multiple of block size
        $encrypted = $this->ms->symmetricEncrypt($plaintext, $keys['encryptingKey'], $keys['iv'], SecurityPolicy::Basic256Sha256);
        $decrypted = $this->ms->symmetricDecrypt($encrypted, $keys['encryptingKey'], $keys['iv'], SecurityPolicy::Basic256Sha256);
        expect($decrypted)->toBe($plaintext);
    });
});
