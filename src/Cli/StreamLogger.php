<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Cli;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Minimal PSR-3 logger that writes to a stream (stdout, stderr, or file).
 */
class StreamLogger extends AbstractLogger
{
    /**
     * @param resource $stream
     */
    public function __construct(private $stream)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function log($level, Stringable|string $message, array $context = []): void
    {
        $timestamp = date('H:i:s.') . substr((string) microtime(true), -3);
        $interpolated = $this->interpolate((string) $message, $context);
        fwrite($this->stream, "[{$timestamp}] [{$level}] {$interpolated}" . PHP_EOL);
    }

    /**
     * @param string $message
     * @param array<string, mixed> $context
     * @return string
     */
    private function interpolate(string $message, array $context): string
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }
}
