<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Protocol;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Exception\ServiceException;
use Gianfriaur\OpcuaPhpClient\Security\SecureChannel;
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaPhpClient\Types\LocalizedText;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use OpenSSLAsymmetricKey;

class SessionService
{
    private int $sequenceNumber = 2;

    private string $usernamePolicyId = 'username';

    private string $certificatePolicyId = 'certificate';

    private string $anonymousPolicyId = 'anonymous';

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
     * @param ?string $usernamePolicyId
     * @param ?string $certificatePolicyId
     * @param ?string $anonymousPolicyId
     */
    public function setUserTokenPolicyIds(
        ?string $usernamePolicyId = null,
        ?string $certificatePolicyId = null,
        ?string $anonymousPolicyId = null,
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

        $body->writeNodeId(NodeId::numeric(0, 461));

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

        $decoder->readNodeId();

        $this->readResponseHeader($decoder);

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

        return [
            'sessionId' => $sessionId,
            'authenticationToken' => $authenticationToken,
            'serverNonce' => $serverNonce,
            'serverCertificate' => $serverCertificate,
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
            );
        }

        $body = new BinaryEncoder();
        $this->writeSecurityHeader($body);
        $this->writeSequenceHeader($body, $requestId);

        $body->writeNodeId(NodeId::numeric(0, 467));

        $body->writeNodeId($authenticationToken);
        $body->writeInt64(0);
        $body->writeUInt32($requestId);
        $body->writeUInt32(0);
        $body->writeString(null);
        $body->writeUInt32(10000);
        $body->writeNodeId(NodeId::numeric(0, 0));
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
        $encoder->writeNodeId(NodeId::numeric(0, 0));
        $encoder->writeInt64(0);
        $encoder->writeUInt32($requestHandle);
        $encoder->writeUInt32(0);
        $encoder->writeString(null);
        $encoder->writeUInt32(10000);
        $encoder->writeNodeId(NodeId::numeric(0, 0));
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
        $decoder->readNodeId();
        $decoder->readByte();

        return $statusCode;
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

        $innerBody->writeNodeId(NodeId::numeric(0, 461));

        $innerBody->writeNodeId(NodeId::numeric(0, 0));
        $innerBody->writeInt64(0);
        $innerBody->writeUInt32($requestId);
        $innerBody->writeUInt32(0);
        $innerBody->writeString(null);
        $innerBody->writeUInt32(10000);
        $innerBody->writeNodeId(NodeId::numeric(0, 0));
        $innerBody->writeByte(0);

        $applicationUri = $this->secureChannel->getCertificateManager()->getApplicationUri(
            $this->secureChannel->getClientCertDer(),
        ) ?? 'urn:opcua-php-client:client';
        $innerBody->writeString($applicationUri);
        $innerBody->writeString(null);
        $innerBody->writeLocalizedText(new LocalizedText(null, 'opcua-php-client'));
        $innerBody->writeUInt32(1);
        $innerBody->writeString(null);
        $innerBody->writeString(null);
        $innerBody->writeInt32(0);

        $innerBody->writeString(null);
        $innerBody->writeString($endpointUrl);
        $innerBody->writeString('opcua-php-client-session');

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
    ): string {
        $innerBody = new BinaryEncoder();

        $innerBody->writeNodeId(NodeId::numeric(0, 467));

        $innerBody->writeNodeId($authenticationToken);
        $innerBody->writeInt64(0);
        $innerBody->writeUInt32($requestId);
        $innerBody->writeUInt32(0);
        $innerBody->writeString(null);
        $innerBody->writeUInt32(10000);
        $innerBody->writeNodeId(NodeId::numeric(0, 0));
        $innerBody->writeByte(0);

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
        $encoder->writeNodeId(NodeId::numeric(0, 321));
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
        $encoder->writeNodeId(NodeId::numeric(0, 324));
        $encoder->writeByte(0x01);

        $tokenBody = new BinaryEncoder();
        $tokenBody->writeString($this->usernamePolicyId);
        $tokenBody->writeString($username);

        if ($this->secureChannel !== null && $this->secureChannel->isSecurityActive() && $serverNonce !== null) {
            $passwordBytes = $password;
            $nonceBytes = $serverNonce;

            $plaintext = pack('V', strlen($passwordBytes) + strlen($nonceBytes))
                . $passwordBytes
                . $nonceBytes;

            $policy = $this->secureChannel->getPolicy();
            $serverCertDer = $this->secureChannel->getServerCertDer();
            $encrypted = $this->secureChannel->getMessageSecurity()->asymmetricEncrypt(
                $plaintext,
                $serverCertDer,
                $policy,
            );

            $tokenBody->writeByteString($encrypted);
            $tokenBody->writeString($policy->getAsymmetricEncryptionUri());
        } else {
            $tokenBody->writeByteString($password);
            $tokenBody->writeString(null);
        }

        $tokenBodyBytes = $tokenBody->getBuffer();
        $encoder->writeInt32(strlen($tokenBodyBytes));
        $encoder->writeRawBytes($tokenBodyBytes);
    }

    /**
     * @param BinaryEncoder $encoder
     * @param string $userCertDer
     */
    private function writeX509IdentityToken(BinaryEncoder $encoder, string $userCertDer): void
    {
        $encoder->writeNodeId(NodeId::numeric(0, 327));
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
        $body->writeString('urn:opcua-php-client:client');
        $body->writeString(null);
        $body->writeLocalizedText(new LocalizedText(null, 'opcua-php-client'));
        $body->writeUInt32(1);
        $body->writeString(null);
        $body->writeString(null);
        $body->writeInt32(0);

        $body->writeString(null);
        $body->writeString($endpointUrl);

        $body->writeString('opcua-php-client-session');

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
}
