<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Client;

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Event\SecureChannelClosed;
use PhpOpcua\Client\Event\SecureChannelOpened;
use PhpOpcua\Client\Exception\ConfigurationException;
use PhpOpcua\Client\Exception\ProtocolException;
use PhpOpcua\Client\Protocol\BrowseService;
use PhpOpcua\Client\Protocol\CallService;
use PhpOpcua\Client\Protocol\GetEndpointsService;
use PhpOpcua\Client\Protocol\HistoryReadService;
use PhpOpcua\Client\Protocol\MessageHeader;
use PhpOpcua\Client\Protocol\MonitoredItemService;
use PhpOpcua\Client\Protocol\PublishService;
use PhpOpcua\Client\Protocol\ReadService;
use PhpOpcua\Client\Protocol\SecureChannelRequest;
use PhpOpcua\Client\Protocol\SecureChannelResponse;
use PhpOpcua\Client\Protocol\ServiceTypeId;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Protocol\SubscriptionService;
use PhpOpcua\Client\Protocol\TranslateBrowsePathService;
use PhpOpcua\Client\Protocol\WriteService;
use PhpOpcua\Client\Security\CertificateManager;
use PhpOpcua\Client\Security\SecureChannel;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Types\NodeId;

/**
 * Provides secure channel opening and closing for the connected client.
 */
trait ManagesSecureChannelTrait
{
    /**
     * Open a secure channel with or without message-level security.
     *
     * @return void
     *
     * @throws ProtocolException If the server responds with an unexpected message type.
     * @throws ConfigurationException If the client certificate cannot be loaded.
     */
    private function openSecureChannel(): void
    {
        $isSecure = $this->securityPolicy !== SecurityPolicy::None
            && $this->securityMode !== SecurityMode::None;

        if ($isSecure) {
            $this->openSecureChannelWithSecurity();
        } else {
            $this->openSecureChannelNoSecurity();
        }

        $this->dispatch(fn () => new SecureChannelOpened($this, $this->secureChannelId, $this->securityPolicy, $this->securityMode));
    }

    /**
     * Open a secure channel without message-level security.
     *
     * @return void
     *
     * @throws ProtocolException If the server responds with an unexpected message type.
     */
    private function openSecureChannelNoSecurity(): void
    {
        $this->secureChannel = new SecureChannel(
            SecurityPolicy::None,
            SecurityMode::None,
        );

        $request = new SecureChannelRequest();
        $this->logger->debug('OpenSecureChannel request (no security)', $this->logContext());
        $this->transport->send($request->encode());

        $response = $this->transport->receive();
        $decoder = new BinaryDecoder($response);
        $header = MessageHeader::decode($decoder);

        if ($header->getMessageType() !== 'OPN') {
            throw new ProtocolException("Expected OPN response, got: {$header->getMessageType()}");
        }

        $decoder->readUInt32();

        $scResponse = SecureChannelResponse::decode($decoder);
        $this->secureChannelId = $scResponse->getSecureChannelId();
        $this->logger->debug('OpenSecureChannel response: channelId={channelId}', $this->logContext(['channelId' => $this->secureChannelId]));

        $this->session = new SessionService($this->secureChannelId, $scResponse->getTokenId());
        $this->session->setUserTokenPolicyIds(
            $this->usernamePolicyId,
            $this->certificatePolicyId,
            $this->anonymousPolicyId,
        );

        $this->initServices($this->session);
    }

    /**
     * Open a secure channel with message-level security.
     *
     * @return void
     *
     * @throws ConfigurationException If the client certificate cannot be loaded.
     * @throws ProtocolException If the secure channel negotiation fails.
     */
    private function openSecureChannelWithSecurity(): void
    {
        [$clientCertDer, $clientPrivateKey] = $this->loadClientCertificateAndKey();
        $clientCertChainDer = $this->buildCertificateChain($clientCertDer);

        $this->secureChannel = new SecureChannel(
            $this->securityPolicy,
            $this->securityMode,
            $clientCertDer,
            $clientPrivateKey,
            $this->serverCertDer,
            $clientCertChainDer,
        );

        $opnMessage = $this->secureChannel->createOpenSecureChannelMessage();
        $this->logger->debug('OpenSecureChannel request (policy={policy}, mode={mode})', $this->logContext([
            'policy' => $this->securityPolicy->name,
            'mode' => $this->securityMode->name,
        ]));
        $this->transport->send($opnMessage);

        $response = $this->transport->receive();
        $result = $this->secureChannel->processOpenSecureChannelResponse($response);

        $this->secureChannelId = $result['secureChannelId'];
        $this->logger->debug('OpenSecureChannel response: channelId={channelId}', $this->logContext(['channelId' => $this->secureChannelId]));
        $this->serverNonce = $result['serverNonce'];

        $this->session = new SessionService(
            $this->secureChannelId,
            $result['tokenId'],
            $this->secureChannel,
        );
        $this->session->setUserTokenPolicyIds(
            $this->usernamePolicyId,
            $this->certificatePolicyId,
            $this->anonymousPolicyId,
        );

        $this->initServices($this->session);
    }

