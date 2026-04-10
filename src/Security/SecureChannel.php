<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Security;

use OpenSSLAsymmetricKey;
use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Exception\ProtocolException;
use PhpOpcua\Client\Exception\SecurityException;
use PhpOpcua\Client\Protocol\MessageHeader;
use PhpOpcua\Client\Types\NodeId;

class SecureChannel
{
    private SecurityPolicy $policy;

    private SecurityMode $mode;

    private ?string $clientCertDer;

    private ?string $clientCertChainDer;

    private ?OpenSSLAsymmetricKey $clientPrivateKey;

    private ?string $serverCertDer = null;

    private string $clientNonce = '';

    private ?string $serverNonce = null;

    private ?OpenSSLAsymmetricKey $clientEphemeralPrivateKey = null;

    private int $secureChannelId = 0;

    private int $tokenId = 0;

    private int $sequenceNumber = 1;

    private ?string $clientSigningKey = null;

    private ?string $clientEncryptingKey = null;

    private ?string $clientIv = null;

    private ?string $serverSigningKey = null;

    private ?string $serverEncryptingKey = null;

    private ?string $serverIv = null;

    private MessageSecurity $messageSecurity;

    private CertificateManager $certManager;

    /**
     * @param SecurityPolicy $policy
     * @param SecurityMode $mode
     * @param ?string $clientCertDer
     * @param ?OpenSSLAsymmetricKey $clientPrivateKey
     * @param ?string $serverCertDer
     * @param ?string $clientCertChainDer
     */
    public function __construct(
        SecurityPolicy $policy,
        SecurityMode $mode,
        ?string $clientCertDer = null,
        ?OpenSSLAsymmetricKey $clientPrivateKey = null,
        ?string $serverCertDer = null,
        ?string $clientCertChainDer = null,
    ) {
        $this->policy = $policy;
        $this->mode = $mode;
        $this->clientCertDer = $clientCertDer;
        $this->clientCertChainDer = $clientCertChainDer ?? $clientCertDer;
        $this->clientPrivateKey = $clientPrivateKey;
        $this->serverCertDer = $serverCertDer;
        $this->certManager = new CertificateManager();
        $this->messageSecurity = new MessageSecurity($this->certManager);
    }

    public function getPolicy(): SecurityPolicy
    {
        return $this->policy;
    }

    public function getMode(): SecurityMode
    {
        return $this->mode;
    }

    public function getSecureChannelId(): int
    {
        return $this->secureChannelId;
    }

    public function getTokenId(): int
    {
        return $this->tokenId;
    }

    public function getNextSequenceNumber(): int
    {
        return $this->sequenceNumber++;
    }

    public function getClientNonce(): string
    {
        return $this->clientNonce;
    }

    public function getServerNonce(): ?string
    {
        return $this->serverNonce;
    }

    public function getServerCertDer(): ?string
    {
        return $this->serverCertDer;
    }

    /**
     * @param string $serverCertDer
     */
    public function setServerCertDer(string $serverCertDer): void
    {
        $this->serverCertDer = $serverCertDer;
    }

    public function getClientPrivateKey(): ?OpenSSLAsymmetricKey
    {
        return $this->clientPrivateKey;
    }

    public function getClientCertDer(): ?string
    {
        return $this->clientCertDer;
    }

    public function getMessageSecurity(): MessageSecurity
    {
        return $this->messageSecurity;
    }

    public function getCertificateManager(): CertificateManager
    {
        return $this->certManager;
    }

    public function isSecurityActive(): bool
    {
        return $this->policy !== SecurityPolicy::None && $this->mode !== SecurityMode::None;
    }

