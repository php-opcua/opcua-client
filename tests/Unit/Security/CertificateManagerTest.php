<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Exception\ConfigurationException;
use Gianfriaur\OpcuaPhpClient\Exception\SecurityException;
use Gianfriaur\OpcuaPhpClient\Security\CertificateManager;

describe('CertificateManager', function () {

    it('throws ConfigurationException for non-existent certificate file', function () {
        $cm = new CertificateManager();
        expect(fn() => $cm->loadCertificatePem('/nonexistent/cert.pem'))
            ->toThrow(ConfigurationException::class);
    });

    it('throws ConfigurationException for non-existent DER certificate file', function () {
        $cm = new CertificateManager();
        expect(fn() => $cm->loadCertificateDer('/nonexistent/cert.der'))
            ->toThrow(ConfigurationException::class);
    });

    it('throws ConfigurationException for non-existent private key file', function () {
        $cm = new CertificateManager();
        expect(fn() => $cm->loadPrivateKeyPem('/nonexistent/key.pem'))
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
            expect(fn() => $cm->loadPrivateKeyPem($tmpFile))
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
        expect(fn() => $cm->getPublicKeyLength('invalid-der'))
            ->toThrow(SecurityException::class);
    });

    it('throws SecurityException for invalid DER in getPublicKeyFromCert', function () {
        $cm = new CertificateManager();
        expect(fn() => $cm->getPublicKeyFromCert('invalid-der'))
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

    it('throws SecurityException for bad PEM in pemToDer', function () {
        $tmpFile = tempnam(sys_get_temp_dir(), 'opcua_test_badpem_');
        // Write invalid base64 content between PEM headers
        file_put_contents($tmpFile, "-----BEGIN CERTIFICATE-----\n!!!invalid!!!\n-----END CERTIFICATE-----\n");

        try {
            $cm = new CertificateManager();
            expect(fn() => $cm->loadCertificatePem($tmpFile))
                ->toThrow(SecurityException::class);
        } finally {
            unlink($tmpFile);
        }
    });
});
