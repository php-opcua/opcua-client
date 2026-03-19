<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Client;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Exception\ConfigurationException;
use Gianfriaur\OpcuaPhpClient\Exception\ProtocolException;
use Gianfriaur\OpcuaPhpClient\Protocol\BrowseService;
use Gianfriaur\OpcuaPhpClient\Protocol\CallService;
use Gianfriaur\OpcuaPhpClient\Protocol\GetEndpointsService;
use Gianfriaur\OpcuaPhpClient\Protocol\HistoryReadService;
use Gianfriaur\OpcuaPhpClient\Protocol\MessageHeader;
use Gianfriaur\OpcuaPhpClient\Protocol\MonitoredItemService;
use Gianfriaur\OpcuaPhpClient\Protocol\PublishService;
use Gianfriaur\OpcuaPhpClient\Protocol\ReadService;
use Gianfriaur\OpcuaPhpClient\Protocol\SecureChannelRequest;
use Gianfriaur\OpcuaPhpClient\Protocol\SecureChannelResponse;
use Gianfriaur\OpcuaPhpClient\Protocol\SessionService;
use Gianfriaur\OpcuaPhpClient\Protocol\SubscriptionService;
use Gianfriaur\OpcuaPhpClient\Protocol\TranslateBrowsePathService;
use Gianfriaur\OpcuaPhpClient\Protocol\WriteService;
use Gianfriaur\OpcuaPhpClient\Security\CertificateManager;
use Gianfriaur\OpcuaPhpClient\Security\SecureChannel;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

trait ManagesSecureChannelTrait
{
    private function openSecureChannel(): void
    {
        $isSecure = $this->securityPolicy !== SecurityPolicy::None
            && $this->securityMode !== SecurityMode::None;

        if ($isSecure) {
            $this->openSecureChannelWithSecurity();
        } else {
            $this->openSecureChannelNoSecurity();
        }
    }

    private function openSecureChannelNoSecurity(): void
    {
        $this->secureChannel = new SecureChannel(
            SecurityPolicy::None,
            SecurityMode::None,
        );

        $request = new SecureChannelRequest();
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

        $this->session = new SessionService($this->secureChannelId, $scResponse->getTokenId());
        $this->session->setUserTokenPolicyIds(
            $this->usernamePolicyId,
            $this->certificatePolicyId,
            $this->anonymousPolicyId,
        );

        $this->initServices($this->session);
    }

    private function openSecureChannelWithSecurity(): void
    {
        $certManager = new CertificateManager();

        $clientCertDer = null;
        $clientPrivateKey = null;

        if ($this->clientCertPath !== null && $this->clientKeyPath !== null) {
            $certContent = file_get_contents($this->clientCertPath);
            if ($certContent === false) {
                throw new ConfigurationException("Failed to read client certificate: {$this->clientCertPath}");
            }

            if (str_contains($certContent, '-----BEGIN')) {
                $clientCertDer = $certManager->loadCertificatePem($this->clientCertPath);
            } else {
                $clientCertDer = $certManager->loadCertificateDer($this->clientCertPath);
            }

            $clientPrivateKey = $certManager->loadPrivateKeyPem($this->clientKeyPath);
        } else {
            $generated = $certManager->generateSelfSignedCertificate();
            $clientCertDer = $generated['certDer'];
            $clientPrivateKey = $generated['privateKey'];
        }

        $clientCertChainDer = $clientCertDer;
        if ($clientCertDer !== null && $this->caCertPath !== null) {
            $caCertContent = file_get_contents($this->caCertPath);
            if ($caCertContent !== false) {
                $caCertDer = str_contains($caCertContent, '-----BEGIN')
                    ? $certManager->loadCertificatePem($this->caCertPath)
                    : $certManager->loadCertificateDer($this->caCertPath);
                $clientCertChainDer = $clientCertDer . $caCertDer;
            }
        }

        $this->secureChannel = new SecureChannel(
            $this->securityPolicy,
            $this->securityMode,
            $clientCertDer,
            $clientPrivateKey,
            $this->serverCertDer,
            $clientCertChainDer,
        );

        $opnMessage = $this->secureChannel->createOpenSecureChannelMessage();
        $this->transport->send($opnMessage);

        $response = $this->transport->receive();
        $result = $this->secureChannel->processOpenSecureChannelResponse($response);

        $this->secureChannelId = $result['secureChannelId'];
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

    private function closeSecureChannel(): void
    {
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

    private function closeSecureChannelSecure(): void
    {
        $requestId = $this->nextRequestId();

        $innerBody = new BinaryEncoder();
        $innerBody->writeNodeId(NodeId::numeric(0, 452));

        $innerBody->writeNodeId(NodeId::numeric(0, 0));
        $innerBody->writeInt64(0);
        $innerBody->writeUInt32($requestId);
        $innerBody->writeUInt32(0);
        $innerBody->writeString(null);
        $innerBody->writeUInt32(10000);
        $innerBody->writeNodeId(NodeId::numeric(0, 0));
        $innerBody->writeByte(0);

        $message = $this->secureChannel->buildMessage($innerBody->getBuffer(), 'CLO');
        $this->transport->send($message);
    }

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
