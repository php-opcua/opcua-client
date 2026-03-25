<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Cli;

use SimpleXMLElement;

/**
 * Parses OPC UA NodeSet2.xml files and extracts nodes, structured DataTypes, and encoding references.
 */
class NodeSetParser
{
    private const NS = 'http://opcfoundation.org/UA/2011/03/UANodeSet.xsd';

    private const BUILTIN_TYPE_IDS = [
        'Boolean' => 1, 'SByte' => 2, 'Byte' => 3,
        'Int16' => 4, 'UInt16' => 5, 'Int32' => 6, 'UInt32' => 7,
        'Int64' => 8, 'UInt64' => 9, 'Float' => 10, 'Double' => 11,
        'String' => 12, 'DateTime' => 13, 'Guid' => 14, 'ByteString' => 15,
        'NodeId' => 17, 'QualifiedName' => 20, 'LocalizedText' => 21,
    ];

    /** @var array<string, string> */
    private array $aliases = [];

    /** @var array<string, array{nodeId: string, browseName: string, displayName: string, type: string}> */
    private array $nodes = [];

    /** @var array<string, array{nodeId: string, name: string, encodingId: ?string, fields: array<array{name: string, dataType: string}>}> */
    private array $dataTypes = [];

    /** @var array<string, array{nodeId: string, name: string, values: array<array{name: string, value: int}>}> */
    private array $enumerations = [];

    /** @var array<array{modelUri: string, version: ?string}> */
    private array $requiredModels = [];

    /**
     * Parse a NodeSet2.xml file.
     *
     * @param string $filePath Absolute path to the XML file.
     * @return self
     */
    public function parse(string $filePath): self
    {
        $xml = simplexml_load_file($filePath);
        if ($xml === false) {
            throw new \RuntimeException("Failed to parse XML file: {$filePath}");
        }

        $xml->registerXPathNamespace('ua', self::NS);

        $this->parseAliases($xml);
        $this->parseNodes($xml);
        $this->parseDataTypes($xml);
        $this->parseRequiredModels($xml);

        return $this;
    }

    /**
     * @return array<string, array{nodeId: string, browseName: string, displayName: string, type: string}>
     */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    /**
     * @return array<string, array{nodeId: string, name: string, encodingId: ?string, fields: array<array{name: string, dataType: string}>}>
     */
    public function getDataTypes(): array
    {
        return $this->dataTypes;
    }

    /**
     * @return array<string, array{nodeId: string, name: string, values: array<array{name: string, value: int}>}>
     */
    public function getEnumerations(): array
    {
        return $this->enumerations;
    }

    /**
     * @return array<array{modelUri: string, version: ?string}>
     */
    public function getRequiredModels(): array
    {
        return $this->requiredModels;
    }

    /**
     * @return array<string, string>
     */
    public function getAliases(): array
    {
        return $this->aliases;
    }

    /**
     * @param SimpleXMLElement $xml
     * @return void
     */
    private function parseAliases(SimpleXMLElement $xml): void
    {
        foreach ($xml->xpath('//ua:Aliases/ua:Alias') ?: [] as $alias) {
            $name = (string) $alias['Alias'];
            $nodeId = (string) $alias;
            $this->aliases[$name] = $nodeId;
        }
    }

    /**
     * @param SimpleXMLElement $xml
     * @return void
     */
    private function parseNodes(SimpleXMLElement $xml): void
    {
        $nodeTypes = ['UAObject', 'UAVariable', 'UAMethod', 'UAObjectType', 'UAVariableType', 'UAReferenceType'];

        foreach ($nodeTypes as $nodeType) {
            foreach ($xml->xpath("//ua:{$nodeType}") ?: [] as $node) {
                $nodeId = (string) $node['NodeId'];
                $browseName = (string) $node['BrowseName'];
                $displayName = isset($node->DisplayName) ? (string) $node->DisplayName : $browseName;

                $cleanName = $this->cleanBrowseName($browseName);
                if ($cleanName === 'Default Binary') {
                    continue;
                }

                $this->nodes[$nodeId] = [
                    'nodeId' => $nodeId,
                    'browseName' => $cleanName,
                    'displayName' => $displayName,
                    'type' => $nodeType,
                ];
            }
        }
    }

