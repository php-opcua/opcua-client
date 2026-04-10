<?php

declare(strict_types=1);

use PhpOpcua\Client\Security\SecurityPolicy;

describe('SecurityPolicy', function () {

    it('None returns empty/zero for all methods', function () {
        $p = SecurityPolicy::None;
        expect($p->getSymmetricEncryptionAlgorithm())->toBe('');
        expect($p->getSymmetricSignatureAlgorithm())->toBe('');
        expect($p->getSymmetricKeyLength())->toBe(0);
        expect($p->getSymmetricBlockSize())->toBe(1);
        expect($p->getSymmetricSignatureSize())->toBe(0);
        expect($p->getAsymmetricEncryptionPadding())->toBe(0);
        expect($p->getAsymmetricSignatureAlgorithm())->toBe('');
        expect($p->getMinAsymmetricKeyLength())->toBe(0);
        expect($p->getDerivedKeyLength())->toBe(0);
        expect($p->getDerivedSignatureKeyLength())->toBe(0);
        expect($p->getKeyDerivationAlgorithm())->toBe('');
        expect($p->getAsymmetricPaddingOverhead())->toBe(0);
        expect($p->getAsymmetricEncryptionUri())->toBe('');
        expect($p->getAsymmetricSignatureUri())->toBe('');
    });

    it('Basic128Rsa15 returns correct values', function () {
        $p = SecurityPolicy::Basic128Rsa15;
        expect($p->getSymmetricEncryptionAlgorithm())->toBe('aes-128-cbc');
        expect($p->getSymmetricSignatureAlgorithm())->toBe('sha1');
        expect($p->getSymmetricKeyLength())->toBe(16);
        expect($p->getSymmetricBlockSize())->toBe(16);
        expect($p->getSymmetricSignatureSize())->toBe(20);
        expect($p->getAsymmetricEncryptionPadding())->toBe(OPENSSL_PKCS1_PADDING);
        expect($p->getAsymmetricSignatureAlgorithm())->toBe(OPENSSL_ALGO_SHA1);
        expect($p->getMinAsymmetricKeyLength())->toBe(1024);
        expect($p->getDerivedKeyLength())->toBe(16);
        expect($p->getDerivedSignatureKeyLength())->toBe(20);
        expect($p->getKeyDerivationAlgorithm())->toBe('sha1');
        expect($p->getAsymmetricPaddingOverhead())->toBe(11);
        expect($p->getAsymmetricEncryptionUri())->toContain('rsa-1_5');
        expect($p->getAsymmetricSignatureUri())->toContain('rsa-sha1');
    });

    it('Basic256 returns correct values', function () {
        $p = SecurityPolicy::Basic256;
        expect($p->getSymmetricEncryptionAlgorithm())->toBe('aes-256-cbc');
        expect($p->getSymmetricSignatureAlgorithm())->toBe('sha1');
        expect($p->getSymmetricKeyLength())->toBe(32);
        expect($p->getSymmetricSignatureSize())->toBe(20);
        expect($p->getAsymmetricEncryptionPadding())->toBe(OPENSSL_PKCS1_OAEP_PADDING);
        expect($p->getAsymmetricSignatureAlgorithm())->toBe(OPENSSL_ALGO_SHA1);
        expect($p->getMinAsymmetricKeyLength())->toBe(1024);
        expect($p->getDerivedSignatureKeyLength())->toBe(20);
        expect($p->getKeyDerivationAlgorithm())->toBe('sha1');
        expect($p->getAsymmetricPaddingOverhead())->toBe(42);
        expect($p->getAsymmetricEncryptionUri())->toContain('rsa-oaep');
        expect($p->getAsymmetricSignatureUri())->toContain('rsa-sha1');
    });

    it('Basic256Sha256 returns correct values', function () {
        $p = SecurityPolicy::Basic256Sha256;
        expect($p->getSymmetricEncryptionAlgorithm())->toBe('aes-256-cbc');
        expect($p->getSymmetricSignatureAlgorithm())->toBe('sha256');
        expect($p->getSymmetricKeyLength())->toBe(32);
        expect($p->getSymmetricSignatureSize())->toBe(32);
        expect($p->getAsymmetricEncryptionPadding())->toBe(OPENSSL_PKCS1_OAEP_PADDING);
        expect($p->getAsymmetricSignatureAlgorithm())->toBe(OPENSSL_ALGO_SHA256);
        expect($p->getMinAsymmetricKeyLength())->toBe(2048);
        expect($p->getDerivedSignatureKeyLength())->toBe(32);
        expect($p->getKeyDerivationAlgorithm())->toBe('sha256');
        expect($p->getAsymmetricPaddingOverhead())->toBe(42);
        expect($p->getAsymmetricEncryptionUri())->toContain('rsa-oaep');
        expect($p->getAsymmetricSignatureUri())->toContain('rsa-sha256');
    });

    it('Aes128Sha256RsaOaep returns correct values', function () {
        $p = SecurityPolicy::Aes128Sha256RsaOaep;
        expect($p->getSymmetricEncryptionAlgorithm())->toBe('aes-128-cbc');
        expect($p->getSymmetricSignatureAlgorithm())->toBe('sha256');
        expect($p->getSymmetricKeyLength())->toBe(16);
        expect($p->getSymmetricSignatureSize())->toBe(32);
        expect($p->getMinAsymmetricKeyLength())->toBe(2048);
        expect($p->getDerivedSignatureKeyLength())->toBe(32);
        expect($p->getKeyDerivationAlgorithm())->toBe('sha256');
        expect($p->getAsymmetricPaddingOverhead())->toBe(42);
    });

    it('Aes256Sha256RsaPss returns correct values', function () {
        $p = SecurityPolicy::Aes256Sha256RsaPss;
        expect($p->getSymmetricEncryptionAlgorithm())->toBe('aes-256-cbc');
        expect($p->getSymmetricSignatureAlgorithm())->toBe('sha256');
        expect($p->getSymmetricKeyLength())->toBe(32);
        expect($p->getSymmetricSignatureSize())->toBe(32);
        expect($p->getMinAsymmetricKeyLength())->toBe(2048);
        expect($p->getDerivedSignatureKeyLength())->toBe(32);
        expect($p->getKeyDerivationAlgorithm())->toBe('sha256');
        expect($p->getAsymmetricPaddingOverhead())->toBe(66);
        expect($p->getAsymmetricEncryptionUri())->toContain('rsa-oaep-sha2-256');
        expect($p->getAsymmetricSignatureUri())->toContain('rsa-pss-sha2-256');
    });

    it('EccNistP256 returns correct values', function () {
        $p = SecurityPolicy::EccNistP256;
        expect($p->getSymmetricEncryptionAlgorithm())->toBe('aes-128-cbc');
        expect($p->getSymmetricSignatureAlgorithm())->toBe('sha256');
        expect($p->getSymmetricKeyLength())->toBe(16);
        expect($p->getSymmetricBlockSize())->toBe(16);
        expect($p->getSymmetricSignatureSize())->toBe(32);
        expect($p->getAsymmetricEncryptionPadding())->toBe(0);
        expect($p->getAsymmetricSignatureAlgorithm())->toBe('sha256');
        expect($p->getMinAsymmetricKeyLength())->toBe(256);
        expect($p->getDerivedKeyLength())->toBe(16);
        expect($p->getDerivedSignatureKeyLength())->toBe(32);
        expect($p->getKeyDerivationAlgorithm())->toBe('sha256');
        expect($p->getAsymmetricPaddingOverhead())->toBe(0);
        expect($p->getAsymmetricEncryptionUri())->toBe('');
        expect($p->getAsymmetricSignatureUri())->toContain('ecdsa-sha256');
        expect($p->isEcc())->toBeTrue();
        expect($p->getEcdhCurveName())->toBe('prime256v1');
        expect($p->getEphemeralKeyLength())->toBe(64);
    });

    it('EccNistP384 returns correct values', function () {
        $p = SecurityPolicy::EccNistP384;
        expect($p->getSymmetricEncryptionAlgorithm())->toBe('aes-256-cbc');
        expect($p->getSymmetricSignatureAlgorithm())->toBe('sha384');
        expect($p->getSymmetricKeyLength())->toBe(32);
        expect($p->getSymmetricBlockSize())->toBe(16);
        expect($p->getSymmetricSignatureSize())->toBe(48);
        expect($p->getAsymmetricEncryptionPadding())->toBe(0);
        expect($p->getAsymmetricSignatureAlgorithm())->toBe('sha384');
        expect($p->getMinAsymmetricKeyLength())->toBe(384);
        expect($p->getDerivedKeyLength())->toBe(32);
        expect($p->getDerivedSignatureKeyLength())->toBe(48);
        expect($p->getKeyDerivationAlgorithm())->toBe('sha384');
        expect($p->getAsymmetricPaddingOverhead())->toBe(0);
        expect($p->getAsymmetricEncryptionUri())->toBe('');
        expect($p->getAsymmetricSignatureUri())->toContain('ecdsa-sha384');
        expect($p->isEcc())->toBeTrue();
        expect($p->getEcdhCurveName())->toBe('secp384r1');
        expect($p->getEphemeralKeyLength())->toBe(96);
    });

    it('EccBrainpoolP256r1 returns correct values', function () {
        $p = SecurityPolicy::EccBrainpoolP256r1;
        expect($p->getSymmetricEncryptionAlgorithm())->toBe('aes-128-cbc');
        expect($p->getSymmetricSignatureAlgorithm())->toBe('sha256');
        expect($p->getSymmetricKeyLength())->toBe(16);
        expect($p->getSymmetricBlockSize())->toBe(16);
        expect($p->getSymmetricSignatureSize())->toBe(32);
        expect($p->getAsymmetricEncryptionPadding())->toBe(0);
        expect($p->getAsymmetricSignatureAlgorithm())->toBe('sha256');
        expect($p->getMinAsymmetricKeyLength())->toBe(256);
        expect($p->getDerivedKeyLength())->toBe(16);
        expect($p->getDerivedSignatureKeyLength())->toBe(32);
        expect($p->getKeyDerivationAlgorithm())->toBe('sha256');
        expect($p->getAsymmetricPaddingOverhead())->toBe(0);
        expect($p->getAsymmetricEncryptionUri())->toBe('');
        expect($p->getAsymmetricSignatureUri())->toContain('ecdsa-sha256');
        expect($p->isEcc())->toBeTrue();
        expect($p->getEcdhCurveName())->toBe('brainpoolP256r1');
        expect($p->getEphemeralKeyLength())->toBe(64);
    });

    it('EccBrainpoolP384r1 returns correct values', function () {
        $p = SecurityPolicy::EccBrainpoolP384r1;
        expect($p->getSymmetricEncryptionAlgorithm())->toBe('aes-256-cbc');
        expect($p->getSymmetricSignatureAlgorithm())->toBe('sha384');
        expect($p->getSymmetricKeyLength())->toBe(32);
        expect($p->getSymmetricBlockSize())->toBe(16);
        expect($p->getSymmetricSignatureSize())->toBe(48);
        expect($p->getAsymmetricEncryptionPadding())->toBe(0);
        expect($p->getAsymmetricSignatureAlgorithm())->toBe('sha384');
        expect($p->getMinAsymmetricKeyLength())->toBe(384);
        expect($p->getDerivedKeyLength())->toBe(32);
        expect($p->getDerivedSignatureKeyLength())->toBe(48);
        expect($p->getKeyDerivationAlgorithm())->toBe('sha384');
        expect($p->getAsymmetricPaddingOverhead())->toBe(0);
        expect($p->getAsymmetricEncryptionUri())->toBe('');
        expect($p->getAsymmetricSignatureUri())->toContain('ecdsa-sha384');
        expect($p->isEcc())->toBeTrue();
        expect($p->getEcdhCurveName())->toBe('brainpoolP384r1');
        expect($p->getEphemeralKeyLength())->toBe(96);
    });

    it('RSA policies are not ECC', function () {
        expect(SecurityPolicy::None->isEcc())->toBeFalse();
        expect(SecurityPolicy::Basic256Sha256->isEcc())->toBeFalse();
        expect(SecurityPolicy::Aes256Sha256RsaPss->isEcc())->toBeFalse();
    });

    it('policy URIs are correct', function () {
        expect(SecurityPolicy::None->value)->toBe('http://opcfoundation.org/UA/SecurityPolicy#None');
        expect(SecurityPolicy::Basic256Sha256->value)->toBe('http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256');
    });

    it('getDerivedKeyLength matches getSymmetricKeyLength', function () {
        foreach (SecurityPolicy::cases() as $policy) {
            expect($policy->getDerivedKeyLength())->toBe($policy->getSymmetricKeyLength());
        }
    });
});
