<?php

declare(strict_types=1);

use PhpOpcua\Client\Exception\ConfigurationException;
use PhpOpcua\Client\Exception\SecurityException;
use PhpOpcua\Client\Security\CertificateManager;

describe('CertificateManager', function () {

    it('throws ConfigurationException for non-existent certificate file', function () {
        $cm = new CertificateManager();
        expect(fn () => $cm->loadCertificatePem('/nonexistent/cert.pem'))
            ->toThrow(ConfigurationException::class);
    });

    it('throws ConfigurationException for non-existent DER certificate file', function () {
        $cm = new CertificateManager();
        expect(fn () => $cm->loadCertificateDer('/nonexistent/cert.der'))
            ->toThrow(ConfigurationException::class);
    });

    it('throws ConfigurationException for non-existent private key file', function () {
        $cm = new CertificateManager();
        expect(fn () => $cm->loadPrivateKeyPem('/nonexistent/key.pem'))
            ->toThrow(ConfigurationException::class);
    });

    it('getThumbprint returns 20-byte SHA-1 hash', function () {
        $cm = new CertificateManager();
        $thumbprint = $cm->getThumbprint('some-der-bytes');
        expect(strlen($thumbprint))->toBe(20);
        // SHA-1 of "some-der-bytes" should be deterministic
        expect($thumbprint)->toBe(sha1('some-der-bytes', true));
    });

    it('loads a self-signed PEM certificate', function () {
        // Generate a self-signed certificate for testing
        $privKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['CN' => 'test'], $privKey);
        openssl_csr_sign($csr, null, $privKey, 365);
        openssl_x509_export(openssl_csr_sign($csr, null, $privKey, 365), $certPem);

        $tmpFile = tempnam(sys_get_temp_dir(), 'opcua_test_cert_');
        file_put_contents($tmpFile, $certPem);

        try {
            $cm = new CertificateManager();
            $der = $cm->loadCertificatePem($tmpFile);
            expect(strlen($der))->toBeGreaterThan(100);
            // DER should start with SEQUENCE tag
            expect(ord($der[0]))->toBe(0x30);
        } finally {
            unlink($tmpFile);
        }
    });

    it('loads a private key PEM', function () {
        $privKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($privKey, $keyPem);

        $tmpFile = tempnam(sys_get_temp_dir(), 'opcua_test_key_');
        file_put_contents($tmpFile, $keyPem);

        try {
            $cm = new CertificateManager();
            $key = $cm->loadPrivateKeyPem($tmpFile);
            expect($key)->toBeInstanceOf(OpenSSLAsymmetricKey::class);
        } finally {
            unlink($tmpFile);
        }
    });

    it('throws SecurityException for invalid private key PEM', function () {
        $tmpFile = tempnam(sys_get_temp_dir(), 'opcua_test_badkey_');
        file_put_contents($tmpFile, 'not a real key');

        try {
            $cm = new CertificateManager();
            expect(fn () => $cm->loadPrivateKeyPem($tmpFile))
                ->toThrow(SecurityException::class);
        } finally {
            unlink($tmpFile);
        }
    });

    it('getPublicKeyLength returns key size in bytes', function () {
        $privKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['CN' => 'test'], $privKey);
        $cert = openssl_csr_sign($csr, null, $privKey, 365);
        openssl_x509_export($cert, $certPem);

        // Convert PEM to DER
        $cm = new CertificateManager();
        $tmpFile = tempnam(sys_get_temp_dir(), 'opcua_test_cert_');
        file_put_contents($tmpFile, $certPem);

        try {
            $der = $cm->loadCertificatePem($tmpFile);
            $keyLength = $cm->getPublicKeyLength($der);
            expect($keyLength)->toBe(256); // 2048 bits / 8
        } finally {
            unlink($tmpFile);
        }
    });

    it('getPublicKeyFromCert returns OpenSSLAsymmetricKey', function () {
        $privKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['CN' => 'test'], $privKey);
        $cert = openssl_csr_sign($csr, null, $privKey, 365);
        openssl_x509_export($cert, $certPem);

        $cm = new CertificateManager();
        $tmpFile = tempnam(sys_get_temp_dir(), 'opcua_test_cert_');
        file_put_contents($tmpFile, $certPem);

        try {
            $der = $cm->loadCertificatePem($tmpFile);
            $pubKey = $cm->getPublicKeyFromCert($der);
            expect($pubKey)->toBeInstanceOf(OpenSSLAsymmetricKey::class);
        } finally {
            unlink($tmpFile);
        }
    });

    it('throws SecurityException for invalid DER in getPublicKeyLength', function () {
        $cm = new CertificateManager();
        expect(fn () => $cm->getPublicKeyLength('invalid-der'))
            ->toThrow(SecurityException::class);
    });

    it('throws SecurityException for invalid DER in getPublicKeyFromCert', function () {
        $cm = new CertificateManager();
        expect(fn () => $cm->getPublicKeyFromCert('invalid-der'))
            ->toThrow(SecurityException::class);
    });

    it('getApplicationUri returns null for invalid DER', function () {
        $cm = new CertificateManager();
        $result = $cm->getApplicationUri('invalid-der');
        expect($result)->toBeNull();
    });

    it('getApplicationUri returns null for cert without SAN', function () {
        $privKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['CN' => 'test'], $privKey);
        $cert = openssl_csr_sign($csr, null, $privKey, 365);
        openssl_x509_export($cert, $certPem);

        $cm = new CertificateManager();
        $tmpFile = tempnam(sys_get_temp_dir(), 'opcua_test_cert_');
        file_put_contents($tmpFile, $certPem);

        try {
            $der = $cm->loadCertificatePem($tmpFile);
            // A basic self-signed cert without SAN extension should return null
            $result = $cm->getApplicationUri($der);
            expect($result)->toBeNull();
        } finally {
            unlink($tmpFile);
        }
    });

    describe('generateSelfSignedCertificate', function () {

        it('returns a valid DER certificate and private key', function () {
            $cm = new CertificateManager();
            $result = $cm->generateSelfSignedCertificate();

            expect($result)->toHaveKeys(['certDer', 'privateKey']);
            expect($result['privateKey'])->toBeInstanceOf(OpenSSLAsymmetricKey::class);
            // DER should start with SEQUENCE tag (0x30)
            expect(ord($result['certDer'][0]))->toBe(0x30);
            expect(strlen($result['certDer']))->toBeGreaterThan(100);
        });

        it('generates a 2048-bit RSA key', function () {
            $cm = new CertificateManager();
            $result = $cm->generateSelfSignedCertificate();

            $keyLength = $cm->getPublicKeyLength($result['certDer']);
            expect($keyLength)->toBe(256); // 2048 bits / 8
        });

        it('includes the application URI in the SAN extension', function () {
            $cm = new CertificateManager();
            $customUri = 'urn:my-custom-app';
            $result = $cm->generateSelfSignedCertificate($customUri);

            $applicationUri = $cm->getApplicationUri($result['certDer']);
            expect($applicationUri)->toBe($customUri);
        });

        it('uses default application URI when none specified', function () {
            $cm = new CertificateManager();
            $result = $cm->generateSelfSignedCertificate();

            $applicationUri = $cm->getApplicationUri($result['certDer']);
            expect($applicationUri)->toBe('urn:opcua-client');
        });

        it('generates a valid thumbprint from the certificate', function () {
            $cm = new CertificateManager();
            $result = $cm->generateSelfSignedCertificate();

            $thumbprint = $cm->getThumbprint($result['certDer']);
            expect(strlen($thumbprint))->toBe(20);
        });

        it('can extract public key from the generated certificate', function () {
            $cm = new CertificateManager();
            $result = $cm->generateSelfSignedCertificate();

            $pubKey = $cm->getPublicKeyFromCert($result['certDer']);
            expect($pubKey)->toBeInstanceOf(OpenSSLAsymmetricKey::class);
        });

        it('generates unique certificates on each call', function () {
            $cm = new CertificateManager();
            $result1 = $cm->generateSelfSignedCertificate();
            $result2 = $cm->generateSelfSignedCertificate();

            expect($result1['certDer'])->not->toBe($result2['certDer']);
        });

        it('does not write permanent files to disk', function () {
            $tmpDir = sys_get_temp_dir();
            $beforeFiles = glob($tmpDir . '/opcua_ssl_*');

            $cm = new CertificateManager();
            $cm->generateSelfSignedCertificate();

            $afterFiles = glob($tmpDir . '/opcua_ssl_*');
            expect($afterFiles)->toBe($beforeFiles ?: []);
        });

    });

    it('loadCertificateDer returns raw DER bytes', function () {
        $cm = new CertificateManager();
        $generated = $cm->generateSelfSignedCertificate();

        $tmpFile = tempnam(sys_get_temp_dir(), 'opcua_test_der_');
        file_put_contents($tmpFile, $generated['certDer']);

        try {
            $der = $cm->loadCertificateDer($tmpFile);
            expect($der)->toBe($generated['certDer']);
        } finally {
            unlink($tmpFile);
        }
    });

    it('getApplicationUri returns null for SAN without URI', function () {
        // Create a cert with SAN containing only DNS, no URI
        $privKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        $configContent = "[req]\n"
            . "distinguished_name = req_dn\n"
            . "x509_extensions = v3_req\n"
            . "prompt = no\n"
            . "[req_dn]\n"
            . "CN = Test\n"
            . "[v3_req]\n"
            . "subjectAltName = DNS:localhost\n";

        $tmpConfig = tempnam(sys_get_temp_dir(), 'opcua_test_cnf_');
        file_put_contents($tmpConfig, $configContent);

        $csr = openssl_csr_new(['CN' => 'Test'], $privKey, ['config' => $tmpConfig]);
        $cert = openssl_csr_sign($csr, null, $privKey, 365, ['config' => $tmpConfig, 'x509_extensions' => 'v3_req']);
        openssl_x509_export($cert, $certPem);
        unlink($tmpConfig);

        $cm = new CertificateManager();
        $tmpFile = tempnam(sys_get_temp_dir(), 'opcua_test_cert_');
        file_put_contents($tmpFile, $certPem);

        try {
            $der = $cm->loadCertificatePem($tmpFile);
            $result = $cm->getApplicationUri($der);
            expect($result)->toBeNull();
        } finally {
            unlink($tmpFile);
        }
    });

    it('getKeyType returns OPENSSL_KEYTYPE_RSA for RSA certificate', function () {
        $cm = new CertificateManager();
        $generated = $cm->generateSelfSignedCertificate();
        $keyType = $cm->getKeyType($generated['certDer']);
        expect($keyType)->toBe(OPENSSL_KEYTYPE_RSA);
    });

    it('getKeyType returns OPENSSL_KEYTYPE_EC for ECC certificate', function () {
        $cm = new CertificateManager();
        $generated = $cm->generateSelfSignedCertificate('urn:test-ecc', 'prime256v1');
        $keyType = $cm->getKeyType($generated['certDer']);
        expect($keyType)->toBe(OPENSSL_KEYTYPE_EC);
    });

    it('getKeyType throws SecurityException for invalid DER', function () {
        $cm = new CertificateManager();
        expect(fn () => $cm->getKeyType('invalid-der'))
            ->toThrow(SecurityException::class);
    });

    describe('generateSelfSignedCertificate ECC', function () {

        it('generates ECC P-256 certificate', function () {
            $cm = new CertificateManager();
            $result = $cm->generateSelfSignedCertificate('urn:ecc-test', 'prime256v1');

            expect($result)->toHaveKeys(['certDer', 'privateKey']);
            expect($result['privateKey'])->toBeInstanceOf(OpenSSLAsymmetricKey::class);
            expect(ord($result['certDer'][0]))->toBe(0x30);

            $keyType = $cm->getKeyType($result['certDer']);
            expect($keyType)->toBe(OPENSSL_KEYTYPE_EC);
        });

        it('generates ECC P-384 certificate with sha384 digest', function () {
            $cm = new CertificateManager();
            $result = $cm->generateSelfSignedCertificate('urn:ecc-384', 'secp384r1');

            expect($result['privateKey'])->toBeInstanceOf(OpenSSLAsymmetricKey::class);
            $keyType = $cm->getKeyType($result['certDer']);
            expect($keyType)->toBe(OPENSSL_KEYTYPE_EC);
        });

        it('generates ECC brainpoolP256r1 certificate', function () {
            $cm = new CertificateManager();
            $result = $cm->generateSelfSignedCertificate('urn:ecc-bp256', 'brainpoolP256r1');

            expect($result['privateKey'])->toBeInstanceOf(OpenSSLAsymmetricKey::class);
            $keyType = $cm->getKeyType($result['certDer']);
            expect($keyType)->toBe(OPENSSL_KEYTYPE_EC);
        });

        it('generates ECC brainpoolP384r1 certificate with sha384 digest', function () {
            $cm = new CertificateManager();
            $result = $cm->generateSelfSignedCertificate('urn:ecc-bp384', 'brainpoolP384r1');

            expect($result['privateKey'])->toBeInstanceOf(OpenSSLAsymmetricKey::class);
            $keyType = $cm->getKeyType($result['certDer']);
            expect($keyType)->toBe(OPENSSL_KEYTYPE_EC);
        });

        it('includes application URI in ECC certificate SAN', function () {
            $cm = new CertificateManager();
            $result = $cm->generateSelfSignedCertificate('urn:my-ecc-app', 'prime256v1');
            $uri = $cm->getApplicationUri($result['certDer']);
            expect($uri)->toBe('urn:my-ecc-app');
        });

    });

    it('throws SecurityException for bad PEM in pemToDer', function () {
        $tmpFile = tempnam(sys_get_temp_dir(), 'opcua_test_badpem_');
        // Write invalid base64 content between PEM headers
        file_put_contents($tmpFile, "-----BEGIN CERTIFICATE-----\n!!!invalid!!!\n-----END CERTIFICATE-----\n");

        try {
            $cm = new CertificateManager();
            expect(fn () => $cm->loadCertificatePem($tmpFile))
                ->toThrow(SecurityException::class);
        } finally {
            unlink($tmpFile);
        }
    });
});
