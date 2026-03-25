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
        'i=13' => ['read' => 'readDateTime', 'write' => 'writeDateTime', 'php' => 'DateTimeImmutable'],
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
     * Generate a Codec class for a structured DataType.
     *
     * @param string $className The codec class name.
     * @param array<array{name: string, dataType: string}> $fields The structure fields.
     * @param string $namespace The PHP namespace.
     * @return string The generated PHP code.
     */
    public function generateCodecClass(string $className, array $fields, string $namespace): string
    {
        $decodeLines = '';
        $encodeLines = '';

        foreach ($fields as $field) {
            $mapping = self::DATATYPE_TO_METHOD[$field['dataType']] ?? null;
            if ($mapping === null) {
                $decodeLines .= "        \$result['{$field['name']}'] = \$decoder->readExtensionObject();\n";
                $encodeLines .= "        \$encoder->writeExtensionObject(\$value['{$field['name']}']);\n";

                continue;
            }

            $decodeLines .= "        \$result['{$field['name']}'] = \$decoder->{$mapping['read']}();\n";
            $encodeLines .= "        \$encoder->{$mapping['write']}(\$value['{$field['name']}']);\n";
        }

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace}\\Codecs;

        use Gianfriaur\\OpcuaPhpClient\\Encoding\\BinaryDecoder;
        use Gianfriaur\\OpcuaPhpClient\\Encoding\\BinaryEncoder;
        use Gianfriaur\\OpcuaPhpClient\\Encoding\\ExtensionObjectCodec;

        /**
         * Codec for the {$className} structured data type.
         *
         * @generated
         */
        class {$className} implements ExtensionObjectCodec
        {
            /**
             * @param BinaryDecoder \$decoder
             * @return array
             */
            public function decode(BinaryDecoder \$decoder): array
            {
                \$result = [];
        {$decodeLines}
                return \$result;
            }

            /**
             * @param BinaryEncoder \$encoder
             * @param mixed \$value
             * @return void
             */
            public function encode(BinaryEncoder \$encoder, mixed \$value): void
            {
        {$encodeLines}    }
        }
        PHP;
    }

    /**
     * Generate a Registrar class that registers all codecs.
     *
     * @param string $className The registrar class name.
     * @param array<array{encodingId: string, codecClass: string}> $codecs Codec registrations.
     * @param string $namespace The PHP namespace.
     * @return string The generated PHP code.
     */
    public function generateRegistrarClass(string $className, array $codecs, string $namespace): string
    {
        $registrations = '';
        foreach ($codecs as $codec) {
            $registrations .= "        \$repository->register(\\Gianfriaur\\OpcuaPhpClient\\Types\\NodeId::parse('{$codec['encodingId']}'), new Codecs\\{$codec['codecClass']}());\n";
        }

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Gianfriaur\\OpcuaPhpClient\\Repository\\ExtensionObjectRepository;

        /**
         * Registers all generated codecs with an ExtensionObjectRepository.
         *
         * @generated
         */
        class {$className}
        {
            /**
             * @param ExtensionObjectRepository \$repository
             * @return void
             */
            public static function register(ExtensionObjectRepository \$repository): void
            {
        {$registrations}    }
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
