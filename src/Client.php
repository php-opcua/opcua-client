<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Exception\ConfigurationException;
use Gianfriaur\OpcuaPhpClient\Exception\ConnectionException;
use Gianfriaur\OpcuaPhpClient\Exception\OpcUaException;
use Gianfriaur\OpcuaPhpClient\Exception\ProtocolException;
use Gianfriaur\OpcuaPhpClient\Exception\SecurityException;
use Gianfriaur\OpcuaPhpClient\Exception\ServiceException;
use Gianfriaur\OpcuaPhpClient\Protocol\AcknowledgeMessage;
use Gianfriaur\OpcuaPhpClient\Protocol\BrowseService;
use Gianfriaur\OpcuaPhpClient\Protocol\CallService;
use Gianfriaur\OpcuaPhpClient\Protocol\GetEndpointsService;
use Gianfriaur\OpcuaPhpClient\Protocol\HelloMessage;
use Gianfriaur\OpcuaPhpClient\Protocol\MessageHeader;
use Gianfriaur\OpcuaPhpClient\Protocol\HistoryReadService;
use Gianfriaur\OpcuaPhpClient\Protocol\MonitoredItemService;
use Gianfriaur\OpcuaPhpClient\Protocol\PublishService;
use Gianfriaur\OpcuaPhpClient\Protocol\ReadService;
use Gianfriaur\OpcuaPhpClient\Protocol\SecureChannelRequest;
use Gianfriaur\OpcuaPhpClient\Protocol\SecureChannelResponse;
use Gianfriaur\OpcuaPhpClient\Protocol\SessionService;
use Gianfriaur\OpcuaPhpClient\Protocol\SubscriptionService;
use Gianfriaur\OpcuaPhpClient\Protocol\WriteService;
use Gianfriaur\OpcuaPhpClient\Security\CertificateManager;
use Gianfriaur\OpcuaPhpClient\Security\SecureChannel;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaPhpClient\Transport\TcpTransport;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\EndpointDescription;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\ReferenceDescription;
use Gianfriaur\OpcuaPhpClient\Types\Variant;

class Client implements OpcUaClientInterface
{
    private TcpTransport $transport;
    private ?SessionService $session = null;
    private ?BrowseService $browseService = null;
    private ?ReadService $readService = null;
    private ?WriteService $writeService = null;
    private ?CallService $callService = null;
    private ?GetEndpointsService $getEndpointsService = null;
    private ?SubscriptionService $subscriptionService = null;
    private ?MonitoredItemService $monitoredItemService = null;
    private ?PublishService $publishService = null;
    private ?HistoryReadService $historyReadService = null;
    private ?NodeId $authenticationToken = null;
    private int $secureChannelId = 0;
    private int $requestId = 10;

    private SecurityPolicy $securityPolicy = SecurityPolicy::None;
    private SecurityMode $securityMode = SecurityMode::None;
    private ?string $username = null;
    private ?string $password = null;
    private ?string $clientCertPath = null;
    private ?string $clientKeyPath = null;
    private ?string $serverCertDer = null;
    private ?SecureChannel $secureChannel = null;
    private ?string $serverNonce = null;

    private ?string $caCertPath = null;

    private ?string $userCertPath = null;
    private ?string $userKeyPath = null;

    private ?string $usernamePolicyId = null;
    private ?string $certificatePolicyId = null;
    private ?string $anonymousPolicyId = null;

    public function __construct()
    {
        $this->transport = new TcpTransport();
    }

    /**
     * @param SecurityPolicy $policy
     */
    public function setSecurityPolicy(SecurityPolicy $policy): self
    {
        $this->securityPolicy = $policy;

        return $this;
    }

    /**
     * @param SecurityMode $mode
     */
    public function setSecurityMode(SecurityMode $mode): self
    {
        $this->securityMode = $mode;

        return $this;
    }

