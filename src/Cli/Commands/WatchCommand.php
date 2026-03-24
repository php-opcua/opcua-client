<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Cli\Commands;

use Gianfriaur\OpcuaPhpClient\Cli\Output\OutputInterface;
use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

/**
 * Watches a node value in real time via subscription or polling.
 */
class WatchCommand implements CommandInterface
{
    private ?int $maxIterations = null;

    /**
     * @param ?int $maxIterations
     * @return self
     */
    public function setMaxIterations(?int $maxIterations): self
    {
        $this->maxIterations = $maxIterations;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'watch';
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return 'Watch a node value in real time (subscription or polling)';
    }

    /**
     * {@inheritDoc}
     */
    public function getUsage(): string
    {
        return 'watch <endpoint> <nodeId> [--interval=N]';
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
        if (count($arguments) < 2) {
            $output->error('Usage: opcua-cli watch <endpoint> <nodeId> [--interval=N]');

            return 1;
        }

        $nodeIdStr = $arguments[1];
        $interval = isset($options['interval']) ? (int) $options['interval'] : null;

        if ($interval !== null) {
            return $this->watchPolling($client, $nodeIdStr, $interval, $output);
        }

        return $this->watchSubscription($client, $nodeIdStr, $output);
    }

    /**
     * @param OpcUaClientInterface $client
     * @param string $nodeIdStr
     * @param int $intervalMs
     * @param OutputInterface $output
     * @return int
     */
    private function watchPolling(OpcUaClientInterface $client, string $nodeIdStr, int $intervalMs, OutputInterface $output): int
    {
        $iteration = 0;
        while ($this->maxIterations === null || $iteration < $this->maxIterations) {
            $dataValue = $client->read($nodeIdStr);
            $timestamp = date('H:i:s.') . substr((string) microtime(true), -3);
            $value = $dataValue->getValue();

            $output->writeln("[{$timestamp}] " . $this->formatValue($value));

            $iteration++;
            if ($this->maxIterations !== null) {
                continue;
            }
            usleep($intervalMs * 1000);
        }

        return 0;
    }

    /**
     * @param OpcUaClientInterface $client
     * @param string $nodeIdStr
     * @param OutputInterface $output
     * @return int
     */
    private function watchSubscription(OpcUaClientInterface $client, string $nodeIdStr, OutputInterface $output): int
    {
        $sub = $client->createSubscription(publishingInterval: 250.0);
        $nodeId = NodeId::parse($nodeIdStr);

        $client->createMonitoredItems($sub->subscriptionId, [
            ['nodeId' => $nodeId, 'clientHandle' => 1],
        ]);

        $lastAck = [];
        $iteration = 0;

        while ($this->maxIterations === null || $iteration < $this->maxIterations) {
            $response = $client->publish($lastAck);

            foreach ($response->notifications as $notif) {
                if ($notif['type'] === 'DataChange') {
                    $timestamp = date('H:i:s.') . substr((string) microtime(true), -3);
                    $value = $notif['dataValue']->getValue();
                    $output->writeln("[{$timestamp}] " . $this->formatValue($value));
                }
            }

            $lastAck = [[
                'subscriptionId' => $response->subscriptionId,
                'sequenceNumber' => $response->sequenceNumber,
            ]];
            $iteration++;
        }

        return 0;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '[]';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        return (string) $value;
    }
}
