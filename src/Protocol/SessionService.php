<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Protocol;

use OpenSSLAsymmetricKey;
use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Security\MessageSecurity;
use PhpOpcua\Client\Security\SecureChannel;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Types\LocalizedText;
use PhpOpcua\Client\Types\NodeId;

class SessionService
{
    private int $sequenceNumber = 2;

    private string $usernamePolicyId = 'username';

    private ?string $lastEccServerEphemeralKey = null;

    private ?string $currentEccServerEphemeralKey = null;

    private string $certificatePolicyId = 'certificate';

    private string $anonymousPolicyId = 'anonymous';

    private ?string $usernameTokenSecurityPolicyUri = null;

    private ?string $userTokenServerCertDer = null;

    private ?MessageSecurity $userTokenMessageSecurity = null;

    /**
     * @param int $secureChannelId
     * @param int $tokenId
     * @param ?SecureChannel $secureChannel
     */
    public function __construct(
        private readonly int $secureChannelId,
        private readonly int $tokenId,
        private readonly ?SecureChannel $secureChannel = null,
    ) {
    }

    /**
     * @param string|null $serverCertDer
     * @param MessageSecurity|null $messageSecurity
     * @return void
     */
    public function setUserTokenEncryptionContext(
        ?string $serverCertDer,
        ?MessageSecurity $messageSecurity,
    ): void {
        $this->userTokenServerCertDer = $serverCertDer;
        $this->userTokenMessageSecurity = $messageSecurity;
    }

    /**
     * @param ?string $usernamePolicyId
     * @param ?string $certificatePolicyId
     * @param ?string $anonymousPolicyId
     * @param ?string $usernameTokenSecurityPolicyUri
     */
    public function setUserTokenPolicyIds(
        ?string $usernamePolicyId = null,
        ?string $certificatePolicyId = null,
        ?string $anonymousPolicyId = null,
        ?string $usernameTokenSecurityPolicyUri = null,
    ): void {
        if ($usernamePolicyId !== null) {
            $this->usernamePolicyId = $usernamePolicyId;
        }
        if ($certificatePolicyId !== null) {
            $this->certificatePolicyId = $certificatePolicyId;
        }
        if ($anonymousPolicyId !== null) {
            $this->anonymousPolicyId = $anonymousPolicyId;
        }
        $this->usernameTokenSecurityPolicyUri = $usernameTokenSecurityPolicyUri;
    }

    public function getSecureChannelId(): int
    {
        if ($this->secureChannel !== null) {
            return $this->secureChannel->getSecureChannelId();
        }

        return $this->secureChannelId;
    }

    public function getTokenId(): int
    {
        if ($this->secureChannel !== null) {
            return $this->secureChannel->getTokenId();
        }

        return $this->tokenId;
    }

    public function getNextSequenceNumber(): int
    {
        if ($this->secureChannel !== null) {
            return $this->secureChannel->getNextSequenceNumber();
        }

        return $this->sequenceNumber++;
    }

    public function getSecureChannel(): ?SecureChannel
    {
        return $this->secureChannel;
    }

