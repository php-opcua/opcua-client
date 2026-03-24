<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Cli\Output;

/**
 * Human-readable console output with ANSI colors and tree rendering.
 */
class ConsoleOutput implements OutputInterface
{
    private bool $colorEnabled;

    /**
     * @param resource $stdout
     * @param resource $stderr
     * @param ?bool $forceColor
     */
    public function __construct(
        private $stdout = STDOUT,
        private $stderr = STDERR,
        ?bool $forceColor = null,
    ) {
        $this->colorEnabled = $forceColor ?? $this->detectColor();
    }

    /**
     * {@inheritDoc}
     */
    public function writeln(string $message): void
    {
        fwrite($this->stdout, $message . PHP_EOL);
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
        fwrite($this->stderr, $this->color($message, '31') . PHP_EOL);
    }

    /**
     * {@inheritDoc}
     */
    public function data(array $data): void
    {
        $maxKeyLen = 0;
        foreach ($data as $key => $value) {
            $maxKeyLen = max($maxKeyLen, strlen($key));
        }

        foreach ($data as $key => $value) {
            $paddedKey = str_pad($key . ':', $maxKeyLen + 1);
            $this->writeln($this->color($paddedKey, '36') . ' ' . $this->formatValue($value));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function table(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $this->writeln('');
            foreach ($row as $key => $value) {
                $this->writeln($this->color($key . ':', '36') . ' ' . $this->formatValue($value));
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function tree(array $nodes, string $prefix = ''): void
    {
        $count = count($nodes);
        foreach ($nodes as $i => $node) {
            $isLast = ($i === $count - 1);
            $connector = $isLast ? '└── ' : '├── ';
            $childPrefix = $isLast ? '    ' : '│   ';

            $line = $prefix . $connector
                . $this->color($node['name'], '37')
                . ' ' . $this->color('(' . $node['nodeId'] . ')', '90')
                . ' ' . $this->color('[' . $node['class'] . ']', '33');

            $this->writeln($line);

            if (! empty($node['children'])) {
                $this->tree($node['children'], $prefix . $childPrefix);
            }
        }
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return $this->color('null', '90');
        }

        if (is_bool($value)) {
            return $value ? $this->color('true', '32') : $this->color('false', '31');
        }

        if (is_array($value)) {
            return implode(', ', $value);
        }

        return (string) $value;
    }

    /**
     * @param string $text
     * @param string $code
     * @return string
     */
    private function color(string $text, string $code): string
    {
        if (! $this->colorEnabled) {
            return $text;
        }

        return "\033[{$code}m{$text}\033[0m";
    }

    /**
     * @return bool
     */
    private function detectColor(): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }

        if (function_exists('posix_isatty') && is_resource($this->stdout)) {
            return posix_isatty($this->stdout);
        }

        return getenv('TERM') !== false && getenv('TERM') !== 'dumb';
    }
}
