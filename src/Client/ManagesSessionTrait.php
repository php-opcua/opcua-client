<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Client;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Exception\OpcUaException;
use Gianfriaur\OpcuaPhpClient\Protocol\MessageHeader;
use Gianfriaur\OpcuaPhpClient\Security\CertificateManager;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

trait ManagesSessionTrait
{
    /**
     * @param string $endpointUrl
     */
    private function createAndActivateSession(string $endpointUrl): void
    {
        $requestId = $this->nextRequestId();
        $request = $this->session->encodeCreateSessionRequest($requestId, $endpointUrl);
        $this->transport->send($request);

        $response = $this->transport->receive();
        $responseBody = $this->unwrapResponse($response);
        $decoder = $this->createDecoder($responseBody);
        $sessionResult = $this->session->decodeCreateSessionResponse($decoder);
        $this->authenticationToken = $sessionResult['authenticationToken'];

        if (isset($sessionResult['serverNonce'])) {
            $this->serverNonce = $sessionResult['serverNonce'];
        }

        if (isset($sessionResult['serverCertificate'])) {
            if ($this->secureChannel !== null && $this->secureChannel->getServerCertDer() === null) {
                $this->secureChannel->setServerCertDer($sessionResult['serverCertificate']);
            }
        }

        $requestId = $this->nextRequestId();

        $userCertDer = null;
        $userPrivateKey = null;

        if ($this->userCertPath !== null && $this->userKeyPath !== null) {
            $certManager = new CertificateManager();
            $certContent = file_get_contents($this->userCertPath);
            if ($certContent !== false && str_contains($certContent, '-----BEGIN')) {
                $userCertDer = $certManager->loadCertificatePem($this->userCertPath);
            } elseif ($certContent !== false) {
                $userCertDer = $certManager->loadCertificateDer($this->userCertPath);
            }
            $userPrivateKey = $certManager->loadPrivateKeyPem($this->userKeyPath);
        }

        $request = $this->session->encodeActivateSessionRequest(
            $requestId,
            $this->authenticationToken,
            $this->username,
            $this->password,
            $userCertDer,
            $userPrivateKey,
            $this->serverNonce,
        );
        $this->transport->send($request);

        $response = $this->transport->receive();
        $responseBody = $this->unwrapResponse($response);
        $decoder = $this->createDecoder($responseBody);
        $this->session->decodeActivateSessionResponse($decoder);
    }

    private function closeSession(): void
    {
        if ($this->secureChannel !== null && $this->secureChannel->isSecurityActive()) {
            $this->closeSessionSecure();
            return;
        }

        $body = new BinaryEncoder();
        $body->writeUInt32($this->session->getTokenId());
        $body->writeUInt32($this->session->getNextSequenceNumber());
        $requestId = $this->nextRequestId();
        $body->writeUInt32($requestId);

        $this->prepareCloseSessionMessage($body, $requestId);

        $bodyBytes = $body->getBuffer();
        $totalSize = MessageHeader::HEADER_SIZE + 4 + strlen($bodyBytes);

        $encoder = new BinaryEncoder();
        $header = new MessageHeader('MSG', 'F', $totalSize);
        $header->encode($encoder);
        $encoder->writeUInt32($this->secureChannelId);
        $encoder->writeRawBytes($bodyBytes);

        $this->transport->send($encoder->getBuffer());

        try {
            $this->transport->receive();
        } catch (OpcUaException) {
        }
    }

    private function closeSessionSecure(): void
    {
        $requestId = $this->nextRequestId();

        $innerBody = new BinaryEncoder();

        $this->prepareCloseSessionMessage($innerBody, $requestId);

        $message = $this->secureChannel->buildMessage($innerBody->getBuffer());
        $this->transport->send($message);

        try {
            $this->transport->receive();
        } catch (OpcUaException) {
        }
    }

    /**
     * @param BinaryEncoder $innerBody
     * @param int $requestId
     * @return void
     */
    private function prepareCloseSessionMessage(BinaryEncoder $innerBody, int $requestId): void
    {
        $innerBody->writeNodeId(NodeId::numeric(0, 473));

        $innerBody->writeNodeId($this->authenticationToken);
        $innerBody->writeInt64(0);
        $innerBody->writeUInt32($requestId);
        $innerBody->writeUInt32(0);
        $innerBody->writeString(null);
        $innerBody->writeUInt32(10000);
        $innerBody->writeNodeId(NodeId::numeric(0, 0));
        $innerBody->writeByte(0);

        $innerBody->writeBoolean(true);
    }
}
