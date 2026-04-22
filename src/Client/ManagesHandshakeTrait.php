<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Client;

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Exception\HandshakeException;
use PhpOpcua\Client\Exception\MessageTypeException;
use PhpOpcua\Client\Exception\ProtocolException;
use PhpOpcua\Client\Exception\SecurityException;
use PhpOpcua\Client\Module\Browse\GetEndpointsService;
use PhpOpcua\Client\Protocol\AcknowledgeMessage;
use PhpOpcua\Client\Protocol\HelloMessage;
use PhpOpcua\Client\Protocol\MessageHeader;
use PhpOpcua\Client\Protocol\SecureChannelRequest;
use PhpOpcua\Client\Protocol\SecureChannelResponse;
use PhpOpcua\Client\Protocol\ServiceTypeId;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Transport\TcpTransport;
use PhpOpcua\Client\Types\NodeId;

/**
 * Provides OPC UA handshake and server certificate discovery for the connected client.
 */
trait ManagesHandshakeTrait
{
    /**
     * Perform the HEL/ACK handshake with the server.
     *
     * @param string $endpointUrl The OPC UA endpoint URL.
     * @return void
     *
     * @throws ProtocolException If the server responds with an error or unexpected message type.
     */
    private function doHandshake(string $endpointUrl): void
    {
        $hello = new HelloMessage(endpointUrl: $endpointUrl);
        $this->logger->debug('Sending HEL message to {url}', $this->logContext(['url' => $endpointUrl]));
        $this->transport->send($hello->encode());

        $response = $this->transport->receive();
        $decoder = new BinaryDecoder($response);
        $header = MessageHeader::decode($decoder);

        if ($header->getMessageType() === 'ERR') {
            $errorCode = $decoder->readUInt32();
            $errorMessage = $decoder->readString();
            throw new HandshakeException($errorCode, $errorMessage ?? '');
        }

        if ($header->getMessageType() !== 'ACK') {
            throw new MessageTypeException('ACK', $header->getMessageType());
        }

        $ack = AcknowledgeMessage::decode($decoder);
        $this->logger->debug('ACK received (receiveBufferSize={bufferSize})', $this->logContext(['bufferSize' => $ack->getReceiveBufferSize()]));
        $this->transport->setReceiveBufferSize($ack->getReceiveBufferSize());
    }

    /**
     * Discover the server certificate by connecting with no security and querying endpoints.
     *
     * @param string $host The server hostname.
     * @param int $port The server port.
     * @param string $endpointUrl The OPC UA endpoint URL.
     * @return void
     *
     * @throws SecurityException If the server certificate cannot be obtained.
     * @throws ProtocolException If the discovery handshake fails.
     */
    private function discoverServerCertificate(string $host, int $port, string $endpointUrl, bool $requireCertificate = true): void
    {
        $this->logger->debug('Discovering server certificate from {host}:{port}', $this->logContext(['host' => $host, 'port' => $port]));
        $discoveryTransport = new TcpTransport();
        $discoveryTransport->connect($host, $port, $this->timeout);

        $session = $this->performDiscoveryHandshake($discoveryTransport, $endpointUrl);

        $getEndpointsService = new GetEndpointsService($session);
        $request = $getEndpointsService->encodeGetEndpointsRequest(1, $endpointUrl, NodeId::numeric(0, ServiceTypeId::NULL));
        $this->logger->debug('Discovery GetEndpoints request for {url}', $this->logContext(['url' => $endpointUrl]));
        $discoveryTransport->send($request);

        $response = $discoveryTransport->receive();
        $this->logger->debug('Discovery GetEndpoints response received', $this->logContext());
        $responseBody = substr($response, MessageHeader::HEADER_SIZE + 4);
        $decoder = new BinaryDecoder($responseBody);
        $endpoints = $getEndpointsService->decodeGetEndpointsResponse($decoder);

        $this->extractServerCertificateFromEndpoints($endpoints);

        $discoveryTransport->close();

        if ($requireCertificate && $this->serverCertDer === null) {
            throw new SecurityException('Could not obtain server certificate from GetEndpoints');
        }
    }

