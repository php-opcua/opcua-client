<?php

declare(strict_types=1);

namespace PhpOpcua\Client\TrustStore;

use DateTimeImmutable;
use PhpOpcua\Client\Exception\CertificateParseException;

/**
 * File-based trust store implementation.
 *
 * Stores trusted and rejected certificates as DER files on the filesystem.
 * Default base path is `~/.opcua/`.
 */
class FileTrustStore implements TrustStoreInterface
{
    private string $trustedDir;

    private string $rejectedDir;

    /**
     * @param ?string $basePath Base directory for the trust store. Defaults to ~/.opcua on Unix, %APPDATA%\opcua on Windows.
     */
    public function __construct(?string $basePath = null)
    {
        $basePath ??= $this->defaultBasePath();
        $basePath = rtrim($basePath, '/\\');

        $this->trustedDir = $basePath . DIRECTORY_SEPARATOR . 'trusted';
        $this->rejectedDir = $basePath . DIRECTORY_SEPARATOR . 'rejected';

        $this->ensureDirectory($this->trustedDir);
        $this->ensureDirectory($this->rejectedDir);
    }

    /**
     * {@inheritDoc}
     */
    public function isTrusted(string $certDer): bool
    {
        $fingerprint = $this->computeFingerprint($certDer);

        return file_exists($this->trustedPath($fingerprint));
    }

