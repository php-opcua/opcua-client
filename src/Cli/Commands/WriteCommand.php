<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Cli\Commands;

use Gianfriaur\OpcuaPhpClient\Cli\Output\OutputInterface;
use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

/**
 * Writes a value to a node and displays the result.
 */
class WriteCommand implements CommandInterface
{
    private const TYPE_MAP = [
        'Boolean' => BuiltinType::Boolean,
        'SByte' => BuiltinType::SByte,
        'Byte' => BuiltinType::Byte,
        'Int16' => BuiltinType::Int16,
        'UInt16' => BuiltinType::UInt16,
        'Int32' => BuiltinType::Int32,
        'UInt32' => BuiltinType::UInt32,
        'Int64' => BuiltinType::Int64,
        'UInt64' => BuiltinType::UInt64,
        'Float' => BuiltinType::Float,
        'Double' => BuiltinType::Double,
        'String' => BuiltinType::String,
    ];

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'write';
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return 'Write a value to a node';
    }

    /**
     * {@inheritDoc}
     */
    public function getUsage(): string
    {
        return "write <endpoint> <nodeId> <value> [--type=TYPE]\n\n"
            . "  --type   OPC UA type (optional, auto-detected if omitted).\n"
            . '           Supported: ' . implode(', ', array_keys(self::TYPE_MAP));
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
        if (count($arguments) < 3) {
            $output->error('Usage: opcua-cli write <endpoint> <nodeId> <value> [--type=Int32]');

            return 1;
        }

        $nodeIdStr = $arguments[1];
        $rawValue = $arguments[2];
        $typeName = isset($options['type']) ? (string) $options['type'] : null;

        $type = null;
        if ($typeName !== null) {
            $type = self::TYPE_MAP[$typeName] ?? null;
            if ($type === null) {
                $output->error("Unknown type '{$typeName}'. Valid types: " . implode(', ', array_keys(self::TYPE_MAP)));

                return 1;
            }
        }

        $value = $this->castValue($rawValue, $type);
        $statusCode = $client->write($nodeIdStr, $value, $type);

        $resolvedType = $type;
        if ($resolvedType === null) {
            $dataValue = $client->read($nodeIdStr);
            $resolvedType = $dataValue->getVariant()?->type;
        }

        $data = [
            'NodeId' => $nodeIdStr,
            'Value' => $this->formatValue($value),
            'Type' => $resolvedType?->name ?? 'Auto-detected',
            'Status' => StatusCode::getName($statusCode) . ' (' . sprintf('0x%08X', $statusCode) . ')',
        ];

        $output->data($data);

        return StatusCode::isGood($statusCode) ? 0 : 1;
    }

    /**
     * Cast a CLI string value to the appropriate PHP type.
     *
     * @param string $raw The raw string from the command line.
     * @param ?BuiltinType $type The target type, or null if auto-detected.
     * @return mixed The cast value.
     */
    private function castValue(string $raw, ?BuiltinType $type): mixed
    {
        if ($type === null) {
            return $this->castAuto($raw);
        }

        return match ($type) {
            BuiltinType::Boolean => in_array(strtolower($raw), ['true', '1'], true),
            BuiltinType::SByte, BuiltinType::Byte,
            BuiltinType::Int16, BuiltinType::UInt16,
            BuiltinType::Int32, BuiltinType::UInt32,
            BuiltinType::Int64, BuiltinType::UInt64 => (int) $raw,
            BuiltinType::Float, BuiltinType::Double => (float) $raw,
            default => $raw,
        };
    }

    /**
     * Best-effort cast when no type is specified.
     *
     * @param string $raw The raw string value.
     * @return mixed The cast value.
     */
    private function castAuto(string $raw): mixed
    {
        if (in_array(strtolower($raw), ['true', 'false'], true)) {
            return strtolower($raw) === 'true';
        }

        if (is_numeric($raw) && ! str_contains($raw, '.')) {
            return (int) $raw;
        }

        if (is_numeric($raw)) {
            return (float) $raw;
        }

        return $raw;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function formatValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '[]';
        }

        return (string) $value;
    }
}