    public function createOpenSecureChannelMessage(): string
    {
        if ($this->isSecurityActive()) {
            if ($this->policy->isEcc()) {
                $ephemeral = $this->messageSecurity->generateEphemeralKeyPair($this->policy->getEcdhCurveName());
                $this->clientEphemeralPrivateKey = $ephemeral['privateKey'];
                $this->clientNonce = substr($ephemeral['publicKeyBytes'], 1);
            } else {
                $nonceLength = $this->policy->getDerivedKeyLength();
                $this->clientNonce = random_bytes(max($nonceLength, 32));
            }
        } else {
            $this->clientNonce = '';
        }

        $body = new BinaryEncoder();

        $body->writeString($this->policy->value);
        if ($this->isSecurityActive()) {
            $body->writeByteString($this->clientCertChainDer);
            $body->writeByteString($this->certManager->getThumbprint($this->serverCertDer));
        } else {
            $body->writeByteString(null);
            $body->writeByteString(null);
        }

        $securityHeaderBytes = $body->getBuffer();

        $plainBody = new BinaryEncoder();

        $plainBody->writeUInt32($this->getNextSequenceNumber());
        $plainBody->writeUInt32(1);

        $plainBody->writeNodeId(NodeId::numeric(0, 446));

        $plainBody->writeNodeId(NodeId::numeric(0, 0));
        $plainBody->writeInt64(0);
        $plainBody->writeUInt32(1);
        $plainBody->writeUInt32(0);
        $plainBody->writeString(null);
        $plainBody->writeUInt32(10000);
        $plainBody->writeNodeId(NodeId::numeric(0, 0));
        $plainBody->writeByte(0);

        $plainBody->writeUInt32(0);
        $plainBody->writeUInt32(0);
        $plainBody->writeUInt32($this->mode->value);
        $plainBody->writeByteString($this->clientNonce ?: null);
        $plainBody->writeUInt32(3600000);

        $plainBodyBytes = $plainBody->getBuffer();

        if ($this->isSecurityActive()) {
            if ($this->policy->isEcc()) {
                return $this->createOPNMessageEcc($securityHeaderBytes, $plainBodyBytes);
            }

            $signatureSize = $this->getClientKeyLengthBytes();

            $keyLengthBytes = $this->certManager->getPublicKeyLength($this->serverCertDer);
            $paddingOverhead = $this->policy->getAsymmetricPaddingOverhead();
            $plainTextBlockSize = $keyLengthBytes - $paddingOverhead;

            $bodyWithPadding = $this->addAsymmetricPadding(
                $plainBodyBytes,
                $signatureSize,
                $plainTextBlockSize,
                $keyLengthBytes,
            );

            $dataToEncryptLen = strlen($bodyWithPadding) + $signatureSize;
            $numBlocks = (int) ceil($dataToEncryptLen / $plainTextBlockSize);
            $encryptedSize = $numBlocks * $keyLengthBytes;

            $totalSize = 12 + strlen($securityHeaderBytes) + $encryptedSize;

            $headerEncoder = new BinaryEncoder();
            $msgHeader = new MessageHeader('OPN', 'F', $totalSize);
            $msgHeader->encode($headerEncoder);
            $headerEncoder->writeUInt32($this->secureChannelId);
            $headerBytes = $headerEncoder->getBuffer();

            $dataToSign = $headerBytes . $securityHeaderBytes . $bodyWithPadding;
            $signature = $this->messageSecurity->asymmetricSign($dataToSign, $this->clientPrivateKey, $this->policy);

            $dataToEncrypt = $bodyWithPadding . $signature;
            $encrypted = $this->messageSecurity->asymmetricEncrypt($dataToEncrypt, $this->serverCertDer, $this->policy);

            $encoder = new BinaryEncoder();
            $encoder->writeRawBytes($headerBytes);
            $encoder->writeRawBytes($securityHeaderBytes);
            $encoder->writeRawBytes($encrypted);

            return $encoder->getBuffer();
        }

        $allBody = $securityHeaderBytes . $plainBodyBytes;
        $totalSize = MessageHeader::HEADER_SIZE + 4 + strlen($allBody);

        $encoder = new BinaryEncoder();
        $header = new MessageHeader('OPN', 'F', $totalSize);
        $header->encode($encoder);
        $encoder->writeUInt32($this->secureChannelId);
        $encoder->writeRawBytes($allBody);

        return $encoder->getBuffer();
    }

