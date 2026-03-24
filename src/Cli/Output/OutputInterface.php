<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Cli\Output;

/**
 * Contract for CLI output formatters.
 */
interface OutputInterface
{
    /**
     * @param string $message
     * @return void
     */
    public function writeln(string $message): void;

    /**
     * @param string $message
     * @return void
     */
    public function write(string $message): void;

    /**
     * @param string $message
     * @return void
     */
    public function error(string $message): void;

    /**
     * @param array<string, mixed> $data
     * @return void
     */
    public function data(array $data): void;

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return void
     */
    public function table(array $rows): void;

    /**
     * @param array<array{name: string, nodeId: string, class: string, children?: array}> $nodes
     * @param string $prefix
     * @return void
     */
    public function tree(array $nodes, string $prefix = ''): void;
}