    /**
     * @param string $username
     * @param string $password
     */
    public function setUserCredentials(string $username, string $password): self
    {
        $this->username = $username;
        $this->password = $password;

        return $this;
    }

    /**
     * @param string $certPath
     * @param string $keyPath
     * @param ?string $caCertPath
     */
    public function setClientCertificate(string $certPath, string $keyPath, ?string $caCertPath = null): self
    {
        $this->clientCertPath = $certPath;
        $this->clientKeyPath = $keyPath;
        $this->caCertPath = $caCertPath;

        return $this;
    }

    /**
     * @param string $certPath
     * @param string $keyPath
     */
    public function setUserCertificate(string $certPath, string $keyPath): self
    {
        $this->userCertPath = $certPath;
        $this->userKeyPath = $keyPath;

        return $this;
    }

    /**
     * @param string $endpointUrl
     */
    public function connect(string $endpointUrl): void
    {
        $parsed = parse_url($endpointUrl);
        if ($parsed === false || !isset($parsed['host'])) {
            throw new ConfigurationException("Invalid endpoint URL: {$endpointUrl}");
        }

        $host = $parsed['host'];
        $port = $parsed['port'] ?? 4840;

        $isSecure = $this->securityPolicy !== SecurityPolicy::None
            && $this->securityMode !== SecurityMode::None;

        if ($isSecure && $this->serverCertDer === null) {
            $this->discoverServerCertificate($host, $port, $endpointUrl);
        }

        $this->transport->connect($host, $port);

        $this->doHandshake($endpointUrl);

        $this->openSecureChannel();

        $this->createAndActivateSession($endpointUrl);
    }

    /**
     * @param string $endpointUrl
     * @return EndpointDescription[]
     */
    public function getEndpoints(string $endpointUrl): array
    {
        if ($this->getEndpointsService === null || $this->session === null) {
            throw new ConnectionException('Not connected (secure channel not open)');
        }

        $requestId = $this->nextRequestId();
        $authToken = $this->authenticationToken ?? NodeId::numeric(0, 0);
        $request = $this->getEndpointsService->encodeGetEndpointsRequest($requestId, $endpointUrl, $authToken);
        $this->transport->send($request);

        $response = $this->transport->receive();
        $responseBody = $this->unwrapResponse($response);
        $decoder = new BinaryDecoder($responseBody);

        return $this->getEndpointsService->decodeGetEndpointsResponse($decoder);
    }

    /**
     * @param NodeId $nodeId
     * @param int $direction
     * @param ?NodeId $referenceTypeId
     * @param bool $includeSubtypes
     * @param int $nodeClassMask
     * @return ReferenceDescription[]
     */
    public function browse(
        NodeId $nodeId,
        int $direction = 0,
        ?NodeId $referenceTypeId = null,
        bool $includeSubtypes = true,
        int $nodeClassMask = 0,
    ): array {
        if ($this->browseService === null || $this->authenticationToken === null) {
            throw new ConnectionException('Not connected');
        }

        $requestId = $this->nextRequestId();
        $request = $this->browseService->encodeBrowseRequest(
            $requestId,
            $nodeId,
            $this->authenticationToken,
            $direction,
            $referenceTypeId,
            $includeSubtypes,
            $nodeClassMask,
        );
        $this->transport->send($request);

        $response = $this->transport->receive();
        $responseBody = $this->unwrapResponse($response);
        $decoder = new BinaryDecoder($responseBody);

        return $this->browseService->decodeBrowseResponse($decoder);
    }