    /**
     * @param string $response
     * @return array{secureChannelId: int, tokenId: int, revisedLifetime: int, serverNonce: ?string}
     */
    public function processOpenSecureChannelResponse(string $response): array
    {
        $decoder = new BinaryDecoder($response);
        $header = MessageHeader::decode($decoder);

        if ($header->getMessageType() === 'ERR') {
            $statusCode = $decoder->readUInt32();
            $reason = '';
            try {
                $reason = $decoder->readString() ?? '';
            } catch (\Throwable) {
            }
            throw new ProtocolException(sprintf(
                'OPN rejected by server: 0x%08X — %s',
                $statusCode,
                $reason,
            ));
        }

        if ($header->getMessageType() !== 'OPN') {
            throw new ProtocolException("Expected OPN response, got: {$header->getMessageType()}");
        }

        $channelId = $decoder->readUInt32();

        $policyUri = $decoder->readString();

        $senderCert = $decoder->readByteString();

        $receiverThumbprint = $decoder->readByteString();

        if ($this->isSecurityActive()) {
            if ($senderCert !== null) {
                $this->serverCertDer = $senderCert;
            }

            if ($this->policy->isEcc()) {
                $innerDecoder = $this->processOPNResponseEcc($decoder, $response, $policyUri, $senderCert, $receiverThumbprint);
            } else {
                $encryptedData = $decoder->readRawBytes($decoder->getRemainingLength());

                $decryptedData = $this->messageSecurity->asymmetricDecrypt(
                    $encryptedData,
                    $this->clientPrivateKey,
                    $this->policy,
                );

                $signatureSize = $this->certManager->getPublicKeyLength($this->serverCertDer);

                $signature = substr($decryptedData, -$signatureSize);
                $dataWithoutSig = substr($decryptedData, 0, -$signatureSize);

                $headerBytes = substr($response, 0, 12);
                $secHeaderEncoder = new BinaryEncoder();
                $secHeaderEncoder->writeString($policyUri);
                $secHeaderEncoder->writeByteString($senderCert);
                $secHeaderEncoder->writeByteString($receiverThumbprint);
                $signedContent = $headerBytes . $secHeaderEncoder->getBuffer() . $dataWithoutSig;

                if (! $this->messageSecurity->asymmetricVerify($signedContent, $signature, $this->serverCertDer, $this->policy)) {
                    throw new SecurityException('OPN response signature verification failed');
                }

                $strippedData = $this->stripAsymmetricPadding($dataWithoutSig);

                $innerDecoder = new BinaryDecoder($strippedData);
            }
        } else {
            $innerDecoder = $decoder;
        }

        $innerDecoder->readUInt32();
        $innerDecoder->readUInt32();

        $innerDecoder->readNodeId();

        $innerDecoder->readInt64();
        $innerDecoder->readUInt32();
        $statusCode = $innerDecoder->readUInt32();
        $innerDecoder->readByte();
        $stringTableLen = $innerDecoder->readInt32();
        for ($i = 0; $i < max(0, $stringTableLen); $i++) {
            $innerDecoder->readString();
        }
        $innerDecoder->readNodeId();
        $innerDecoder->readByte();

        $innerDecoder->readUInt32();
        $this->secureChannelId = $innerDecoder->readUInt32();
        $this->tokenId = $innerDecoder->readUInt32();
        $innerDecoder->readInt64();
        $revisedLifetime = $innerDecoder->readUInt32();
        $this->serverNonce = $innerDecoder->readByteString();

        if ($this->isSecurityActive() && $this->serverNonce !== null) {
            if ($this->policy->isEcc()) {
                $this->deriveSymmetricKeysEcc();
            } else {
                $this->deriveSymmetricKeys();
            }
        }

        return [
            'secureChannelId' => $this->secureChannelId,
            'tokenId' => $this->tokenId,
            'revisedLifetime' => (int) $revisedLifetime,
            'serverNonce' => $this->serverNonce,
        ];
    }

