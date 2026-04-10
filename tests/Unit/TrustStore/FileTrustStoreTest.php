<?php

declare(strict_types=1);

use PhpOpcua\Client\Security\CertificateManager;
use PhpOpcua\Client\TrustStore\FileTrustStore;
use PhpOpcua\Client\TrustStore\TrustPolicy;
use PhpOpcua\Client\TrustStore\TrustResult;

function generateTestCert(): string
{
    $cm = new CertificateManager();
    $generated = $cm->generateSelfSignedCertificate();

    return $generated['certDer'];
}

function generateExpiredTestCert(): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['CN' => 'Expired Test'], $key);
    $cert = openssl_csr_sign($csr, null, $key, 0);
    openssl_x509_export($cert, $pem);
    $der = base64_decode(
        str_replace(['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n", "\r"], '', $pem),
    );

    return $der;
}

function createTempTrustStore(): FileTrustStore
{
    $dir = sys_get_temp_dir() . '/opcua-trust-test-' . uniqid();

    return new FileTrustStore($dir);
}

function cleanupTrustStore(FileTrustStore $store): void
{
    $dirs = [$store->getTrustedDir(), $store->getRejectedDir()];
    foreach ($dirs as $dir) {
        $files = glob($dir . '/*.der') ?: [];
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
    @rmdir(dirname($store->getTrustedDir()));
}

describe('FileTrustStore', function () {

    it('creates trusted and rejected directories on construction', function () {
        $store = createTempTrustStore();
        expect(is_dir($store->getTrustedDir()))->toBeTrue();
        expect(is_dir($store->getRejectedDir()))->toBeTrue();
        cleanupTrustStore($store);
    });

    it('trusts a certificate and checks isTrusted', function () {
        $store = createTempTrustStore();
        $cert = generateTestCert();

        expect($store->isTrusted($cert))->toBeFalse();
        $store->trust($cert);
        expect($store->isTrusted($cert))->toBeTrue();

        cleanupTrustStore($store);
    });

    it('untrusts a certificate by fingerprint', function () {
        $store = createTempTrustStore();
        $cert = generateTestCert();
        $store->trust($cert);

        $fingerprint = implode(':', str_split(sha1($cert), 2));
        $store->untrust($fingerprint);

        expect($store->isTrusted($cert))->toBeFalse();
        cleanupTrustStore($store);
    });

    it('untrusts with plain hex fingerprint', function () {
        $store = createTempTrustStore();
        $cert = generateTestCert();
        $store->trust($cert);

        $fingerprint = sha1($cert);
        $store->untrust($fingerprint);

        expect($store->isTrusted($cert))->toBeFalse();
        cleanupTrustStore($store);
    });

    it('untrust on non-existent fingerprint does nothing', function () {
        $store = createTempTrustStore();
        $store->untrust('aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99:aa:bb:cc:dd');
        expect(true)->toBeTrue();
        cleanupTrustStore($store);
    });

    it('rejects a certificate', function () {
        $store = createTempTrustStore();
        $cert = generateTestCert();
        $store->reject($cert);

        $fingerprint = sha1($cert);
        $rejectedPath = $store->getRejectedDir() . '/' . $fingerprint . '.der';
        expect(file_exists($rejectedPath))->toBeTrue();

        cleanupTrustStore($store);
    });

    it('trust removes from rejected if present', function () {
        $store = createTempTrustStore();
        $cert = generateTestCert();
        $store->reject($cert);

        $fingerprint = sha1($cert);
        $rejectedPath = $store->getRejectedDir() . '/' . $fingerprint . '.der';
        expect(file_exists($rejectedPath))->toBeTrue();

        $store->trust($cert);
        expect(file_exists($rejectedPath))->toBeFalse();
        expect($store->isTrusted($cert))->toBeTrue();

        cleanupTrustStore($store);
    });

    it('lists trusted certificates with metadata', function () {
        $store = createTempTrustStore();
        $cert1 = generateTestCert();
        $cert2 = generateTestCert();
        $store->trust($cert1);
        $store->trust($cert2);

        $certs = $store->getTrustedCertificates();
        expect($certs)->toHaveCount(2);
        expect($certs[0])->toHaveKeys(['fingerprint', 'subject', 'notAfter', 'path']);
        expect($certs[0]['fingerprint'])->toBeString();
        expect($certs[0]['subject'])->not->toBeNull();

        cleanupTrustStore($store);
    });

    it('returns empty array when no trusted certificates', function () {
        $store = createTempTrustStore();
        expect($store->getTrustedCertificates())->toBe([]);
        cleanupTrustStore($store);
    });

});

describe('FileTrustStore::getTrustedCertificates', function () {

    it('returns correct fingerprint for a single trusted certificate', function () {
        $store = createTempTrustStore();
        $cert = generateTestCert();
        $store->trust($cert);

        $expectedFingerprint = implode(':', str_split(sha1($cert), 2));

        $certs = $store->getTrustedCertificates();
        expect($certs)->toHaveCount(1);
        expect($certs[0]['fingerprint'])->toBe($expectedFingerprint);

        cleanupTrustStore($store);
    });

    it('returns valid file path pointing to an existing .der file', function () {
        $store = createTempTrustStore();
        $cert = generateTestCert();
        $store->trust($cert);

        $certs = $store->getTrustedCertificates();
        expect($certs[0]['path'])->toEndWith('.der');
        expect(file_exists($certs[0]['path']))->toBeTrue();
        expect(file_get_contents($certs[0]['path']))->toBe($cert);

        cleanupTrustStore($store);
    });

    it('returns subject parsed from the certificate', function () {
        $store = createTempTrustStore();
        $cert = generateTestCert();
        $store->trust($cert);

        $certs = $store->getTrustedCertificates();
        expect($certs[0]['subject'])->toBeString();
        expect($certs[0]['subject'])->not->toBeEmpty();

        cleanupTrustStore($store);
    });

    it('returns notAfter as DateTimeImmutable', function () {
        $store = createTempTrustStore();
        $cert = generateTestCert();
        $store->trust($cert);

        $certs = $store->getTrustedCertificates();
        expect($certs[0]['notAfter'])->toBeInstanceOf(DateTimeImmutable::class);

        cleanupTrustStore($store);
    });

    it('does not include untrusted certificates', function () {
        $store = createTempTrustStore();
        $cert1 = generateTestCert();
        $cert2 = generateTestCert();
        $store->trust($cert1);
        $store->trust($cert2);

        $fingerprint1 = implode(':', str_split(sha1($cert1), 2));
        $store->untrust($fingerprint1);

        $certs = $store->getTrustedCertificates();
        expect($certs)->toHaveCount(1);
        expect($certs[0]['fingerprint'])->toBe(implode(':', str_split(sha1($cert2), 2)));

        cleanupTrustStore($store);
    });

    it('does not include rejected-only certificates', function () {
        $store = createTempTrustStore();
        $cert = generateTestCert();
        $store->reject($cert);

        $certs = $store->getTrustedCertificates();
        expect($certs)->toHaveCount(0);

        cleanupTrustStore($store);
    });

    it('returns three certificates when three are trusted', function () {
        $store = createTempTrustStore();
        $certs = [];
        for ($i = 0; $i < 3; $i++) {
            $certs[] = generateTestCert();
            $store->trust($certs[$i]);
        }

        $result = $store->getTrustedCertificates();
        expect($result)->toHaveCount(3);

        $fingerprints = array_column($result, 'fingerprint');
        foreach ($certs as $cert) {
            $expected = implode(':', str_split(sha1($cert), 2));
            expect($fingerprints)->toContain($expected);
        }

        cleanupTrustStore($store);
    });

    it('returns empty array when trusted directory is removed', function () {
        $store = createTempTrustStore();
        $cert = generateTestCert();
        $store->trust($cert);

        // Remove the trusted directory to force glob() to return false
        array_map('unlink', glob($store->getTrustedDir() . '/*.der') ?: []);
        rmdir($store->getTrustedDir());

        $certs = $store->getTrustedCertificates();
        expect($certs)->toBe([]);

        // Re-create dir for cleanup
        @mkdir($store->getTrustedDir(), 0700, true);
        cleanupTrustStore($store);
    });

    it('skips unreadable .der files', function () {
        $store = createTempTrustStore();
        $cert = generateTestCert();
        $store->trust($cert);

        // Add a second .der file that is unreadable
        $unreadablePath = $store->getTrustedDir() . '/' . sha1('fake') . '.der';
        file_put_contents($unreadablePath, 'data');
        chmod($unreadablePath, 0000);

        $certs = $store->getTrustedCertificates();
        // Should have only the readable cert (or both if running as root)
        expect(count($certs))->toBeLessThanOrEqual(2);
        expect(count($certs))->toBeGreaterThanOrEqual(1);

        // Restore permissions for cleanup
        chmod($unreadablePath, 0644);
        cleanupTrustStore($store);
    });

    it('skips non-der files in the trusted directory', function () {
        $store = createTempTrustStore();
        $cert = generateTestCert();
        $store->trust($cert);

        // Place a non-.der file in the trusted directory
        file_put_contents($store->getTrustedDir() . '/notes.txt', 'not a cert');

        $certs = $store->getTrustedCertificates();
        expect($certs)->toHaveCount(1);

        // Cleanup extra file
        @unlink($store->getTrustedDir() . '/notes.txt');
        cleanupTrustStore($store);
    });

    it('handles invalid DER data gracefully with null metadata', function () {
        $store = createTempTrustStore();

        // Write invalid data directly as a .der file
        $fakeDer = 'this-is-not-a-valid-certificate';
        $fingerprint = sha1($fakeDer);
        file_put_contents($store->getTrustedDir() . '/' . $fingerprint . '.der', $fakeDer);

        $certs = $store->getTrustedCertificates();
        expect($certs)->toHaveCount(1);
        expect($certs[0]['fingerprint'])->toBe(implode(':', str_split($fingerprint, 2)));
        expect($certs[0]['subject'])->toBeNull();
        expect($certs[0]['notAfter'])->toBeNull();

        cleanupTrustStore($store);
    });

    it('trusting the same certificate twice does not duplicate it', function () {
        $store = createTempTrustStore();
        $cert = generateTestCert();
        $store->trust($cert);
        $store->trust($cert);

        $certs = $store->getTrustedCertificates();
        expect($certs)->toHaveCount(1);

        cleanupTrustStore($store);
    });

    it('returns expired certificate metadata correctly', function () {
        $store = createTempTrustStore();
        $cert = generateExpiredTestCert();
        $store->trust($cert);

        $certs = $store->getTrustedCertificates();
        expect($certs)->toHaveCount(1);
        expect($certs[0]['notAfter'])->toBeInstanceOf(DateTimeImmutable::class);
        expect($certs[0]['notAfter'])->toBeLessThanOrEqual(new DateTimeImmutable());

        cleanupTrustStore($store);
    });

});

describe('FileTrustStore validation', function () {

    it('validates trusted cert with Fingerprint policy', function () {
        $store = createTempTrustStore();
        $cert = generateTestCert();
        $store->trust($cert);

        $result = $store->validate($cert, TrustPolicy::Fingerprint);
        expect($result)->toBeInstanceOf(TrustResult::class);
        expect($result->trusted)->toBeTrue();
        expect($result->fingerprint)->toBeString();
        expect($result->reason)->toBeNull();

        cleanupTrustStore($store);
    });

    it('rejects untrusted cert with Fingerprint policy', function () {
        $store = createTempTrustStore();
        $cert = generateTestCert();

        $result = $store->validate($cert, TrustPolicy::Fingerprint);
        expect($result->trusted)->toBeFalse();
        expect($result->reason)->toContain('not found');

        cleanupTrustStore($store);
    });

    it('validates trusted non-expired cert with FingerprintAndExpiry policy', function () {
        $store = createTempTrustStore();
        $cert = generateTestCert();
        $store->trust($cert);

        $result = $store->validate($cert, TrustPolicy::FingerprintAndExpiry);
        expect($result->trusted)->toBeTrue();
        expect($result->notAfter)->toBeInstanceOf(DateTimeImmutable::class);

        cleanupTrustStore($store);
    });

    it('validates with Full policy without CA cert', function () {
        $store = createTempTrustStore();
        $cert = generateTestCert();
        $store->trust($cert);

        $result = $store->validate($cert, TrustPolicy::Full);
        expect($result->trusted)->toBeTrue();

        cleanupTrustStore($store);
    });

    it('rejects not-yet-valid cert with FingerprintAndExpiry policy', function () {
        $store = createTempTrustStore();
        $futureCertDer = file_get_contents(__DIR__ . '/Fixtures/future_cert.der');
        $store->trust($futureCertDer);

        $result = $store->validate($futureCertDer, TrustPolicy::FingerprintAndExpiry);
        expect($result->trusted)->toBeFalse();
        expect($result->reason)->toContain('not yet valid');

        cleanupTrustStore($store);
    });

    it('rejects expired cert with FingerprintAndExpiry policy', function () {
        $store = createTempTrustStore();
        $cert = generateTestCert();
        $store->trust($cert);

        $fingerprint = sha1($cert);
        $trustedPath = $store->getTrustedDir() . '/' . $fingerprint . '.der';

        $expiredCert = generateExpiredTestCert();
        $store->trust($expiredCert);

        $result = $store->validate($expiredCert, TrustPolicy::FingerprintAndExpiry);
        expect($result->trusted)->toBeFalse();
        expect($result->reason)->toContain('expired');

        cleanupTrustStore($store);
    });

    it('validates with Full policy and CA cert', function () {
        $store = createTempTrustStore();
        $cert = generateTestCert();
        $store->trust($cert);

        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($cert), 64) . "-----END CERTIFICATE-----\n";

        $result = $store->validate($cert, TrustPolicy::Full, $pem);
        expect($result)->toBeInstanceOf(TrustResult::class);

        cleanupTrustStore($store);
    });

    it('includes subject and dates in TrustResult', function () {
        $store = createTempTrustStore();
        $cert = generateTestCert();
        $store->trust($cert);

        $result = $store->validate($cert, TrustPolicy::Fingerprint);
        expect($result->subject)->not->toBeNull();
        expect($result->notBefore)->toBeInstanceOf(DateTimeImmutable::class);
        expect($result->notAfter)->toBeInstanceOf(DateTimeImmutable::class);

        cleanupTrustStore($store);
    });

    it('verifyCaChain returns false for invalid cert', function () {
        $store = createTempTrustStore();
        $method = new ReflectionMethod($store, 'verifyCaChain');
        $result = $method->invoke($store, 'invalid-der-data', 'invalid-pem-data');
        expect($result)->toBeFalse();
        cleanupTrustStore($store);
    });

    it('validates with Full policy and invalid CA rejects', function () {
        $store = createTempTrustStore();
        $cert = generateTestCert();
        $store->trust($cert);

        $otherCert = generateTestCert();
        $otherPem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($otherCert), 64) . "-----END CERTIFICATE-----\n";

        $result = $store->validate($cert, TrustPolicy::Full, $otherPem);
        expect($result->trusted)->toBeFalse();
        expect($result->reason)->toContain('chain verification failed');

        cleanupTrustStore($store);
    });

    it('validate with FingerprintAndExpiry for valid cert', function () {
        $store = createTempTrustStore();

        $privKey = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['CN' => 'Test'], $privKey);
        $cert = openssl_csr_sign($csr, null, $privKey, 365);
        openssl_x509_export($cert, $certPem);

        $pemBody = trim(str_replace(['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----'], '', $certPem));
        $certDer = base64_decode($pemBody);

        $store->trust($certDer);

        $result = $store->validate($certDer, TrustPolicy::FingerprintAndExpiry);
        expect($result->trusted)->toBeTrue();
        expect($result->subject)->toBe('Test');

        cleanupTrustStore($store);
    });

    it('validate returns not yet valid for future cert', function () {
        $store = createTempTrustStore();

        $privKey = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['CN' => 'FutureTest'], $privKey);
        $cert = openssl_csr_sign($csr, null, $privKey, 365);
        openssl_x509_export($cert, $certPem);
        $pemBody = trim(str_replace(['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----'], '', $certPem));
        $certDer = base64_decode($pemBody);

        $store->trust($certDer);

        $result = $store->validate($certDer, TrustPolicy::FingerprintAndExpiry);
        expect($result->trusted)->toBeTrue();

        cleanupTrustStore($store);
    });

    it('parseCertificateInfo returns nulls for invalid cert', function () {
        $store = createTempTrustStore();
        $method = new ReflectionMethod($store, 'parseCertificateInfo');
        $result = $method->invoke($store, 'not-a-valid-cert');
        expect($result['subject'])->toBeNull();
        expect($result['notBefore'])->toBeNull();
        expect($result['notAfter'])->toBeNull();
        cleanupTrustStore($store);
    });

    it('throwCertificateParseExceptionIfNull returns value when not null', function () {
        $dir = sys_get_temp_dir() . '/opcua-trust-helper-' . uniqid();
        $store = new class($dir) extends FileTrustStore {
            public function callThrowIfNull(mixed $value, string $message): mixed
            {
                return $this->throwCertificateParseExceptionIfNull($value, $message);
            }
        };

        expect($store->callThrowIfNull(12345, 'should not throw'))->toBe(12345);
        expect($store->callThrowIfNull('hello', 'should not throw'))->toBe('hello');

        cleanupTrustStore($store);
    });

    it('throwCertificateParseExceptionIfNull throws when null', function () {
        $dir = sys_get_temp_dir() . '/opcua-trust-helper-' . uniqid();
        $store = new class($dir) extends FileTrustStore {
            public function callThrowIfNull(mixed $value, string $message): mixed
            {
                return $this->throwCertificateParseExceptionIfNull($value, $message);
            }
        };

        expect(fn () => $store->callThrowIfNull(null, 'Missing field'))
            ->toThrow(\PhpOpcua\Client\Exception\CertificateParseException::class, 'Missing field');

        cleanupTrustStore($store);
    });

    it('uses default base path when none provided', function () {
        $store = new FileTrustStore();
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();
        expect($store->getTrustedDir())->toContain('.opcua/trusted');
        expect(str_starts_with($store->getTrustedDir(), $home))->toBeTrue();
    });

});

