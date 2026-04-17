<?php

declare(strict_types=1);

namespace PhpOpcua\Client;

use PhpOpcua\Client\Module\ServiceModule;
use PhpOpcua\Client\Repository\ExtensionObjectRepository;
use PhpOpcua\Client\Repository\GeneratedTypeRegistrar;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\TrustStore\TrustPolicy;
use PhpOpcua\Client\TrustStore\TrustStoreInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Contract for building and configuring an OPC UA client before connecting.
 *
 * All configuration methods return `self` for fluent chaining. Call {@see connect()} to
 * establish the connection and obtain a {@see Client} instance with operation methods.
 *
 * The builder is reusable: calling `connect()` multiple times creates independent
 * connected clients sharing the same configuration snapshot.
 *
 * @see Client
 * @see ClientBuilder
 */
interface ClientBuilderInterface
{
    /**
     * Create a new client builder instance.
     *
     * @param ?ExtensionObjectRepository $extensionObjectRepository Optional custom repository for extension object decoding.
     * @param ?LoggerInterface $logger Optional PSR-3 logger for connection events, retries, and errors.
     * @return static
     */
    public static function create(?ExtensionObjectRepository $extensionObjectRepository = null, ?LoggerInterface $logger = null): static;

    /**
     * Set the PSR-3 logger for connection events, retries, and errors.
     *
     * @param LoggerInterface $logger
     * @return self
     */
    public function setLogger(LoggerInterface $logger): self;

