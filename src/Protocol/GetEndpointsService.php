<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Protocol;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Types\EndpointDescription;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\UserTokenPolicy;

class GetEndpointsService
{
    /**
     * @param SessionService $session
     */
    public function __construct(private readonly SessionService $session)
    {
    }

    /**
     * @param int $requestId
     * @param string $endpointUrl
     * @param NodeId $authToken
     */
    public function encodeGetEndpointsRequest(int $requestId, string $endpointUrl, NodeId $authToken): string
    {
        $secureChannel = $this->session->getSecureChannel();
        if ($secureChannel !== null && $secureChannel->isSecurityActive()) {
            return $this->encodeGetEndpointsRequestSecure($requestId, $endpointUrl, $authToken);
        }

        $body = new BinaryEncoder();

        $body->writeUInt32($this->session->getTokenId());
        $body->writeUInt32($this->session->getNextSequenceNumber());
        $body->writeUInt32($requestId);

        $this->writeGetEndpointsInnerBody($body, $requestId, $endpointUrl, $authToken);

        return $this->wrapInMessage($body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return EndpointDescription[]
     */
    public function decodeGetEndpointsResponse(BinaryDecoder $decoder): array
    {
        $decoder->readUInt32();
        $decoder->readUInt32();
        $decoder->readUInt32();

        $decoder->readNodeId();

        $this->session->readResponseHeader($decoder);

        $count = $decoder->readInt32();
        $endpoints = [];

        for ($i = 0; $i < $count; $i++) {
            $endpoints[] = $this->readEndpointDescription($decoder);
        }

        return $endpoints;
    }

    /**
     * @param int $requestId
     * @param string $endpointUrl
     * @param NodeId $authToken
     */
    private function encodeGetEndpointsRequestSecure(int $requestId, string $endpointUrl, NodeId $authToken): string
    {
        $body = new BinaryEncoder();
        $this->writeGetEndpointsInnerBody($body, $requestId, $endpointUrl, $authToken);

        return $this->session->getSecureChannel()->buildMessage($body->getBuffer());
    }

    /**
     * @param BinaryEncoder $body
     * @param int $requestId
     * @param string $endpointUrl
     * @param NodeId $authToken
     */
    private function writeGetEndpointsInnerBody(BinaryEncoder $body, int $requestId, string $endpointUrl, NodeId $authToken): void
    {
        $body->writeNodeId(NodeId::numeric(0, 428));

        $body->writeNodeId($authToken);
        $body->writeInt64(0);
        $body->writeUInt32($requestId);
        $body->writeUInt32(0);
        $body->writeString(null);
        $body->writeUInt32(10000);
        $body->writeNodeId(NodeId::numeric(0, 0));
        $body->writeByte(0);

        $body->writeString($endpointUrl);

        $body->writeInt32(0);

        $body->writeInt32(0);
    }

    /**
     * @param BinaryDecoder $decoder
     */
    private function readEndpointDescription(BinaryDecoder $decoder): EndpointDescription
    {
        $endpointUrl = $decoder->readString() ?? '';

        $decoder->readString();
        $decoder->readString();
        $decoder->readLocalizedText();
        $decoder->readUInt32();
        $decoder->readString();
        $decoder->readString();
        $discoveryUrlCount = $decoder->readInt32();
        for ($j = 0; $j < $discoveryUrlCount; $j++) {
            $decoder->readString();
        }

        $serverCertificate = $decoder->readByteString();
        $securityMode = $decoder->readUInt32();
        $securityPolicyUri = $decoder->readString() ?? '';

        $tokenCount = $decoder->readInt32();
        $userIdentityTokens = [];
        for ($j = 0; $j < $tokenCount; $j++) {
            $userIdentityTokens[] = new UserTokenPolicy(
                policyId: $decoder->readString(),
                tokenType: $decoder->readUInt32(),
                issuedTokenType: $decoder->readString(),
                issuerEndpointUrl: $decoder->readString(),
                securityPolicyUri: $decoder->readString(),
            );
        }

        $transportProfileUri = $decoder->readString() ?? '';
        $securityLevel = $decoder->readByte();

        return new EndpointDescription(
            endpointUrl: $endpointUrl,
            serverCertificate: $serverCertificate,
            securityMode: $securityMode,
            securityPolicyUri: $securityPolicyUri,
            userIdentityTokens: $userIdentityTokens,
            transportProfileUri: $transportProfileUri,
            securityLevel: $securityLevel,
        );
    }

    /**
     * @param string $bodyBytes
     */
    private function wrapInMessage(string $bodyBytes): string
    {
        $totalSize = MessageHeader::HEADER_SIZE + 4 + strlen($bodyBytes);

        $encoder = new BinaryEncoder();
        $header = new MessageHeader('MSG', 'F', $totalSize);
        $header->encode($encoder);
        $encoder->writeUInt32($this->session->getSecureChannelId());
        $encoder->writeRawBytes($bodyBytes);

        return $encoder->getBuffer();
    }
}
