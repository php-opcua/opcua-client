<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Cli;

use Gianfriaur\OpcuaPhpClient\Cli\Output\OutputInterface;
use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Psr\Log\LoggerInterface;

/**
 * Configures and manages the OPC UA Client lifecycle for CLI commands.
 */
class CommandRunner
{
    /**
     * @param array<string, string|bool> $options
     * @param OutputInterface $output
     * @return Client
     */
    public function createClient(array $options, OutputInterface $output): Client
    {
        $logger = $this->createLogger($options, $output);
        $client = new Client(logger: $logger);

        if (isset($options['timeout'])) {
            $client->setTimeout((float) $options['timeout']);
        }

        $this->configureSecurity($client, $options);
        $this->configureAuthentication($client, $options);

        return $client;
    }

    /**
     * @param Client $client
     * @param array<string, string|bool> $options
     */
    private function configureSecurity(Client $client, array $options): void
    {
        if (isset($options['security-policy'])) {
            $policy = SecurityPolicy::tryFrom('http://opcfoundation.org/UA/SecurityPolicy#' . $options['security-policy']);
            if ($policy === null) {
                $policy = SecurityPolicy::from((string) $options['security-policy']);
            }
            $client->setSecurityPolicy($policy);
        }

        if (isset($options['security-mode'])) {
            $mode = match ((string) $options['security-mode']) {
                'None', '1' => SecurityMode::None,
                'Sign', '2' => SecurityMode::Sign,
                'SignAndEncrypt', '3' => SecurityMode::SignAndEncrypt,
                default => SecurityMode::None,
            };
            $client->setSecurityMode($mode);
        }

        $certPath = $options['cert'] ?? null;
        $keyPath = $options['key'] ?? null;
        $caPath = $options['ca'] ?? null;

        if (is_string($certPath) && is_string($keyPath)) {
            $client->setClientCertificate($certPath, $keyPath, is_string($caPath) ? $caPath : null);
        }
    }

    /**
     * @param Client $client
     * @param array<string, string|bool> $options
     */
    private function configureAuthentication(Client $client, array $options): void
    {
        $username = $options['username'] ?? null;
        $password = $options['password'] ?? null;

        if (is_string($username) && is_string($password)) {
            $client->setUserCredentials($username, $password);
        }
    }

    /**
     * @param array<string, string|bool> $options
     * @param OutputInterface $output
     * @return LoggerInterface
     */
    private function createLogger(array $options, OutputInterface $output): LoggerInterface
    {
        if (isset($options['debug-file']) && is_string($options['debug-file'])) {
            return new StreamLogger(fopen($options['debug-file'], 'a'));
        }

        if (isset($options['debug-stderr']) && $options['debug-stderr'] === true) {
            return new StreamLogger(STDERR);
        }

        if (isset($options['debug']) && $options['debug'] === true) {
            return new StreamLogger(STDOUT);
        }

        return new \Psr\Log\NullLogger();
    }
}
