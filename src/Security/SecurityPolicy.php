<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Security;

/**
 * OPC UA security policy URIs with associated algorithm configuration.
 */
enum SecurityPolicy: string
{
    case None = 'http://opcfoundation.org/UA/SecurityPolicy#None';
    case Basic128Rsa15 = 'http://opcfoundation.org/UA/SecurityPolicy#Basic128Rsa15';
    case Basic256 = 'http://opcfoundation.org/UA/SecurityPolicy#Basic256';
    case Basic256Sha256 = 'http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256';
    case Aes128Sha256RsaOaep = 'http://opcfoundation.org/UA/SecurityPolicy#Aes128_Sha256_RsaOaep';
    case Aes256Sha256RsaPss = 'http://opcfoundation.org/UA/SecurityPolicy#Aes256_Sha256_RsaPss';
    case EccNistP256 = 'http://opcfoundation.org/UA/SecurityPolicy#ECC_nistP256';
    case EccNistP384 = 'http://opcfoundation.org/UA/SecurityPolicy#ECC_nistP384';
    case EccBrainpoolP256r1 = 'http://opcfoundation.org/UA/SecurityPolicy#ECC_brainpoolP256r1';
    case EccBrainpoolP384r1 = 'http://opcfoundation.org/UA/SecurityPolicy#ECC_brainpoolP384r1';

    public function getSymmetricEncryptionAlgorithm(): string
    {
        return match ($this) {
            self::None => '',
            self::Basic128Rsa15, self::Aes128Sha256RsaOaep, self::EccNistP256, self::EccBrainpoolP256r1 => 'aes-128-cbc',
            self::Basic256, self::Basic256Sha256, self::Aes256Sha256RsaPss, self::EccNistP384, self::EccBrainpoolP384r1 => 'aes-256-cbc',
        };
    }

    public function getSymmetricSignatureAlgorithm(): string
    {
        return match ($this) {
            self::None => '',
            self::Basic128Rsa15, self::Basic256 => 'sha1',
            self::Basic256Sha256, self::Aes128Sha256RsaOaep, self::Aes256Sha256RsaPss, self::EccNistP256, self::EccBrainpoolP256r1 => 'sha256',
            self::EccNistP384, self::EccBrainpoolP384r1 => 'sha384',
        };
    }

    public function getSymmetricKeyLength(): int
    {
        return match ($this) {
            self::None => 0,
            self::Basic128Rsa15, self::Aes128Sha256RsaOaep, self::EccNistP256, self::EccBrainpoolP256r1 => 16,
            self::Basic256, self::Basic256Sha256, self::Aes256Sha256RsaPss, self::EccNistP384, self::EccBrainpoolP384r1 => 32,
        };
    }

    public function getSymmetricBlockSize(): int
    {
        return match ($this) {
            self::None => 1,
            default => 16, // AES block size is always 16
        };
    }

    public function getSymmetricSignatureSize(): int
    {
        return match ($this) {
            self::None => 0,
            self::Basic128Rsa15, self::Basic256 => 20,
            self::Basic256Sha256, self::Aes128Sha256RsaOaep, self::Aes256Sha256RsaPss, self::EccNistP256, self::EccBrainpoolP256r1 => 32,
            self::EccNistP384, self::EccBrainpoolP384r1 => 48,
        };
    }

    public function getAsymmetricEncryptionPadding(): int
    {
        return match ($this) {
            self::None, self::EccNistP256, self::EccNistP384, self::EccBrainpoolP256r1, self::EccBrainpoolP384r1 => 0,
            self::Basic128Rsa15 => OPENSSL_PKCS1_PADDING,
            self::Basic256, self::Basic256Sha256, self::Aes128Sha256RsaOaep => OPENSSL_PKCS1_OAEP_PADDING,
            self::Aes256Sha256RsaPss => OPENSSL_PKCS1_OAEP_PADDING,
        };
    }

    public function getAsymmetricSignatureAlgorithm(): int|string
    {
        return match ($this) {
            self::None => '',
            self::Basic128Rsa15, self::Basic256 => OPENSSL_ALGO_SHA1,
            self::Basic256Sha256, self::Aes128Sha256RsaOaep => OPENSSL_ALGO_SHA256,
            self::Aes256Sha256RsaPss => OPENSSL_ALGO_SHA256,
            self::EccNistP256, self::EccBrainpoolP256r1 => 'sha256',
            self::EccNistP384, self::EccBrainpoolP384r1 => 'sha384',
        };
    }

