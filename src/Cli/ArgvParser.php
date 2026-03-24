<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Cli;

/**
 * Zero-dependency argv parser for the OPC UA CLI tool.
 *
 * Parses command-line arguments into a structured result with command name,
 * positional arguments, and named options (long/short).
 */
class ArgvParser
{
    private const SHORT_ALIASES = [
        's' => 'security-policy',
        'm' => 'security-mode',
        'u' => 'username',
        'p' => 'password',
        't' => 'timeout',
        'j' => 'json',
        'd' => 'debug',
        'h' => 'help',
        'v' => 'version',
    ];

    /**
     * @param string[] $argv
     * @return array{command: ?string, arguments: string[], options: array<string, string|bool>}
     */
    public function parse(array $argv): array
    {
        array_shift($argv);

        $command = null;
        $arguments = [];
        $options = [];

        $i = 0;
        while ($i < count($argv)) {
            $arg = $argv[$i];

            if (str_starts_with($arg, '--')) {
                $this->parseLongOption($arg, $argv, $i, $options);
            } elseif (str_starts_with($arg, '-') && strlen($arg) > 1) {
                $this->parseShortOption($arg, $argv, $i, $options);
            } elseif ($command === null) {
                $command = $arg;
            } else {
                $arguments[] = $arg;
            }

            $i++;
        }

        return [
            'command' => $command,
            'arguments' => $arguments,
            'options' => $options,
        ];
    }

    /**
     * @param string $arg
     * @param string[] $argv
     * @param int $i
     * @param array<string, string|bool> $options
     */
    private function parseLongOption(string $arg, array $argv, int &$i, array &$options): void
    {
        $option = substr($arg, 2);

        if (str_contains($option, '=')) {
            [$key, $value] = explode('=', $option, 2);
            $options[$key] = $value;

            return;
        }

        if (isset($argv[$i + 1]) && ! str_starts_with($argv[$i + 1], '-')) {
            $next = $argv[$i + 1];
            if ($this->isValueOption($option)) {
                $options[$option] = $next;
                $i++;

                return;
            }
        }

        $options[$option] = true;
    }

    /**
     * @param string $arg
     * @param string[] $argv
     * @param int $i
     * @param array<string, string|bool> $options
     */
    private function parseShortOption(string $arg, array $argv, int &$i, array &$options): void
    {
        $short = substr($arg, 1);
        $long = self::SHORT_ALIASES[$short] ?? $short;

        if (isset($argv[$i + 1]) && ! str_starts_with($argv[$i + 1], '-')) {
            if ($this->isValueOption($long)) {
                $options[$long] = $argv[$i + 1];
                $i++;

                return;
            }
        }

        $options[$long] = true;
    }

    /**
     * @param string $option
     * @return bool
     */
    private function isValueOption(string $option): bool
    {
        return in_array($option, [
            'security-policy',
            'security-mode',
            'cert',
            'key',
            'ca',
            'username',
            'password',
            'timeout',
            'attribute',
            'depth',
            'interval',
            'debug-file',
        ], true);
    }
}