    /**
     * @param string $innerBody
     * @param string $msgType
     */
    public function buildMessage(string $innerBody, string $msgType = 'MSG'): string
    {
        $sequenceNumber = $this->getNextSequenceNumber();
        $requestId = $this->extractRequestId($innerBody);

        if (! $this->isSecurityActive()) {
            $body = new BinaryEncoder();
            $body->writeUInt32($this->tokenId);
            $body->writeUInt32($sequenceNumber);
            $body->writeUInt32($requestId);
            $body->writeRawBytes($innerBody);

            $bodyBytes = $body->getBuffer();
            $totalSize = MessageHeader::HEADER_SIZE + 4 + strlen($bodyBytes);

            $encoder = new BinaryEncoder();
            $header = new MessageHeader($msgType, 'F', $totalSize);
            $header->encode($encoder);
            $encoder->writeUInt32($this->secureChannelId);
            $encoder->writeRawBytes($bodyBytes);

            return $encoder->getBuffer();
        }

        $tokenIdBytes = pack('V', $this->tokenId);

        $plaintext = new BinaryEncoder();
        $plaintext->writeUInt32($sequenceNumber);
        $plaintext->writeUInt32($requestId);
        $plaintext->writeRawBytes($innerBody);
        $plaintextBytes = $plaintext->getBuffer();

        $blockSize = $this->policy->getSymmetricBlockSize();

        $signatureSize = $this->policy->getSymmetricSignatureSize();

        if ($this->mode === SecurityMode::SignAndEncrypt) {
            $paddedPlaintext = $this->addSymmetricPadding($plaintextBytes, $signatureSize, $blockSize);

            $encryptedDataLen = strlen($paddedPlaintext) + $signatureSize;
            $messageBody = $tokenIdBytes . str_repeat("\x00", $encryptedDataLen);
            $totalSize = MessageHeader::HEADER_SIZE + 4 + strlen($messageBody);

            $headerEncoder = new BinaryEncoder();
            $headerMsg = new MessageHeader($msgType, 'F', $totalSize);
            $headerMsg->encode($headerEncoder);
            $headerEncoder->writeUInt32($this->secureChannelId);
            $headerBytes = $headerEncoder->getBuffer();

            $dataToSign = $headerBytes . $tokenIdBytes . $paddedPlaintext;
            $signature = $this->messageSecurity->symmetricSign($dataToSign, $this->clientSigningKey, $this->policy);

            $dataToEncrypt = $paddedPlaintext . $signature;
            $encrypted = $this->messageSecurity->symmetricEncrypt(
                $dataToEncrypt,
                $this->clientEncryptingKey,
                $this->clientIv,
                $this->policy,
            );

            $encoder = new BinaryEncoder();
            $encoder->writeRawBytes($headerBytes);
            $encoder->writeRawBytes($tokenIdBytes);
            $encoder->writeRawBytes($encrypted);

            return $encoder->getBuffer();
        }

        $messageBody = $tokenIdBytes . $plaintextBytes . str_repeat("\x00", $signatureSize);
        $totalSize = MessageHeader::HEADER_SIZE + 4 + strlen($messageBody);

        $headerEncoder = new BinaryEncoder();
        $headerMsg = new MessageHeader($msgType, 'F', $totalSize);
        $headerMsg->encode($headerEncoder);
        $headerEncoder->writeUInt32($this->secureChannelId);
        $headerBytes = $headerEncoder->getBuffer();

        $dataToSign = $headerBytes . $tokenIdBytes . $plaintextBytes;
        $signature = $this->messageSecurity->symmetricSign($dataToSign, $this->clientSigningKey, $this->policy);

        $encoder = new BinaryEncoder();
        $encoder->writeRawBytes($headerBytes);
        $encoder->writeRawBytes($tokenIdBytes);
        $encoder->writeRawBytes($plaintextBytes);
        $encoder->writeRawBytes($signature);

        return $encoder->getBuffer();
    }