    /**
     * @param NodeId $nodeId
     * @param int $direction
     * @param ?NodeId $referenceTypeId
     * @param bool $includeSubtypes
     * @param int $nodeClassMask
     * @return array{references: ReferenceDescription[], continuationPoint: ?string}
     */
    public function browseWithContinuation(
        NodeId $nodeId,
        int $direction = 0,
        ?NodeId $referenceTypeId = null,
        bool $includeSubtypes = true,
        int $nodeClassMask = 0,
    ): array {
        if ($this->browseService === null || $this->authenticationToken === null) {
            throw new ConnectionException('Not connected');
        }

        $requestId = $this->nextRequestId();
        $request = $this->browseService->encodeBrowseRequest(
            $requestId,
            $nodeId,
            $this->authenticationToken,
            $direction,
            $referenceTypeId,
            $includeSubtypes,
            $nodeClassMask,
        );
        $this->transport->send($request);

        $response = $this->transport->receive();
        $responseBody = $this->unwrapResponse($response);
        $decoder = new BinaryDecoder($responseBody);

        return $this->browseService->decodeBrowseResponseWithContinuation($decoder);
    }

    /**
     * @param string $continuationPoint
     * @return array{references: ReferenceDescription[], continuationPoint: ?string}
     */
    public function browseNext(string $continuationPoint): array
    {
        if ($this->browseService === null || $this->authenticationToken === null) {
            throw new ConnectionException('Not connected');
        }

        $requestId = $this->nextRequestId();
        $request = $this->browseService->encodeBrowseNextRequest($requestId, $continuationPoint, $this->authenticationToken);
        $this->transport->send($request);

        $response = $this->transport->receive();
        $responseBody = $this->unwrapResponse($response);
        $decoder = new BinaryDecoder($responseBody);

        return $this->browseService->decodeBrowseNextResponse($decoder);
    }

    /**
     * @param NodeId $nodeId
     * @param int $attributeId
     */
    public function read(NodeId $nodeId, int $attributeId = 13): DataValue
    {
        if ($this->readService === null || $this->authenticationToken === null) {
            throw new ConnectionException('Not connected');
        }

        $requestId = $this->nextRequestId();
        $request = $this->readService->encodeReadRequest($requestId, $nodeId, $this->authenticationToken, $attributeId);
        $this->transport->send($request);

        $response = $this->transport->receive();
        $responseBody = $this->unwrapResponse($response);
        $decoder = new BinaryDecoder($responseBody);

        return $this->readService->decodeReadResponse($decoder);
    }

    /**
     * @param array<array{nodeId: NodeId, attributeId?: int}> $items
     * @return DataValue[]
     */
    public function readMulti(array $items): array
    {
        if ($this->readService === null || $this->authenticationToken === null) {
            throw new ConnectionException('Not connected');
        }

        $requestId = $this->nextRequestId();
        $request = $this->readService->encodeReadMultiRequest($requestId, $items, $this->authenticationToken);
        $this->transport->send($request);

        $response = $this->transport->receive();
        $responseBody = $this->unwrapResponse($response);
        $decoder = new BinaryDecoder($responseBody);

        return $this->readService->decodeReadMultiResponse($decoder);
    }

    /**
     * @param NodeId $nodeId
     * @param mixed $value
     * @param BuiltinType $type
     */
    public function write(NodeId $nodeId, mixed $value, BuiltinType $type): int
    {
        if ($this->writeService === null || $this->authenticationToken === null) {
            throw new ConnectionException('Not connected');
        }

        $variant = new Variant($type, $value);
        $dataValue = new DataValue($variant);

        $requestId = $this->nextRequestId();
        $request = $this->writeService->encodeWriteRequest($requestId, $nodeId, $dataValue, $this->authenticationToken);
        $this->transport->send($request);

        $response = $this->transport->receive();
        $responseBody = $this->unwrapResponse($response);
        $decoder = new BinaryDecoder($responseBody);

        $results = $this->writeService->decodeWriteResponse($decoder);

        return $results[0] ?? 0;
    }

