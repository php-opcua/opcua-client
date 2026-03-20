<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Encoding\DataTypeMapping;
use Gianfriaur\OpcuaPhpClient\Encoding\DynamicCodec;
use Gianfriaur\OpcuaPhpClient\Encoding\StructureDefinitionParser;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\StructureDefinition;
use Gianfriaur\OpcuaPhpClient\Types\StructureField;

describe('DataTypeMapping', function () {

    it('resolves standard built-in types', function () {
        expect(DataTypeMapping::resolve(NodeId::numeric(0, 1)))->toBe(BuiltinType::Boolean);
        expect(DataTypeMapping::resolve(NodeId::numeric(0, 6)))->toBe(BuiltinType::Int32);
        expect(DataTypeMapping::resolve(NodeId::numeric(0, 11)))->toBe(BuiltinType::Double);
        expect(DataTypeMapping::resolve(NodeId::numeric(0, 12)))->toBe(BuiltinType::String);
        expect(DataTypeMapping::resolve(NodeId::numeric(0, 13)))->toBe(BuiltinType::DateTime);
    });

    it('returns null for custom types', function () {
        expect(DataTypeMapping::resolve(NodeId::numeric(2, 5001)))->toBeNull();
        expect(DataTypeMapping::resolve(NodeId::string(1, 'MyType')))->toBeNull();
    });

    it('returns null for unknown ns=0 identifiers', function () {
        expect(DataTypeMapping::resolve(NodeId::numeric(0, 9999)))->toBeNull();
    });
});

describe('StructureDefinitionParser', function () {

    it('parses a simple structure definition', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeNodeId(NodeId::numeric(2, 5001));
        $encoder->writeNodeId(NodeId::numeric(0, 22));
        $encoder->writeUInt32(StructureDefinition::STRUCTURE);
        $encoder->writeInt32(3);

        foreach (['x', 'y', 'z'] as $name) {
            $encoder->writeString($name);
            $encoder->writeLocalizedText(new \Gianfriaur\OpcuaPhpClient\Types\LocalizedText(null, null));
            $encoder->writeNodeId(NodeId::numeric(0, 11));
            $encoder->writeInt32(-1);
            $encoder->writeInt32(0);
            $encoder->writeUInt32(0);
            $encoder->writeBoolean(false);
        }

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $def = StructureDefinitionParser::parse($decoder);

        expect($def->structureType)->toBe(StructureDefinition::STRUCTURE);
        expect($def->fields)->toHaveCount(3);
        expect($def->fields[0]->name)->toBe('x');
        expect($def->fields[0]->dataType->identifier)->toBe(11);
        expect($def->fields[1]->name)->toBe('y');
        expect($def->fields[2]->name)->toBe('z');
        expect($def->defaultEncodingId->identifier)->toBe(5001);
    });

    it('parses a definition with optional fields', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeNodeId(NodeId::numeric(2, 6001));
        $encoder->writeNodeId(NodeId::numeric(0, 22));
        $encoder->writeUInt32(StructureDefinition::WITH_OPTIONAL_FIELDS);
        $encoder->writeInt32(2);

        $encoder->writeString('required');
        $encoder->writeLocalizedText(new \Gianfriaur\OpcuaPhpClient\Types\LocalizedText(null, null));
        $encoder->writeNodeId(NodeId::numeric(0, 6));
        $encoder->writeInt32(-1);
        $encoder->writeInt32(0);
        $encoder->writeUInt32(0);
        $encoder->writeBoolean(false);

        $encoder->writeString('optional');
        $encoder->writeLocalizedText(new \Gianfriaur\OpcuaPhpClient\Types\LocalizedText(null, null));
        $encoder->writeNodeId(NodeId::numeric(0, 12));
        $encoder->writeInt32(-1);
        $encoder->writeInt32(0);
        $encoder->writeUInt32(0);
        $encoder->writeBoolean(true);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $def = StructureDefinitionParser::parse($decoder);

        expect($def->structureType)->toBe(StructureDefinition::WITH_OPTIONAL_FIELDS);
        expect($def->fields[0]->isOptional)->toBeFalse();
        expect($def->fields[1]->isOptional)->toBeTrue();
    });
});

