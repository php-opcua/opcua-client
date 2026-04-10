<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Security;

use OpenSSLAsymmetricKey;
use PhpOpcua\Client\Exception\ConfigurationException;
use PhpOpcua\Client\Exception\SecurityException;

/**
 * Utilities for loading, parsing, and generating X.509 certificates and private keys.
 */
class CertificateManager
{
    use EnsuresOpenSslSuccess;

    /**
     * @param string $path
     * @return string DER-encoded certificate bytes.
     * @throws ConfigurationException
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
     * @return string DER-encoded certificate bytes.
     * @throws ConfigurationException
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
     * @return OpenSSLAsymmetricKey
     * @throws ConfigurationException
     * @throws SecurityException
     */
    public function loadPrivateKeyPem(string $path): OpenSSLAsymmetricKey
    {
        $pem = file_get_contents($path);
        if ($pem === false) {
            throw new ConfigurationException("Failed to read private key file: {$path}");
        }

        $key = openssl_pkey_get_private($pem);

        return self::ensureNotFalse($key, 'Failed to parse private key');
    }

    /**
     * @param string $derCert
     * @return string SHA-1 thumbprint (binary, 20 bytes).
     */
    public function getThumbprint(string $derCert): string
    {
        return sha1($derCert, true);
    }

    /**
     * @param string $derCert
     * @return int Key length in bytes (e.g. 256 for 2048-bit).
     * @throws SecurityException
     */
    public function getPublicKeyLength(string $derCert): int
    {
        $pem = $this->derToPem($derCert);
        $cert = self::ensureNotFalse(openssl_x509_read($pem), 'Failed to read certificate');
        $pubKey = self::ensureNotFalse(openssl_pkey_get_public($cert), 'Failed to get public key from certificate');
        $details = self::ensureNotFalse(openssl_pkey_get_details($pubKey), 'Failed to get key details');

        return (int) ($details['bits'] / 8);
    }

    /**
     * @param string $derCert
     * @return OpenSSLAsymmetricKey
     * @throws SecurityException
     */
    public function getPublicKeyFromCert(string $derCert): OpenSSLAsymmetricKey
    {
        $pem = $this->derToPem($derCert);
        $cert = self::ensureNotFalse(openssl_x509_read($pem), 'Failed to read certificate');

        return self::ensureNotFalse(openssl_pkey_get_public($cert), 'Failed to get public key from certificate');
    }

    /**
     * @param string $derCert
     * @return ?string The application URI from the SAN extension, or null.
     */
    public function getApplicationUri(string $derCert): ?string
    {
        $pem = $this->derToPem($derCert);
        $cert = openssl_x509_read($pem);
        if ($cert === false) {
            return null;
        }

        $parsed = openssl_x509_parse($cert);
        if ($parsed === false || ! isset($parsed['extensions']['subjectAltName'])) {
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
     * @return string
     * @throws SecurityException
     */
    private function pemToDer(string $pem): string
    {
        $pem = preg_replace('/-----BEGIN [^-]+-----/', '', $pem);
        $pem = preg_replace('/-----END [^-]+-----/', '', $pem);
        $pem = str_replace(["\r", "\n", ' '], '', $pem);

        $der = base64_decode($pem, true);
        if ($der === false) {
            throw new SecurityException('Failed to decode PEM certificate');
        }

        return $der;
    }

    /**
     * @param string $derCert
     * @return int OPENSSL_KEYTYPE_RSA or OPENSSL_KEYTYPE_EC.
     * @throws SecurityException
     */
    public function getKeyType(string $derCert): int
    {
        $pem = $this->derToPem($derCert);
        $cert = self::ensureNotFalse(openssl_x509_read($pem), 'Failed to read certificate');
        $pubKey = self::ensureNotFalse(openssl_pkey_get_public($cert), 'Failed to get public key from certificate');
        $details = self::ensureNotFalse(openssl_pkey_get_details($pubKey), 'Failed to get key details');

        return (int) $details['type'];
    }

    /**
     * @param string $applicationUri
     * @param ?string $eccCurveName OpenSSL curve name for ECC (e.g. 'prime256v1'). Null for RSA.
     * @return array{certDer: string, privateKey: OpenSSLAsymmetricKey}
     * @throws SecurityException
     */
    public function generateSelfSignedCertificate(
        string $applicationUri = 'urn:opcua-client',
        ?string $eccCurveName = null,
    ): array {
        $hostname = gethostname() ?: 'localhost';

        $configContent = "[req]\n"
            . "distinguished_name = req_dn\n"
            . "x509_extensions = v3_req\n"
            . "prompt = no\n"
            . "[req_dn]\n"
            . "CN = OPC UA PHP Client\n"
            . "O = OPC UA PHP Client\n"
            . "[v3_req]\n"
            . "basicConstraints = CA:FALSE\n"
            . ($eccCurveName !== null
                ? "keyUsage = digitalSignature, nonRepudiation, keyAgreement\n"
                : "keyUsage = digitalSignature, nonRepudiation, keyEncipherment, dataEncipherment\n")
            . "extendedKeyUsage = clientAuth\n"
            . "subjectAltName = URI:{$applicationUri}, DNS:{$hostname}\n";

        $tmpHandle = self::ensureNotFalse(tmpfile(), 'Failed to create temporary OpenSSL config');

        try {
            fwrite($tmpHandle, $configContent);
            fflush($tmpHandle);
            $meta = stream_get_meta_data($tmpHandle);
            $configPath = $meta['uri'];

            if ($eccCurveName !== null) {
                $keyConfig = [
                    'private_key_type' => OPENSSL_KEYTYPE_EC,
                    'curve_name' => $eccCurveName,
                ];
                $digestAlg = match ($eccCurveName) {
                    'secp384r1', 'brainpoolP384r1' => 'sha384',
                    default => 'sha256',
                };
            } else {
                $keyConfig = [
                    'private_key_bits' => 2048,
                    'private_key_type' => OPENSSL_KEYTYPE_RSA,
                    'config' => $configPath,
                ];
                $digestAlg = 'sha256';
            }

            $privateKey = self::ensureNotFalse(openssl_pkey_new($keyConfig), 'Failed to generate private key');

            $dn = ['CN' => 'OPC UA PHP Client', 'O' => 'OPC UA PHP Client'];

            $csr = self::ensureNotFalse(
                openssl_csr_new($dn, $privateKey, ['digest_alg' => $digestAlg, 'config' => $configPath]),
                'Failed to generate CSR',
            );

            $cert = self::ensureNotFalse(
                openssl_csr_sign($csr, null, $privateKey, 365, ['digest_alg' => $digestAlg, 'config' => $configPath, 'x509_extensions' => 'v3_req']),
                'Failed to generate self-signed certificate',
            );

            $certPem = '';
            self::ensureNotFalse(
                openssl_x509_export($cert, $certPem) ?: false,
                'Failed to export certificate',
            );

            return [
                'certDer' => $this->pemToDer($certPem),
                'privateKey' => $privateKey,
            ];
        } finally {
            fclose($tmpHandle);
        }
    }

    /**
     * @param string $der
     * @return string PEM-encoded certificate.
     */
    private function derToPem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END CERTIFICATE-----\n";
    }
}
