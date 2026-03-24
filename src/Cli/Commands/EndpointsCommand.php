<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Cli\Commands;

use Gianfriaur\OpcuaPhpClient\Cli\Output\OutputInterface;
use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;

/**
 * Discovers and displays available server endpoints.
 */
class EndpointsCommand implements CommandInterface
{
    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'endpoints';
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return 'Discover available server endpoints and security policies';
    }

    /**
     * {@inheritDoc}
     */
    public function getUsage(): string
    {
        return 'endpoints <endpoint>';
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
            $output->error('Usage: opcua-cli endpoints <endpoint>');

            return 1;
        }

        $endpointUrl = $arguments[0];
        $endpoints = $client->getEndpoints($endpointUrl);

        if (empty($endpoints)) {
            $output->writeln('No endpoints found.');

            return 0;
        }

        $rows = [];
        foreach ($endpoints as $ep) {
            $authTypes = [];
            foreach ($ep->userIdentityTokens as $token) {
                $authTypes[] = match ($token->tokenType) {
                    0 => 'Anonymous',
                    1 => 'UserName',
                    2 => 'Certificate',
                    default => 'Unknown(' . $token->tokenType . ')',
                };
            }

            $modeName = match ($ep->securityMode) {
                1 => 'None',
                2 => 'Sign',
                3 => 'SignAndEncrypt',
                default => 'Unknown',
            };

            $policyName = basename(str_replace('#', '/', $ep->securityPolicyUri));

            $rows[] = [
                'Endpoint' => $ep->endpointUrl,
                'Security' => $policyName . ' (mode: ' . $modeName . ')',
                'Auth' => implode(', ', $authTypes),
            ];
        }

        $output->table($rows);

        return 0;
    }
}
