<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\ServerInfo;

use DateTimeImmutable;
use PhpOpcua\Client\Module\ReadWrite\ReadWriteModule;
use PhpOpcua\Client\Module\ServiceModule;
use PhpOpcua\Client\Types\AttributeId;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Wire\WireTypeRegistry;

/**
 * Provides quick-access methods for standard OPC UA Server BuildInfo nodes (ns=0).
 *
 * These nodes are mandatory on every OPC UA server and expose build metadata
 * under the Server object's ServerStatus/BuildInfo structure.
 *
 * @see https://reference.opcfoundation.org/Core/Part5/v105/docs/12.4
 */
class ServerInfoModule extends ServiceModule
{
    /**
     * @return array<class-string<ServiceModule>>
     */
    public function requires(): array
    {
        return [ReadWriteModule::class];
    }

    public function register(): void
    {
        $this->client->registerMethod('getServerProductName', $this->getServerProductName(...));
        $this->client->registerMethod('getServerManufacturerName', $this->getServerManufacturerName(...));
        $this->client->registerMethod('getServerSoftwareVersion', $this->getServerSoftwareVersion(...));
        $this->client->registerMethod('getServerBuildNumber', $this->getServerBuildNumber(...));
        $this->client->registerMethod('getServerBuildDate', $this->getServerBuildDate(...));
        $this->client->registerMethod('getServerBuildInfo', $this->getServerBuildInfo(...));
    }

    public function registerWireTypes(WireTypeRegistry $registry): void
    {
        $registry->register(BuildInfo::class);
    }

    /**
     * Read the server's product name (ns=0;i=2262).
     *
     * @return ?string The product name, or null if the server returned no value.
     *
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     */
    public function getServerProductName(): ?string
    {
        $value = $this->client->read(NodeId::numeric(0, 2262), AttributeId::Value)->getValue();

        return is_string($value) ? $value : null;
    }

    /**
     * Read the server's manufacturer name (ns=0;i=2263).
     *
     * @return ?string The manufacturer name, or null if the server returned no value.
     *
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     */
    public function getServerManufacturerName(): ?string
    {
        $value = $this->client->read(NodeId::numeric(0, 2263), AttributeId::Value)->getValue();

        return is_string($value) ? $value : null;
    }

    /**
     * Read the server's software version string (ns=0;i=2264).
     *
     * @return ?string The software version, or null if the server returned no value.
     *
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     */
    public function getServerSoftwareVersion(): ?string
    {
        $value = $this->client->read(NodeId::numeric(0, 2264), AttributeId::Value)->getValue();

        return is_string($value) ? $value : null;
    }

    /**
     * Read the server's build number (ns=0;i=2265).
     *
     * @return ?string The build number, or null if the server returned no value.
     *
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     */
    public function getServerBuildNumber(): ?string
    {
        $value = $this->client->read(NodeId::numeric(0, 2265), AttributeId::Value)->getValue();

        return is_string($value) ? $value : null;
    }

    /**
     * Read the server's build date (ns=0;i=2266).
     *
     * @return ?DateTimeImmutable The build date, or null if the server returned no value.
     *
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     */
    public function getServerBuildDate(): ?DateTimeImmutable
    {
        $value = $this->client->read(NodeId::numeric(0, 2266), AttributeId::Value)->getValue();

        return $value instanceof DateTimeImmutable ? $value : null;
    }

    /**
     * Read all BuildInfo fields in a single readMulti request.
     *
     * @return BuildInfo
     *
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     */
    public function getServerBuildInfo(): BuildInfo
    {
        $results = $this->client->readMulti([
            ['nodeId' => NodeId::numeric(0, 2262)],
            ['nodeId' => NodeId::numeric(0, 2263)],
            ['nodeId' => NodeId::numeric(0, 2264)],
            ['nodeId' => NodeId::numeric(0, 2265)],
            ['nodeId' => NodeId::numeric(0, 2266)],
        ]);

        $productName = $results[0]->getValue();
        $manufacturerName = $results[1]->getValue();
        $softwareVersion = $results[2]->getValue();
        $buildNumber = $results[3]->getValue();
        $buildDate = $results[4]->getValue();

        return new BuildInfo(
            productName: is_string($productName) ? $productName : null,
            manufacturerName: is_string($manufacturerName) ? $manufacturerName : null,
            softwareVersion: is_string($softwareVersion) ? $softwareVersion : null,
            buildNumber: is_string($buildNumber) ? $buildNumber : null,
            buildDate: $buildDate instanceof DateTimeImmutable ? $buildDate : null,
        );
    }
}
