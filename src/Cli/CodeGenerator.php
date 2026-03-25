<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Cli;

/**
 * Generates PHP class files from parsed NodeSet2.xml data.
 */
class CodeGenerator
{
    private const DATATYPE_TO_METHOD = [
        'i=1' => ['read' => 'readBoolean', 'write' => 'writeBoolean', 'php' => 'bool'],
        'i=2' => ['read' => 'readSByte', 'write' => 'writeSByte', 'php' => 'int'],
        'i=3' => ['read' => 'readByte', 'write' => 'writeByte', 'php' => 'int'],
        'i=4' => ['read' => 'readInt16', 'write' => 'writeInt16', 'php' => 'int'],
        'i=5' => ['read' => 'readUInt16', 'write' => 'writeUInt16', 'php' => 'int'],
        'i=6' => ['read' => 'readInt32', 'write' => 'writeInt32', 'php' => 'int'],
        'i=7' => ['read' => 'readUInt32', 'write' => 'writeUInt32', 'php' => 'int'],
        'i=8' => ['read' => 'readInt64', 'write' => 'writeInt64', 'php' => 'int'],
        'i=9' => ['read' => 'readUInt64', 'write' => 'writeUInt64', 'php' => 'int'],
        'i=10' => ['read' => 'readFloat', 'write' => 'writeFloat', 'php' => 'float'],
        'i=11' => ['read' => 'readDouble', 'write' => 'writeDouble', 'php' => 'float'],
        'i=12' => ['read' => 'readString', 'write' => 'writeString', 'php' => 'string'],
        'i=13' => ['read' => 'readDateTime', 'write' => 'writeDateTime', 'php' => '\\DateTimeImmutable'],
        'i=14' => ['read' => 'readGuid', 'write' => 'writeGuid', 'php' => 'string'],
        'i=15' => ['read' => 'readByteString', 'write' => 'writeByteString', 'php' => 'string'],
        'i=17' => ['read' => 'readNodeId', 'write' => 'writeNodeId', 'php' => 'NodeId'],
        'i=20' => ['read' => 'readQualifiedName', 'write' => 'writeQualifiedName', 'php' => 'QualifiedName'],
        'i=21' => ['read' => 'readLocalizedText', 'write' => 'writeLocalizedText', 'php' => 'LocalizedText'],
    ];

    /**
     * Generate a PHP class with NodeId string constants.
     *
     * @param string $className The class name.
     * @param array<string, array{nodeId: string, browseName: string, displayName: string, type: string}> $nodes Parsed nodes.
     * @param string $namespace The PHP namespace.
     * @return string The generated PHP code.
     */
    public function generateNodeIdClass(string $className, array $nodes, string $namespace): string
    {
        $constants = '';
        $usedNames = [];
        foreach ($nodes as $node) {
            $constName = $this->toConstantName($node['browseName']);
            if (isset($usedNames[$constName])) {
                $usedNames[$constName]++;
                $constName .= '_' . $usedNames[$constName];
            } else {
                $usedNames[$constName] = 1;
            }
            $constants .= "    public const {$constName} = '{$node['nodeId']}';\n\n";
        }

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        /**
         * NodeId constants generated from a NodeSet2.xml file.
         *
         * @generated
         */
        class {$className}
        {
        {$constants}}
        PHP;
    }

    /**
     * Generate a readonly DTO class for a structured DataType.
     *
     * @param string $className The DTO class name.
     * @param array<array{name: string, dataType: string}> $fields The structure fields.
     * @param string $namespace The PHP namespace.
     * @param array<string, string> $enumFieldMap DataType NodeId → enum class name (short) for fields that are enums.
     * @return string The generated PHP code.
     */
    public function generateDtoClass(string $className, array $fields, string $namespace, array $enumFieldMap = []): string
    {
        $properties = '';
        foreach ($fields as $field) {
            $phpType = $this->resolvePhpType($field['dataType'], $enumFieldMap);
            $isArray = ($field['valueRank'] ?? -1) >= 0;
            $isOptional = $field['isOptional'] ?? false;

            if ($isArray) {
                $phpType = 'array';
            } elseif ($isOptional && $phpType !== 'mixed') {
                $phpType = '?' . $phpType;
            }
            $properties .= "        public {$phpType} \${$field['name']},\n";
        }

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace}\\Types;