    /**
     * @param SimpleXMLElement $xml
     * @return void
     */
    private function parseDataTypes(SimpleXMLElement $xml): void
    {
        foreach ($xml->xpath('//ua:UADataType') ?: [] as $dt) {
            $nodeId = (string) $dt['NodeId'];
            $browseName = $this->cleanBrowseName((string) $dt['BrowseName']);
            $displayName = isset($dt->DisplayName) ? (string) $dt->DisplayName : $browseName;

            $definition = $dt->Definition ?? null;
            if ($definition === null) {
                $this->nodes[$nodeId] = [
                    'nodeId' => $nodeId,
                    'browseName' => $browseName,
                    'displayName' => $displayName,
                    'type' => 'UADataType',
                ];

                continue;
            }

            if ($this->isEnumeration($definition)) {
                $this->enumerations[$nodeId] = [
                    'nodeId' => $nodeId,
                    'name' => $this->cleanBrowseName((string) ($definition['Name'] ?? $displayName)),
                    'values' => $this->parseEnumValues($definition),
                ];
            } else {
                $encodingId = $this->findEncodingId($dt);
                $fields = $this->parseFields($definition);

                $this->dataTypes[$nodeId] = [
                    'nodeId' => $nodeId,
                    'name' => $displayName,
                    'encodingId' => $encodingId,
                    'fields' => $fields,
                ];
            }

            $this->nodes[$nodeId] = [
                'nodeId' => $nodeId,
                'browseName' => $browseName,
                'displayName' => $displayName,
                'type' => 'UADataType',
            ];
        }
    }

    /**
     * @param SimpleXMLElement $definition
     * @return array<array{name: string, dataType: string, valueRank: int, isOptional: bool}>
     */
    private function parseFields(SimpleXMLElement $definition): array
    {
        $fields = [];
        foreach ($definition->Field ?? [] as $field) {
            $dataTypeRef = (string) $field['DataType'];
            $resolvedType = $this->resolveDataType($dataTypeRef);

            $rawName = (string) $field['Name'];
            $safeName = preg_replace('/[^a-zA-Z0-9_]/', '_', $rawName) ?? $rawName;

            $fields[] = [
                'name' => $safeName,
                'dataType' => $resolvedType,
                'valueRank' => (int) ($field['ValueRank'] ?? -1),
                'isOptional' => ((string) ($field['IsOptional'] ?? 'false')) === 'true',
            ];
        }

        return $fields;
    }

    /**
     * @param SimpleXMLElement $dt
     * @return ?string
     */
    private function findEncodingId(SimpleXMLElement $dt): ?string
    {
        foreach ($dt->References->Reference ?? [] as $ref) {
            $refType = (string) $ref['ReferenceType'];
            $isForward = ((string) $ref['IsForward']) !== 'false';

            $resolvedRefType = $this->aliases[$refType] ?? $refType;
            if ($resolvedRefType === 'i=38' && $isForward) {
                return (string) $ref;
            }
        }

        return null;
    }

    /**
     * @param string $dataTypeRef
     * @return string
     */
    private function resolveDataType(string $dataTypeRef): string
    {
        if (isset($this->aliases[$dataTypeRef])) {
            return $this->aliases[$dataTypeRef];
        }

        if (isset(self::BUILTIN_TYPE_IDS[$dataTypeRef])) {
            return 'i=' . self::BUILTIN_TYPE_IDS[$dataTypeRef];
        }

        return $dataTypeRef;
    }

    /**
     * @param string $browseName
     * @return string
     */
    private function cleanBrowseName(string $browseName): string
    {
        if (str_contains($browseName, ':')) {
            return substr($browseName, strpos($browseName, ':') + 1);
        }

        return $browseName;
    }

    /**
     * @param SimpleXMLElement $xml
     * @return void
     */
    private function parseRequiredModels(SimpleXMLElement $xml): void
    {
        foreach ($xml->xpath('//ua:Models/ua:Model/ua:RequiredModel') ?: [] as $req) {
            $this->requiredModels[] = [
                'modelUri' => (string) $req['ModelUri'],
                'version' => ((string) ($req['Version'] ?? '')) ?: null,
            ];
        }
    }

    /**
     * @param SimpleXMLElement $definition
     * @return bool
     */
    private function isEnumeration(SimpleXMLElement $definition): bool
    {
        foreach ($definition->Field ?? [] as $field) {
            if (isset($field['Value'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param SimpleXMLElement $definition
     * @return array<array{name: string, value: int}>
     */
    private function parseEnumValues(SimpleXMLElement $definition): array
    {
        $values = [];
        foreach ($definition->Field ?? [] as $field) {
            $values[] = [
                'name' => (string) $field['Name'],
                'value' => (int) ($field['Value'] ?? 0),
            ];
        }

        return $values;
    }
}
