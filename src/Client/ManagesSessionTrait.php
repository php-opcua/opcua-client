<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Client;

use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Event\SessionActivated;
use PhpOpcua\Client\Event\SessionClosed;
use PhpOpcua\Client\Event\SessionCreated;
use PhpOpcua\Client\Event\SessionReactivated;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\OpcUaException;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Protocol\MessageHeader;
use PhpOpcua\Client\Protocol\ServiceTypeId;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Security\CertificateManager;
use PhpOpcua\Client\Security\SecureChannel;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\SessionState;

/**
 * Provides session creation, activation, and teardown for the connected client.
 */
trait ManagesSessionTrait
{
    /**
     * Get the active session service, failing if none is established.
     */
    private function requireSession(): SessionService
    {
        return $this->session ?? throw new ConnectionException('No active session: call connect() first');
    }

    /**
     * Get the current session, to reactivate it later with {@see \PhpOpcua\Client\ClientBuilder::resumeSession()}.
     *
     * @return ?SessionState Null when no session is active.
     */
    public function getSessionState(): ?SessionState
    {
        if ($this->authenticationToken === null || $this->lastEndpointUrl === null) {
            return null;
        }

        return new SessionState(
            $this->lastEndpointUrl,
            $this->authenticationToken,
            $this->serverNonce,
            $this->revisedSessionTimeout ?? $this->sessionTimeout,
        );
    }

    /**
     * Reactivate the pending session on the new secure channel, or create a new session when there is none or the server rejects it.
     *
     * @param string $endpointUrl The OPC UA endpoint URL.
     * @return void
     */
    private function establishSession(string $endpointUrl): void
    {
        $state = $this->pendingSessionState;

        if ($state !== null) {
            $this->authenticationToken = $state->authenticationToken;
            $this->serverNonce = $state->serverNonce;
            $this->revisedSessionTimeout = $state->sessionTimeout;

            try {
                $this->activateSession($endpointUrl);
                $this->pendingSessionState = null;
                $this->logger->info('Session reactivated on the new secure channel', $this->logContext());
                $this->dispatch(fn () => new SessionReactivated($this, $endpointUrl));

                return;
            } catch (ServiceException $e) {
                $this->pendingSessionState = null;
                $this->logger->warning('Session could not be reactivated ({message}), creating a new one', $this->logContext([
                    'message' => $e->getMessage(),
                ]));
                $this->authenticationToken = null;
                $this->serverNonce = null;
                $this->revisedSessionTimeout = null;
            }
        }

        $this->createAndActivateSession($endpointUrl);
    }

    /**
     * Create and activate an OPC UA session.
     *
     * @param string $endpointUrl The OPC UA endpoint URL.
     * @return void
     */
    private function createAndActivateSession(string $endpointUrl): void
    {
        $this->createSession($endpointUrl);
        $this->activateSession($endpointUrl);
    }

    /**
     * Send a CreateSession request and process the response.
     *
     * @param string $endpointUrl The OPC UA endpoint URL.
     * @return void
     */
    private function createSession(string $endpointUrl): void
    {
        $session = $this->requireSession();
        $requestId = $this->nextRequestId();
        $request = $session->encodeCreateSessionRequest($requestId, $endpointUrl, $this->sessionTimeout);
        $this->logger->debug('CreateSession request for {url}', $this->logContext(['url' => $endpointUrl]));
        $this->transport->send($request);

        $response = $this->transport->receive();
        $responseBody = $this->unwrapResponse($response);
        $decoder = $this->createDecoder($responseBody);
        $sessionResult = $session->decodeCreateSessionResponse($decoder);
        $this->authenticationToken = $sessionResult['authenticationToken'];
        $this->revisedSessionTimeout = $sessionResult['revisedSessionTimeout'];
        $this->logger->debug('CreateSession response: authToken={token}', $this->logContext(['token' => (string) $this->authenticationToken]));
        $this->dispatch(fn () => new SessionCreated($this, $endpointUrl, $this->authenticationToken));

        if (isset($sessionResult['serverNonce'])) {
            $this->serverNonce = $sessionResult['serverNonce'];
        }

        $eccKey = $session->getLastEccServerEphemeralKey();
        if ($eccKey !== null) {
            $this->eccServerEphemeralKey = $eccKey;
        }

        if (isset($sessionResult['serverCertificate'])
            && $this->secureChannel !== null
            && $this->secureChannel->getServerCertDer() === null) {
            $this->secureChannel->setServerCertDer($sessionResult['serverCertificate']);
        }
    }