    /**
     * @param array<array{nodeId: NodeId, value: mixed, type: BuiltinType, attributeId?: int}> $items
     * @return int[]
     */
    public function writeMulti(array $items): array
    {
        if ($this->writeService === null || $this->authenticationToken === null) {
            throw new ConnectionException('Not connected');
        }

        $writeItems = [];
        foreach ($items as $item) {
            $variant = new Variant($item['type'], $item['value']);
            $writeItems[] = [
                'nodeId' => $item['nodeId'],
                'dataValue' => new DataValue($variant),
                'attributeId' => $item['attributeId'] ?? 13,
            ];
        }

        $requestId = $this->nextRequestId();
        $request = $this->writeService->encodeWriteMultiRequest($requestId, $writeItems, $this->authenticationToken);
        $this->transport->send($request);

        $response = $this->transport->receive();
        $responseBody = $this->unwrapResponse($response);
        $decoder = new BinaryDecoder($responseBody);

        return $this->writeService->decodeWriteResponse($decoder);
    }

    /**
     * @param NodeId $objectId
     * @param NodeId $methodId
     * @param Variant[] $inputArguments
     * @return array{statusCode: int, inputArgumentResults: int[], outputArguments: Variant[]}
     */
    public function call(NodeId $objectId, NodeId $methodId, array $inputArguments = []): array
    {
        if ($this->callService === null || $this->authenticationToken === null) {
            throw new ConnectionException('Not connected');
        }

        $requestId = $this->nextRequestId();
        $request = $this->callService->encodeCallRequest(
            $requestId,
            $objectId,
            $methodId,
            $inputArguments,
            $this->authenticationToken,
        );
        $this->transport->send($request);

        $response = $this->transport->receive();
        $responseBody = $this->unwrapResponse($response);
        $decoder = new BinaryDecoder($responseBody);

        return $this->callService->decodeCallResponse($decoder);
    }

    /**
     * @param float $publishingInterval
     * @param int $lifetimeCount
     * @param int $maxKeepAliveCount
     * @param int $maxNotificationsPerPublish
     * @param bool $publishingEnabled
     * @param int $priority
     * @return array{subscriptionId: int, revisedPublishingInterval: float, revisedLifetimeCount: int, revisedMaxKeepAliveCount: int}
     */
    public function createSubscription(
        float $publishingInterval = 500.0,
        int $lifetimeCount = 2400,
        int $maxKeepAliveCount = 10,
        int $maxNotificationsPerPublish = 0,
        bool $publishingEnabled = true,
        int $priority = 0,
    ): array {
        if ($this->subscriptionService === null || $this->authenticationToken === null) {
            throw new ConnectionException('Not connected');
        }

        $requestId = $this->nextRequestId();
        $request = $this->subscriptionService->encodeCreateSubscriptionRequest(
            $requestId,
            $this->authenticationToken,
            $publishingInterval,
            $lifetimeCount,
            $maxKeepAliveCount,
            $maxNotificationsPerPublish,
            $publishingEnabled,
            $priority,
        );
        $this->transport->send($request);

        $response = $this->transport->receive();
        $responseBody = $this->unwrapResponse($response);
        $decoder = new BinaryDecoder($responseBody);

        return $this->subscriptionService->decodeCreateSubscriptionResponse($decoder);
    }

    /**
     * @param int $subscriptionId
     * @param array<array{nodeId: NodeId, attributeId?: int, samplingInterval?: float, queueSize?: int, clientHandle?: int, monitoringMode?: int}> $items
     * @return array<array{statusCode: int, monitoredItemId: int, revisedSamplingInterval: float, revisedQueueSize: int}>
     */
    public function createMonitoredItems(
        int $subscriptionId,
        array $items,
    ): array {
        if ($this->monitoredItemService === null || $this->authenticationToken === null) {
            throw new ConnectionException('Not connected');
        }

        $requestId = $this->nextRequestId();
        $request = $this->monitoredItemService->encodeCreateMonitoredItemsRequest(
            $requestId,
            $this->authenticationToken,
            $subscriptionId,
            $items,
        );
        $this->transport->send($request);

        $response = $this->transport->receive();
        $responseBody = $this->unwrapResponse($response);
        $decoder = new BinaryDecoder($responseBody);

        return $this->monitoredItemService->decodeCreateMonitoredItemsResponse($decoder);
    }