describe('DynamicCodec', function () {

    it('decodes a simple structure with scalar fields', function () {
        $def = new StructureDefinition(
            StructureDefinition::STRUCTURE,
            [
                new StructureField('x', NodeId::numeric(0, 11)),
                new StructureField('y', NodeId::numeric(0, 11)),
                new StructureField('z', NodeId::numeric(0, 11)),
            ],
            NodeId::numeric(2, 5001),
        );
        $codec = new DynamicCodec($def);

        $encoder = new BinaryEncoder();
        $encoder->writeDouble(1.5);
        $encoder->writeDouble(2.5);
        $encoder->writeDouble(3.5);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $codec->decode($decoder);

        expect($result)->toBe(['x' => 1.5, 'y' => 2.5, 'z' => 3.5]);
    });

    it('encodes a simple structure', function () {
        $def = new StructureDefinition(
            StructureDefinition::STRUCTURE,
            [
                new StructureField('x', NodeId::numeric(0, 11)),
                new StructureField('y', NodeId::numeric(0, 11)),
            ],
            NodeId::numeric(2, 5001),
        );
        $codec = new DynamicCodec($def);

        $encoder = new BinaryEncoder();
        $codec->encode($encoder, ['x' => 1.0, 'y' => 2.0]);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        expect($decoder->readDouble())->toBe(1.0);
        expect($decoder->readDouble())->toBe(2.0);
    });

    it('round-trips decode/encode for mixed types', function () {
        $def = new StructureDefinition(
            StructureDefinition::STRUCTURE,
            [
                new StructureField('name', NodeId::numeric(0, 12)),
                new StructureField('value', NodeId::numeric(0, 11)),
                new StructureField('count', NodeId::numeric(0, 6)),
                new StructureField('active', NodeId::numeric(0, 1)),
            ],
            NodeId::numeric(2, 5001),
        );
        $codec = new DynamicCodec($def);

        $original = ['name' => 'sensor', 'value' => 42.5, 'count' => 100, 'active' => true];

        $enc = new BinaryEncoder();
        $codec->encode($enc, $original);

        $dec = new BinaryDecoder($enc->getBuffer());
        $decoded = $codec->decode($dec);

        expect($decoded)->toBe($original);
    });

    it('handles array fields', function () {
        $def = new StructureDefinition(
            StructureDefinition::STRUCTURE,
            [
                new StructureField('values', NodeId::numeric(0, 11), valueRank: 1),
            ],
            NodeId::numeric(2, 5001),
        );
        $codec = new DynamicCodec($def);

        $encoder = new BinaryEncoder();
        $encoder->writeInt32(3);
        $encoder->writeDouble(10.0);
        $encoder->writeDouble(20.0);
        $encoder->writeDouble(30.0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $codec->decode($decoder);

        expect($result['values'])->toBe([10.0, 20.0, 30.0]);
    });

    it('handles optional fields present', function () {
        $def = new StructureDefinition(
            StructureDefinition::WITH_OPTIONAL_FIELDS,
            [
                new StructureField('required', NodeId::numeric(0, 6)),
                new StructureField('optional', NodeId::numeric(0, 12), isOptional: true),
            ],
            NodeId::numeric(2, 6001),
        );
        $codec = new DynamicCodec($def);

        $encoder = new BinaryEncoder();
        $encoder->writeUInt32(1);
        $encoder->writeInt32(42);
        $encoder->writeString('hello');

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $codec->decode($decoder);

        expect($result['required'])->toBe(42);
        expect($result['optional'])->toBe('hello');
    });

    it('handles optional fields absent', function () {
        $def = new StructureDefinition(
            StructureDefinition::WITH_OPTIONAL_FIELDS,
            [
                new StructureField('required', NodeId::numeric(0, 6)),
                new StructureField('optional', NodeId::numeric(0, 12), isOptional: true),
            ],
            NodeId::numeric(2, 6001),
        );
        $codec = new DynamicCodec($def);

        $encoder = new BinaryEncoder();
        $encoder->writeUInt32(0);
        $encoder->writeInt32(42);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $codec->decode($decoder);

        expect($result['required'])->toBe(42);
        expect($result['optional'])->toBeNull();
    });

    it('handles union with active field', function () {
        $def = new StructureDefinition(
            StructureDefinition::UNION,
            [
                new StructureField('intVal', NodeId::numeric(0, 6)),
                new StructureField('strVal', NodeId::numeric(0, 12)),
            ],
            NodeId::numeric(2, 7001),
        );
        $codec = new DynamicCodec($def);

        $encoder = new BinaryEncoder();
        $encoder->writeUInt32(2);
        $encoder->writeString('test');

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $codec->decode($decoder);

        expect($result['_switchField'])->toBe(2);
        expect($result['strVal'])->toBe('test');
    });

    it('handles union with no active field', function () {
        $def = new StructureDefinition(
            StructureDefinition::UNION,
            [
                new StructureField('intVal', NodeId::numeric(0, 6)),
            ],
            NodeId::numeric(2, 7001),
        );
        $codec = new DynamicCodec($def);

        $encoder = new BinaryEncoder();
        $encoder->writeUInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $codec->decode($decoder);

        expect($result['_switchField'])->toBe(0);
    });

    it('exposes the definition via getDefinition()', function () {
        $def = new StructureDefinition(StructureDefinition::STRUCTURE, [], NodeId::numeric(2, 1));
        $codec = new DynamicCodec($def);

        expect($codec->getDefinition())->toBe($def);
    });
});
