<?php

declare(strict_types=1);

namespace PhpOpcua\Client;

use PhpOpcua\Client\ClientBuilder\ManagesAutoRetryTrait;
use PhpOpcua\Client\ClientBuilder\ManagesBatchingTrait;
use PhpOpcua\Client\ClientBuilder\ManagesBrowseDepthTrait;
use PhpOpcua\Client\ClientBuilder\ManagesCacheTrait;
use PhpOpcua\Client\ClientBuilder\ManagesEventDispatcherTrait;
use PhpOpcua\Client\ClientBuilder\ManagesReadWriteConfigTrait;
use PhpOpcua\Client\ClientBuilder\ManagesTimeoutTrait;
use PhpOpcua\Client\ClientBuilder\ManagesTrustStoreTrait;
use PhpOpcua\Client\Event\NullEventDispatcher;
use PhpOpcua\Client\Module\Browse\BrowseModule;
use PhpOpcua\Client\Module\History\HistoryModule;
use PhpOpcua\Client\Module\ModuleRegistry;
use PhpOpcua\Client\Module\ReadWrite\ReadWriteModule;
use PhpOpcua\Client\Module\ServerInfo\ServerInfoModule;
use PhpOpcua\Client\Module\ServiceModule;
use PhpOpcua\Client\Module\Subscription\SubscriptionModule;
use PhpOpcua\Client\Module\TranslateBrowsePath\TranslateBrowsePathModule;
use PhpOpcua\Client\Module\TypeDiscovery\TypeDiscoveryModule;
use PhpOpcua\Client\Repository\ExtensionObjectRepository;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * OPC UA client builder. Configures security, credentials, cache, and other options before connecting.
 *
 * All configuration methods return `self` for fluent chaining. Call {@see connect()} to
 * establish the connection and obtain a {@see Client} instance with operation methods.
 *
 * The builder is reusable: calling `connect()` multiple times creates independent
 * connected clients sharing the same configuration snapshot.
 *
 * @implements ClientBuilderInterface
 *
 * @see ClientBuilderInterface
 * @see Client
 */
class ClientBuilder implements ClientBuilderInterface
{
    use ManagesTimeoutTrait;
    use ManagesAutoRetryTrait;
    use ManagesBatchingTrait;
    use ManagesCacheTrait;
    use ManagesBrowseDepthTrait;
    use ManagesEventDispatcherTrait;
    use ManagesTrustStoreTrait;
    use ManagesReadWriteConfigTrait;

    private SecurityPolicy $securityPolicy = SecurityPolicy::None;

    private SecurityMode $securityMode = SecurityMode::None;

    private ?string $username = null;

    private ?string $password = null;

    private ?string $clientCertPath = null;

    private ?string $clientKeyPath = null;

    private ?string $caCertPath = null;

    private ?string $userCertPath = null;

    private ?string $userKeyPath = null;

    private ExtensionObjectRepository $extensionObjectRepository;

    private LoggerInterface $logger;

    private ModuleRegistry $moduleRegistry;

    /**
     * Create a new client builder instance.
     *
     * @param ?ExtensionObjectRepository $extensionObjectRepository Optional custom repository for extension object decoding.
     * @param ?LoggerInterface $logger Optional PSR-3 logger for connection events, retries, and errors.
     * @return static
     */
    public static function create(?ExtensionObjectRepository $extensionObjectRepository = null, ?LoggerInterface $logger = null): static
    {
        return new static($extensionObjectRepository, $logger);
    }

    /**
     * @param ?ExtensionObjectRepository $extensionObjectRepository Optional custom repository for extension object decoding.
     * @param ?LoggerInterface $logger Optional PSR-3 logger for connection events, retries, and errors.
     */
    public function __construct(?ExtensionObjectRepository $extensionObjectRepository = null, ?LoggerInterface $logger = null)
    {
        $this->extensionObjectRepository = $extensionObjectRepository ?? new ExtensionObjectRepository();
        $this->logger = $logger ?? new NullLogger();
        $this->eventDispatcher = new NullEventDispatcher();
        $this->moduleRegistry = $this->createDefaultModuleRegistry();
    }

