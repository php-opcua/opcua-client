<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Security;

use Gianfriaur\OpcuaPhpClient\Exception\SecurityException;
use OpenSSLAsymmetricKey;

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
     */
    public function asymmetricSign(string $data, OpenSSLAsymmetricKey $privateKey, SecurityPolicy $policy): string
    {
        if ($policy === SecurityPolicy::None) {
            return '';
        }

        $algorithm = $policy->getAsymmetricSignatureAlgorithm();
        $signature = '';

        $result = openssl_sign($data, $signature, $privateKey, $algorithm);
        if ($result === false) {
            throw new SecurityException("Asymmetric signing failed: " . openssl_error_string());
        }

        return $signature;
    }

    /**
     * @param string $data
     * @param string $signature
     * @param string $derCert
     * @param SecurityPolicy $policy
     */
    public function asymmetricVerify(
        string         $data,
        string         $signature,
        string         $derCert,
        SecurityPolicy $policy,
    ): bool
    {
        if ($policy === SecurityPolicy::None) {
            return true;
        }

        $pubKey = $this->certManager->getPublicKeyFromCert($derCert);
        $algorithm = $policy->getAsymmetricSignatureAlgorithm();

        $result = openssl_verify($data, $signature, $pubKey, $algorithm);
        if ($result === -1) {
            throw new SecurityException("Asymmetric verification failed: " . openssl_error_string());
        }

        return $result === 1;
    }

    /**
     * @param string $data
     * @param string $derCert
     * @param SecurityPolicy $policy
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

            $result = openssl_public_encrypt($block, $encryptedBlock, $pubKey, $padding);
            if ($result === false) {
                throw new SecurityException("Asymmetric encryption failed: " . openssl_error_string());
            }

            $encrypted .= $encryptedBlock;
            $offset += $blockSize;
        }

        return $encrypted;
    }

    /**
     * @param string $data
     * @param OpenSSLAsymmetricKey $privateKey
     * @param SecurityPolicy $policy
     */
    public function asymmetricDecrypt(
        string               $data,
        OpenSSLAsymmetricKey $privateKey,
        SecurityPolicy       $policy,
    ): string
    {
        if ($policy === SecurityPolicy::None) {
            return $data;
        }

        $details = openssl_pkey_get_details($privateKey);
        if ($details === false) {
            throw new SecurityException("Failed to get private key details: " . openssl_error_string());
        }
        $keyLengthBytes = (int)($details['bits'] / 8);
        $padding = $policy->getAsymmetricEncryptionPadding();

        $decrypted = '';
        $dataLen = strlen($data);
        $offset = 0;

        while ($offset < $dataLen) {
            $block = substr($data, $offset, $keyLengthBytes);
            $decryptedBlock = '';

            $result = openssl_private_decrypt($block, $decryptedBlock, $privateKey, $padding);
            if ($result === false) {
                throw new SecurityException("Asymmetric decryption failed: " . openssl_error_string());
            }

            $decrypted .= $decryptedBlock;
            $offset += $keyLengthBytes;
        }

        return $decrypted;
    }

    /**
     * @param string $data
     * @param string $signingKey
     * @param SecurityPolicy $policy
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
     */
    public function symmetricVerify(
        string         $data,
        string         $signature,
        string         $signingKey,
        SecurityPolicy $policy,
    ): bool
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
     */
    public function symmetricEncrypt(
        string         $data,
        string         $encryptingKey,
        string         $iv,
        SecurityPolicy $policy,
    ): string
    {
        if ($policy === SecurityPolicy::None) {
            return $data;
        }

        $cipher = $policy->getSymmetricEncryptionAlgorithm();

        $encrypted = openssl_encrypt(
            $data,
            $cipher,
            $encryptingKey,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            $iv,
        );

        if ($encrypted === false) {
            throw new SecurityException("Symmetric encryption failed: " . openssl_error_string());
        }

        return $encrypted;
    }

    /**
     * @param string $data
     * @param string $encryptingKey
     * @param string $iv
     * @param SecurityPolicy $policy
     */
    public function symmetricDecrypt(
        string         $data,
        string         $encryptingKey,
        string         $iv,
        SecurityPolicy $policy,
    ): string
    {
        if ($policy === SecurityPolicy::None) {
            return $data;
        }

        $cipher = $policy->getSymmetricEncryptionAlgorithm();

        $decrypted = openssl_decrypt(
            $data,
            $cipher,
            $encryptingKey,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            $iv,
        );

        if ($decrypted === false) {
            throw new SecurityException("Symmetric decryption failed: " . openssl_error_string());
        }

        return $decrypted;
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
}