    /**
     * @param int $subscriptionId
     * @param NodeId $nodeId
     * @param string[] $selectFields
     * @param int $clientHandle
     * @return array{statusCode: int, monitoredItemId: int, revisedSamplingInterval: float, revisedQueueSize: int}
     */
    public function createEventMonitoredItem(
        int $subscriptionId,
        NodeId $nodeId,
        array $selectFields = ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'],
        int $clientHandle = 1,
    ): array {
        if ($this->monitoredItemService === null || $this->authenticationToken === null) {
            throw new ConnectionException('Not connected');
        }

        $requestId = $this->nextRequestId();
        $request = $this->monitoredItemService->encodeCreateEventMonitoredItemRequest(
            $requestId,
            $this->authenticationToken,
            $subscriptionId,
            $nodeId,
            $selectFields,
            $clientHandle,
        );
        $this->transport->send($request);

        $response = $this->transport->receive();
        $responseBody = $this->unwrapResponse($response);
        $decoder = new BinaryDecoder($responseBody);

        $results = $this->monitoredItemService->decodeCreateMonitoredItemsResponse($decoder);

        return $results[0] ?? ['statusCode' => 0, 'monitoredItemId' => 0, 'revisedSamplingInterval' => 0.0, 'revisedQueueSize' => 0];
    }

    /**
     * @param int $subscriptionId
     * @param int[] $monitoredItemIds
     * @return int[]
     */
    public function deleteMonitoredItems(int $subscriptionId, array $monitoredItemIds): array
    {
        if ($this->monitoredItemService === null || $this->authenticationToken === null) {
            throw new ConnectionException('Not connected');
        }

        $requestId = $this->nextRequestId();
        $request = $this->monitoredItemService->encodeDeleteMonitoredItemsRequest(
            $requestId,
            $this->authenticationToken,
            $subscriptionId,
            $monitoredItemIds,
        );
        $this->transport->send($request);

        $response = $this->transport->receive();
        $responseBody = $this->unwrapResponse($response);
        $decoder = new BinaryDecoder($responseBody);

        return $this->monitoredItemService->decodeDeleteMonitoredItemsResponse($decoder);
    }

    /**
     * @param int $subscriptionId
     */
    public function deleteSubscription(int $subscriptionId): int
    {
        if ($this->subscriptionService === null || $this->authenticationToken === null) {
            throw new ConnectionException('Not connected');
        }

        $requestId = $this->nextRequestId();
        $request = $this->subscriptionService->encodeDeleteSubscriptionsRequest(
            $requestId,
            $this->authenticationToken,
            [$subscriptionId],
        );
        $this->transport->send($request);

        $response = $this->transport->receive();
        $responseBody = $this->unwrapResponse($response);
        $decoder = new BinaryDecoder($responseBody);

        $results = $this->subscriptionService->decodeDeleteSubscriptionsResponse($decoder);

        return $results[0] ?? 0;
    }

    /**
     * @param array<array{subscriptionId: int, sequenceNumber: int}> $acknowledgements
     * @return array{subscriptionId: int, sequenceNumber: int, moreNotifications: bool, notifications: array, availableSequenceNumbers: int[]}
     */
    public function publish(array $acknowledgements = []): array
    {
        if ($this->publishService === null || $this->authenticationToken === null) {
            throw new ConnectionException('Not connected');
        }

        $requestId = $this->nextRequestId();
        $request = $this->publishService->encodePublishRequest(
            $requestId,
            $this->authenticationToken,
            $acknowledgements,
        );
        $this->transport->send($request);

        $response = $this->transport->receive();
        $responseBody = $this->unwrapResponse($response);
        $decoder = new BinaryDecoder($responseBody);

        return $this->publishService->decodePublishResponse($decoder);
    }

