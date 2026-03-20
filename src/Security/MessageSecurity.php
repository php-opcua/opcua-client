<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Security;

use Gianfriaur\OpcuaPhpClient\Exception\SecurityException;
use OpenSSLAsymmetricKey;

/**
 * Low-level cryptographic operations for OPC UA message security.
 */
class MessageSecurity
{
    private CertificateManager $certManager;

    /**
     * @param ?CertificateManager $certManager
     */
    public function __construct(?CertificateManager $certManager = null)
    {
        $this->certManager = $certManager ?? new CertificateManager();
    }

    /**
     * @param string $data
     * @param OpenSSLAsymmetricKey $privateKey
     * @param SecurityPolicy $policy
     * @return string
     * @throws SecurityException
     */
    public function asymmetricSign(string $data, OpenSSLAsymmetricKey $privateKey, SecurityPolicy $policy): string
    {
        if ($policy === SecurityPolicy::None) {
            return '';
        }

        $algorithm = $policy->getAsymmetricSignatureAlgorithm();
        $signature = '';

        self::ensureNotFalse(
            openssl_sign($data, $signature, $privateKey, $algorithm),
            "Asymmetric signing failed",
        );

        return $signature;
    }

    /**
     * @param string $data
     * @param string $signature
     * @param string $derCert
     * @param SecurityPolicy $policy
     * @return bool
     * @throws SecurityException
     */
    public function asymmetricVerify(string $data, string $signature, string $derCert, SecurityPolicy $policy): bool
    {
        if ($policy === SecurityPolicy::None) {
            return true;
        }

        $pubKey = $this->certManager->getPublicKeyFromCert($derCert);
        $algorithm = $policy->getAsymmetricSignatureAlgorithm();

        $result = openssl_verify($data, $signature, $pubKey, $algorithm);
        self::ensureNotFalse($result !== -1 ? $result : false, "Asymmetric verification failed");

        return $result === 1;
    }

    /**
     * @param string $data
     * @param string $derCert
     * @param SecurityPolicy $policy
     * @return string
     * @throws SecurityException
     */
    public function asymmetricEncrypt(string $data, string $derCert, SecurityPolicy $policy): string
    {
        if ($policy === SecurityPolicy::None) {
            return $data;
        }

        $pubKey = $this->certManager->getPublicKeyFromCert($derCert);
        $keyLengthBytes = $this->certManager->getPublicKeyLength($derCert);
        $paddingOverhead = $policy->getAsymmetricPaddingOverhead();
        $plainTextBlockSize = $keyLengthBytes - $paddingOverhead;
        $padding = $policy->getAsymmetricEncryptionPadding();

        $encrypted = '';
        $dataLen = strlen($data);
        $offset = 0;

        while ($offset < $dataLen) {
            $blockSize = min($plainTextBlockSize, $dataLen - $offset);
            $block = substr($data, $offset, $blockSize);
            $encryptedBlock = '';

            self::ensureNotFalse(
                openssl_public_encrypt($block, $encryptedBlock, $pubKey, $padding),
                "Asymmetric encryption failed",
            );

            $encrypted .= $encryptedBlock;
            $offset += $blockSize;
        }

        return $encrypted;
    }

    /**
     * @param string $data
     * @param OpenSSLAsymmetricKey $privateKey
     * @param SecurityPolicy $policy
     * @return string
     * @throws SecurityException
     */
    public function asymmetricDecrypt(string $data, OpenSSLAsymmetricKey $privateKey, SecurityPolicy $policy): string
    {
        if ($policy === SecurityPolicy::None) {
            return $data;
        }

        $details = self::ensureNotFalse(openssl_pkey_get_details($privateKey), "Failed to get private key details");
        $keyLengthBytes = (int)($details['bits'] / 8);
        $padding = $policy->getAsymmetricEncryptionPadding();

        $decrypted = '';
        $dataLen = strlen($data);
        $offset = 0;

        while ($offset < $dataLen) {
            $block = substr($data, $offset, $keyLengthBytes);
            $decryptedBlock = '';

            self::ensureNotFalse(
                openssl_private_decrypt($block, $decryptedBlock, $privateKey, $padding),
                "Asymmetric decryption failed",
            );

            $decrypted .= $decryptedBlock;
            $offset += $keyLengthBytes;
        }

        return $decrypted;
    }

