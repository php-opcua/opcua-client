<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Protocol;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Types\EndpointDescription;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\UserTokenPolicy;

class GetEndpointsService extends AbstractProtocolService
{
    /**
     * @param int $requestId
     * @param string $endpointUrl
     * @param NodeId $authToken
     */
    public function encodeGetEndpointsRequest(int $requestId, string $endpointUrl, NodeId $authToken): string
    {
        $body = new BinaryEncoder();
        $this->writeGetEndpointsInnerBody($body, $requestId, $endpointUrl, $authToken);

        return $this->encodeRequestAuto($requestId, $body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return EndpointDescription[]
     */
    public function decodeGetEndpointsResponse(BinaryDecoder $decoder): array
    {
        $this->readResponseMetadata($decoder);

        $count = $decoder->readInt32();
        $endpoints = [];

        for ($i = 0; $i < $count; $i++) {
            $endpoints[] = $this->readEndpointDescription($decoder);
        }

        return $endpoints;
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

        $this->writeRequestHeader($body, $requestId, $authToken);

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
}
