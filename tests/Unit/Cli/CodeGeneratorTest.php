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

    it('generates Codec class with correct decode/encode methods', function () {
        $gen = new CodeGenerator();
        $fields = [
            ['name' => 'X', 'dataType' => 'i=11'],
            ['name' => 'Y', 'dataType' => 'i=11'],
            ['name' => 'Name', 'dataType' => 'i=12'],
        ];

        $code = $gen->generateCodecClass('TestCodec', $fields, 'App\\OpcUa');

        expect($code)->toContain('namespace App\\OpcUa\\Codecs;');
        expect($code)->toContain('class TestCodec implements ExtensionObjectCodec');
        expect($code)->toContain('$decoder->readDouble()');
        expect($code)->toContain('$decoder->readString()');
        expect($code)->toContain("\$encoder->writeDouble(\$value['X'])");
        expect($code)->toContain("\$encoder->writeString(\$value['Name'])");
    });

    it('generates Registrar class', function () {
        $gen = new CodeGenerator();
        $codecs = [
            ['encodingId' => 'ns=1;i=5001', 'codecClass' => 'TestPointCodec'],
            ['encodingId' => 'ns=1;i=5002', 'codecClass' => 'TestRangeCodec'],
        ];

        $code = $gen->generateRegistrarClass('TestRegistrar', $codecs, 'App\\OpcUa');

        expect($code)->toContain('namespace App\\OpcUa;');
        expect($code)->toContain('class TestRegistrar');
        expect($code)->toContain("NodeId::parse('ns=1;i=5001')");
        expect($code)->toContain('new Codecs\\TestPointCodec()');
        expect($code)->toContain('new Codecs\\TestRangeCodec()');
        expect($code)->toContain('static function register');
    });

    it('handles unknown data types with readExtensionObject', function () {
        $gen = new CodeGenerator();
        $fields = [
            ['name' => 'Nested', 'dataType' => 'ns=2;i=9999'],
        ];

        $code = $gen->generateCodecClass('NestedCodec', $fields, 'App');

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

    it('sanitizes constant names', function () {
        $gen = new CodeGenerator();
        $nodes = [
            'ns=1;i=1' => ['nodeId' => 'ns=1;i=1', 'browseName' => 'My-Node.Name', 'displayName' => 'My Node', 'type' => 'UAVariable'],
        ];

        $code = $gen->generateNodeIdClass('Test', $nodes, 'App');

        expect($code)->toContain('public const My_Node_Name');
    });
});
