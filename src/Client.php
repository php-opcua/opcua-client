<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient;

use Gianfriaur\OpcuaPhpClient\Client\ManagesAutoRetryTrait;
use Gianfriaur\OpcuaPhpClient\Client\ManagesBatchingTrait;
use Gianfriaur\OpcuaPhpClient\Client\ManagesCacheTrait;
use Gianfriaur\OpcuaPhpClient\Client\ManagesBrowseDepthTrait;
use Gianfriaur\OpcuaPhpClient\Client\ManagesBrowseTrait;
use Gianfriaur\OpcuaPhpClient\Client\ManagesConnectionTrait;
use Gianfriaur\OpcuaPhpClient\Client\ManagesHandshakeTrait;
use Gianfriaur\OpcuaPhpClient\Client\ManagesHistoryTrait;
use Gianfriaur\OpcuaPhpClient\Client\ManagesReadWriteTrait;
use Gianfriaur\OpcuaPhpClient\Client\ManagesSecureChannelTrait;
use Gianfriaur\OpcuaPhpClient\Client\ManagesSessionTrait;
use Gianfriaur\OpcuaPhpClient\Client\ManagesSubscriptionsTrait;
use Gianfriaur\OpcuaPhpClient\Client\ManagesTypeDiscoveryTrait;
use Gianfriaur\OpcuaPhpClient\Client\ManagesTimeoutTrait;
use Gianfriaur\OpcuaPhpClient\Client\ManagesTranslateBrowsePathTrait;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Exception\ServiceException;
use Gianfriaur\OpcuaPhpClient\Protocol\BrowseService;
use Gianfriaur\OpcuaPhpClient\Protocol\CallService;
use Gianfriaur\OpcuaPhpClient\Protocol\GetEndpointsService;
use Gianfriaur\OpcuaPhpClient\Protocol\HistoryReadService;
use Gianfriaur\OpcuaPhpClient\Protocol\MessageHeader;
use Gianfriaur\OpcuaPhpClient\Protocol\MonitoredItemService;
use Gianfriaur\OpcuaPhpClient\Protocol\PublishService;
use Gianfriaur\OpcuaPhpClient\Protocol\ReadService;
use Gianfriaur\OpcuaPhpClient\Protocol\SessionService;
use Gianfriaur\OpcuaPhpClient\Protocol\SubscriptionService;
use Gianfriaur\OpcuaPhpClient\Protocol\TranslateBrowsePathService;
use Gianfriaur\OpcuaPhpClient\Protocol\WriteService;
use Gianfriaur\OpcuaPhpClient\Repository\ExtensionObjectRepository;
use Gianfriaur\OpcuaPhpClient\Security\SecureChannel;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaPhpClient\Transport\TcpTransport;
use Gianfriaur\OpcuaPhpClient\Types\ConnectionState;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * OPC UA client implementation providing connection management, browsing, reading, writing, subscriptions, and history access.
 *
 * @implements OpcUaClientInterface
 *
 * @see OpcUaClientInterface
 */
class Client implements OpcUaClientInterface
{
    use ManagesTimeoutTrait;
    use ManagesAutoRetryTrait;
    use ManagesBatchingTrait;
    use ManagesCacheTrait;
    use ManagesBrowseDepthTrait;
    use ManagesConnectionTrait;
    use ManagesHandshakeTrait;
    use ManagesSecureChannelTrait;
    use ManagesSessionTrait;
    use ManagesBrowseTrait;
    use ManagesReadWriteTrait;
    use ManagesSubscriptionsTrait;
    use ManagesHistoryTrait;
    use ManagesTypeDiscoveryTrait;
    use ManagesTranslateBrowsePathTrait;

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
    private ?TranslateBrowsePathService $translateBrowsePathService = null;
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

    private ?string $lastEndpointUrl = null;
    private ConnectionState $connectionState = ConnectionState::Disconnected;
    private ExtensionObjectRepository $extensionObjectRepository;
    private LoggerInterface $logger;