    /**
     * @param NodeId $nodeId
     * @param ?\DateTimeImmutable $startTime
     * @param ?\DateTimeImmutable $endTime
     * @param int $numValuesPerNode
     * @param bool $returnBounds
     * @return DataValue[]
     */
    public function historyReadRaw(
        NodeId $nodeId,
        ?\DateTimeImmutable $startTime = null,
        ?\DateTimeImmutable $endTime = null,
        int $numValuesPerNode = 0,
        bool $returnBounds = false,
    ): array {
        if ($this->historyReadService === null || $this->authenticationToken === null) {
            throw new ConnectionException('Not connected');
        }

        $requestId = $this->nextRequestId();
        $request = $this->historyReadService->encodeHistoryReadRawRequest(
            $requestId,
            $this->authenticationToken,
            $nodeId,
            $startTime,
            $endTime,
            $numValuesPerNode,
            $returnBounds,
        );
        $this->transport->send($request);

        $response = $this->transport->receive();
        $responseBody = $this->unwrapResponse($response);
        $decoder = new BinaryDecoder($responseBody);

        return $this->historyReadService->decodeHistoryReadResponse($decoder);
    }

    /**
     * @param NodeId $nodeId
     * @param \DateTimeImmutable $startTime
     * @param \DateTimeImmutable $endTime
     * @param float $processingInterval
     * @param NodeId $aggregateType
     * @return DataValue[]
     */
    public function historyReadProcessed(
        NodeId $nodeId,
        \DateTimeImmutable $startTime,
        \DateTimeImmutable $endTime,
        float $processingInterval,
        NodeId $aggregateType,
    ): array {
        if ($this->historyReadService === null || $this->authenticationToken === null) {
            throw new ConnectionException('Not connected');
        }

        $requestId = $this->nextRequestId();
        $request = $this->historyReadService->encodeHistoryReadProcessedRequest(
            $requestId,
            $this->authenticationToken,
            $nodeId,
            $startTime,
            $endTime,
            $processingInterval,
            $aggregateType,
        );
        $this->transport->send($request);

        $response = $this->transport->receive();
        $responseBody = $this->unwrapResponse($response);
        $decoder = new BinaryDecoder($responseBody);

        return $this->historyReadService->decodeHistoryReadResponse($decoder);
    }

    /**
     * @param NodeId $nodeId
     * @param \DateTimeImmutable[] $timestamps
     * @return DataValue[]
     */
    public function historyReadAtTime(
        NodeId $nodeId,
        array $timestamps,
    ): array {
        if ($this->historyReadService === null || $this->authenticationToken === null) {
            throw new ConnectionException('Not connected');
        }

        $requestId = $this->nextRequestId();
        $request = $this->historyReadService->encodeHistoryReadAtTimeRequest(
            $requestId,
            $this->authenticationToken,
            $nodeId,
            $timestamps,
        );
        $this->transport->send($request);

        $response = $this->transport->receive();
        $responseBody = $this->unwrapResponse($response);
        $decoder = new BinaryDecoder($responseBody);

        return $this->historyReadService->decodeHistoryReadResponse($decoder);
    }

    public function disconnect(): void
    {
        if ($this->session !== null && $this->authenticationToken !== null) {
            try {
                $this->closeSession();
            } catch (OpcUaException) {
            }
        }

        if ($this->secureChannelId !== 0) {
            try {
                $this->closeSecureChannel();
            } catch (OpcUaException) {
            }
        }

        $this->transport->close();
        $this->session = null;
        $this->browseService = null;
        $this->readService = null;
        $this->writeService = null;
        $this->callService = null;
        $this->getEndpointsService = null;
        $this->subscriptionService = null;
        $this->monitoredItemService = null;
        $this->publishService = null;
        $this->historyReadService = null;
        $this->authenticationToken = null;
        $this->secureChannelId = 0;
        $this->secureChannel = null;
        $this->serverNonce = null;
    }