    /**
     * @param string $rawResponse
     */
    public function processMessage(string $rawResponse): string
    {
        if (! $this->isSecurityActive()) {
            return substr($rawResponse, MessageHeader::HEADER_SIZE + 4);
        }

        $msgType = substr($rawResponse, 0, 3);
        if ($msgType === 'ERR') {
            return substr($rawResponse, MessageHeader::HEADER_SIZE + 4);
        }

        $headerBytes = substr($rawResponse, 0, 12);

        $decoder = new BinaryDecoder($rawResponse);
        $header = MessageHeader::decode($decoder);
        $channelId = $decoder->readUInt32();
        $tokenId = $decoder->readUInt32();

        $tokenIdBytes = pack('V', $tokenId);

        $remaining = $decoder->readRawBytes($decoder->getRemainingLength());

        $signatureSize = $this->policy->getSymmetricSignatureSize();
        $blockSize = $this->policy->getSymmetricBlockSize();

        if ($this->mode === SecurityMode::SignAndEncrypt) {
            $decrypted = $this->messageSecurity->symmetricDecrypt(
                $remaining,
                $this->serverEncryptingKey,
                $this->serverIv,
                $this->policy,
            );

            $signature = substr($decrypted, -$signatureSize);
            $dataWithoutSig = substr($decrypted, 0, -$signatureSize);

            $dataToVerify = $headerBytes . $tokenIdBytes . $dataWithoutSig;
            if (! $this->messageSecurity->symmetricVerify($dataToVerify, $signature, $this->serverSigningKey, $this->policy)) {
                throw new SecurityException('MSG response symmetric signature verification failed');
            }

            $plainData = $this->stripSymmetricPadding($dataWithoutSig);

            return $tokenIdBytes . $plainData;
        }

        $signature = substr($remaining, -$signatureSize);
        $dataWithoutSig = substr($remaining, 0, -$signatureSize);

        $dataToVerify = $headerBytes . $tokenIdBytes . $dataWithoutSig;
        if (! $this->messageSecurity->symmetricVerify($dataToVerify, $signature, $this->serverSigningKey, $this->policy)) {
            throw new SecurityException('MSG response symmetric signature verification failed');
        }

        return $tokenIdBytes . $dataWithoutSig;
    }

    private function deriveSymmetricKeys(): void
    {
        $clientKeys = $this->messageSecurity->deriveKeys(
            $this->serverNonce,
            $this->clientNonce,
            $this->policy,
        );
        $this->clientSigningKey = $clientKeys['signingKey'];
        $this->clientEncryptingKey = $clientKeys['encryptingKey'];
        $this->clientIv = $clientKeys['iv'];

        $serverKeys = $this->messageSecurity->deriveKeys(
            $this->clientNonce,
            $this->serverNonce,
            $this->policy,
        );
        $this->serverSigningKey = $serverKeys['signingKey'];
        $this->serverEncryptingKey = $serverKeys['encryptingKey'];
        $this->serverIv = $serverKeys['iv'];
    }

    /**
     * @param string $securityHeaderBytes
     * @param string $plainBodyBytes
     */
    private function asymmetricSignAndEncrypt(string $securityHeaderBytes, string $plainBodyBytes): string
    {
        $keyLengthBytes = $this->certManager->getPublicKeyLength($this->serverCertDer);
        $paddingOverhead = $this->policy->getAsymmetricPaddingOverhead();
        $plainTextBlockSize = $keyLengthBytes - $paddingOverhead;
        $signatureSize = $this->getClientKeyLengthBytes();

        $bodyWithPadding = $this->addAsymmetricPadding(
            $plainBodyBytes,
            $signatureSize,
            $plainTextBlockSize,
            $keyLengthBytes,
        );

        $dataToSign = $securityHeaderBytes . $bodyWithPadding;
        $signature = $this->messageSecurity->asymmetricSign($dataToSign, $this->clientPrivateKey, $this->policy);

        $dataToEncrypt = $bodyWithPadding . $signature;
        $encrypted = $this->messageSecurity->asymmetricEncrypt($dataToEncrypt, $this->serverCertDer, $this->policy);

        return $encrypted;
    }

    /**
     * @param string $plainBody
     * @param int $signatureSize
     * @param int $plainTextBlockSize
     * @param int $keyLengthBytes
     */
    private function addAsymmetricPadding(
        string $plainBody,
        int $signatureSize,
        int $plainTextBlockSize,
        int $keyLengthBytes,
    ): string {
        $bodyLen = strlen($plainBody);
        $extraPaddingByte = ($keyLengthBytes > 256) ? 1 : 0;

        $overhead = 1 + $extraPaddingByte + $signatureSize;
        $totalWithMinPadding = $bodyLen + $overhead;
        $remainder = $totalWithMinPadding % $plainTextBlockSize;

        if ($remainder === 0) {
            $paddingSize = 1;
        } else {
            $paddingSize = 1 + ($plainTextBlockSize - $remainder);
        }

        $paddingByte = chr($paddingSize - 1);
        $padding = str_repeat($paddingByte, $paddingSize);

        if ($extraPaddingByte) {
            $padding .= chr(($paddingSize - 1) >> 8);
        }

        return $plainBody . $padding;
    }

