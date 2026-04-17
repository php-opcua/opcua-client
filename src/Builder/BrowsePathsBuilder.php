<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Builder;

use PhpOpcua\Client\Module\TranslateBrowsePath\BrowsePathResult;
use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\QualifiedName;

/**
 * Fluent builder for translating browse paths to NodeIds.
 *
 * @see OpcUaClientInterface::translateBrowsePaths()
 */
class BrowsePathsBuilder
{
    /** @var array<array{startingNodeId: NodeId|string, relativePath: array<array{targetName: QualifiedName}>}> */
    private array $paths = [];

    /**
     * Creates a new BrowsePathsBuilder bound to the given client.
     *
     * @param OpcUaClientInterface $client
     */
    public function __construct(
        private readonly OpcUaClientInterface $client,
    ) {
    }

    /**
     * Sets the starting node for a new browse path.
     *
     * @param NodeId|string $startingNodeId
     * @return $this
     */
    public function from(NodeId|string $startingNodeId): self
    {
        $this->paths[] = ['startingNodeId' => $startingNodeId, 'relativePath' => []];

        return $this;
    }

    /**
     * Appends one or more path segments to the current browse path.
     *
     * @param string ...$segments
     * @return $this
     */
    public function path(string ...$segments): self
    {
        if (empty($this->paths)) {
            $this->from(NodeId::numeric(0, 84));
        }

        $idx = array_key_last($this->paths);
        foreach ($segments as $segment) {
            $this->paths[$idx]['relativePath'][] = ['targetName' => self::parseSegment($segment)];
        }

        return $this;
    }

    /**
     * Appends a single QualifiedName segment to the current browse path.
     *
     * @param QualifiedName $name
     * @return $this
     */
    public function segment(QualifiedName $name): self
    {
        if (empty($this->paths)) {
            $this->from(NodeId::numeric(0, 84));
        }

        $idx = array_key_last($this->paths);
        $this->paths[$idx]['relativePath'][] = ['targetName' => $name];

        return $this;
    }

    /**
     * Translates all configured browse paths and returns the results.
     *
     * @return BrowsePathResult[]
     */
    public function execute(): array
    {
        return $this->client->translateBrowsePaths($this->paths);
    }

    /**
     * Parses a string segment into a QualifiedName, supporting optional namespace prefix.
     *
     * @param string $segment
     * @return QualifiedName
     */
    private static function parseSegment(string $segment): QualifiedName
    {
        if (str_contains($segment, ':')) {
            $parts = explode(':', $segment, 2);
            if (ctype_digit($parts[0])) {
                return new QualifiedName((int) $parts[0], $parts[1]);
            }
        }

        return new QualifiedName(0, $segment);
    }
}