    public function getMinAsymmetricKeyLength(): int
    {
        return match ($this) {
            self::None => 0,
            self::Basic128Rsa15 => 1024,
            self::Basic256 => 1024,
            self::Basic256Sha256 => 2048,
            self::Aes128Sha256RsaOaep => 2048,
            self::Aes256Sha256RsaPss => 2048,
            self::EccNistP256, self::EccBrainpoolP256r1 => 256,
            self::EccNistP384, self::EccBrainpoolP384r1 => 384,
        };
    }

    public function getDerivedKeyLength(): int
    {
        return $this->getSymmetricKeyLength();
    }

    public function getDerivedSignatureKeyLength(): int
    {
        return match ($this) {
            self::None => 0,
            self::Basic128Rsa15, self::Basic256 => 20,
            self::Basic256Sha256, self::Aes128Sha256RsaOaep, self::Aes256Sha256RsaPss, self::EccNistP256, self::EccBrainpoolP256r1 => 32,
            self::EccNistP384, self::EccBrainpoolP384r1 => 48,
        };
    }

    public function getKeyDerivationAlgorithm(): string
    {
        return match ($this) {
            self::None => '',
            self::Basic128Rsa15, self::Basic256 => 'sha1',
            self::Basic256Sha256, self::Aes128Sha256RsaOaep, self::Aes256Sha256RsaPss, self::EccNistP256, self::EccBrainpoolP256r1 => 'sha256',
            self::EccNistP384, self::EccBrainpoolP384r1 => 'sha384',
        };
    }

    public function getAsymmetricPaddingOverhead(): int
    {
        return match ($this) {
            self::None, self::EccNistP256, self::EccNistP384, self::EccBrainpoolP256r1, self::EccBrainpoolP384r1 => 0,
            self::Basic128Rsa15 => 11,
            self::Basic256, self::Basic256Sha256, self::Aes128Sha256RsaOaep => 42,
            self::Aes256Sha256RsaPss => 66,
        };
    }

    public function getAsymmetricEncryptionUri(): string
    {
        return match ($this) {
            self::None, self::EccNistP256, self::EccNistP384, self::EccBrainpoolP256r1, self::EccBrainpoolP384r1 => '',
            self::Basic128Rsa15 => 'http://www.w3.org/2001/04/xmlenc#rsa-1_5',
            self::Basic256, self::Basic256Sha256, self::Aes128Sha256RsaOaep => 'http://www.w3.org/2001/04/xmlenc#rsa-oaep',
            self::Aes256Sha256RsaPss => 'http://opcfoundation.org/UA/security/rsa-oaep-sha2-256',
        };
    }

    public function getAsymmetricSignatureUri(): string
    {
        return match ($this) {
            self::None => '',
            self::Basic128Rsa15, self::Basic256 => 'http://www.w3.org/2000/09/xmldsig#rsa-sha1',
            self::Basic256Sha256, self::Aes128Sha256RsaOaep => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
            self::Aes256Sha256RsaPss => 'http://opcfoundation.org/UA/security/rsa-pss-sha2-256',
            self::EccNistP256, self::EccBrainpoolP256r1 => 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256',
            self::EccNistP384, self::EccBrainpoolP384r1 => 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha384',
        };
    }

    /**
     * @return bool True if this policy uses Elliptic Curve Cryptography.
     */
    public function isEcc(): bool
    {
        return match ($this) {
            self::EccNistP256, self::EccNistP384, self::EccBrainpoolP256r1, self::EccBrainpoolP384r1 => true,
            default => false,
        };
    }

    /**
     * @return string OpenSSL curve name for ECDH key agreement.
     */
    public function getEcdhCurveName(): string
    {
        return match ($this) {
            self::EccNistP256 => 'prime256v1',
            self::EccNistP384 => 'secp384r1',
            self::EccBrainpoolP256r1 => 'brainpoolP256r1',
            self::EccBrainpoolP384r1 => 'brainpoolP384r1',
            default => '',
        };
    }

    /**
     * @return int Size in bytes of the ephemeral EC public key nonce (X + Y coordinates, no 0x04 prefix).
     */
    public function getEphemeralKeyLength(): int
    {
        return match ($this) {
            self::EccNistP256, self::EccBrainpoolP256r1 => 64,
            self::EccNistP384, self::EccBrainpoolP384r1 => 96,
            default => 0,
        };
    }
}
