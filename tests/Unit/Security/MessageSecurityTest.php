<?php

declare(strict_types=1);

use PhpOpcua\Client\Security\CertificateManager;
use PhpOpcua\Client\Security\MessageSecurity;
use PhpOpcua\Client\Security\SecurityPolicy;

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

    describe('ECC operations', function () {

        it('generates P-256 ephemeral key pair with correct public key format', function () {
            $result = $this->ms->generateEphemeralKeyPair('prime256v1');
            expect($result['privateKey'])->toBeInstanceOf(OpenSSLAsymmetricKey::class);
            expect(strlen($result['publicKeyBytes']))->toBe(65);
            expect($result['publicKeyBytes'][0])->toBe("\x04");
        });

        it('generates P-384 ephemeral key pair with correct public key format', function () {
            $result = $this->ms->generateEphemeralKeyPair('secp384r1');
            expect($result['privateKey'])->toBeInstanceOf(OpenSSLAsymmetricKey::class);
            expect(strlen($result['publicKeyBytes']))->toBe(97);
            expect($result['publicKeyBytes'][0])->toBe("\x04");
        });

        it('computes ECDH shared secret for P-256', function () {
            $a = $this->ms->generateEphemeralKeyPair('prime256v1');
            $b = $this->ms->generateEphemeralKeyPair('prime256v1');
            $bPub = $this->ms->loadEcPublicKeyFromBytes($b['publicKeyBytes'], 'prime256v1');

            $secret = $this->ms->computeEcdhSharedSecret($a['privateKey'], $bPub);
            expect(strlen($secret))->toBe(32);
        });

        it('ECDH shared secret is symmetric (A-B == B-A)', function () {
            $a = $this->ms->generateEphemeralKeyPair('prime256v1');
            $b = $this->ms->generateEphemeralKeyPair('prime256v1');

            $aPub = $this->ms->loadEcPublicKeyFromBytes($a['publicKeyBytes'], 'prime256v1');
            $bPub = $this->ms->loadEcPublicKeyFromBytes($b['publicKeyBytes'], 'prime256v1');

            $secretAB = $this->ms->computeEcdhSharedSecret($a['privateKey'], $bPub);
            $secretBA = $this->ms->computeEcdhSharedSecret($b['privateKey'], $aPub);
            expect($secretAB)->toBe($secretBA);
        });

        it('computes ECDH shared secret for P-384', function () {
            $a = $this->ms->generateEphemeralKeyPair('secp384r1');
            $b = $this->ms->generateEphemeralKeyPair('secp384r1');
            $bPub = $this->ms->loadEcPublicKeyFromBytes($b['publicKeyBytes'], 'secp384r1');

            $secret = $this->ms->computeEcdhSharedSecret($a['privateKey'], $bPub);
            expect(strlen($secret))->toBe(48);
        });

        it('loads EC public key from bytes and round-trips', function () {
            $pair = $this->ms->generateEphemeralKeyPair('prime256v1');
            $loaded = $this->ms->loadEcPublicKeyFromBytes($pair['publicKeyBytes'], 'prime256v1');
            expect($loaded)->toBeInstanceOf(OpenSSLAsymmetricKey::class);

            $details = openssl_pkey_get_details($loaded);
            expect($details['type'])->toBe(OPENSSL_KEYTYPE_EC);
        });

        it('derives keys with HKDF for EccNistP256', function () {
            $sharedSecret = random_bytes(32);
            $info = random_bytes(64);
            $keys = $this->ms->deriveKeysHkdf($sharedSecret, '', $info, SecurityPolicy::EccNistP256);

            expect(strlen($keys['signingKey']))->toBe(32);
            expect(strlen($keys['encryptingKey']))->toBe(16);
            expect(strlen($keys['iv']))->toBe(16);
        });

        it('derives keys with HKDF for EccNistP384', function () {
            $sharedSecret = random_bytes(48);
            $info = random_bytes(64);
            $keys = $this->ms->deriveKeysHkdf($sharedSecret, '', $info, SecurityPolicy::EccNistP384);

            expect(strlen($keys['signingKey']))->toBe(48);
            expect(strlen($keys['encryptingKey']))->toBe(32);
            expect(strlen($keys['iv']))->toBe(16);
        });

        it('generates brainpoolP256r1 ephemeral key pair with correct public key format', function () {
            $result = $this->ms->generateEphemeralKeyPair('brainpoolP256r1');
            expect($result['privateKey'])->toBeInstanceOf(OpenSSLAsymmetricKey::class);
            expect(strlen($result['publicKeyBytes']))->toBe(65);
            expect($result['publicKeyBytes'][0])->toBe("\x04");
        });

        it('generates brainpoolP384r1 ephemeral key pair with correct public key format', function () {
            $result = $this->ms->generateEphemeralKeyPair('brainpoolP384r1');
            expect($result['privateKey'])->toBeInstanceOf(OpenSSLAsymmetricKey::class);
            expect(strlen($result['publicKeyBytes']))->toBe(97);
            expect($result['publicKeyBytes'][0])->toBe("\x04");
        });

        it('computes ECDH shared secret for brainpoolP256r1', function () {
            $a = $this->ms->generateEphemeralKeyPair('brainpoolP256r1');
            $b = $this->ms->generateEphemeralKeyPair('brainpoolP256r1');
            $bPub = $this->ms->loadEcPublicKeyFromBytes($b['publicKeyBytes'], 'brainpoolP256r1');

            $secret = $this->ms->computeEcdhSharedSecret($a['privateKey'], $bPub);
            expect(strlen($secret))->toBe(32);
        });

        it('ECDH shared secret is symmetric for brainpoolP256r1 (A-B == B-A)', function () {
            $a = $this->ms->generateEphemeralKeyPair('brainpoolP256r1');
            $b = $this->ms->generateEphemeralKeyPair('brainpoolP256r1');

            $aPub = $this->ms->loadEcPublicKeyFromBytes($a['publicKeyBytes'], 'brainpoolP256r1');
            $bPub = $this->ms->loadEcPublicKeyFromBytes($b['publicKeyBytes'], 'brainpoolP256r1');

            $secretAB = $this->ms->computeEcdhSharedSecret($a['privateKey'], $bPub);
            $secretBA = $this->ms->computeEcdhSharedSecret($b['privateKey'], $aPub);
            expect($secretAB)->toBe($secretBA);
        });

        it('computes ECDH shared secret for brainpoolP384r1', function () {
            $a = $this->ms->generateEphemeralKeyPair('brainpoolP384r1');
            $b = $this->ms->generateEphemeralKeyPair('brainpoolP384r1');
            $bPub = $this->ms->loadEcPublicKeyFromBytes($b['publicKeyBytes'], 'brainpoolP384r1');

            $secret = $this->ms->computeEcdhSharedSecret($a['privateKey'], $bPub);
            expect(strlen($secret))->toBe(48);
        });

        it('loads brainpoolP256r1 EC public key from bytes and round-trips', function () {
            $pair = $this->ms->generateEphemeralKeyPair('brainpoolP256r1');
            $loaded = $this->ms->loadEcPublicKeyFromBytes($pair['publicKeyBytes'], 'brainpoolP256r1');
            expect($loaded)->toBeInstanceOf(OpenSSLAsymmetricKey::class);

            $details = openssl_pkey_get_details($loaded);
            expect($details['type'])->toBe(OPENSSL_KEYTYPE_EC);
        });

        it('derives keys with HKDF for EccBrainpoolP256r1', function () {
            $sharedSecret = random_bytes(32);
            $info = random_bytes(64);
            $keys = $this->ms->deriveKeysHkdf($sharedSecret, '', $info, SecurityPolicy::EccBrainpoolP256r1);

            expect(strlen($keys['signingKey']))->toBe(32);
            expect(strlen($keys['encryptingKey']))->toBe(16);
            expect(strlen($keys['iv']))->toBe(16);
        });

        it('derives keys with HKDF for EccBrainpoolP384r1', function () {
            $sharedSecret = random_bytes(48);
            $info = random_bytes(64);
            $keys = $this->ms->deriveKeysHkdf($sharedSecret, '', $info, SecurityPolicy::EccBrainpoolP384r1);

            expect(strlen($keys['signingKey']))->toBe(48);
            expect(strlen($keys['encryptingKey']))->toBe(32);
            expect(strlen($keys['iv']))->toBe(16);
        });

        it('ECDSA sign and verify work with brainpoolP256r1', function () {
            $pair = $this->ms->generateEphemeralKeyPair('brainpoolP256r1');
            $data = 'test data for ECDSA signing';

            $signature = '';
            openssl_sign($data, $signature, $pair['privateKey'], 'sha256');
            expect(strlen($signature))->toBeGreaterThan(60)->toBeLessThan(80);

            $pubKey = $this->ms->loadEcPublicKeyFromBytes($pair['publicKeyBytes'], 'brainpoolP256r1');
            $valid = openssl_verify($data, $signature, $pubKey, 'sha256');
            expect($valid)->toBe(1);
        });

        it('ECDSA sign and verify work with brainpoolP384r1', function () {
            $pair = $this->ms->generateEphemeralKeyPair('brainpoolP384r1');
            $data = 'test data for ECDSA signing';

            $signature = '';
            openssl_sign($data, $signature, $pair['privateKey'], 'sha384');
            expect(strlen($signature))->toBeGreaterThan(90)->toBeLessThan(115);

            $pubKey = $this->ms->loadEcPublicKeyFromBytes($pair['publicKeyBytes'], 'brainpoolP384r1');
            $valid = openssl_verify($data, $signature, $pubKey, 'sha384');
            expect($valid)->toBe(1);
        });

        it('ECDSA sign and verify work with P-256', function () {
            $pair = $this->ms->generateEphemeralKeyPair('prime256v1');
            $data = 'test data for ECDSA signing';

            $signature = '';
            openssl_sign($data, $signature, $pair['privateKey'], 'sha256');
            expect(strlen($signature))->toBeGreaterThan(60)->toBeLessThan(80);

            $pubKey = $this->ms->loadEcPublicKeyFromBytes($pair['publicKeyBytes'], 'prime256v1');
            $valid = openssl_verify($data, $signature, $pubKey, 'sha256');
            expect($valid)->toBe(1);
        });

    });
});

