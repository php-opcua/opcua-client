<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Cli\Output;

/**
 * JSON output formatter for machine-readable CLI output.
 */
class JsonOutput implements OutputInterface
{
    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        private $stdout = STDOUT,
        private $stderr = STDERR,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function writeln(string $message): void
    {
        fwrite($this->stdout, json_encode(['message' => $message], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /**
     * {@inheritDoc}
     */
    public function write(string $message): void
    {
        fwrite($this->stdout, $message);
    }

    /**
     * {@inheritDoc}
     */
    public function error(string $message): void
    {
        fwrite($this->stderr, json_encode(['error' => $message], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /**
     * {@inheritDoc}
     */
    public function data(array $data): void
    {
        fwrite($this->stdout, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /**
     * {@inheritDoc}
     */
    public function table(array $rows): void
    {
        fwrite($this->stdout, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /**
     * {@inheritDoc}
     */
    public function tree(array $nodes, string $prefix = ''): void
    {
        fwrite($this->stdout, json_encode($nodes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
}
