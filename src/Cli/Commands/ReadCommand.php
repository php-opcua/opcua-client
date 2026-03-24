<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Cli\Commands;

use Gianfriaur\OpcuaPhpClient\Cli\Output\OutputInterface;
use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;
use Gianfriaur\OpcuaPhpClient\Types\AttributeId;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

/**
 * Reads a node value or attribute and displays the result.
 */
class ReadCommand implements CommandInterface
{
    private const ATTRIBUTE_MAP = [
        'NodeId' => AttributeId::NodeId,
        'NodeClass' => AttributeId::NodeClass,
        'BrowseName' => AttributeId::BrowseName,
        'DisplayName' => AttributeId::DisplayName,
        'Description' => AttributeId::Description,
        'Value' => AttributeId::Value,
        'DataType' => AttributeId::DataType,
        'AccessLevel' => AttributeId::AccessLevel,
    ];

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'read';
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return 'Read a node value or attribute';
    }

    /**
     * {@inheritDoc}
     */
    public function getUsage(): string
    {
        return 'read <endpoint> <nodeId> [--attribute=Value]';
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
            $output->error('Usage: opcua-cli read <endpoint> <nodeId> [--attribute=Value]');

            return 1;
        }

        $nodeIdStr = $arguments[1];
        $attributeName = (string) ($options['attribute'] ?? 'Value');
        $attributeId = self::ATTRIBUTE_MAP[$attributeName] ?? AttributeId::Value;

        $dataValue = $client->read($nodeIdStr, $attributeId);
        $value = $dataValue->getValue();
        $variant = $dataValue->getVariant();

        $typeName = $variant?->type instanceof BuiltinType ? $variant->type->name : 'Unknown';

        $data = [
            'NodeId' => $nodeIdStr,
            'Attribute' => $attributeName,
            'Value' => $this->formatReadValue($value),
            'Type' => $typeName,
            'Status' => StatusCode::getName($dataValue->statusCode) . ' (' . sprintf('0x%08X', $dataValue->statusCode) . ')',
            'Source' => $dataValue->sourceTimestamp?->format('c') ?? 'N/A',
            'Server' => $dataValue->serverTimestamp?->format('c') ?? 'N/A',
        ];

        $output->data($data);

        return 0;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function formatReadValue(mixed $value): string
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

        if (is_object($value)) {
            return (string) $value;
        }

        return (string) $value;
    }
}
