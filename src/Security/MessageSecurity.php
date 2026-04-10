<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Security;

use OpenSSLAsymmetricKey;
use PhpOpcua\Client\Exception\SecurityException;
use PhpOpcua\Client\Exception\UnsupportedCurveException;

/**
 * Low-level cryptographic operations for OPC UA message security.
 */
class MessageSecurity
{
    use EnsuresOpenSslSuccess;

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
            'Asymmetric signing failed',
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
        self::ensureNotFalse($result !== -1 ? $result : false, 'Asymmetric verification failed');

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
                'Asymmetric encryption failed',
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

        $details = self::ensureNotFalse(openssl_pkey_get_details($privateKey), 'Failed to get private key details');
        $keyLengthBytes = (int) ($details['bits'] / 8);
        $padding = $policy->getAsymmetricEncryptionPadding();

        $decrypted = '';
        $dataLen = strlen($data);
        $offset = 0;

        while ($offset < $dataLen) {
            $block = substr($data, $offset, $keyLengthBytes);
            $decryptedBlock = '';

            self::ensureNotFalse(
                openssl_private_decrypt($block, $decryptedBlock, $privateKey, $padding),
                'Asymmetric decryption failed',
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
            'Symmetric encryption failed',
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
            'Symmetric decryption failed',
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
     * @param OpenSSLAsymmetricKey $localPrivateKey
     * @param OpenSSLAsymmetricKey $peerPublicKey
     * @return string Raw ECDH shared secret bytes.
     * @throws SecurityException
     */
    public function computeEcdhSharedSecret(
        OpenSSLAsymmetricKey $localPrivateKey,
        OpenSSLAsymmetricKey $peerPublicKey,
    ): string {
        $secret = openssl_pkey_derive($peerPublicKey, $localPrivateKey);

        return self::ensureNotFalse($secret, 'ECDH key agreement failed');
    }

    /**
     * @param string $sharedSecret The ECDH shared secret (IKM for HKDF).
     * @param string $salt The HKDF salt.
     * @param string $info The HKDF info context (typically clientNonce + serverNonce).
     * @param SecurityPolicy $policy
     * @return array{signingKey: string, encryptingKey: string, iv: string}
     * @throws SecurityException
     */
    public function deriveKeysHkdf(
        string $sharedSecret,
        string $salt,
        string $info,
        SecurityPolicy $policy,
    ): array {
        $sigKeyLen = $policy->getDerivedSignatureKeyLength();
        $encKeyLen = $policy->getDerivedKeyLength();
        $ivLen = $policy->getSymmetricBlockSize();
        $totalLen = $sigKeyLen + $encKeyLen + $ivLen;

        $algorithm = $policy->getKeyDerivationAlgorithm();
        $derived = hash_hkdf($algorithm, $sharedSecret, $totalLen, $info, $salt);

        return [
            'signingKey' => substr($derived, 0, $sigKeyLen),
            'encryptingKey' => substr($derived, $sigKeyLen, $encKeyLen),
            'iv' => substr($derived, $sigKeyLen + $encKeyLen, $ivLen),
        ];
    }

    /**
     * @param string $curveName OpenSSL curve name (e.g. 'prime256v1', 'secp384r1').
     * @return array{privateKey: OpenSSLAsymmetricKey, publicKeyBytes: string}
     * @throws SecurityException
     */
    public function generateEphemeralKeyPair(string $curveName): array
    {
        $key = self::ensureNotFalse(
            openssl_pkey_new([
                'private_key_type' => OPENSSL_KEYTYPE_EC,
                'curve_name' => $curveName,
            ]),
            'Failed to generate ephemeral EC key pair',
        );

        $details = self::ensureNotFalse(openssl_pkey_get_details($key), 'Failed to get ephemeral key details');

        $x = $details['ec']['x'];
        $y = $details['ec']['y'];
        $coordinateSize = self::getCoordinateSize($curveName);

        $publicKeyBytes = "\x04"
            . str_pad($x, $coordinateSize, "\x00", STR_PAD_LEFT)
            . str_pad($y, $coordinateSize, "\x00", STR_PAD_LEFT);

        return [
            'privateKey' => $key,
            'publicKeyBytes' => $publicKeyBytes,
        ];
    }

    /**
     * @param string $publicKeyBytes Uncompressed EC point (0x04 + X + Y).
     * @param string $curveName OpenSSL curve name (e.g. 'prime256v1', 'secp384r1').
     * @return OpenSSLAsymmetricKey
     * @throws SecurityException
     */
    public function loadEcPublicKeyFromBytes(string $publicKeyBytes, string $curveName): OpenSSLAsymmetricKey
    {
        if ($publicKeyBytes[0] !== "\x04") {
            throw new SecurityException('EC public key must be in uncompressed format (0x04 prefix)');
        }

        $coordinateSize = self::getCoordinateSize($curveName);

        $x = substr($publicKeyBytes, 1, $coordinateSize);
        $y = substr($publicKeyBytes, 1 + $coordinateSize, $coordinateSize);

        $curveOid = match ($curveName) {
            'prime256v1' => "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07",
            'secp384r1' => "\x06\x05\x2b\x81\x04\x00\x22",
            'brainpoolP256r1' => "\x06\x09\x2b\x24\x03\x03\x02\x08\x01\x01\x07",
            'brainpoolP384r1' => "\x06\x09\x2b\x24\x03\x03\x02\x08\x01\x01\x0b",
        };

        $ecOid = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";
        $algorithmIdentifier = "\x30" . chr(strlen($ecOid) + strlen($curveOid)) . $ecOid . $curveOid;

        $bitString = "\x03" . $this->derEncodeLength(1 + strlen($publicKeyBytes)) . "\x00" . $publicKeyBytes;

        $spki = "\x30" . $this->derEncodeLength(strlen($algorithmIdentifier) + strlen($bitString))
            . $algorithmIdentifier . $bitString;

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";

        return self::ensureNotFalse(openssl_pkey_get_public($pem), 'Failed to load EC public key from bytes');
    }

    /**
     * @param string $derSignature DER-encoded ECDSA signature.
     * @param int $coordinateSize Size of each coordinate in bytes (32 for P-256, 48 for P-384).
     * @return string Raw (r || s) signature with fixed size.
     * @throws SecurityException
     */
    public function ecdsaDerToRaw(string $derSignature, int $coordinateSize): string
    {
        $offset = 0;
        if (ord($derSignature[$offset]) !== 0x30) {
            throw new SecurityException('Invalid ECDSA DER signature: missing SEQUENCE tag');
        }
        $offset++;
        $offset += (ord($derSignature[$offset]) & 0x80) ? (ord($derSignature[$offset]) & 0x7F) + 1 : 1;

        if (ord($derSignature[$offset]) !== 0x02) {
            throw new SecurityException('Invalid ECDSA DER signature: missing INTEGER tag for r');
        }
        $offset++;
        $rLen = ord($derSignature[$offset]);
        $offset++;
        $r = substr($derSignature, $offset, $rLen);
        $offset += $rLen;

        if (ord($derSignature[$offset]) !== 0x02) {
            throw new SecurityException('Invalid ECDSA DER signature: missing INTEGER tag for s');
        }
        $offset++;
        $sLen = ord($derSignature[$offset]);
        $offset++;
        $s = substr($derSignature, $offset, $sLen);

        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");

        return str_pad($r, $coordinateSize, "\x00", STR_PAD_LEFT)
            . str_pad($s, $coordinateSize, "\x00", STR_PAD_LEFT);
    }

    /**
     * @param string $rawSignature Raw (r || s) fixed-size signature.
     * @param int $coordinateSize Size of each coordinate in bytes.
     * @return string DER-encoded ECDSA signature.
     */
    public function ecdsaRawToDer(string $rawSignature, int $coordinateSize): string
    {
        $r = substr($rawSignature, 0, $coordinateSize);
        $s = substr($rawSignature, $coordinateSize, $coordinateSize);

        $r = ltrim($r, "\x00") ?: "\x00";
        $s = ltrim($s, "\x00") ?: "\x00";

        if (ord($r[0]) & 0x80) {
            $r = "\x00" . $r;
        }
        if (ord($s[0]) & 0x80) {
            $s = "\x00" . $s;
        }

        $rDer = "\x02" . chr(strlen($r)) . $r;
        $sDer = "\x02" . chr(strlen($s)) . $s;
        $inner = $rDer . $sDer;

        return "\x30" . chr(strlen($inner)) . $inner;
    }

    /**
     * @param string $curveName OpenSSL curve name.
     * @return int The coordinate size in bytes (32 for P-256/BP-256, 48 for P-384/BP-384).
     * @throws SecurityException
     */
    private static function getCoordinateSize(string $curveName): int
    {
        return match ($curveName) {
            'prime256v1', 'brainpoolP256r1' => 32,
            'secp384r1', 'brainpoolP384r1' => 48,
            default => throw new UnsupportedCurveException($curveName),
        };
    }

    /**
     * @param int $length
     * @return string DER-encoded length bytes.
     */
    protected function derEncodeLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $bytes = '';
        $temp = $length;
        while ($temp > 0) {
            $bytes = chr($temp & 0xFF) . $bytes;
            $temp >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
