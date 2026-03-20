<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient;

use Gianfriaur\OpcuaPhpClient\Client\ManagesAutoRetryTrait;
use Gianfriaur\OpcuaPhpClient\Client\ManagesBatchingTrait;
use Gianfriaur\OpcuaPhpClient\Client\ManagesBrowseDepthTrait;
use Gianfriaur\OpcuaPhpClient\Client\ManagesBrowseTrait;
use Gianfriaur\OpcuaPhpClient\Client\ManagesConnectionTrait;
use Gianfriaur\OpcuaPhpClient\Client\ManagesHandshakeTrait;
use Gianfriaur\OpcuaPhpClient\Client\ManagesHistoryTrait;
use Gianfriaur\OpcuaPhpClient\Client\ManagesReadWriteTrait;
use Gianfriaur\OpcuaPhpClient\Client\ManagesSecureChannelTrait;
use Gianfriaur\OpcuaPhpClient\Client\ManagesSessionTrait;
use Gianfriaur\OpcuaPhpClient\Client\ManagesSubscriptionsTrait;
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

class Client implements OpcUaClientInterface
{
    use ManagesTimeoutTrait;
    use ManagesAutoRetryTrait;
    use ManagesBatchingTrait;
    use ManagesBrowseDepthTrait;
    use ManagesConnectionTrait;
    use ManagesHandshakeTrait;
    use ManagesSecureChannelTrait;
    use ManagesSessionTrait;
    use ManagesBrowseTrait;
    use ManagesReadWriteTrait;
    use ManagesSubscriptionsTrait;
    use ManagesHistoryTrait;
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

    public function __construct(?ExtensionObjectRepository $extensionObjectRepository = null)
    {
        $this->transport = new TcpTransport();
        $this->extensionObjectRepository = $extensionObjectRepository ?? new ExtensionObjectRepository();
        $this->initTimeout();
    }

    public function getExtensionObjectRepository(): ExtensionObjectRepository
    {
        return $this->extensionObjectRepository;
    }

    /**
     * @param SecurityPolicy $policy
     * @return Client
     */
    public function setSecurityPolicy(SecurityPolicy $policy): self
    {
        $this->securityPolicy = $policy;

        return $this;
    }

    /**
     * @param SecurityMode $mode
     * @return Client
     */
    public function setSecurityMode(SecurityMode $mode): self
    {
        $this->securityMode = $mode;

        return $this;
    }

    /**
     * @param string $username
     * @param string $password
     * @return Client
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
     * @return Client
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
     * @return Client
     */
    public function setUserCertificate(string $certPath, string $keyPath): self
    {
        $this->userCertPath = $certPath;
        $this->userKeyPath = $keyPath;

        return $this;
    }

    /**
     * @param string $response
     * @return string
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

    private function createDecoder(string $data): BinaryDecoder
    {
        return new BinaryDecoder($data, $this->extensionObjectRepository);
    }

    private function nextRequestId(): int
    {
        return $this->requestId++;
    }
}