describe('MessageSecurity error handling', function () {

    beforeEach(function () {
        $this->ms = new MessageSecurity();
    });

    it('generateEphemeralKeyPair throws for unsupported curve', function () {
        expect(fn () => $this->ms->generateEphemeralKeyPair('secp521r1'))
            ->toThrow(PhpOpcua\Client\Exception\SecurityException::class, 'Unsupported curve: secp521r1');
    });

    it('loadEcPublicKeyFromBytes throws for non-uncompressed format', function () {
        $compressedKey = "\x02" . str_repeat("\x01", 32);
        expect(fn () => $this->ms->loadEcPublicKeyFromBytes($compressedKey, 'prime256v1'))
            ->toThrow(PhpOpcua\Client\Exception\SecurityException::class, 'uncompressed format');
    });

    it('loadEcPublicKeyFromBytes throws for unsupported curve', function () {
        $fakeKey = "\x04" . str_repeat("\x01", 64);
        expect(fn () => $this->ms->loadEcPublicKeyFromBytes($fakeKey, 'secp521r1'))
            ->toThrow(PhpOpcua\Client\Exception\SecurityException::class, 'Unsupported curve: secp521r1');
    });

});

describe('MessageSecurity ECDSA DER/Raw conversion', function () {

    beforeEach(function () {
        $this->ms = new MessageSecurity();
    });

    it('round-trips ecdsaRawToDer and ecdsaDerToRaw for P-256', function () {
        $pair = $this->ms->generateEphemeralKeyPair('prime256v1');
        $data = 'test data for ecdsa conversion';

        $derSignature = '';
        openssl_sign($data, $derSignature, $pair['privateKey'], 'sha256');

        $raw = $this->ms->ecdsaDerToRaw($derSignature, 32);
        expect(strlen($raw))->toBe(64);

        $derAgain = $this->ms->ecdsaRawToDer($raw, 32);

        $pubKey = $this->ms->loadEcPublicKeyFromBytes($pair['publicKeyBytes'], 'prime256v1');
        $valid = openssl_verify($data, $derAgain, $pubKey, 'sha256');
        expect($valid)->toBe(1);
    });

    it('round-trips ecdsaRawToDer and ecdsaDerToRaw for P-384', function () {
        $pair = $this->ms->generateEphemeralKeyPair('secp384r1');
        $data = 'test data for ecdsa 384';

        $derSignature = '';
        openssl_sign($data, $derSignature, $pair['privateKey'], 'sha384');

        $raw = $this->ms->ecdsaDerToRaw($derSignature, 48);
        expect(strlen($raw))->toBe(96);

        $derAgain = $this->ms->ecdsaRawToDer($raw, 48);

        $pubKey = $this->ms->loadEcPublicKeyFromBytes($pair['publicKeyBytes'], 'secp384r1');
        $valid = openssl_verify($data, $derAgain, $pubKey, 'sha384');
        expect($valid)->toBe(1);
    });

    it('ecdsaDerToRaw throws for missing SEQUENCE tag', function () {
        expect(fn () => $this->ms->ecdsaDerToRaw("\x31\x00", 32))
            ->toThrow(PhpOpcua\Client\Exception\SecurityException::class, 'missing SEQUENCE tag');
    });

    it('ecdsaDerToRaw throws for missing INTEGER tag for r', function () {
        $invalid = "\x30\x04\x03\x01\x01\x02";
        expect(fn () => $this->ms->ecdsaDerToRaw($invalid, 32))
            ->toThrow(PhpOpcua\Client\Exception\SecurityException::class, 'missing INTEGER tag for r');
    });

    it('ecdsaDerToRaw throws for missing INTEGER tag for s', function () {
        $invalid = "\x30\x06\x02\x01\x01\x03\x01\x01";
        expect(fn () => $this->ms->ecdsaDerToRaw($invalid, 32))
            ->toThrow(PhpOpcua\Client\Exception\SecurityException::class, 'missing INTEGER tag for s');
    });

    it('ecdsaRawToDer handles high-bit r and s values', function () {
        $r = "\x80" . str_repeat("\x01", 31);
        $s = "\x90" . str_repeat("\x02", 31);
        $raw = $r . $s;

        $der = $this->ms->ecdsaRawToDer($raw, 32);
        expect(ord($der[0]))->toBe(0x30);

        $rawBack = $this->ms->ecdsaDerToRaw($der, 32);
        expect($rawBack)->toBe($raw);
    });

});

