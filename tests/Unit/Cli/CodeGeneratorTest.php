<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Cli\CodeGenerator;

describe('CodeGenerator', function () {

    it('generates NodeId constants class', function () {
        $gen = new CodeGenerator();
        $nodes = [
            'ns=1;i=1000' => ['nodeId' => 'ns=1;i=1000', 'browseName' => 'TestFolder', 'displayName' => 'TestFolder', 'type' => 'UAObject'],
            'ns=1;i=2001' => ['nodeId' => 'ns=1;i=2001', 'browseName' => 'Temperature', 'displayName' => 'Temperature', 'type' => 'UAVariable'],
        ];

        $code = $gen->generateNodeIdClass('TestNodeIds', $nodes, 'App\\OpcUa');

        expect($code)->toContain('namespace App\\OpcUa;');
        expect($code)->toContain('class TestNodeIds');
        expect($code)->toContain("public const TestFolder = 'ns=1;i=1000';");
        expect($code)->toContain("public const Temperature = 'ns=1;i=2001';");
        expect($code)->toContain('declare(strict_types=1);');
    });

    it('generates DTO class with typed properties', function () {
        $gen = new CodeGenerator();
        $fields = [
            ['name' => 'X', 'dataType' => 'i=11'],
            ['name' => 'Name', 'dataType' => 'i=12'],
            ['name' => 'Active', 'dataType' => 'i=1'],
        ];

        $code = $gen->generateDtoClass('TestPoint', $fields, 'App\\OpcUa');

        expect($code)->toContain('namespace App\\OpcUa\\Types;');
        expect($code)->toContain('readonly class TestPoint');
        expect($code)->toContain('public float $X');
        expect($code)->toContain('public string $Name');
        expect($code)->toContain('public bool $Active');
    });

    it('generates DTO with enum field types', function () {
        $gen = new CodeGenerator();
        $fields = [
            ['name' => 'Value', 'dataType' => 'i=11'],
            ['name' => 'Status', 'dataType' => 'ns=1;i=100'],
        ];
        $enumMap = ['ns=1;i=100' => 'StatusEnum'];

        $code = $gen->generateDtoClass('Snapshot', $fields, 'App', $enumMap);

        expect($code)->toContain('public float $Value');
        expect($code)->toContain('public Enums\\StatusEnum $Status');
    });

    it('generates Codec class that returns DTO', function () {
        $gen = new CodeGenerator();
        $fields = [
            ['name' => 'X', 'dataType' => 'i=11'],
            ['name' => 'Y', 'dataType' => 'i=11'],
        ];

        $code = $gen->generateCodecClass('TestPointCodec', 'TestPoint', $fields, 'App\\OpcUa');

        expect($code)->toContain('namespace App\\OpcUa\\Codecs;');
        expect($code)->toContain('class TestPointCodec implements ExtensionObjectCodec');
        expect($code)->toContain('return new TestPoint(');
        expect($code)->toContain('$decoder->readDouble()');
        expect($code)->toContain('$encoder->writeDouble($value->X)');
    });

    it('generates Codec with enum field casting', function () {
        $gen = new CodeGenerator();
        $fields = [
            ['name' => 'Value', 'dataType' => 'i=10'],
            ['name' => 'Status', 'dataType' => 'ns=1;i=100'],
        ];
        $enumMap = ['ns=1;i=100' => 'StatusEnum'];

        $code = $gen->generateCodecClass('TestCodec', 'TestDto', $fields, 'App', $enumMap);

        expect($code)->toContain('Enums\\StatusEnum::from($decoder->readInt32())');
        expect($code)->toContain('$encoder->writeInt32($value->Status->value)');
    });

    it('generates Registrar implementing GeneratedTypeRegistrar with constants', function () {
        $gen = new CodeGenerator();
        $codecs = [
            ['encodingId' => 'ns=1;i=5001', 'codecClass' => 'TestPointCodec', 'constName' => 'TestPoint'],
        ];
        $enumMappings = [
            'ns=1;i=100' => ['enumClass' => 'StatusEnum', 'constName' => 'StatusNode'],
        ];

        $code = $gen->generateRegistrarClass('TestRegistrar', $codecs, $enumMappings, 'TestNodeIds', 'App\\OpcUa');

        expect($code)->toContain('class TestRegistrar implements GeneratedTypeRegistrar');
        expect($code)->toContain('NodeId::parse(TestNodeIds::TestPoint)');
        expect($code)->toContain('new Codecs\\TestPointCodec()');
        expect($code)->toContain('TestNodeIds::StatusNode => Enums\\StatusEnum::class');
    });

    it('generates Registrar with string fallback when no constant', function () {
        $gen = new CodeGenerator();
        $codecs = [
            ['encodingId' => 'ns=1;i=5001', 'codecClass' => 'TestCodec', 'constName' => null],
        ];
        $enumMappings = [
            'ns=1;i=100' => ['enumClass' => 'MyEnum', 'constName' => null],
        ];

        $code = $gen->generateRegistrarClass('TestRegistrar', $codecs, $enumMappings, 'NodeIds', 'App');

        expect($code)->toContain("NodeId::parse('ns=1;i=5001')");
        expect($code)->toContain("'ns=1;i=100' => Enums\\MyEnum::class");
    });

    it('generates Registrar with dependency registrars', function () {
        $gen = new CodeGenerator();
        $deps = [
            'Gianfriaur\\OpcuaNodeset\\DI\\DIRegistrar',
            'Gianfriaur\\OpcuaNodeset\\Machinery\\MachineryRegistrar',
        ];

        $code = $gen->generateRegistrarClass('TestRegistrar', [], [], 'NodeIds', 'App', $deps);

        expect($code)->toContain('function dependencyRegistrars()');
        expect($code)->toContain('new \\Gianfriaur\\OpcuaNodeset\\DI\\DIRegistrar()');
        expect($code)->toContain('new \\Gianfriaur\\OpcuaNodeset\\Machinery\\MachineryRegistrar()');
        expect($code)->toContain('public bool $only = false');
    });

    it('handles unknown data types with readExtensionObject', function () {
        $gen = new CodeGenerator();
        $fields = [
            ['name' => 'Nested', 'dataType' => 'ns=2;i=9999'],
        ];

        $code = $gen->generateCodecClass('NestedCodec', 'NestedDto', $fields, 'App');

        expect($code)->toContain('readExtensionObject()');
        expect($code)->toContain('writeExtensionObject');
    });

    it('generates PHP enum class', function () {
        $gen = new CodeGenerator();
        $values = [
            ['name' => 'IDLE', 'value' => 0],
            ['name' => 'RUNNING', 'value' => 1],
            ['name' => 'ERROR', 'value' => 2],
        ];

        $code = $gen->generateEnumClass('TestStatus', $values, 'App\\OpcUa');

        expect($code)->toContain('namespace App\\OpcUa\\Enums;');
        expect($code)->toContain('enum TestStatus: int');
        expect($code)->toContain('case IDLE = 0;');
        expect($code)->toContain('case RUNNING = 1;');
        expect($code)->toContain('case ERROR = 2;');
    });

    it('generates DTO with array field', function () {
        $gen = new CodeGenerator();
        $fields = [
            ['name' => 'Name', 'dataType' => 'i=12', 'valueRank' => -1, 'isOptional' => false],
            ['name' => 'Values', 'dataType' => 'i=11', 'valueRank' => 1, 'isOptional' => false],
        ];

        $code = $gen->generateDtoClass('DataSet', $fields, 'App');

        expect($code)->toContain('public string $Name');
        expect($code)->toContain('public array $Values');
    });

    it('generates DTO with optional field', function () {
        $gen = new CodeGenerator();
        $fields = [
            ['name' => 'Name', 'dataType' => 'i=12', 'valueRank' => -1, 'isOptional' => false],
            ['name' => 'Desc', 'dataType' => 'i=12', 'valueRank' => -1, 'isOptional' => true],
        ];

        $code = $gen->generateDtoClass('Item', $fields, 'App');

        expect($code)->toContain('public string $Name');
        expect($code)->toContain('public ?string $Desc');
    });

    it('generates Codec with array read/write helpers', function () {
        $gen = new CodeGenerator();
        $fields = [
            ['name' => 'Items', 'dataType' => 'i=6', 'valueRank' => 1, 'isOptional' => false],
        ];

        $code = $gen->generateCodecClass('TestCodec', 'TestDto', $fields, 'App');

        expect($code)->toContain('readArray');
        expect($code)->toContain('writeArray');
    });

    it('sanitizes constant names', function () {
        $gen = new CodeGenerator();
        $nodes = [
            'ns=1;i=1' => ['nodeId' => 'ns=1;i=1', 'browseName' => 'My-Node.Name', 'displayName' => 'My Node', 'type' => 'UAVariable'],
        ];

        $code = $gen->generateNodeIdClass('Test', $nodes, 'App');

        expect($code)->toContain('public const My_Node_Name');
    });

    it('deduplicates constant names with _N suffix', function () {
        $gen = new CodeGenerator();
        $nodes = [
            'ns=1;i=1' => ['nodeId' => 'ns=1;i=1', 'browseName' => 'Temp', 'displayName' => 'Temp', 'type' => 'UAVariable'],
            'ns=1;i=2' => ['nodeId' => 'ns=1;i=2', 'browseName' => 'Temp', 'displayName' => 'Temp', 'type' => 'UAVariable'],
            'ns=1;i=3' => ['nodeId' => 'ns=1;i=3', 'browseName' => 'Temp', 'displayName' => 'Temp', 'type' => 'UAVariable'],
        ];

        $code = $gen->generateNodeIdClass('Test', $nodes, 'App');

        expect($code)->toContain("public const Temp = 'ns=1;i=1';");
        expect($code)->toContain("public const Temp_2 = 'ns=1;i=2';");
        expect($code)->toContain("public const Temp_3 = 'ns=1;i=3';");
    });
});