    /**
     * @param ?ExtensionObjectRepository $extensionObjectRepository Optional custom repository for extension object decoding.
     * @param ?LoggerInterface $logger Optional PSR-3 logger for connection events, retries, and errors.
     */
    public function __construct(?ExtensionObjectRepository $extensionObjectRepository = null, ?LoggerInterface $logger = null)
    {
        $this->transport = new TcpTransport();
        $this->extensionObjectRepository = $extensionObjectRepository ?? new ExtensionObjectRepository();
        $this->logger = $logger ?? new NullLogger();
        $this->initTimeout();
    }

    /**
     * @param LoggerInterface $logger
     * @return self
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Return the extension object repository used for custom type decoding.
     *
     * @return ExtensionObjectRepository
     *
     * @see ExtensionObjectRepository
     */
    public function getExtensionObjectRepository(): ExtensionObjectRepository
    {
        return $this->extensionObjectRepository;
    }

    /**
     * Set the security policy for the connection.
     *
     * @param SecurityPolicy $policy The security policy to use.
     * @return self
     *
     * @see SecurityPolicy
     */
    public function setSecurityPolicy(SecurityPolicy $policy): self
    {
        $this->securityPolicy = $policy;

        return $this;
    }

    /**
     * Set the message security mode for the connection.
     *
     * @param SecurityMode $mode The security mode to use.
     * @return self
     *
     * @see SecurityMode
     */
    public function setSecurityMode(SecurityMode $mode): self
    {
        $this->securityMode = $mode;

        return $this;
    }

    /**
     * Set username/password credentials for session authentication.
     *
     * @param string $username The username.
     * @param string $password The password.
     * @return self
     */
    public function setUserCredentials(string $username, string $password): self
    {
        $this->username = $username;
        $this->password = $password;

        return $this;
    }

    /**
     * Set the client application certificate and private key for channel-level security.
     *
     * @param string $certPath Path to the client certificate file (DER or PEM).
     * @param string $keyPath Path to the client private key file.
     * @param ?string $caCertPath Optional path to the CA certificate for chain validation.
     * @return self
     */
    public function setClientCertificate(string $certPath, string $keyPath, ?string $caCertPath = null): self
    {
        $this->clientCertPath = $certPath;
        $this->clientKeyPath = $keyPath;
        $this->caCertPath = $caCertPath;

        return $this;
    }

    /**
     * Set the user certificate and private key for X509 identity token authentication.
     *
     * @param string $certPath Path to the user certificate file.
     * @param string $keyPath Path to the user private key file.
     * @return self
     */
    public function setUserCertificate(string $certPath, string $keyPath): self
    {
        $this->userCertPath = $certPath;
        $this->userKeyPath = $keyPath;

        return $this;
    }

    /**
     * Unwrap a raw transport response, handling ERR messages and secure channel decryption.
     *
     * @param string $response The raw response bytes from the transport layer.
     * @return string The decoded response body.
     *
     * @throws ServiceException If the server returned an ERR message.
     */
    private function unwrapResponse(string $response): string
    {
        if (str_starts_with($response, 'ERR')) {
            $decoder = $this->createDecoder($response);
            MessageHeader::decode($decoder);
            $errorCode = $decoder->readUInt32();
            $reason = $decoder->readString() ?? 'Unknown error';
            throw new ServiceException(sprintf("Server error 0x%08X: %s", $errorCode, $reason), $errorCode);
        }

        if ($this->secureChannel !== null && $this->secureChannel->isSecurityActive()) {
            return $this->secureChannel->processMessage($response);
        }

        return substr($response, MessageHeader::HEADER_SIZE + 4);
    }

    /**
     * Create a BinaryDecoder for the given data buffer.
     *
     * @param string $data The binary data to decode.
     * @return BinaryDecoder
     */
    private function createDecoder(string $data): BinaryDecoder
    {
        return new BinaryDecoder($data, $this->extensionObjectRepository);
    }

    /**
     * Generate and return the next sequential request ID.
     *
     * @return int
     */
    private function nextRequestId(): int
    {
        return $this->requestId++;
    }

    /**
     * Resolve a NodeId parameter that may be passed as a string.
     *
     * @param NodeId|string $nodeId The node identifier as a NodeId object or OPC UA string format (e.g. 'i=2259', 'ns=2;s=MyNode').
     * @return NodeId
     *
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     */
    private function resolveNodeIdParam(NodeId|string $nodeId): NodeId
    {
        return is_string($nodeId) ? NodeId::parse($nodeId) : $nodeId;
    }
}