    /**
     * @param int $requestId
     * @param string $endpointUrl
     */
    public function encodeCreateSessionRequest(int $requestId, string $endpointUrl): string
    {
        if ($this->secureChannel !== null && $this->secureChannel->isSecurityActive()) {
            return $this->encodeCreateSessionRequestSecure($requestId, $endpointUrl);
        }

        $body = new BinaryEncoder();
        $this->writeSecurityHeader($body);
        $this->writeSequenceHeader($body, $requestId);

        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::CREATE_SESSION_REQUEST));

        $this->writeRequestHeader($body, $requestId);

        $this->writeCreateSessionBody($body, $endpointUrl);

        return $this->wrapInMessage($body->getBuffer(), 'MSG');
    }

    /**
     * @param BinaryDecoder $decoder
     * @return array{sessionId: NodeId, authenticationToken: NodeId, serverNonce: ?string, serverCertificate: ?string}
     */
    public function decodeCreateSessionResponse(BinaryDecoder $decoder): array
    {
        $this->readSecurityHeader($decoder);
        $this->readSequenceHeader($decoder);

        $typeId = $decoder->readNodeId();

        $status = $this->readResponseHeader($decoder);

        ServiceFault::throwIf($typeId, $status);

        $sessionId = $decoder->readNodeId();
        $authenticationToken = $decoder->readNodeId();
        $revisedSessionTimeout = $decoder->readDouble();
        $serverNonce = $decoder->readByteString();
        $serverCertificate = $decoder->readByteString();

        $endpointCount = $decoder->readInt32();
        for ($i = 0; $i < $endpointCount; $i++) {
            $this->skipEndpointDescription($decoder);
        }

        $certCount = $decoder->readInt32();
        for ($i = 0; $i < $certCount; $i++) {
            $this->skipSignedSoftwareCertificate($decoder);
        }

        $decoder->readString();
        $decoder->readByteString();

        $decoder->readUInt32();

        $eccServerEphemeralKey = $this->readEccServerEphemeralKey($decoder);

        return [
            'sessionId' => $sessionId,
            'authenticationToken' => $authenticationToken,
            'serverNonce' => $serverNonce,
            'serverCertificate' => $serverCertificate,
            'eccServerEphemeralKey' => $eccServerEphemeralKey,
        ];
    }

    /**
     * @param int $requestId
     * @param NodeId $authenticationToken
     * @param ?string $username
     * @param ?string $password
     * @param ?string $userCertDer
     * @param ?OpenSSLAsymmetricKey $userPrivateKey
     * @param ?string $serverNonce
     */
    public function encodeActivateSessionRequest(
        int $requestId,
        NodeId $authenticationToken,
        ?string $username = null,
        ?string $password = null,
        ?string $userCertDer = null,
        ?OpenSSLAsymmetricKey $userPrivateKey = null,
        ?string $serverNonce = null,
        ?string $eccServerEphemeralKey = null,
    ): string {
        if ($this->secureChannel !== null && $this->secureChannel->isSecurityActive()) {
            return $this->encodeActivateSessionRequestSecure(
                $requestId,
                $authenticationToken,
                $username,
                $password,
                $userCertDer,
                $userPrivateKey,
                $serverNonce,
                $eccServerEphemeralKey,
            );
        }

        $body = new BinaryEncoder();
        $this->writeSecurityHeader($body);
        $this->writeSequenceHeader($body, $requestId);

        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::ACTIVATE_SESSION_REQUEST));

        $body->writeNodeId($authenticationToken);
        $body->writeDateTime(new \DateTimeImmutable());
        $body->writeUInt32($requestId);
        $body->writeUInt32(0);
        $body->writeString(null);
        $body->writeUInt32(10000);
        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::NULL));
        $body->writeByte(0);

        $body->writeString(null);
        $body->writeByteString(null);

        $body->writeInt32(0);

        $body->writeInt32(0);

        $this->writeIdentityToken(
            $body,
            $username,
            $password,
            $userCertDer,
            $userPrivateKey,
            $serverNonce,
        );

        if ($userCertDer !== null && $userPrivateKey !== null && $serverNonce !== null) {
            $this->writeUserTokenSignature($body, $userPrivateKey, $serverNonce);
        } else {
            $body->writeString(null);
            $body->writeByteString(null);
        }

        return $this->wrapInMessage($body->getBuffer(), 'MSG');
    }

    /**
     * @param BinaryDecoder $decoder
     */
    public function decodeActivateSessionResponse(BinaryDecoder $decoder): void
    {
        $this->readSecurityHeader($decoder);
        $this->readSequenceHeader($decoder);

        $typeId = $decoder->readNodeId();
        $statusCode = $this->readResponseHeader($decoder);

        ServiceFault::throwIf($typeId, $statusCode);

        if (($statusCode & 0x80000000) !== 0) {
            throw new ServiceException(sprintf('ActivateSession failed with status 0x%08X', $statusCode), $statusCode);
        }

        $decoder->readByteString();
        $count = $decoder->readInt32();
        for ($i = 0; $i < $count; $i++) {
            $decoder->readUInt32();
        }
        $decoder->skipDiagnosticInfoArray();
    }

    /**
     * @param BinaryEncoder $encoder
     */
    private function writeSecurityHeader(BinaryEncoder $encoder): void
    {
        $encoder->writeUInt32($this->getTokenId());
    }

    /**
     * @param BinaryEncoder $encoder
     * @param int $requestId
     */
    private function writeSequenceHeader(BinaryEncoder $encoder, int $requestId): void
    {
        $encoder->writeUInt32($this->getNextSequenceNumber());
        $encoder->writeUInt32($requestId);
    }

    /**
     * @param BinaryEncoder $encoder
     * @param int $requestHandle
     */
    private function writeRequestHeader(BinaryEncoder $encoder, int $requestHandle): void
    {
        $encoder->writeNodeId(NodeId::numeric(0, ServiceTypeId::NULL));
        $encoder->writeDateTime(new \DateTimeImmutable());
        $encoder->writeUInt32($requestHandle);
        $encoder->writeUInt32(0);
        $encoder->writeString(null);
        $encoder->writeUInt32(10000);
        $encoder->writeNodeId(NodeId::numeric(0, ServiceTypeId::NULL));
        $encoder->writeByte(0);
    }

    /**
     * @param BinaryDecoder $decoder
     */
    private function readSecurityHeader(BinaryDecoder $decoder): void
    {
        $decoder->readUInt32();
    }

    /**
     * @param BinaryDecoder $decoder
     */
    private function readSequenceHeader(BinaryDecoder $decoder): void
    {
        $decoder->readUInt32();
        $decoder->readUInt32();
    }

    /**
     * @param BinaryDecoder $decoder
     */
    public function readResponseHeader(BinaryDecoder $decoder): int
    {
        $decoder->readInt64();
        $decoder->readUInt32();
        $statusCode = $decoder->readUInt32();
        $diagMask = $decoder->readByte();
        $decoder->skipDiagnosticInfoBody($diagMask);
        $count = $decoder->readInt32();
        for ($i = 0; $i < $count; $i++) {
            $decoder->readString();
        }

        $additionalHeaderTypeId = $decoder->readNodeId();
        $additionalHeaderEncoding = $decoder->readByte();

        if (getenv('OPCUA_ECC_DEBUG')) {
            fwrite(STDERR, '[ECC] AdditionalHeader: typeId=' . $additionalHeaderTypeId . ' encoding=' . $additionalHeaderEncoding . "\n");
        }

        if ($additionalHeaderEncoding === 0x01) {
            $bodyLen = $decoder->readInt32();
            $bodyBytes = $decoder->readRawBytes($bodyLen);
            if (getenv('OPCUA_ECC_DEBUG')) {
                fwrite(STDERR, '[ECC] AdditionalHeader body: ' . $bodyLen . 'b hex=' . bin2hex(substr($bodyBytes, 0, 40)) . "...\n");
            }
            $this->parseAdditionalParameters($bodyBytes);
        }

        return $statusCode;
    }

    /**
     * @param string $bodyBytes
     */
    private function parseAdditionalParameters(string $bodyBytes): void
    {
        $dec = new BinaryDecoder($bodyBytes);

        $paramCount = $dec->readInt32();
        for ($i = 0; $i < $paramCount; $i++) {
            $nsIndex = $dec->readUInt16();
            $key = $dec->readString();

            $variantEncoding = $dec->readByte();
            if ($key === 'ECDHKey' && ($variantEncoding & 0x3F) === 22) {
                $extTypeId = $dec->readNodeId();
                $extEncoding = $dec->readByte();
                if ($extEncoding === 0x01) {
                    $extBodyLen = $dec->readInt32();
                    $publicKey = $dec->readByteString();
                    $signature = $dec->readByteString();
                    $this->lastEccServerEphemeralKey = $publicKey;
                }
            } else {
                $this->skipVariantValue($dec, $variantEncoding);
            }
        }
    }

    /**
     * @param BinaryDecoder $dec
     * @param int $encoding
     */
    private function skipVariantValue(BinaryDecoder $dec, int $encoding): void
    {
        $builtinType = $encoding & 0x3F;
        match ($builtinType) {
            1 => $dec->readByte(),
            2, 3 => $dec->readByte(),
            4, 5 => $dec->readRawBytes(2),
            6, 7 => $dec->readUInt32(),
            8, 9 => $dec->readRawBytes(8),
            10 => $dec->readRawBytes(4),
            11 => $dec->readRawBytes(8),
            12 => $dec->readString(),
            13 => $dec->readRawBytes(8),
            14 => $dec->readRawBytes(16),
            15, 16 => $dec->readByteString(),
            22 => $this->skipExtensionObject($dec),
            default => null,
        };
    }

    /**
     * @param BinaryDecoder $dec
     */
    private function skipExtensionObject(BinaryDecoder $dec): void
    {
        $dec->readNodeId();
        $enc = $dec->readByte();
        if ($enc === 0x01) {
            $len = $dec->readInt32();
            $dec->readRawBytes($len);
        }
    }

    /**
     * @return ?string
     */
    /**
     * @param BinaryEncoder $body
     * @param string $eccPolicyUri
     */
    private function writeEcdhAdditionalHeader(BinaryEncoder $body, string $eccPolicyUri): void
    {
        $inner = new BinaryEncoder();
        $inner->writeInt32(1);
        $inner->writeUInt16(0);
        $inner->writeString('ECDHPolicyUri');
        $inner->writeByte(12);
        $inner->writeString($eccPolicyUri);
        $innerBytes = $inner->getBuffer();

        $body->writeNodeId(NodeId::numeric(0, 17537));
        $body->writeByte(0x01);
        $body->writeInt32(strlen($innerBytes));
        $body->writeRawBytes($innerBytes);
    }

    public function getLastEccServerEphemeralKey(): ?string
    {
        return $this->lastEccServerEphemeralKey;
    }

    /**
     * @param BinaryDecoder $decoder
     * @return ?string
     */
    private function readEccServerEphemeralKey(BinaryDecoder $decoder): ?string
    {
        $remaining = $decoder->getRemainingLength();
        if ($remaining <= 0) {
            return null;
        }

        if (getenv('OPCUA_ECC_DEBUG')) {
            fwrite(STDERR, "[ECC] CreateSession response: $remaining bytes remaining after maxRequestSize\n");
            $rawRemaining = $decoder->readRawBytes($remaining);
            fwrite(STDERR, '[ECC] Remaining hex: ' . bin2hex(substr($rawRemaining, 0, min(80, $remaining))) . "\n");

            $innerDec = new BinaryDecoder($rawRemaining);

            try {
                $key = $innerDec->readByteString();
                fwrite(STDERR, '[ECC] eccServerEphemeralKey: ' . strlen($key ?? '') . " bytes\n");

                return $key;
            } catch (\Throwable $e) {
                fwrite(STDERR, '[ECC] Failed to read eccServerEphemeralKey: ' . $e->getMessage() . "\n");

                return null;
            }
        }

        try {
            return $decoder->readByteString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param string $bodyBytes
     * @param string $msgType
     */
    private function wrapInMessage(string $bodyBytes, string $msgType): string
    {
        if ($this->secureChannel !== null && $this->secureChannel->isSecurityActive()) {
        }

        $totalSize = MessageHeader::HEADER_SIZE + 4 + strlen($bodyBytes);

        $encoder = new BinaryEncoder();
        $header = new MessageHeader($msgType, 'F', $totalSize);
        $header->encode($encoder);
        $encoder->writeUInt32($this->getSecureChannelId());
        $encoder->writeRawBytes($bodyBytes);

        return $encoder->getBuffer();
    }

    /**
     * @param string $innerBody
     * @param string $msgType
     */
    public function wrapWithSecureChannel(string $innerBody, string $msgType = 'MSG'): string
    {
        if ($this->secureChannel !== null && $this->secureChannel->isSecurityActive()) {
            return $this->secureChannel->buildMessage($innerBody, $msgType);
        }

        $body = new BinaryEncoder();
        $body->writeUInt32($this->getTokenId());
        $body->writeUInt32($this->getNextSequenceNumber());

        $decoder = new BinaryDecoder($innerBody);
        $decoder->readNodeId();
        $decoder->readNodeId();
        $decoder->readInt64();
        $requestHandle = $decoder->readUInt32();
        $body->writeUInt32($requestHandle);

        $body->writeRawBytes($innerBody);
        $bodyBytes = $body->getBuffer();
        $totalSize = MessageHeader::HEADER_SIZE + 4 + strlen($bodyBytes);

        $encoder = new BinaryEncoder();
        $header = new MessageHeader($msgType, 'F', $totalSize);
        $header->encode($encoder);
        $encoder->writeUInt32($this->getSecureChannelId());
        $encoder->writeRawBytes($bodyBytes);

        return $encoder->getBuffer();
    }

    /**
     * @param string $rawResponse
     */
    public function unwrapResponse(string $rawResponse): string
    {
        if ($this->secureChannel !== null && $this->secureChannel->isSecurityActive()) {
            return $this->secureChannel->processMessage($rawResponse);
        }

        return substr($rawResponse, MessageHeader::HEADER_SIZE + 4);
    }

    /**
     * @param int $requestId
     * @param string $endpointUrl
     */
    private function encodeCreateSessionRequestSecure(int $requestId, string $endpointUrl): string
    {
        $innerBody = new BinaryEncoder();

        $innerBody->writeNodeId(NodeId::numeric(0, ServiceTypeId::CREATE_SESSION_REQUEST));

        $eccPolicyUri = ($this->secureChannel !== null && $this->secureChannel->getPolicy()->isEcc())
            ? $this->secureChannel->getPolicy()->value : null;

        $innerBody->writeNodeId(NodeId::numeric(0, ServiceTypeId::NULL));
        $innerBody->writeDateTime(new \DateTimeImmutable());
        $innerBody->writeUInt32($requestId);
        $innerBody->writeUInt32(0);
        $innerBody->writeString(null);
        $innerBody->writeUInt32(10000);
        if ($eccPolicyUri !== null) {
            $this->writeEcdhAdditionalHeader($innerBody, $eccPolicyUri);
        } else {
            $innerBody->writeNodeId(NodeId::numeric(0, ServiceTypeId::NULL));
            $innerBody->writeByte(0);
        }

        $applicationUri = $this->secureChannel->getCertificateManager()->getApplicationUri(
            $this->secureChannel->getClientCertDer(),
        ) ?? 'urn:opcua-client:client';
        $innerBody->writeString($applicationUri);
        $innerBody->writeString(null);
        $innerBody->writeLocalizedText(new LocalizedText(null, 'opcua-client'));
        $innerBody->writeUInt32(1);
        $innerBody->writeString(null);
        $innerBody->writeString(null);
        $innerBody->writeInt32(0);

        $innerBody->writeString(null);
        $innerBody->writeString($endpointUrl);
        $innerBody->writeString('opcua-client-session');

        $nonce = random_bytes(32);
        $innerBody->writeByteString($nonce);

        $clientCertDer = $this->secureChannel->getClientCertDer();
        $innerBody->writeByteString($clientCertDer);

        $innerBody->writeDouble(120000.0);
        $innerBody->writeUInt32(0);

        return $this->secureChannel->buildMessage($innerBody->getBuffer());
    }

    /**
     * @param int $requestId
     * @param NodeId $authenticationToken
     * @param ?string $username
     * @param ?string $password
     * @param ?string $userCertDer
     * @param ?OpenSSLAsymmetricKey $userPrivateKey
     * @param ?string $serverNonce
     */
    private function encodeActivateSessionRequestSecure(
        int $requestId,
        NodeId $authenticationToken,
        ?string $username,
        ?string $password,
        ?string $userCertDer,
        ?OpenSSLAsymmetricKey $userPrivateKey,
        ?string $serverNonce,
        ?string $eccServerEphemeralKey = null,
    ): string {
        $innerBody = new BinaryEncoder();

        $innerBody->writeNodeId(NodeId::numeric(0, ServiceTypeId::ACTIVATE_SESSION_REQUEST));

        $innerBody->writeNodeId($authenticationToken);
        $innerBody->writeDateTime(new \DateTimeImmutable());
        $innerBody->writeUInt32($requestId);
        $innerBody->writeUInt32(0);
        $innerBody->writeString(null);
        $innerBody->writeUInt32(10000);
        if ($this->secureChannel !== null && $this->secureChannel->getPolicy()->isEcc()) {
            $this->writeEcdhAdditionalHeader($innerBody, $this->secureChannel->getPolicy()->value);
        } else {
            $innerBody->writeNodeId(NodeId::numeric(0, ServiceTypeId::NULL));
            $innerBody->writeByte(0);
        }

        $this->currentEccServerEphemeralKey = $eccServerEphemeralKey;

        $this->writeClientSignature($innerBody, $serverNonce);

        $innerBody->writeInt32(0);

        $innerBody->writeInt32(0);

        $this->writeIdentityToken(
            $innerBody,
            $username,
            $password,
            $userCertDer,
            $userPrivateKey,
            $serverNonce,
        );

        if ($userCertDer !== null && $userPrivateKey !== null && $serverNonce !== null) {
            $this->writeUserTokenSignature($innerBody, $userPrivateKey, $serverNonce);
        } else {
            $innerBody->writeString(null);
            $innerBody->writeByteString(null);
        }

        return $this->secureChannel->buildMessage($innerBody->getBuffer());
    }

    /**
     * @param BinaryEncoder $encoder
     * @param ?string $createSessionNonce
     */
    private function writeClientSignature(BinaryEncoder $encoder, ?string $createSessionNonce = null): void
    {
        $serverCertDer = $this->secureChannel->getServerCertDer();
        $serverNonce = $createSessionNonce ?? $this->secureChannel->getServerNonce();
        $clientPrivateKey = $this->secureChannel->getClientPrivateKey();
        $policy = $this->secureChannel->getPolicy();

        if ($serverCertDer === null || $serverNonce === null || $clientPrivateKey === null) {
            $encoder->writeByteString(null);
            $encoder->writeString(null);

            return;
        }

        $serverLeafCert = $this->extractLeafCertificate($serverCertDer);
        $dataToSign = $serverLeafCert . $serverNonce;
        $signature = $this->secureChannel->getMessageSecurity()->asymmetricSign(
            $dataToSign,
            $clientPrivateKey,
            $policy,
        );

        if ($policy->isEcc()) {
            $coordinateSize = $policy->getEphemeralKeyLength() / 2;
            $signature = $this->secureChannel->getMessageSecurity()->ecdsaDerToRaw($signature, $coordinateSize);
        }

        $encoder->writeString($policy->getAsymmetricSignatureUri());
        $encoder->writeByteString($signature);
    }

    /**
     * @param BinaryEncoder $encoder
     * @param ?string $username
     * @param ?string $password
     * @param ?string $userCertDer
     * @param ?OpenSSLAsymmetricKey $userPrivateKey
     * @param ?string $serverNonce
     */
    private function writeIdentityToken(
        BinaryEncoder $encoder,
        ?string $username,
        ?string $password,
        ?string $userCertDer,
        ?OpenSSLAsymmetricKey $userPrivateKey,
        ?string $serverNonce,
    ): void {
        if ($username !== null && $password !== null) {
            $this->writeUsernameIdentityToken($encoder, $username, $password, $serverNonce);
        } elseif ($userCertDer !== null) {
            $this->writeX509IdentityToken($encoder, $userCertDer);
        } else {
            $this->writeAnonymousIdentityToken($encoder);
        }
    }

    /**
     * @param BinaryEncoder $encoder
     */
    private function writeAnonymousIdentityToken(BinaryEncoder $encoder): void
    {
        $encoder->writeNodeId(NodeId::numeric(0, ServiceTypeId::ANONYMOUS_IDENTITY_TOKEN));
        $encoder->writeByte(0x01);

        $tokenBody = new BinaryEncoder();
        $tokenBody->writeString($this->anonymousPolicyId);
        $tokenBodyBytes = $tokenBody->getBuffer();
        $encoder->writeInt32(strlen($tokenBodyBytes));
        $encoder->writeRawBytes($tokenBodyBytes);
    }

    /**
     * @param BinaryEncoder $encoder
     * @param string $username
     * @param string $password
     * @param ?string $serverNonce
     */
    private function writeUsernameIdentityToken(
        BinaryEncoder $encoder,
        string $username,
        string $password,
        ?string $serverNonce,
    ): void {
        $encoder->writeNodeId(NodeId::numeric(0, ServiceTypeId::USERNAME_IDENTITY_TOKEN));
        $encoder->writeByte(0x01);

        $tokenBody = new BinaryEncoder();
        $tokenBody->writeString($this->usernamePolicyId);
        $tokenBody->writeString($username);

        $effectivePolicy = $this->resolveUserTokenEncryptionPolicy();
        $serverCertDer = $this->resolveUserTokenServerCertDer();
        $messageSecurity = $this->resolveUserTokenMessageSecurity();

        if ($effectivePolicy !== SecurityPolicy::None
            && $serverNonce !== null
            && $serverCertDer !== null
            && $messageSecurity !== null
        ) {
            $passwordBytes = $password;
            $nonceBytes = $serverNonce;

            if ($effectivePolicy->isEcc()) {
                $encryptedSecret = $this->buildEccEncryptedSecret($passwordBytes, $nonceBytes, $effectivePolicy);
                $tokenBody->writeByteString($encryptedSecret);
                $tokenBody->writeString(null);
            } else {
                $plaintext = pack('V', strlen($passwordBytes) + strlen($nonceBytes))
                    . $passwordBytes
                    . $nonceBytes;
                $encrypted = $messageSecurity->asymmetricEncrypt($plaintext, $serverCertDer, $effectivePolicy);

                $tokenBody->writeByteString($encrypted);
                $tokenBody->writeString($effectivePolicy->getAsymmetricEncryptionUri());
            }
        } else {
            $tokenBody->writeByteString($password);
            $tokenBody->writeString(null);
        }

        $tokenBodyBytes = $tokenBody->getBuffer();
        $encoder->writeInt32(strlen($tokenBodyBytes));
        $encoder->writeRawBytes($tokenBodyBytes);
    }

    /**
     * @param string $password Raw UTF-8 password bytes.
     * @param string $receiverNonce Server session nonce (contains server ephemeral public key).
     * @param SecurityPolicy $policy
     * @return string Complete EccEncryptedSecret blob for the Password ByteString field.
     */
    private function buildEccEncryptedSecret(string $password, string $receiverNonce, SecurityPolicy $policy): string
    {
        $ms = $this->secureChannel->getMessageSecurity();
        $clientPrivateKey = $this->secureChannel->getClientPrivateKey();
        $clientCertDer = $this->secureChannel->getClientCertDer();
        $curveName = $policy->getEcdhCurveName();
        $algorithm = $policy->getKeyDerivationAlgorithm();
        $coordinateSize = $policy->getEphemeralKeyLength() / 2;
        $signatureSize = $coordinateSize * 2;

        $ephemeral = $ms->generateEphemeralKeyPair($curveName);
        $senderNonce = substr($ephemeral['publicKeyBytes'], 1);

        $eccReceiverNonce = $this->currentEccServerEphemeralKey ?? $receiverNonce;
        $ephemeralKeyLen = $policy->getEphemeralKeyLength();
        $receiverEphemeralRawKey = substr($eccReceiverNonce, 0, $ephemeralKeyLen);
        $receiverEphemeralPub = $ms->loadEcPublicKeyFromBytes("\x04" . $receiverEphemeralRawKey, $curveName);

        $sharedSecret = $ms->computeEcdhSharedSecret($ephemeral['privateKey'], $receiverEphemeralPub);

        $encKeyLen = $policy->getDerivedKeyLength();
        $blockSize = $policy->getSymmetricBlockSize();
        $salt = pack('v', $encKeyLen + $blockSize) . 'opcua-secret' . $senderNonce . $eccReceiverNonce;
        $keyData = hash_hkdf($algorithm, $sharedSecret, $encKeyLen + $blockSize, $salt, $salt);
        $encKey = substr($keyData, 0, $encKeyLen);
        $iv = substr($keyData, $encKeyLen, $blockSize);

        $secretEncoder = new BinaryEncoder();
        $secretEncoder->writeByteString($receiverNonce);
        $secretEncoder->writeByteString($password);
        $pos = strlen($secretEncoder->getBuffer());
        $paddingSize = $blockSize - (($pos + 2) % $blockSize);
        $paddingSize %= $blockSize;
        if (strlen($password) + $paddingSize < $blockSize) {
            $paddingSize += $blockSize;
        }
        for ($i = 0; $i < $paddingSize; $i++) {
            $secretEncoder->writeByte($paddingSize & 0xFF);
        }
        $secretEncoder->writeUInt16($paddingSize);
        $dataToEncrypt = $secretEncoder->getBuffer();

        $cipher = $policy->getSymmetricEncryptionAlgorithm();
        $encryptedData = openssl_encrypt($dataToEncrypt, $cipher, $encKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);

        $headerLen = strlen($senderNonce) + strlen($eccReceiverNonce) + 8;

        $body = new BinaryEncoder();
        $body->writeString($policy->value);
        $body->writeByteString($clientCertDer);
        $body->writeDateTime(new \DateTimeImmutable());
        $body->writeUInt16($headerLen);
        $body->writeByteString($senderNonce);
        $body->writeByteString($eccReceiverNonce);
        $body->writeRawBytes($encryptedData);
        $body->writeRawBytes(str_repeat("\x00", $signatureSize));
        $bodyBytes = $body->getBuffer();

        $ext = new BinaryEncoder();
        $ext->writeNodeId(NodeId::numeric(0, 17546));
        $ext->writeByte(0x01);
        $ext->writeInt32(strlen($bodyBytes));
        $ext->writeRawBytes($bodyBytes);
        $fullBlob = $ext->getBuffer();

        $dataToSign = substr($fullBlob, 0, strlen($fullBlob) - $signatureSize);
        $derSig = $ms->asymmetricSign($dataToSign, $clientPrivateKey, $policy);
        $rawSig = $ms->ecdsaDerToRaw($derSig, $coordinateSize);

        return $dataToSign . $rawSig;
    }

    /**
     * @param BinaryEncoder $encoder
     * @param string $userCertDer
     */
    private function writeX509IdentityToken(BinaryEncoder $encoder, string $userCertDer): void
    {
        $encoder->writeNodeId(NodeId::numeric(0, ServiceTypeId::X509_IDENTITY_TOKEN));
        $encoder->writeByte(0x01);

        $tokenBody = new BinaryEncoder();
        $tokenBody->writeString($this->certificatePolicyId);
        $tokenBody->writeByteString($userCertDer);

        $tokenBodyBytes = $tokenBody->getBuffer();
        $encoder->writeInt32(strlen($tokenBodyBytes));
        $encoder->writeRawBytes($tokenBodyBytes);
    }

    /**
     * @param BinaryEncoder $encoder
     * @param OpenSSLAsymmetricKey $userPrivateKey
     * @param string $serverNonce
     */
    private function writeUserTokenSignature(
        BinaryEncoder $encoder,
        OpenSSLAsymmetricKey $userPrivateKey,
        string $serverNonce,
    ): void {
        $policy = $this->secureChannel?->getPolicy() ?? SecurityPolicy::None;
        $serverCertDer = $this->secureChannel?->getServerCertDer();

        if ($serverCertDer === null || $policy === SecurityPolicy::None) {
            $encoder->writeString(null);
            $encoder->writeByteString(null);

            return;
        }

        $serverLeafCert = $this->extractLeafCertificate($serverCertDer);
        $dataToSign = $serverLeafCert . $serverNonce;
        $messageSecurity = $this->secureChannel->getMessageSecurity();
        $signature = $messageSecurity->asymmetricSign($dataToSign, $userPrivateKey, $policy);

        $encoder->writeString($policy->getAsymmetricSignatureUri());
        $encoder->writeByteString($signature);
    }

    /**
     * @param BinaryEncoder $body
     * @param string $endpointUrl
     */
    private function writeCreateSessionBody(BinaryEncoder $body, string $endpointUrl): void
    {
        $body->writeString('urn:opcua-client:client');
        $body->writeString(null);
        $body->writeLocalizedText(new LocalizedText(null, 'opcua-client'));
        $body->writeUInt32(1);
        $body->writeString(null);
        $body->writeString(null);
        $body->writeInt32(0);

        $body->writeString(null);
        $body->writeString($endpointUrl);

        $body->writeString('opcua-client-session');

        $nonce = random_bytes(32);
        $body->writeByteString($nonce);

        $body->writeByteString(null);

        $body->writeDouble(120000.0);
        $body->writeUInt32(0);
    }

    /**
     * @param BinaryDecoder $decoder
     */
    private function skipEndpointDescription(BinaryDecoder $decoder): void
    {
        $decoder->readString();
        $decoder->readString();
        $decoder->readString();
        $decoder->readLocalizedText();
        $decoder->readUInt32();
        $decoder->readString();
        $decoder->readString();
        $discoveryUrlCount = $decoder->readInt32();
        for ($i = 0; $i < $discoveryUrlCount; $i++) {
            $decoder->readString();
        }
        $decoder->readByteString();
        $decoder->readUInt32();
        $decoder->readString();
        $tokenCount = $decoder->readInt32();
        for ($i = 0; $i < $tokenCount; $i++) {
            $decoder->readString();
            $decoder->readUInt32();
            $decoder->readString();
            $decoder->readString();
            $decoder->readString();
        }
        $decoder->readString();
        $decoder->readByte();
    }

    /**
     * @param BinaryDecoder $decoder
     */
    private function skipSignedSoftwareCertificate(BinaryDecoder $decoder): void
    {
        $decoder->readByteString();
        $decoder->readByteString();
    }

    /**
     * @param string $chainDer
     */
    private function extractLeafCertificate(string $chainDer): string
    {
        if (strlen($chainDer) < 4 || ord($chainDer[0]) !== 0x30) {
            return $chainDer;
        }

        $pos = 1;
        $lenByte = ord($chainDer[$pos]);
        $pos++;

        if ($lenByte & 0x80) {
            $numLenBytes = $lenByte & 0x7F;
            $length = 0;
            for ($i = 0; $i < $numLenBytes; $i++) {
                $length = ($length << 8) | ord($chainDer[$pos]);
                $pos++;
            }
        } else {
            $length = $lenByte;
        }

        return substr($chainDer, 0, $pos + $length);
    }

    /**
     * @return SecurityPolicy
     */
    private function resolveUserTokenEncryptionPolicy(): SecurityPolicy
    {
        $channelPolicy = $this->secureChannel?->getPolicy() ?? SecurityPolicy::None;

        if ($this->usernameTokenSecurityPolicyUri === null
            || $this->usernameTokenSecurityPolicyUri === ''
        ) {
            return $channelPolicy;
        }

        return SecurityPolicy::tryFrom($this->usernameTokenSecurityPolicyUri) ?? $channelPolicy;
    }

    /**
     * @return string|null
     */
    private function resolveUserTokenServerCertDer(): ?string
    {
        return $this->secureChannel?->getServerCertDer() ?? $this->userTokenServerCertDer;
    }

    /**
     * @return MessageSecurity|null
     */
    private function resolveUserTokenMessageSecurity(): ?MessageSecurity
    {
        return $this->secureChannel?->getMessageSecurity() ?? $this->userTokenMessageSecurity;
    }
}