    /**
     * @param string $data
     */
    private function stripAsymmetricPadding(string $data): string
    {
        $keyLengthBytes = $this->getClientKeyLengthBytes();
        $extraPaddingByte = ($keyLengthBytes > 256) ? 1 : 0;

        $dataLen = strlen($data);

        if ($extraPaddingByte) {
            $paddingSizeLow = ord($data[$dataLen - 2]);
            $paddingSizeHigh = ord($data[$dataLen - 1]);
            $paddingSize = $paddingSizeLow + 1 + ($paddingSizeHigh << 8);
            $totalPaddingBytes = $paddingSize + 1;
        } else {
            $paddingSize = ord($data[$dataLen - 1]) + 1;
            $totalPaddingBytes = $paddingSize;
        }

        return substr($data, 0, $dataLen - $totalPaddingBytes);
    }

    /**
     * @param string $plaintext
     * @param int $signatureSize
     * @param int $blockSize
     */
    protected function addSymmetricPadding(string $plaintext, int $signatureSize, int $blockSize): string
    {
        $plaintextLen = strlen($plaintext);

        $overhead = 1 + $signatureSize;
        $totalWithMinPadding = $plaintextLen + $overhead;
        $remainder = $totalWithMinPadding % $blockSize;

        if ($remainder === 0) {
            $paddingSize = 1;
        } else {
            $paddingSize = 1 + ($blockSize - $remainder);
        }

        $paddingByte = chr($paddingSize - 1);
        $padding = str_repeat($paddingByte, $paddingSize);

        return $plaintext . $padding;
    }

    /**
     * @param string $data
     */
    private function stripSymmetricPadding(string $data): string
    {
        $dataLen = strlen($data);
        $paddingSize = ord($data[$dataLen - 1]) + 1;

        return substr($data, 0, $dataLen - $paddingSize);
    }

    private function getClientKeyLengthBytes(): int
    {
        if ($this->clientPrivateKey === null) {
            return 0;
        }

        return $this->extractKeyLengthBytes(openssl_pkey_get_details($this->clientPrivateKey));
    }

    /**
     * @param array|false $details
     * @return int
     */
    private function extractKeyLengthBytes(array|false $details): int
    {
        if ($details === false) {
            throw new SecurityException('Failed to get client private key details');
        }

        return (int) ($details['bits'] / 8);
    }

    /**
     * @param string $innerBody
     */
    private function extractRequestId(string $innerBody): int
    {
        $decoder = new BinaryDecoder($innerBody);
        $decoder->readNodeId();

        $decoder->readNodeId();
        $decoder->readInt64();
        $requestHandle = $decoder->readUInt32();

        return $requestHandle;
    }

    /**
     * @param string $securityHeaderBytes
     * @param string $plainBodyBytes
     * @return string ECC OPN message (signed, NOT encrypted).
     */
    private function createOPNMessageEcc(string $securityHeaderBytes, string $plainBodyBytes): string
    {
        $coordinateSize = $this->policy->getEphemeralKeyLength() / 2;
        $signatureSize = $coordinateSize * 2;

        $totalSize = 12 + strlen($securityHeaderBytes) + strlen($plainBodyBytes) + $signatureSize;

        $headerEncoder = new BinaryEncoder();
        $msgHeader = new MessageHeader('OPN', 'F', $totalSize);
        $msgHeader->encode($headerEncoder);
        $headerEncoder->writeUInt32($this->secureChannelId);
        $headerBytes = $headerEncoder->getBuffer();

        $dataToSign = $headerBytes . $securityHeaderBytes . $plainBodyBytes;
        $derSignature = $this->messageSecurity->asymmetricSign($dataToSign, $this->clientPrivateKey, $this->policy);
        $rawSignature = $this->messageSecurity->ecdsaDerToRaw($derSignature, $coordinateSize);

        $encoder = new BinaryEncoder();
        $encoder->writeRawBytes($headerBytes);
        $encoder->writeRawBytes($securityHeaderBytes);
        $encoder->writeRawBytes($plainBodyBytes);
        $encoder->writeRawBytes($rawSignature);

        return $encoder->getBuffer();
    }