        /**
         * DTO for the {$className} structured data type.
         *
         * @generated
         */
        readonly class {$className}
        {
            public function __construct(
        {$properties}    ) {
            }
        }
        PHP;
    }

    /**
     * Generate a Codec class for a structured DataType that returns a DTO.
     *
     * @param string $codecName The codec class name.
     * @param string $dtoName The DTO class name.
     * @param array<array{name: string, dataType: string}> $fields The structure fields.
     * @param string $namespace The PHP namespace.
     * @param array<string, string> $enumFieldMap DataType NodeId → enum class name (short) for fields that are enums.
     * @return string The generated PHP code.
     */
    public function generateCodecClass(string $codecName, string $dtoName, array $fields, string $namespace, array $enumFieldMap = []): string
    {
        $decodeArgs = '';
        $encodeLines = '';

        foreach ($fields as $field) {
            $mapping = self::DATATYPE_TO_METHOD[$field['dataType']] ?? null;
            $enumClass = $enumFieldMap[$field['dataType']] ?? null;
            $isArray = ($field['valueRank'] ?? -1) >= 0;
            $fieldName = $field['name'];

            if ($isArray) {
                $readExpr = $mapping !== null ? "\$decoder->{$mapping['read']}()" : '$decoder->readExtensionObject()';
                $writeExpr = $mapping !== null ? "\$encoder->{$mapping['write']}(\$item)" : '$encoder->writeExtensionObject($item)';
                $decodeArgs .= "            \$this->readArray(\$decoder, fn () => {$readExpr}),\n";
                $encodeLines .= "        \$this->writeArray(\$encoder, \$value->{$fieldName}, fn (\$item) => {$writeExpr});\n";
            } elseif ($enumClass !== null) {
                $decodeArgs .= "            Enums\\{$enumClass}::from(\$decoder->readInt32()),\n";
                $encodeLines .= "        \$encoder->writeInt32(\$value->{$fieldName}->value);\n";
            } elseif ($mapping === null) {
                $decodeArgs .= "            \$decoder->readExtensionObject(),\n";
                $encodeLines .= "        \$encoder->writeExtensionObject(\$value->{$fieldName});\n";
            } else {
                $decodeArgs .= "            \$decoder->{$mapping['read']}(),\n";
                $encodeLines .= "        \$encoder->{$mapping['write']}(\$value->{$fieldName});\n";
            }
        }

        $hasArrayFields = false;
        foreach ($fields as $f) {
            if (($f['valueRank'] ?? -1) >= 0) {
                $hasArrayFields = true;
                break;
            }
        }

        $arrayHelpers = '';
        if ($hasArrayFields) {
            $arrayHelpers = <<<'HELPERS'

                private function readArray(BinaryDecoder $decoder, callable $readItem): array
                {
                    $count = $decoder->readInt32();
                    $items = [];
                    for ($i = 0; $i < $count; $i++) {
                        $items[] = $readItem();
                    }

                    return $items;
                }

                private function writeArray(BinaryEncoder $encoder, array $items, callable $writeItem): void
                {
                    $encoder->writeInt32(count($items));
                    foreach ($items as $item) {
                        $writeItem($item);
                    }
                }

            HELPERS;
        }

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace}\\Codecs;

        use Gianfriaur\\OpcuaPhpClient\\Encoding\\BinaryDecoder;
        use Gianfriaur\\OpcuaPhpClient\\Encoding\\BinaryEncoder;
        use Gianfriaur\\OpcuaPhpClient\\Encoding\\ExtensionObjectCodec;
        use {$namespace}\\Types\\{$dtoName};

        /**
         * Codec for the {$dtoName} structured data type.
         *
         * @generated
         */
        class {$codecName} implements ExtensionObjectCodec
        {
            /**
             * @param BinaryDecoder \$decoder
             * @return {$dtoName}
             */
            public function decode(BinaryDecoder \$decoder): {$dtoName}
            {
                return new {$dtoName}(
        {$decodeArgs}        );
            }

            /**
             * @param BinaryEncoder \$encoder
             * @param mixed \$value
             * @return void
             */
            public function encode(BinaryEncoder \$encoder, mixed \$value): void
            {
        {$encodeLines}    }
        {$arrayHelpers}}
        PHP;
    }

    /**
     * Generate a Registrar class that implements GeneratedTypeRegistrar.
     *
     * @param string $className The registrar class name.
     * @param array<array{encodingId: string, codecClass: string, constName: ?string}> $codecs Codec registrations.
     * @param array<string, array{enumClass: string, constName: ?string}> $enumMappings NodeId → enum info.
     * @param string $nodeIdClassName The NodeIds class name for constant references.
     * @param string $namespace The PHP namespace.
     * @param array<string> $dependencyClasses Fully qualified class names of dependency registrars.
     * @return string The generated PHP code.
     */
    public function generateRegistrarClass(string $className, array $codecs, array $enumMappings, string $nodeIdClassName, string $namespace, array $dependencyClasses = []): string
    {
        $codecRegistrations = '';
        foreach ($codecs as $codec) {
            $nodeIdRef = $codec['constName'] !== null
                ? "{$nodeIdClassName}::{$codec['constName']}"
                : "'{$codec['encodingId']}'";
            $codecRegistrations .= "        \$repository->register(\\Gianfriaur\\OpcuaPhpClient\\Types\\NodeId::parse({$nodeIdRef}), new Codecs\\{$codec['codecClass']}());\n";
        }

        $enumEntries = '';
        foreach ($enumMappings as $nodeId => $info) {
            $nodeIdRef = $info['constName'] !== null
                ? "{$nodeIdClassName}::{$info['constName']}"
                : "'{$nodeId}'";
            $enumEntries .= "            {$nodeIdRef} => Enums\\{$info['enumClass']}::class,\n";
        }

        $depEntries = '';
        foreach ($dependencyClasses as $depClass) {
            $depEntries .= "            new \\{$depClass}(),\n";
        }

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Gianfriaur\\OpcuaPhpClient\\Repository\\ExtensionObjectRepository;
        use Gianfriaur\\OpcuaPhpClient\\Repository\\GeneratedTypeRegistrar;

        /**
         * Registers all generated codecs and enum mappings.
         *
         * @generated
         */
        class {$className} implements GeneratedTypeRegistrar
        {
            /**
             * @param bool \$only If true, skip loading dependency registrars.
             */
            public function __construct(public bool \$only = false)
            {
            }

            /**
             * @param ExtensionObjectRepository \$repository
             * @return void
             */
            public function registerCodecs(ExtensionObjectRepository \$repository): void
            {
        {$codecRegistrations}    }

            /**
             * @return array<string, class-string<\\BackedEnum>>
             */
            public function getEnumMappings(): array
            {
                return [
        {$enumEntries}        ];
            }

            /**
             * @return GeneratedTypeRegistrar[]
             */
            public function dependencyRegistrars(): array
            {
                return [
        {$depEntries}        ];
            }
        }
        PHP;
    }

    /**
     * Generate a PHP enum class from an OPC UA enumeration definition.
     *
     * @param string $enumName The enum name.
     * @param array<array{name: string, value: int}> $values The enum values.
     * @param string $namespace The PHP namespace.
     * @return string The generated PHP code.
     */
    public function generateEnumClass(string $enumName, array $values, string $namespace): string
    {
        $cases = '';
        foreach ($values as $v) {
            $caseName = $this->toConstantName($v['name']);
            $cases .= "    case {$caseName} = {$v['value']};\n";
        }

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace}\\Enums;

        /**
         * OPC UA enumeration type: {$enumName}.
         *
         * @generated
         */
        enum {$enumName}: int
        {
        {$cases}}
        PHP;
    }

    /**
     * @param string $dataType
     * @param array<string, string> $enumFieldMap
     * @return string
     */
    private function resolvePhpType(string $dataType, array $enumFieldMap): string
    {
        if (isset($enumFieldMap[$dataType])) {
            return 'Enums\\' . $enumFieldMap[$dataType];
        }

        $mapping = self::DATATYPE_TO_METHOD[$dataType] ?? null;
        if ($mapping !== null) {
            return $mapping['php'];
        }

        return 'mixed';
    }

    /**
     * @param string $name
     * @return string
     */
    private function toConstantName(string $name): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '_', $name) ?? $name;
        if (is_numeric($name[0] ?? '')) {
            $name = '_' . $name;
        }

        return $name;
    }
}
