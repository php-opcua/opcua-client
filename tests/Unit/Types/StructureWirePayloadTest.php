<?php

declare(strict_types=1);

use PhpOpcua\Client\Exception\EncodingException;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StructureDefinition;
use PhpOpcua\Client\Types\StructureField;

describe('StructureField::fromWireArray validation', function () {

    $nodeId = NodeId::numeric(0, 11);

    it('rejects when name is missing', function () {
        expect(fn () => StructureField::fromWireArray([
            'dataType' => NodeId::numeric(0, 11),
            'valueRank' => -1,
            'isOptional' => false,
        ]))->toThrow(EncodingException::class);
    });

    it('rejects when name is not a string', function () {
        expect(fn () => StructureField::fromWireArray([
            'name' => 123,
            'dataType' => NodeId::numeric(0, 11),
            'valueRank' => -1,
            'isOptional' => false,
        ]))->toThrow(EncodingException::class);
    });

    it('rejects when dataType is missing', function () {
        expect(fn () => StructureField::fromWireArray([
            'name' => 'x',
            'valueRank' => -1,
            'isOptional' => false,
        ]))->toThrow(EncodingException::class);
    });

    it('rejects when dataType is not a NodeId instance', function () {
        expect(fn () => StructureField::fromWireArray([
            'name' => 'x',
            'dataType' => 'ns=0;i=11',
            'valueRank' => -1,
            'isOptional' => false,
        ]))->toThrow(EncodingException::class);
    });

    it('rejects when valueRank is missing', function () {
        expect(fn () => StructureField::fromWireArray([
            'name' => 'x',
            'dataType' => NodeId::numeric(0, 11),
            'isOptional' => false,
        ]))->toThrow(EncodingException::class);
    });

    it('rejects when valueRank is not an int', function () {
        expect(fn () => StructureField::fromWireArray([
            'name' => 'x',
            'dataType' => NodeId::numeric(0, 11),
            'valueRank' => 'scalar',
            'isOptional' => false,
        ]))->toThrow(EncodingException::class);
    });

    it('rejects when isOptional is missing', function () {
        expect(fn () => StructureField::fromWireArray([
            'name' => 'x',
            'dataType' => NodeId::numeric(0, 11),
            'valueRank' => -1,
        ]))->toThrow(EncodingException::class);
    });

    it('rejects when isOptional is not a bool', function () {
        expect(fn () => StructureField::fromWireArray([
            'name' => 'x',
            'dataType' => NodeId::numeric(0, 11),
            'valueRank' => -1,
            'isOptional' => 0,
        ]))->toThrow(EncodingException::class);
    });
});

describe('StructureDefinition::fromWireArray validation', function () {

    it('rejects when structureType is missing', function () {
        expect(fn () => StructureDefinition::fromWireArray([
            'fields' => [],
            'defaultEncodingId' => NodeId::numeric(0, 1),
        ]))->toThrow(EncodingException::class);
    });

    it('rejects when structureType is not an int', function () {
        expect(fn () => StructureDefinition::fromWireArray([
            'structureType' => '0',
            'fields' => [],
            'defaultEncodingId' => NodeId::numeric(0, 1),
        ]))->toThrow(EncodingException::class);
    });

    it('rejects when defaultEncodingId is missing', function () {
        expect(fn () => StructureDefinition::fromWireArray([
            'structureType' => 0,
            'fields' => [],
        ]))->toThrow(EncodingException::class);
    });

    it('rejects when defaultEncodingId is not a NodeId instance', function () {
        expect(fn () => StructureDefinition::fromWireArray([
            'structureType' => 0,
            'fields' => [],
            'defaultEncodingId' => 'ns=0;i=1',
        ]))->toThrow(EncodingException::class);
    });
});
