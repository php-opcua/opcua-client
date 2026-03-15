<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Security;

use Gianfriaur\OpcuaPhpClient\Exception\ConfigurationException;
use Gianfriaur\OpcuaPhpClient\Exception\SecurityException;
use OpenSSLAsymmetricKey;

class CertificateManager
{
    /**
     * @param string $path
     */
    public function loadCertificatePem(string $path): string
    {
        $pem = file_get_contents($path);
        if ($pem === false) {
            throw new ConfigurationException("Failed to read certificate file: {$path}");
        }

        return $this->pemToDer($pem);
    }

    /**
     * @param string $path
     */
    public function loadCertificateDer(string $path): string
    {
        $der = file_get_contents($path);
        if ($der === false) {
            throw new ConfigurationException("Failed to read certificate file: {$path}");
        }

        return $der;
    }

    /**
     * @param string $path
     */
    public function loadPrivateKeyPem(string $path): OpenSSLAsymmetricKey
    {
        $pem = file_get_contents($path);
        if ($pem === false) {
            throw new ConfigurationException("Failed to read private key file: {$path}");
        }

        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new SecurityException("Failed to parse private key: " . openssl_error_string());
        }

        return $key;
    }

    /**
     * @param string $derCert
     */
    public function getThumbprint(string $derCert): string
    {
        return sha1($derCert, true);
    }

    /**
     * @param string $derCert
     */
    public function getPublicKeyLength(string $derCert): int
    {
        $pem = $this->derToPem($derCert);
        $cert = openssl_x509_read($pem);
        if ($cert === false) {
            throw new SecurityException("Failed to read certificate: " . openssl_error_string());
        }

        $pubKey = openssl_pkey_get_public($cert);
        if ($pubKey === false) {
            throw new SecurityException("Failed to get public key from certificate: " . openssl_error_string());
        }

        $details = openssl_pkey_get_details($pubKey);
        if ($details === false) {
            throw new SecurityException("Failed to get key details: " . openssl_error_string());
        }

        return (int) ($details['bits'] / 8);
    }

    /**
     * @param string $derCert
     */
    public function getPublicKeyFromCert(string $derCert): OpenSSLAsymmetricKey
    {
        $pem = $this->derToPem($derCert);
        $cert = openssl_x509_read($pem);
        if ($cert === false) {
            throw new SecurityException("Failed to read certificate: " . openssl_error_string());
        }

        $pubKey = openssl_pkey_get_public($cert);
        if ($pubKey === false) {
            throw new SecurityException("Failed to get public key from certificate: " . openssl_error_string());
        }

        return $pubKey;
    }

    /**
     * @param string $derCert
     */
    public function getApplicationUri(string $derCert): ?string
    {
        $pem = $this->derToPem($derCert);
        $cert = openssl_x509_read($pem);
        if ($cert === false) {
            return null;
        }

        $parsed = openssl_x509_parse($cert);
        if ($parsed === false || !isset($parsed['extensions']['subjectAltName'])) {
            return null;
        }

        $san = $parsed['extensions']['subjectAltName'];
        $parts = explode(',', $san);
        foreach ($parts as $part) {
            $part = trim($part);
            if (str_starts_with($part, 'URI:')) {
                return substr($part, 4);
            }
        }

        return null;
    }

    /**
     * @param string $pem
     */
    private function pemToDer(string $pem): string
    {
        $pem = preg_replace('/-----BEGIN [^-]+-----/', '', $pem);
        $pem = preg_replace('/-----END [^-]+-----/', '', $pem);
        $pem = str_replace(["\r", "\n", " "], '', $pem);

        $der = base64_decode($pem, true);
        if ($der === false) {
            throw new SecurityException("Failed to decode PEM certificate");
        }

        return $der;
    }

    /**
     * @param string $der
     */
    private function derToPem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END CERTIFICATE-----\n";
    }
}