    /**
     * Perform a discovery handshake on a temporary transport to obtain a SessionService.
     *
     * @param TcpTransport $transport The temporary transport.
     * @param string $endpointUrl The OPC UA endpoint URL.
     * @return SessionService
     *
     * @throws ProtocolException If the handshake fails.
     */
    private function performDiscoveryHandshake(TcpTransport $transport, string $endpointUrl): SessionService
    {
        $this->logger->debug('Discovery HEL for {url}', $this->logContext(['url' => $endpointUrl]));
        $helloMessage = new HelloMessage(endpointUrl: $endpointUrl);
        $transport->send($helloMessage->encode());
        $helloResponse = $transport->receive();
        $helloDecoder = new BinaryDecoder($helloResponse);
        $helloHeader = MessageHeader::decode($helloDecoder);
        if ($helloHeader->getMessageType() === 'ERR') {
            $errorCode = $helloDecoder->readUInt32();
            $errorMessage = $helloDecoder->readString();
            throw new HandshakeException($errorCode, $errorMessage ?? '');
        }
        if ($helloHeader->getMessageType() !== 'ACK') {
            throw new MessageTypeException('ACK', $helloHeader->getMessageType());
        }
        AcknowledgeMessage::decode($helloDecoder);

        $this->logger->debug('Discovery ACK received, sending OPN request', $this->logContext());
        $opnRequest = new SecureChannelRequest();
        $transport->send($opnRequest->encode());
        $opnResponse = $transport->receive();
        $this->logger->debug('Discovery OPN response received', $this->logContext());
        $opnDecoder = new BinaryDecoder($opnResponse);
        $opnHeader = MessageHeader::decode($opnDecoder);
        if ($opnHeader->getMessageType() !== 'OPN') {
            throw new MessageTypeException('OPN', $opnHeader->getMessageType());
        }
        $opnDecoder->readUInt32();
        $scResponse = SecureChannelResponse::decode($opnDecoder);

        return new SessionService($scResponse->getSecureChannelId(), $scResponse->getTokenId());
    }

    /**
     * Extract the server certificate from discovered endpoints matching the configured security.
     *
     * @param \PhpOpcua\Client\Types\EndpointDescription[] $endpoints
     * @return void
     */
    private function extractServerCertificateFromEndpoints(array $endpoints): void
    {
        foreach ($endpoints as $ep) {
            if ($ep->getSecurityPolicyUri() === $this->securityPolicy->value
                && $ep->getSecurityMode() === $this->securityMode->value
            ) {
                if ($ep->getServerCertificate() !== null) {
                    $this->serverCertDer = $ep->getServerCertificate();
                }
                $this->extractTokenPolicies($ep);

                break;
            }
        }

        if ($this->serverCertDer === null) {
            foreach ($endpoints as $ep) {
                if ($ep->getServerCertificate() !== null) {
                    $this->serverCertDer = $ep->getServerCertificate();

                    break;
                }
            }
        }
    }

    /**
     * Extract user identity token policy IDs from an endpoint description.
     *
     * @param \PhpOpcua\Client\Types\EndpointDescription $endpoint
     * @return void
     */
    private function extractTokenPolicies(\PhpOpcua\Client\Types\EndpointDescription $endpoint): void
    {
        foreach ($endpoint->getUserIdentityTokens() as $tokenPolicy) {
            match ($tokenPolicy->getTokenType()) {
                1 => $this->usernamePolicyId = $tokenPolicy->getPolicyId(),
                2 => $this->certificatePolicyId = $tokenPolicy->getPolicyId(),
                0 => $this->anonymousPolicyId = $tokenPolicy->getPolicyId(),
                default => null,
            };
        }
    }
}
