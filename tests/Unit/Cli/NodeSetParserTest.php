<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Cli\NodeSetParser;

$fixturePath = __DIR__ . '/../../Fixtures/TestNodeSet2.xml';

describe('NodeSetParser', function () use ($fixturePath) {

    it('parses aliases from XML', function () use ($fixturePath) {
        $parser = new NodeSetParser();
        $parser->parse($fixturePath);

        $aliases = $parser->getAliases();
        expect($aliases)->toHaveKey('Boolean');
        expect($aliases['Boolean'])->toBe('i=1');
        expect($aliases['Int32'])->toBe('i=6');
        expect($aliases['Double'])->toBe('i=11');
        expect($aliases['String'])->toBe('i=12');
        expect($aliases['HasEncoding'])->toBe('i=38');
    });

    it('parses node elements', function () use ($fixturePath) {
        $parser = new NodeSetParser();
        $parser->parse($fixturePath);

        $nodes = $parser->getNodes();
        expect($nodes)->toHaveKey('ns=1;i=1000');
        expect($nodes['ns=1;i=1000']['browseName'])->toBe('TestFolder');
        expect($nodes['ns=1;i=1000']['type'])->toBe('UAObject');

        expect($nodes)->toHaveKey('ns=1;i=2001');
        expect($nodes['ns=1;i=2001']['browseName'])->toBe('Temperature');
        expect($nodes['ns=1;i=2001']['type'])->toBe('UAVariable');

        expect($nodes)->toHaveKey('ns=1;i=7001');
        expect($nodes['ns=1;i=7001']['browseName'])->toBe('Reset');
        expect($nodes['ns=1;i=7001']['type'])->toBe('UAMethod');
    });

    it('excludes Default Binary encoding objects from nodes', function () use ($fixturePath) {
        $parser = new NodeSetParser();
        $parser->parse($fixturePath);

        $nodes = $parser->getNodes();
        expect($nodes)->not->toHaveKey('ns=1;i=5001');
        expect($nodes)->not->toHaveKey('ns=1;i=5002');
        expect($nodes)->not->toHaveKey('ns=1;i=5003');
    });

    it('parses structured DataTypes with fields', function () use ($fixturePath) {
        $parser = new NodeSetParser();
        $parser->parse($fixturePath);

        $dataTypes = $parser->getDataTypes();
        expect($dataTypes)->toHaveKey('ns=1;i=3001');

        $testPoint = $dataTypes['ns=1;i=3001'];
        expect($testPoint['name'])->toBe('TestPoint');
        expect($testPoint['encodingId'])->toBe('ns=1;i=5001');
        expect($testPoint['fields'])->toHaveCount(3);
        expect($testPoint['fields'][0]['name'])->toBe('X');
        expect($testPoint['fields'][0]['dataType'])->toBe('i=11');
    });

    it('resolves alias DataTypes in fields', function () use ($fixturePath) {
        $parser = new NodeSetParser();
        $parser->parse($fixturePath);

        $dataTypes = $parser->getDataTypes();
        $person = $dataTypes['ns=1;i=3003'];

        expect($person['fields'][0]['dataType'])->toBe('i=12');
        expect($person['fields'][1]['dataType'])->toBe('i=7');
        expect($person['fields'][2]['dataType'])->toBe('i=1');
    });

    it('finds encoding NodeId from HasEncoding reference', function () use ($fixturePath) {
        $parser = new NodeSetParser();
        $parser->parse($fixturePath);

        $dataTypes = $parser->getDataTypes();
        expect($dataTypes['ns=1;i=3001']['encodingId'])->toBe('ns=1;i=5001');
        expect($dataTypes['ns=1;i=3002']['encodingId'])->toBe('ns=1;i=5002');
        expect($dataTypes['ns=1;i=3003']['encodingId'])->toBe('ns=1;i=5003');
    });

    it('includes DataTypes in nodes list', function () use ($fixturePath) {
        $parser = new NodeSetParser();
        $parser->parse($fixturePath);

        $nodes = $parser->getNodes();
        expect($nodes)->toHaveKey('ns=1;i=3001');
        expect($nodes['ns=1;i=3001']['type'])->toBe('UADataType');
    });

    it('parses enumeration definitions', function () use ($fixturePath) {
        $parser = new NodeSetParser();
        $parser->parse($fixturePath);

        $enums = $parser->getEnumerations();
        expect($enums)->toHaveKey('ns=1;i=3010');

        $testEnum = $enums['ns=1;i=3010'];
        expect($testEnum['name'])->toBe('TestStatusEnum');
        expect($testEnum['values'])->toHaveCount(4);
        expect($testEnum['values'][0]['name'])->toBe('IDLE');
        expect($testEnum['values'][0]['value'])->toBe(0);
        expect($testEnum['values'][2]['name'])->toBe('ERROR');
        expect($testEnum['values'][2]['value'])->toBe(2);
    });

    it('does not mix enums with structured DataTypes', function () use ($fixturePath) {
        $parser = new NodeSetParser();
        $parser->parse($fixturePath);

        $dataTypes = $parser->getDataTypes();
        $enums = $parser->getEnumerations();

        expect($dataTypes)->not->toHaveKey('ns=1;i=3010');
        expect($enums)->not->toHaveKey('ns=1;i=3001');
    });

    it('throws on invalid file', function () {
        $parser = new NodeSetParser();

        expect(fn () => $parser->parse('/nonexistent/file.xml'))
            ->toThrow(RuntimeException::class);
    });
});
