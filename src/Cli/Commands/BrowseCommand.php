<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Cli\Commands;

use Gianfriaur\OpcuaPhpClient\Cli\Output\OutputInterface;
use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;
use Gianfriaur\OpcuaPhpClient\Types\BrowseNode;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\ReferenceDescription;

/**
 * Browses the OPC UA address space and displays the node tree.
 */
class BrowseCommand implements CommandInterface
{
    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'browse';
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return 'Browse the server address space';
    }

    /**
     * {@inheritDoc}
     */
    public function getUsage(): string
    {
        return 'browse <endpoint> [path|nodeId] [--recursive] [--depth=N]';
    }

    /**
     * {@inheritDoc}
     */
    public function requiresConnection(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function execute(OpcUaClientInterface $client, array $arguments, array $options, OutputInterface $output): int
    {
        if (empty($arguments)) {
            $output->error('Usage: opcua-cli browse <endpoint> [path|nodeId]');

            return 1;
        }

        $target = $arguments[1] ?? 'i=85';
        $recursive = isset($options['recursive']) && $options['recursive'] === true;
        $depth = isset($options['depth']) ? (int) $options['depth'] : 3;

        $nodeId = $this->resolveTarget($client, $target);

        if ($recursive) {
            return $this->browseRecursive($client, $nodeId, $depth, $output);
        }

        return $this->browseFlat($client, $nodeId, $output);
    }

    /**
     * @param OpcUaClientInterface $client
     * @param string $target
     * @return NodeId
     */
    private function resolveTarget(OpcUaClientInterface $client, string $target): NodeId
    {
        if (str_starts_with($target, '/')) {
            return $client->resolveNodeId($target);
        }

        return NodeId::parse($target);
    }

    /**
     * @param OpcUaClientInterface $client
     * @param NodeId $nodeId
     * @param OutputInterface $output
     * @return int
     */
    private function browseFlat(OpcUaClientInterface $client, NodeId $nodeId, OutputInterface $output): int
    {
        $refs = $client->browseAll($nodeId);

        if (empty($refs)) {
            $output->writeln('No children found.');

            return 0;
        }

        $nodes = array_map(fn (ReferenceDescription $ref) => [
            'name' => (string) $ref->displayName,
            'nodeId' => $ref->nodeId->__toString(),
            'class' => $ref->nodeClass->name,
        ], $refs);

        $output->tree($nodes);

        return 0;
    }

    /**
     * @param OpcUaClientInterface $client
     * @param NodeId $nodeId
     * @param int $depth
     * @param OutputInterface $output
     * @return int
     */
    private function browseRecursive(OpcUaClientInterface $client, NodeId $nodeId, int $depth, OutputInterface $output): int
    {
        $tree = $client->browseRecursive($nodeId, maxDepth: $depth);

        if (empty($tree)) {
            $output->writeln('No children found.');

            return 0;
        }

        $nodes = $this->browseNodesToArray($tree);
        $output->tree($nodes);

        return 0;
    }

    /**
     * @param BrowseNode[] $browseNodes
     * @return array<array{name: string, nodeId: string, class: string, children?: array}>
     */
    private function browseNodesToArray(array $browseNodes): array
    {
        $result = [];
        foreach ($browseNodes as $node) {
            $entry = [
                'name' => (string) $node->reference->displayName,
                'nodeId' => $node->reference->nodeId->__toString(),
                'class' => $node->reference->nodeClass->name,
            ];

            if ($node->hasChildren()) {
                $entry['children'] = $this->browseNodesToArray($node->getChildren());
            }

            $result[] = $entry;
        }

        return $result;
    }
}
