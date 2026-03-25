<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Cli;

use Gianfriaur\OpcuaPhpClient\Cli\Commands\BrowseCommand;
use Gianfriaur\OpcuaPhpClient\Cli\Commands\CommandInterface;
use Gianfriaur\OpcuaPhpClient\Cli\Commands\EndpointsCommand;
use Gianfriaur\OpcuaPhpClient\Cli\Commands\GenerateNodesetCommand;
use Gianfriaur\OpcuaPhpClient\Cli\Commands\ReadCommand;
use Gianfriaur\OpcuaPhpClient\Cli\Commands\TrustCommand;
use Gianfriaur\OpcuaPhpClient\Cli\Commands\TrustListCommand;
use Gianfriaur\OpcuaPhpClient\Cli\Commands\TrustRemoveCommand;
use Gianfriaur\OpcuaPhpClient\Cli\Commands\WatchCommand;
use Gianfriaur\OpcuaPhpClient\Cli\Commands\WriteCommand;
use Gianfriaur\OpcuaPhpClient\Cli\Output\ConsoleOutput;
use Gianfriaur\OpcuaPhpClient\Cli\Output\JsonOutput;
use Gianfriaur\OpcuaPhpClient\Cli\Output\OutputInterface;
use Gianfriaur\OpcuaPhpClient\Exception\OpcUaException;
use Gianfriaur\OpcuaPhpClient\Exception\UntrustedCertificateException;

/**
 * Main CLI application. Parses argv, routes to the correct command, manages the client lifecycle.
 */
class Application
{
    private const VERSION = '4.0.0';

    /** @var array<string, CommandInterface> */
    private array $commands = [];

    public function __construct()
    {
        $this->registerCommand(new BrowseCommand());
        $this->registerCommand(new ReadCommand());
        $this->registerCommand(new WriteCommand());
        $this->registerCommand(new EndpointsCommand());
        $this->registerCommand(new WatchCommand());
        $this->registerCommand(new GenerateNodesetCommand());
        $this->registerCommand(new TrustCommand());
        $this->registerCommand(new TrustListCommand());
        $this->registerCommand(new TrustRemoveCommand());
    }

    /**
     * @param CommandInterface $command
     */
    public function registerCommand(CommandInterface $command): void
    {
        $this->commands[$command->getName()] = $command;
    }

    /**
     * @param string[] $argv
     * @return int
     */
    public function run(array $argv): int
    {
        $parser = new ArgvParser();
        $parsed = $parser->parse($argv);

        $options = $parsed['options'];
        $output = $this->createOutput($options);

        if (isset($options['version'])) {
            $output->writeln('opcua-cli ' . self::VERSION);

            return 0;
        }

        if (isset($options['help']) || $parsed['command'] === null) {
            return $this->showHelp($output, $parsed['command']);
        }

        if (isset($options['debug']) && isset($options['json'])) {
            $output->error('Error: --debug and --json cannot be used together. Use --debug-stderr or --debug-file instead.');

            return 1;
        }

        $commandName = $parsed['command'];

        if (! isset($this->commands[$commandName])) {
            $output->error("Unknown command: {$commandName}");
            $output->writeln('');

            $this->showHelp($output, null);

            return 1;
        }

        $command = $this->commands[$commandName];
        $runner = new CommandRunner();

        try {
            $client = $runner->createClient($options, $output);

            if ($command->requiresConnection()) {
                $endpointUrl = $parsed['arguments'][0] ?? null;
                if ($endpointUrl === null) {
                    $output->error('Error: endpoint URL is required.');
                    $output->writeln('Usage: opcua-cli ' . $command->getUsage());

                    return 1;
                }
                $client->connect($endpointUrl);
            }

            $exitCode = $command->execute($client, $parsed['arguments'], $options, $output);

            if ($client->isConnected()) {
                $client->disconnect();
            }

            return $exitCode;
        } catch (UntrustedCertificateException $e) {
            $output->error('Error: Server certificate not trusted.');
            $output->error('  Fingerprint: ' . $e->fingerprint);
            $output->writeln('');
            $output->writeln('To trust this certificate, run:');
            $output->writeln('  opcua-cli trust ' . ($parsed['arguments'][0] ?? '<endpoint>'));
            $output->writeln('');
            $output->writeln('To list trusted certificates:');
            $output->writeln('  opcua-cli trust:list');
            $output->writeln('');
            $output->writeln('To skip trust validation for this command:');
            $output->writeln('  opcua-cli ' . ($parsed['command'] ?? '') . ' ... --no-trust-policy');

            return 1;
        } catch (OpcUaException $e) {
            $output->error('Error: ' . $e->getMessage());

            return 1;
        }
    }

    /**
     * @param array<string, string|bool> $options
     * @return OutputInterface
     */
    private function createOutput(array $options): OutputInterface
    {
        if (isset($options['json'])) {
            return new JsonOutput();
        }

        return new ConsoleOutput();
    }

    /**
     * @param OutputInterface $output
     * @param ?string $commandName
     * @return int
     */
    private function showHelp(OutputInterface $output, ?string $commandName): int
    {
        if ($commandName !== null && isset($this->commands[$commandName])) {
            $command = $this->commands[$commandName];
            $output->writeln($command->getDescription());
            $output->writeln('');
            $output->writeln('Usage: opcua-cli ' . $command->getUsage());

            return 0;
        }

        $output->writeln('opcua-cli — OPC UA command-line tool');
        $output->writeln('');
        $output->writeln('Usage: opcua-cli <command> <endpoint> [arguments] [options]');
        $output->writeln('');
        $output->writeln('Commands:');

        foreach ($this->commands as $cmd) {
            $output->writeln('  ' . str_pad($cmd->getName(), 12) . $cmd->getDescription());
        }

        $output->writeln('');
        $output->writeln('Global options:');
        $output->writeln('  -s, --security-policy=<policy>   Security policy (None, Basic256Sha256, ...)');
        $output->writeln('  -m, --security-mode=<mode>       Security mode (None, Sign, SignAndEncrypt)');
        $output->writeln('      --cert=<path>                Client certificate path');
        $output->writeln('      --key=<path>                 Client private key path');
        $output->writeln('      --ca=<path>                  CA certificate path');
        $output->writeln('  -u, --username=<user>            Username for authentication');
        $output->writeln('  -p, --password=<pass>            Password for authentication');
        $output->writeln('  -t, --timeout=<seconds>          Connection timeout (default: 5)');
        $output->writeln('  -j, --json                       Output in JSON format');
        $output->writeln('  -d, --debug                      Enable debug logging on stdout');
        $output->writeln('      --debug-stderr               Enable debug logging on stderr');
        $output->writeln('      --debug-file=<path>          Enable debug logging to file');
        $output->writeln('  -h, --help                       Show help');
        $output->writeln('  -v, --version                    Show version');

        return 0;
    }
}