    /**
     * Load the client certificate and private key for secure channel setup.
     *
     * @return array{0: ?string, 1: mixed}
     *
     * @throws ConfigurationException If the certificate file cannot be read.
     */
    private function loadClientCertificateAndKey(): array
    {
        $certManager = new CertificateManager();

        if ($this->clientCertPath !== null && $this->clientKeyPath !== null) {
            $certContent = file_get_contents($this->clientCertPath);
            if ($certContent === false) {
                throw new ConfigurationException("Failed to read client certificate: {$this->clientCertPath}");
            }

            $clientCertDer = str_contains($certContent, '-----BEGIN')
                ? $certManager->loadCertificatePem($this->clientCertPath)
                : $certManager->loadCertificateDer($this->clientCertPath);

            return [$clientCertDer, $certManager->loadPrivateKeyPem($this->clientKeyPath)];
        }

        $eccCurve = $this->securityPolicy->isEcc() ? $this->securityPolicy->getEcdhCurveName() : null;
        $generated = $certManager->generateSelfSignedCertificate('urn:opcua-client', $eccCurve);

        return [$generated['certDer'], $generated['privateKey']];
    }

    /**
     * Build a certificate chain by appending the CA certificate if configured.
     *
     * @param ?string $clientCertDer The client certificate in DER format.
     * @return ?string The certificate chain, or the original certificate if no CA is configured.
     */
    private function buildCertificateChain(?string $clientCertDer): ?string
    {
        if ($clientCertDer === null || $this->caCertPath === null) {
            return $clientCertDer;
        }

        $caCertContent = file_get_contents($this->caCertPath);
        if ($caCertContent === false) {
            return $clientCertDer;
        }

        $certManager = new CertificateManager();
        $caCertDer = str_contains($caCertContent, '-----BEGIN')
            ? $certManager->loadCertificatePem($this->caCertPath)
            : $certManager->loadCertificateDer($this->caCertPath);

        return $clientCertDer . $caCertDer;
    }

    /**
     * Close the secure channel.
     *
     * @return void
     */
    private function closeSecureChannel(): void
    {
        $this->logger->debug('CloseSecureChannel request (channelId={channelId})', $this->logContext(['channelId' => $this->secureChannelId]));
        $this->dispatch(fn () => new SecureChannelClosed($this, $this->secureChannelId));

        if ($this->secureChannel !== null && $this->secureChannel->isSecurityActive()) {
            $this->closeSecureChannelSecure();

            return;
        }

        $body = new BinaryEncoder();
        $body->writeUInt32($this->session?->getTokenId() ?? 0);
        $body->writeUInt32($this->session?->getNextSequenceNumber() ?? 1);
        $body->writeUInt32($this->nextRequestId());

        $bodyBytes = $body->getBuffer();
        $totalSize = MessageHeader::HEADER_SIZE + 4 + strlen($bodyBytes);

        $encoder = new BinaryEncoder();
        $header = new MessageHeader('CLO', 'F', $totalSize);
        $header->encode($encoder);
        $encoder->writeUInt32($this->secureChannelId);
        $encoder->writeRawBytes($bodyBytes);

        $this->transport->send($encoder->getBuffer());
    }

    /**
     * Close the secure channel when message-level security is active.
     *
     * @return void
     */
    private function closeSecureChannelSecure(): void
    {
        $requestId = $this->nextRequestId();

        $innerBody = new BinaryEncoder();
        $innerBody->writeNodeId(NodeId::numeric(0, ServiceTypeId::CLOSE_SECURE_CHANNEL_REQUEST));

        $innerBody->writeNodeId(NodeId::numeric(0, ServiceTypeId::NULL));
        $innerBody->writeInt64(0);
        $innerBody->writeUInt32($requestId);
        $innerBody->writeUInt32(0);
        $innerBody->writeString(null);
        $innerBody->writeUInt32(10000);
        $innerBody->writeNodeId(NodeId::numeric(0, ServiceTypeId::NULL));
        $innerBody->writeByte(0);

        $message = $this->secureChannel->buildMessage($innerBody->getBuffer(), 'CLO');
        $this->transport->send($message);
    }

    /**
     * Initialize all protocol service instances from a session.
     *
     * @param SessionService $session The session service to derive services from.
     * @return void
     */
    private function initServices(SessionService $session): void
    {
        $this->browseService = new BrowseService($session);
        $this->readService = new ReadService($session);
        $this->writeService = new WriteService($session);
        $this->callService = new CallService($session);
        $this->getEndpointsService = new GetEndpointsService($session);
        $this->subscriptionService = new SubscriptionService($session);
        $this->monitoredItemService = new MonitoredItemService($session);
        $this->publishService = new PublishService($session);
        $this->historyReadService = new HistoryReadService($session);
        $this->translateBrowsePathService = new TranslateBrowsePathService($session);
    }
}