    /**
     * @param string $endpointUrl
     */
    private function doHandshake(string $endpointUrl): void
    {
        $hello = new HelloMessage(
            endpointUrl: $endpointUrl,
        );
        $this->transport->send($hello->encode());

        $response = $this->transport->receive();
        $decoder = new BinaryDecoder($response);
        $header = MessageHeader::decode($decoder);

        if ($header->getMessageType() === 'ERR') {
            $errorCode = $decoder->readUInt32();
            $errorMessage = $decoder->readString();
            throw new ProtocolException("Server error during handshake: [{$errorCode}] {$errorMessage}");
        }

        if ($header->getMessageType() !== 'ACK') {
            throw new ProtocolException("Expected ACK, got: {$header->getMessageType()}");
        }

        $ack = AcknowledgeMessage::decode($decoder);
        $this->transport->setReceiveBufferSize($ack->getReceiveBufferSize());
    }

    /**
     * @param string $host
     * @param int $port
     * @param string $endpointUrl
     */
    private function discoverServerCertificate(string $host, int $port, string $endpointUrl): void
    {
        $discoveryTransport = new TcpTransport();
        $discoveryTransport->connect($host, $port);

        $helloMessage = new HelloMessage(endpointUrl: $endpointUrl);
        $discoveryTransport->send($helloMessage->encode());
        $helloResponse = $discoveryTransport->receive();
        $helloDecoder = new BinaryDecoder($helloResponse);
        $helloHeader = MessageHeader::decode($helloDecoder);
        if ($helloHeader->getMessageType() !== 'ACK') {
            throw new ProtocolException("Discovery: Expected ACK, got: {$helloHeader->getMessageType()}");
        }
        AcknowledgeMessage::decode($helloDecoder);

        $opnRequest = new SecureChannelRequest();
        $discoveryTransport->send($opnRequest->encode());
        $opnResponse = $discoveryTransport->receive();
        $opnDecoder = new BinaryDecoder($opnResponse);
        $opnHeader = MessageHeader::decode($opnDecoder);
        if ($opnHeader->getMessageType() !== 'OPN') {
            throw new ProtocolException("Discovery: Expected OPN, got: {$opnHeader->getMessageType()}");
        }
        $opnDecoder->readUInt32();
        $scResponse = SecureChannelResponse::decode($opnDecoder);
        $discoveryChannelId = $scResponse->getSecureChannelId();
        $discoveryTokenId = $scResponse->getTokenId();

        $session = new SessionService($discoveryChannelId, $discoveryTokenId);
        $getEndpointsService = new GetEndpointsService($session);
        $requestId = 1;
        $request = $getEndpointsService->encodeGetEndpointsRequest($requestId, $endpointUrl, NodeId::numeric(0, 0));
        $discoveryTransport->send($request);

        $response = $discoveryTransport->receive();
        $responseBody = substr($response, MessageHeader::HEADER_SIZE + 4);
        $decoder = new BinaryDecoder($responseBody);
        $endpoints = $getEndpointsService->decodeGetEndpointsResponse($decoder);

        foreach ($endpoints as $ep) {
            if ($ep->getSecurityPolicyUri() === $this->securityPolicy->value
                && $ep->getSecurityMode() === $this->securityMode->value
                && $ep->getServerCertificate() !== null
            ) {
                $this->serverCertDer = $ep->getServerCertificate();
                foreach ($ep->getUserIdentityTokens() as $tokenPolicy) {
                    match ($tokenPolicy->getTokenType()) {
                        1 => $this->usernamePolicyId = $tokenPolicy->getPolicyId(),
                        2 => $this->certificatePolicyId = $tokenPolicy->getPolicyId(),
                        0 => $this->anonymousPolicyId = $tokenPolicy->getPolicyId(),
                        default => null,
                    };
                }
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

        $discoveryTransport->close();

        if ($this->serverCertDer === null) {
            throw new SecurityException('Could not obtain server certificate from GetEndpoints');
        }
    }

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

        $channelId = $decoder->readUInt32();

        $scResponse = SecureChannelResponse::decode($decoder);
        $this->secureChannelId = $scResponse->getSecureChannelId();

        $this->session = new SessionService($this->secureChannelId, $scResponse->getTokenId());
        $this->session->setUserTokenPolicyIds(
            $this->usernamePolicyId,
            $this->certificatePolicyId,
            $this->anonymousPolicyId,
        );
        $this->browseService = new BrowseService($this->session);
        $this->readService = new ReadService($this->session);
        $this->writeService = new WriteService($this->session);
        $this->callService = new CallService($this->session);
        $this->getEndpointsService = new GetEndpointsService($this->session);
        $this->subscriptionService = new SubscriptionService($this->session);
        $this->monitoredItemService = new MonitoredItemService($this->session);
        $this->publishService = new PublishService($this->session);
        $this->historyReadService = new HistoryReadService($this->session);
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

        $serverCertDer = $this->serverCertDer;

        $this->secureChannel = new SecureChannel(
            $this->securityPolicy,
            $this->securityMode,
            $clientCertDer,
            $clientPrivateKey,
            $serverCertDer,
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
        $this->browseService = new BrowseService($this->session);
        $this->readService = new ReadService($this->session);
        $this->writeService = new WriteService($this->session);
        $this->callService = new CallService($this->session);
        $this->getEndpointsService = new GetEndpointsService($this->session);
        $this->subscriptionService = new SubscriptionService($this->session);
        $this->monitoredItemService = new MonitoredItemService($this->session);
        $this->publishService = new PublishService($this->session);
        $this->historyReadService = new HistoryReadService($this->session);
    }

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
        $decoder = new BinaryDecoder($responseBody);
        $sessionResult = $this->session->decodeCreateSessionResponse($decoder);
        $this->authenticationToken = $sessionResult['authenticationToken'];

        if (isset($sessionResult['serverNonce']) && $sessionResult['serverNonce'] !== null) {
            $this->serverNonce = $sessionResult['serverNonce'];
        }

        if (isset($sessionResult['serverCertificate']) && $sessionResult['serverCertificate'] !== null) {
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
        $decoder = new BinaryDecoder($responseBody);
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

        $body->writeNodeId(NodeId::numeric(0, 473));

        $body->writeNodeId($this->authenticationToken);
        $body->writeInt64(0);
        $body->writeUInt32($requestId);
        $body->writeUInt32(0);
        $body->writeString(null);
        $body->writeUInt32(10000);
        $body->writeNodeId(NodeId::numeric(0, 0));
        $body->writeByte(0);

        $body->writeBoolean(true);

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

        $message = $this->secureChannel->buildMessage($innerBody->getBuffer());
        $this->transport->send($message);

        try {
            $this->transport->receive();
        } catch (OpcUaException) {
        }
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

    /**
     * @param string $response
     */
    private function unwrapResponse(string $response): string
    {
        if (substr($response, 0, 3) === 'ERR') {
            $decoder = new \Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder($response);
            \Gianfriaur\OpcuaPhpClient\Protocol\MessageHeader::decode($decoder);
            $errorCode = $decoder->readUInt32();
            $reason = $decoder->readString() ?? 'Unknown error';
            throw new ServiceException(sprintf("Server error 0x%08X: %s", $errorCode, $reason), $errorCode);
        }

        if ($this->secureChannel !== null && $this->secureChannel->isSecurityActive()) {
            $result = $this->secureChannel->processMessage($response);
            return $result;
        }

        return substr($response, MessageHeader::HEADER_SIZE + 4);
    }

    private function nextRequestId(): int
    {
        return $this->requestId++;
    }
}