    /**
     * {@inheritDoc}
     */
    public function trust(string $certDer): void
    {
        $fingerprint = $this->computeFingerprint($certDer);

        file_put_contents($this->trustedPath($fingerprint), $certDer, LOCK_EX);

        $rejectedPath = $this->rejectedPath($fingerprint);
        if (file_exists($rejectedPath)) {
            @unlink($rejectedPath);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function untrust(string $fingerprint): void
    {
        $fingerprint = $this->normalizeFingerprint($fingerprint);
        $path = $this->trustedPath($fingerprint);

        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function reject(string $certDer): void
    {
        $fingerprint = $this->computeFingerprint($certDer);

        file_put_contents($this->rejectedPath($fingerprint), $certDer, LOCK_EX);
    }

    /**
     * {@inheritDoc}
     */
    public function getTrustedCertificates(): array
    {
        $files = glob($this->trustedDir . DIRECTORY_SEPARATOR . '*.der') ?: [];

        $certificates = [];
        foreach ($files as $path) {
            $certDer = file_get_contents($path);
            if ($certDer === false) {
                continue;
            }

            $fingerprint = $this->computeFingerprint($certDer);
            $info = $this->parseCertificateInfo($certDer);

            $certificates[] = [
                'fingerprint' => $fingerprint,
                'subject' => $info['subject'],
                'notAfter' => $info['notAfter'],
                'path' => $path,
            ];
        }

        return $certificates;
    }

    /**
     * {@inheritDoc}
     */
    public function validate(string $certDer, TrustPolicy $policy, ?string $caCertPem = null): TrustResult
    {
        $fingerprint = $this->computeFingerprint($certDer);
        $info = $this->parseCertificateInfo($certDer);

        if (! $this->isTrusted($certDer)) {
            return new TrustResult(
                false,
                $fingerprint,
                'Certificate not found in trust store',
                $info['subject'],
                $info['notBefore'],
                $info['notAfter'],
            );
        }

        if ($policy === TrustPolicy::FingerprintAndExpiry || $policy === TrustPolicy::Full) {
            if ($info['notAfter'] !== null && $info['notAfter'] < new DateTimeImmutable()) {
                return new TrustResult(false, $fingerprint, 'Certificate has expired', $info['subject'], $info['notBefore'], $info['notAfter']);
            }

            if ($info['notBefore'] !== null && $info['notBefore'] > new DateTimeImmutable()) {
                return new TrustResult(false, $fingerprint, 'Certificate is not yet valid', $info['subject'], $info['notBefore'], $info['notAfter']);
            }
        }

        if ($policy === TrustPolicy::Full && $caCertPem !== null) {
            if (! $this->verifyCaChain($certDer, $caCertPem)) {
                return new TrustResult(false, $fingerprint, 'Certificate chain verification failed', $info['subject'], $info['notBefore'], $info['notAfter']);
            }
        }

        return new TrustResult(true, $fingerprint, null, $info['subject'], $info['notBefore'], $info['notAfter']);
    }

    /**
     * @return string
     */
    public function getTrustedDir(): string
    {
        return $this->trustedDir;
    }

    /**
     * @return string
     */
    public function getRejectedDir(): string
    {
        return $this->rejectedDir;
    }

    /**
     * @param string $certDer
     * @return string
     */
    private function computeFingerprint(string $certDer): string
    {
        $raw = sha1($certDer);

        return implode(':', str_split($raw, 2));
    }

    /**
     * @param string $fingerprint
     * @return string
     */
    private function normalizeFingerprint(string $fingerprint): string
    {
        return strtolower(str_replace(':', '', $fingerprint));
    }

    /**
     * @param string $fingerprint
     * @return string
     */
    private function trustedPath(string $fingerprint): string
    {
        return $this->trustedDir . DIRECTORY_SEPARATOR . $this->normalizeFingerprint($fingerprint) . '.der';
    }

    /**
     * @param string $fingerprint
     * @return string
     */
    private function rejectedPath(string $fingerprint): string
    {
        return $this->rejectedDir . DIRECTORY_SEPARATOR . $this->normalizeFingerprint($fingerprint) . '.der';
    }

    /**
     * @param string $certDer
     * @return array{subject: ?string, notBefore: ?DateTimeImmutable, notAfter: ?DateTimeImmutable}
     */
    protected function parseCertificateInfo(string $certDer): array
    {
        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($certDer), 64) . "-----END CERTIFICATE-----\n";
        $parsed = @openssl_x509_parse($pem);

        if ($parsed === false) {
            return ['subject' => null, 'notBefore' => null, 'notAfter' => null];
        }

        $subject = $parsed['subject']['CN'] ?? ($parsed['name'] ?? null);

        $notBefore = new DateTimeImmutable('@' . $this->throwCertificateParseExceptionIfNull(
            $parsed['validFrom_time_t'] ?? null,
            'Missing validFrom_time_t in parsed certificate',
        ));

        $notAfter = new DateTimeImmutable('@' . $this->throwCertificateParseExceptionIfNull(
            $parsed['validTo_time_t'] ?? null,
            'Missing validTo_time_t in parsed certificate',
        ));

        return ['subject' => $subject, 'notBefore' => $notBefore, 'notAfter' => $notAfter];
    }

    /**
     * @param string $certDer
     * @param string $caCertPem
     * @return bool
     */
    private function verifyCaChain(string $certDer, string $caCertPem): bool
    {
        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($certDer), 64) . "-----END CERTIFICATE-----\n";

        $certResource = @openssl_x509_read($pem);
        if ($certResource === false) {
            return false;
        }

        $result = @openssl_x509_verify($certResource, openssl_pkey_get_public($caCertPem));

        return $result === 1;
    }

    /**
     * @template T
     *
     * @param T|null $value
     * @param string $message
     * @return T
     *
     * @throws CertificateParseException
     */
    protected function throwCertificateParseExceptionIfNull(mixed $value, string $message): mixed
    {
        if ($value === null) {
            throw new CertificateParseException($message);
        }

        return $value;
    }

    /**
     * @param string $dir
     */
    private function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
    }

    /**
     * @return string
     */
    private function defaultBasePath(): string
    {
        $base = PHP_OS_FAMILY === 'Windows'
            ? (getenv('APPDATA') ?: getenv('LOCALAPPDATA') ?: null)
            : ($_SERVER['HOME'] ?? getenv('HOME') ?: null);

        $base = $base ?: sys_get_temp_dir();
        $dotPrefix = PHP_OS_FAMILY === 'Windows' ? '' : '.';

        return $base . DIRECTORY_SEPARATOR . $dotPrefix . 'opcua';
    }
}