    /**
     * Add a custom service module to the client.
     *
     * @param ServiceModule $module The module instance to add.
     * @return static
     */
    public function addModule(ServiceModule $module): static
    {
        $this->moduleRegistry->add($module);

        return $this;
    }

    /**
     * Replace a built-in module with a custom implementation.
     *
     * @param class-string<ServiceModule> $moduleClass The class name of the module to replace.
     * @param ServiceModule $replacement The replacement module instance.
     * @return static
     */
    public function replaceModule(string $moduleClass, ServiceModule $replacement): static
    {
        $this->moduleRegistry->replace($moduleClass, $replacement);

        return $this;
    }

    /**
     * Create the default module registry with all built-in modules.
     *
     * @return ModuleRegistry
     */
    private function createDefaultModuleRegistry(): ModuleRegistry
    {
        $registry = new ModuleRegistry();

        foreach ($this->defaultModules() as $moduleClass) {
            $registry->add(new $moduleClass());
        }

        return $registry;
    }

    /**
     * Return the list of default built-in module classes.
     *
     * `NodeManagementModule` is intentionally disabled — opt in with
     * `ClientBuilder::addModule(new NodeManagementModule())`. See ROADMAP.md.
     *
     * @return array<class-string<ServiceModule>>
     */
    private function defaultModules(): array
    {
        return [
            ReadWriteModule::class,
            BrowseModule::class,
            SubscriptionModule::class,
            HistoryModule::class,
            TranslateBrowsePathModule::class,
            ServerInfoModule::class,
            TypeDiscoveryModule::class,
        ];
    }

    /**
     * Set the PSR-3 logger for connection events, retries, and errors.
     *
     * @param LoggerInterface $logger
     * @return self
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Get the configured logger.
     *
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
     * Connect to an OPC UA server endpoint.
     *
     * Creates a new {@see Client} instance with a snapshot of the current configuration,
     * establishes the TCP connection, handshake, secure channel, and session.
     *
     * @param string $endpointUrl The OPC UA endpoint URL (e.g. "opc.tcp://host:4840").
     * @return Client
     *
     * @throws Exception\ConfigurationException If the endpoint URL is invalid.
     * @throws Exception\ConnectionException If the TCP connection or handshake fails.
     * @throws Exception\ServiceException If a protocol-level error occurs during session creation.
     */
    public function connect(string $endpointUrl): Client
    {
        $this->ensureCacheInitialized();

        return new Client(
            endpointUrl: $endpointUrl,
            securityPolicy: $this->securityPolicy,
            securityMode: $this->securityMode,
            clientCertPath: $this->clientCertPath,
            clientKeyPath: $this->clientKeyPath,
            caCertPath: $this->caCertPath,
            username: $this->username,
            password: $this->password,
            userCertPath: $this->userCertPath,
            userKeyPath: $this->userKeyPath,
            logger: $this->logger,
            eventDispatcher: $this->eventDispatcher,
            trustStore: $this->trustStore,
            trustPolicy: $this->trustPolicy,
            autoAcceptEnabled: $this->autoAcceptEnabled,
            autoAcceptForce: $this->autoAcceptForce,
            cache: $this->cache,
            cacheInitialized: $this->cacheInitialized,
            cacheCodec: $this->getCacheCodec(),
            timeout: $this->timeout,
            autoRetry: $this->autoRetry,
            batchSize: $this->batchSize,
            defaultBrowseMaxDepth: $this->defaultBrowseMaxDepth,
            autoDetectWriteType: $this->autoDetectWriteType,
            readMetadataCache: $this->readMetadataCache,
            extensionObjectRepository: $this->extensionObjectRepository,
            enumMappings: $this->enumMappings,
            moduleRegistry: $this->moduleRegistry,
        );
    }
}