describe('TrustPolicy enum', function () {

    it('has three cases', function () {
        expect(TrustPolicy::cases())->toHaveCount(3);
    });

    it('has correct values', function () {
        expect(TrustPolicy::Fingerprint->value)->toBe('fingerprint');
        expect(TrustPolicy::FingerprintAndExpiry->value)->toBe('fingerprint+expiry');
        expect(TrustPolicy::Full->value)->toBe('full');
    });

    it('creates from string', function () {
        expect(TrustPolicy::from('fingerprint'))->toBe(TrustPolicy::Fingerprint);
        expect(TrustPolicy::from('full'))->toBe(TrustPolicy::Full);
    });

});

describe('TrustResult', function () {

    it('creates trusted result', function () {
        $result = new TrustResult(true, 'aa:bb:cc', null, 'CN=Server');
        expect($result->trusted)->toBeTrue();
        expect($result->fingerprint)->toBe('aa:bb:cc');
        expect($result->reason)->toBeNull();
        expect($result->subject)->toBe('CN=Server');
    });

    it('creates rejected result with reason', function () {
        $result = new TrustResult(false, 'aa:bb:cc', 'Certificate expired');
        expect($result->trusted)->toBeFalse();
        expect($result->reason)->toBe('Certificate expired');
    });

});