describe('MessageSecurity ensureNotFalse', function () {

    it('symmetricEncrypt throws SecurityException for invalid cipher params', function () {
        $ms = new MessageSecurity();
        // AES-256-CBC requires 32-byte key and 16-byte IV; use wrong sizes to trigger OpenSSL failure
        expect(fn () => $ms->symmetricEncrypt('data', 'short', 'x', SecurityPolicy::Basic256Sha256))
            ->toThrow(PhpOpcua\Client\Exception\SecurityException::class);
    });

    it('symmetricDecrypt throws SecurityException for invalid data', function () {
        $ms = new MessageSecurity();
        expect(fn () => $ms->symmetricDecrypt('not-encrypted', 'short', 'x', SecurityPolicy::Basic256Sha256))
            ->toThrow(PhpOpcua\Client\Exception\SecurityException::class);
    });

});

describe('MessageSecurity derEncodeLength', function () {

    beforeEach(function () {
        $this->ms = new class() extends MessageSecurity {
            public function callDerEncodeLength(int $length): string
            {
                return $this->derEncodeLength($length);
            }
        };
    });

    it('encodes short length (< 128) as single byte', function () {
        expect($this->ms->callDerEncodeLength(0))->toBe("\x00");
        expect($this->ms->callDerEncodeLength(127))->toBe("\x7F");
    });

    it('encodes length 128 in long form', function () {
        $encoded = $this->ms->callDerEncodeLength(128);
        expect($encoded)->toBe("\x81\x80");
    });

    it('encodes length 256 in long form with two bytes', function () {
        $encoded = $this->ms->callDerEncodeLength(256);
        expect($encoded)->toBe("\x82\x01\x00");
    });

    it('encodes length 65535 in long form', function () {
        $encoded = $this->ms->callDerEncodeLength(65535);
        expect($encoded)->toBe("\x82\xFF\xFF");
    });

});