    /**
     * @param BinaryDecoder $decoder
     * @param string $response
     * @param ?string $policyUri
     * @param ?string $senderCert
     * @param ?string $receiverThumbprint
     * @return BinaryDecoder
     */
    private function processOPNResponseEcc(
        BinaryDecoder $decoder,
        string $response,
        ?string $policyUri,
        ?string $senderCert,
        ?string $receiverThumbprint,
    ): BinaryDecoder {
        $signedBody = $decoder->readRawBytes($decoder->getRemainingLength());

        $coordinateSize = $this->policy->getEphemeralKeyLength() / 2;
        $signatureSize = $coordinateSize * 2;
        $dataWithoutSig = substr($signedBody, 0, -$signatureSize);
        $rawSignature = substr($signedBody, -$signatureSize);
        $signature = $this->messageSecurity->ecdsaRawToDer($rawSignature, $coordinateSize);

        $headerBytes = substr($response, 0, 12);
        $secHeaderEncoder = new BinaryEncoder();
        $secHeaderEncoder->writeString($policyUri);
        $secHeaderEncoder->writeByteString($senderCert);
        $secHeaderEncoder->writeByteString($receiverThumbprint);
        $signedContent = $headerBytes . $secHeaderEncoder->getBuffer() . $dataWithoutSig;

        $verified = $this->messageSecurity->asymmetricVerify($signedContent, $signature, $this->serverCertDer, $this->policy);
        if (! $verified) {
            throw new SecurityException('OPN response ECC signature verification failed');
        }

        return new BinaryDecoder($dataWithoutSig);
    }

    private function deriveSymmetricKeysEcc(): void
    {
        $ephemeralKeyLen = $this->policy->getEphemeralKeyLength();
        $serverEphemeralRawKey = substr($this->serverNonce, 0, $ephemeralKeyLen);

        $serverEphemeralPublicKey = $this->messageSecurity->loadEcPublicKeyFromBytes(
            "\x04" . $serverEphemeralRawKey,
            $this->policy->getEcdhCurveName(),
        );

        $sharedSecret = $this->messageSecurity->computeEcdhSharedSecret(
            $this->clientEphemeralPrivateKey,
            $serverEphemeralPublicKey,
        );

        $sigKeyLen = $this->policy->getDerivedSignatureKeyLength();
        $encKeyLen = $this->policy->getDerivedKeyLength();
        $blockSize = $this->policy->getSymmetricBlockSize();
        $totalLen = $sigKeyLen + $encKeyLen + $blockSize;
        $saltKeyLen = $this->mode === SecurityMode::SignAndEncrypt
            ? $totalLen
            : $encKeyLen + $blockSize;

        $algorithm = $this->policy->getKeyDerivationAlgorithm();

        // HKDF key derivation matching UA-.NETStandard UaSCBinaryChannel.Symmetric.cs
        // Salt = uint16_le(encKeyLen+blockSize) + label + clientNonce + serverNonce
        $clientSalt = pack('v', $saltKeyLen) . 'opcua-client' . $this->clientNonce . $this->serverNonce;
        $clientDerived = hash_hkdf($algorithm, $sharedSecret, $totalLen, $clientSalt, $clientSalt);
        $this->clientSigningKey = substr($clientDerived, 0, $sigKeyLen);
        $this->clientEncryptingKey = substr($clientDerived, $sigKeyLen, $encKeyLen);
        $this->clientIv = substr($clientDerived, $sigKeyLen + $encKeyLen, $blockSize);

        $serverSalt = pack('v', $saltKeyLen) . 'opcua-server' . $this->serverNonce . $this->clientNonce;
        $serverDerived = hash_hkdf($algorithm, $sharedSecret, $totalLen, $serverSalt, $serverSalt);
        $this->serverSigningKey = substr($serverDerived, 0, $sigKeyLen);
        $this->serverEncryptingKey = substr($serverDerived, $sigKeyLen, $encKeyLen);
        $this->serverIv = substr($serverDerived, $sigKeyLen + $encKeyLen, $blockSize);
    }
}