    /**
     * @param string $data
     * @param string $signingKey
     * @param SecurityPolicy $policy
     * @return string
     */
    public function symmetricSign(string $data, string $signingKey, SecurityPolicy $policy): string
    {
        if ($policy === SecurityPolicy::None) {
            return '';
        }

        $algorithm = $policy->getSymmetricSignatureAlgorithm();

        return hash_hmac($algorithm, $data, $signingKey, true);
    }

    /**
     * @param string $data
     * @param string $signature
     * @param string $signingKey
     * @param SecurityPolicy $policy
     * @return bool
     */
    public function symmetricVerify(string $data, string $signature, string $signingKey, SecurityPolicy $policy): bool
    {
        if ($policy === SecurityPolicy::None) {
            return true;
        }

        $expected = $this->symmetricSign($data, $signingKey, $policy);

        return hash_equals($expected, $signature);
    }

    /**
     * @param string $data
     * @param string $encryptingKey
     * @param string $iv
     * @param SecurityPolicy $policy
     * @return string
     * @throws SecurityException
     */
    public function symmetricEncrypt(string $data, string $encryptingKey, string $iv, SecurityPolicy $policy): string
    {
        if ($policy === SecurityPolicy::None) {
            return $data;
        }

        $cipher = $policy->getSymmetricEncryptionAlgorithm();

        return self::ensureNotFalse(
            openssl_encrypt($data, $cipher, $encryptingKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv),
            "Symmetric encryption failed",
        );
    }

    /**
     * @param string $data
     * @param string $encryptingKey
     * @param string $iv
     * @param SecurityPolicy $policy
     * @return string
     * @throws SecurityException
     */
    public function symmetricDecrypt(string $data, string $encryptingKey, string $iv, SecurityPolicy $policy): string
    {
        if ($policy === SecurityPolicy::None) {
            return $data;
        }

        $cipher = $policy->getSymmetricEncryptionAlgorithm();

        return self::ensureNotFalse(
            openssl_decrypt($data, $cipher, $encryptingKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv),
            "Symmetric decryption failed",
        );
    }

    /**
     * @param string $secret
     * @param string $seed
     * @param SecurityPolicy $policy
     * @return array{signingKey: string, encryptingKey: string, iv: string}
     */
    public function deriveKeys(string $secret, string $seed, SecurityPolicy $policy): array
    {
        if ($policy === SecurityPolicy::None) {
            return ['signingKey' => '', 'encryptingKey' => '', 'iv' => ''];
        }

        $sigKeyLen = $policy->getDerivedSignatureKeyLength();
        $encKeyLen = $policy->getDerivedKeyLength();
        $ivLen = $policy->getSymmetricBlockSize();
        $totalLen = $sigKeyLen + $encKeyLen + $ivLen;

        $algorithm = $policy->getKeyDerivationAlgorithm();
        $derived = $this->prf($secret, $seed, $totalLen, $algorithm);

        return [
            'signingKey' => substr($derived, 0, $sigKeyLen),
            'encryptingKey' => substr($derived, $sigKeyLen, $encKeyLen),
            'iv' => substr($derived, $sigKeyLen + $encKeyLen, $ivLen),
        ];
    }

    /**
     * @param string $secret
     * @param string $seed
     * @param int $length
     * @param string $algo
     * @return string
     */
    private function prf(string $secret, string $seed, int $length, string $algo): string
    {
        $result = '';
        $a = $seed;

        while (strlen($result) < $length) {
            $a = hash_hmac($algo, $a, $secret, true);
            $result .= hash_hmac($algo, $a . $seed, $secret, true);
        }

        return substr($result, 0, $length);
    }

    /**
     * @template T
     * @param T|false $result
     * @param string $message
     * @return T
     * @throws SecurityException
     */
    private static function ensureNotFalse(mixed $result, string $message): mixed
    {
        if ($result === false) {
            throw new SecurityException("{$message}: " . openssl_error_string());
        }

        return $result;
    }
}
