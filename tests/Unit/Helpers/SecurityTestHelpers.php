<?php

declare(strict_types=1);

use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Protocol\MessageHeader;
use PhpOpcua\Client\Security\CertificateManager;
use PhpOpcua\Client\Security\MessageSecurity;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Types\NodeId;

if (! function_exists('generateTestCertKeyPair')) {

    function generateTestCertKeyPair(int $bits = 2048): array
    {
        $privKey = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['CN' => 'test'], $privKey);
        $cert = openssl_csr_sign($csr, null, $privKey, 365);
        openssl_x509_export($cert, $certPem);

        $cm = new CertificateManager();
        $tmpFile = tempnam(sys_get_temp_dir(), 'opcua_h_');
        file_put_contents($tmpFile, $certPem);
        $derCert = $cm->loadCertificatePem($tmpFile);
        unlink($tmpFile);

        return [$derCert, $privKey];
    }

    function buildTestOPNResponse(
        string $serverDer,
        OpenSSLAsymmetricKey $serverKey,
        string $clientDer,
        OpenSSLAsymmetricKey $clientKey,
        string $clientNonce,
        string $serverNonce,
        int $channelId,
        int $tokenId,
        SecurityPolicy $policy,
    ): string {
        $ms = new MessageSecurity();

        $inner = new BinaryEncoder();
        $inner->writeUInt32(1);
        $inner->writeUInt32(1);
        $inner->writeNodeId(NodeId::numeric(0, 449));
        $inner->writeInt64(0);
        $inner->writeUInt32(1);
        $inner->writeUInt32(0);
        $inner->writeByte(0);
        $inner->writeInt32(0);
        $inner->writeNodeId(NodeId::numeric(0, 0));
        $inner->writeByte(0);
        $inner->writeUInt32(0);
        $inner->writeUInt32($channelId);
        $inner->writeUInt32($tokenId);
        $inner->writeInt64(0);
        $inner->writeUInt32(3600000);
        $inner->writeByteString($serverNonce);

        $plainBody = $inner->getBuffer();

        $secHeader = new BinaryEncoder();
        $secHeader->writeString($policy->value);
        $secHeader->writeByteString($serverDer);
        $cm = new CertificateManager();
        $secHeader->writeByteString($cm->getThumbprint($clientDer));
        $secHeaderBytes = $secHeader->getBuffer();

        $keyLengthBytes = $cm->getPublicKeyLength($clientDer);
        $paddingOverhead = $policy->getAsymmetricPaddingOverhead();
        $plainTextBlockSize = $keyLengthBytes - $paddingOverhead;
        $serverKeyDetails = openssl_pkey_get_details($serverKey);
        $signatureSize = (int) ($serverKeyDetails['bits'] / 8);

        $bodyLen = strlen($plainBody);
        $extraPaddingByte = ($keyLengthBytes > 256) ? 1 : 0;
        $overhead = 1 + $extraPaddingByte + $signatureSize;
        $totalWithMinPadding = $bodyLen + $overhead;
        $remainder = $totalWithMinPadding % $plainTextBlockSize;
        $paddingSize = ($remainder === 0) ? 1 : 1 + ($plainTextBlockSize - $remainder);
        $paddingByte = chr(($paddingSize - 1) & 0xFF);
        $padding = str_repeat($paddingByte, $paddingSize);
        if ($extraPaddingByte) {
            $padding .= chr((($paddingSize - 1) >> 8) & 0xFF);
        }
        $bodyWithPadding = $plainBody . $padding;

        $dataToEncryptLen = strlen($bodyWithPadding) + $signatureSize;
        $numBlocks = (int) ceil($dataToEncryptLen / $plainTextBlockSize);
        $encryptedSize = $numBlocks * $keyLengthBytes;

        $totalSize = 12 + strlen($secHeaderBytes) + $encryptedSize;

        $headerEncoder = new BinaryEncoder();
        $msgHeader = new MessageHeader('OPN', 'F', $totalSize);
        $msgHeader->encode($headerEncoder);
        $headerEncoder->writeUInt32($channelId);
        $headerBytes = $headerEncoder->getBuffer();

        $dataToSign = $headerBytes . $secHeaderBytes . $bodyWithPadding;
        $signature = $ms->asymmetricSign($dataToSign, $serverKey, $policy);

        $dataToEncrypt = $bodyWithPadding . $signature;
        $encrypted = $ms->asymmetricEncrypt($dataToEncrypt, $clientDer, $policy);

        return $headerBytes . $secHeaderBytes . $encrypted;
    }
}
