<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\ServerInfo;

use DateTimeImmutable;
use PhpOpcua\Client\Wire\WireSerializable;

/**
 * Represents the OPC UA Server BuildInfo structure (ns=0;i=2260).
 *
 * Contains metadata about the server software: product name, manufacturer,
 * version, build number, and build date. These fields are mandatory on
 * every OPC UA server.
 *
 * @see https://reference.opcfoundation.org/Core/Part5/v105/docs/12.4
 */
readonly class BuildInfo implements WireSerializable
{
    /**
     * @param ?string $productName The name of the server product (ns=0;i=2262).
     * @param ?string $manufacturerName The name of the server manufacturer (ns=0;i=2263).
     * @param ?string $softwareVersion The software version of the server (ns=0;i=2264).
     * @param ?string $buildNumber The build number of the server (ns=0;i=2265).
     * @param ?DateTimeImmutable $buildDate The date the server was built (ns=0;i=2266).
     */
    public function __construct(
        public ?string $productName,
        public ?string $manufacturerName,
        public ?string $softwareVersion,
        public ?string $buildNumber,
        public ?DateTimeImmutable $buildDate,
    ) {
    }

    /**
     * @return array{product: ?string, manufacturer: ?string, version: ?string, build: ?string, date: ?DateTimeImmutable}
     */
    public function jsonSerialize(): array
    {
        return [
            'product' => $this->productName,
            'manufacturer' => $this->manufacturerName,
            'version' => $this->softwareVersion,
            'build' => $this->buildNumber,
            'date' => $this->buildDate,
        ];
    }

    /**
     * @param array{product?: ?string, manufacturer?: ?string, version?: ?string, build?: ?string, date?: ?DateTimeImmutable} $data
     * @return static
     */
    public static function fromWireArray(array $data): static
    {
        return new self(
            $data['product'] ?? null,
            $data['manufacturer'] ?? null,
            $data['version'] ?? null,
            $data['build'] ?? null,
            $data['date'] ?? null,
        );
    }

    /**
     * @return string
     */
    public static function wireTypeId(): string
    {
        return 'BuildInfo';
    }
}