    /**
     * Send an ActivateSession request and process the response.
     *
     * @param string $endpointUrl The OPC UA endpoint URL.
     * @return void
     */
    private function activateSession(string $endpointUrl): void
    {
        $session = $this->requireSession();
        $authenticationToken = $this->authenticationToken
            ?? throw new ConnectionException('Cannot activate session: createSession() has not run');
        $requestId = $this->nextRequestId();
        [$userCertDer, $userPrivateKey] = $this->loadUserCertificate();

        $request = $session->encodeActivateSessionRequest(
            $requestId,
            $authenticationToken,
            $this->username,
            $this->password,
            $userCertDer,
            $userPrivateKey,
            $this->serverNonce,
            $this->eccServerEphemeralKey,
        );
        $this->logger->debug('ActivateSession request', $this->logContext());
        $this->transport->send($request);

        $response = $this->transport->receive();
        $responseBody = $this->unwrapResponse($response);
        $decoder = $this->createDecoder($responseBody);
        $serverNonce = $session->decodeActivateSessionResponse($decoder);
        if ($serverNonce !== null) {
            $this->serverNonce = $serverNonce;
        }
        $this->logger->debug('ActivateSession response received', $this->logContext());
        $this->dispatch(fn () => new SessionActivated($this, $endpointUrl));
    }

    /**
     * Load the user certificate and private key for X509 identity token.
     *
     * @return array{0: ?string, 1: ?\OpenSSLAsymmetricKey}
     */
    private function loadUserCertificate(): array
    {
        if ($this->userCertPath === null || $this->userKeyPath === null) {
            return [null, null];
        }

        $certManager = new CertificateManager();
        $certContent = file_get_contents($this->userCertPath);

        $userCertDer = null;
        if ($certContent !== false && str_contains($certContent, '-----BEGIN')) {
            $userCertDer = $certManager->loadCertificatePem($this->userCertPath);
        } elseif ($certContent !== false) {
            $userCertDer = $certManager->loadCertificateDer($this->userCertPath);
        }

        return [$userCertDer, $certManager->loadPrivateKeyPem($this->userKeyPath)];
    }

    /**
     * Close the current session.
     *
     * @return void
     */
    private function closeSession(): void
    {
        $this->logger->debug('CloseSession request', $this->logContext());
        $this->dispatch(fn () => new SessionClosed($this));

        if ($this->secureChannel !== null && $this->secureChannel->isSecurityActive()) {
            $this->closeSessionSecure($this->secureChannel);

            return;
        }

        $session = $this->requireSession();
        $body = new BinaryEncoder();
        $body->writeUInt32($session->getTokenId());
        $body->writeUInt32($session->getNextSequenceNumber());
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

    /**
     * Close the session when message-level security is active.
     *
     * @param SecureChannel $secureChannel The active secure channel.
     * @return void
     */
    private function closeSessionSecure(SecureChannel $secureChannel): void
    {
        $requestId = $this->nextRequestId();

        $innerBody = new BinaryEncoder();

        $this->prepareCloseSessionMessage($innerBody, $requestId);

        $message = $secureChannel->buildMessage($innerBody->getBuffer());
        $this->transport->send($message);

        try {
            $this->transport->receive();
        } catch (OpcUaException) {
        }
    }

    /**
     * Encode the CloseSession request body into a BinaryEncoder.
     *
     * @param BinaryEncoder $innerBody The encoder to write the message into.
     * @param int $requestId The request identifier.
     * @return void
     */
    private function prepareCloseSessionMessage(BinaryEncoder $innerBody, int $requestId): void
    {
        $innerBody->writeNodeId(NodeId::numeric(0, ServiceTypeId::CLOSE_SESSION_REQUEST));

        $innerBody->writeNodeId($this->authenticationToken ?? NodeId::numeric(0, ServiceTypeId::NULL));
        $innerBody->writeDateTime(new \DateTimeImmutable());
        $innerBody->writeUInt32($requestId);
        $innerBody->writeUInt32(0);
        $innerBody->writeString(null);
        $innerBody->writeUInt32(10000);
        $innerBody->writeNodeId(NodeId::numeric(0, ServiceTypeId::NULL));
        $innerBody->writeByte(0);

        $innerBody->writeBoolean(true);
    }
}