    /**
     * Get the configured logger.
     *
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface;

    /**
     * Set the PSR-14 event dispatcher for client lifecycle and operation events.
     *
     * @param EventDispatcherInterface $eventDispatcher The event dispatcher to use.
     * @return self
     */
    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): self;

    /**
     * Get the current PSR-14 event dispatcher.
     *
     * @return EventDispatcherInterface
     */
    public function getEventDispatcher(): EventDispatcherInterface;

    /**
     * Set the security policy for the connection.
     *
     * @param SecurityPolicy $policy The security policy to use.
     * @return self
     */
    public function setSecurityPolicy(SecurityPolicy $policy): self;

    /**
     * Set the message security mode for the connection.
     *
     * @param SecurityMode $mode The security mode to use.
     * @return self
     */
    public function setSecurityMode(SecurityMode $mode): self;

    /**
     * Set the client application certificate and private key for channel-level security.
     *
     * @param string $certPath Path to the client certificate file (DER or PEM).
     * @param string $keyPath Path to the client private key file.
     * @param ?string $caCertPath Optional path to the CA certificate for chain validation.
     * @return self
     */
    public function setClientCertificate(string $certPath, string $keyPath, ?string $caCertPath = null): self;

    /**
     * Set the user certificate and private key for X509 identity token authentication.
     *
     * @param string $certPath Path to the user certificate file.
     * @param string $keyPath Path to the user private key file.
     * @return self
     */
    public function setUserCertificate(string $certPath, string $keyPath): self;

    /**
     * Set username/password credentials for session authentication.
     *
     * @param string $username The username.
     * @param string $password The password.
     * @return self
     */
    public function setUserCredentials(string $username, string $password): self;

    /**
     * Set the trust store for server certificate validation.
     *
     * @param ?TrustStoreInterface $trustStore
     * @return self
     */
    public function setTrustStore(?TrustStoreInterface $trustStore): self;

    /**
     * Get the current trust store, or null if none configured.
     *
     * @return ?TrustStoreInterface
     */
    public function getTrustStore(): ?TrustStoreInterface;

    /**
     * Set the trust validation policy. Pass null to disable trust validation (accept all certificates).
     *
     * @param ?TrustPolicy $policy
     * @return self
     */
    public function setTrustPolicy(?TrustPolicy $policy): self;

    /**
     * Get the current trust policy. Null means validation is disabled.
     *
     * @return ?TrustPolicy
     */
    public function getTrustPolicy(): ?TrustPolicy;

    /**
     * Enable or disable auto-accept (TOFU) for unknown server certificates.
     *
     * @param bool $enabled
     * @param bool $force
     * @return self
     */
    public function autoAccept(bool $enabled = true, bool $force = false): self;

    /**
     * Set the cache driver. Pass null to disable caching entirely.
     *
     * @param ?CacheInterface $cache A PSR-16 cache instance, or null to disable.
     * @return self
     */
    public function setCache(?CacheInterface $cache): self;

    /**
     * Get the current cache driver, or null if caching is disabled.
     *
     * @return ?CacheInterface
     */
    public function getCache(): ?CacheInterface;

    /**
     * Set the network timeout for transport operations.
     *
     * @param float $timeout Timeout in seconds.
     * @return self
     */
    public function setTimeout(float $timeout): self;

    /**
     * Get the current network timeout.
     *
     * @return float Timeout in seconds.
     */
    public function getTimeout(): float;

    /**
     * Set the maximum number of automatic reconnection retries on connection loss.
     *
     * @param int $maxRetries Maximum retry count (0 to disable).
     * @return self
     */
    public function setAutoRetry(int $maxRetries): self;

    /**
     * Get the current automatic retry count.
     *
     * @return int
     */
    public function getAutoRetry(): int;

    /**
     * Set the batch size for multi-read and multi-write operations.
     *
     * @param int $batchSize Maximum items per batch (0 to disable batching).
     * @return self
     */
    public function setBatchSize(int $batchSize): self;

    /**
     * Get the configured batch size, or null if not explicitly set.
     *
     * @return int|null
     */
    public function getBatchSize(): ?int;

    /**
     * Set the default maximum depth for recursive browse operations.
     *
     * @param int $maxDepth Maximum depth (-1 for unlimited up to internal cap).
     * @return self
     */
    public function setDefaultBrowseMaxDepth(int $maxDepth): self;

    /**
     * Get the default maximum depth for recursive browse operations.
     *
     * @return int
     */
    public function getDefaultBrowseMaxDepth(): int;

    /**
     * Enable or disable automatic write type detection.
     *
     * @param bool $enabled Whether to enable auto-detection.
     * @return self
     */
    public function setAutoDetectWriteType(bool $enabled): self;

    /**
     * Enable or disable caching for metadata read operations.
     *
     * @param bool $enabled Whether to enable metadata caching.
     * @return self
     */
    public function setReadMetadataCache(bool $enabled): self;

    /**
     * Load generated types from a NodeSet2.xml code generator registrar.
     *
     * @param GeneratedTypeRegistrar $registrar The generated registrar.
     * @return self
     */
    public function loadGeneratedTypes(GeneratedTypeRegistrar $registrar): self;

    /**
     * Return the extension object repository used for custom type decoding.
     *
     * @return ExtensionObjectRepository
     */
    public function getExtensionObjectRepository(): ExtensionObjectRepository;

    /**
     * Add a custom service module to the client.
     *
     * @param ServiceModule $module The module instance to add.
     * @return static
     */
    public function addModule(ServiceModule $module): static;

    /**
     * Replace a built-in module with a custom implementation.
     *
     * @param class-string<ServiceModule> $moduleClass The class name of the module to replace.
     * @param ServiceModule $replacement The replacement module instance.
     * @return static
     */
    public function replaceModule(string $moduleClass, ServiceModule $replacement): static;

    /**
     * Connect to an OPC UA server endpoint.
     *
     * Performs TCP connection, handshake, secure channel setup, and session creation.
     * Returns a {@see Client} instance ready for operations.
     *
     * @param string $endpointUrl The OPC UA endpoint URL (e.g. "opc.tcp://host:4840").
     * @return Client
     *
     * @throws Exception\ConfigurationException If the endpoint URL is invalid.
     * @throws Exception\ConnectionException If the TCP connection or handshake fails.
     * @throws Exception\ServiceException If a protocol-level error occurs during session creation.
     */
    public function connect(string $endpointUrl): Client;
}
