<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Encoding\DynamicCodec;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\StructureDefinition;
use Gianfriaur\OpcuaPhpClient\Types\StructureField;

describe('DynamicCodec encode paths', function () {

    it('encodes optional fields with present and absent values', function () {
        $def = new StructureDefinition(
            StructureDefinition::WITH_OPTIONAL_FIELDS,
            [
                new StructureField('required', NodeId::numeric(0, 6)),
                new StructureField('opt1', NodeId::numeric(0, 12), isOptional: true),
                new StructureField('opt2', NodeId::numeric(0, 11), isOptional: true),
            ],
            NodeId::numeric(2, 6001),
        );
        $codec = new DynamicCodec($def);

        $enc = new BinaryEncoder();
        $codec->encode($enc, ['required' => 10, 'opt1' => 'hello', 'opt2' => null]);

        $dec = new BinaryDecoder($enc->getBuffer());
        $result = $codec->decode($dec);

        expect($result['required'])->toBe(10);
        expect($result['opt1'])->toBe('hello');
        expect($result['opt2'])->toBeNull();
    });

    it('round-trips optional fields encode/decode', function () {
        $def = new StructureDefinition(
            StructureDefinition::WITH_OPTIONAL_FIELDS,
            [
                new StructureField('a', NodeId::numeric(0, 6)),
                new StructureField('b', NodeId::numeric(0, 12), isOptional: true),
            ],
            NodeId::numeric(2, 6001),
        );
        $codec = new DynamicCodec($def);

        $enc = new BinaryEncoder();
        $codec->encode($enc, ['a' => 42, 'b' => 'test']);

        $dec = new BinaryDecoder($enc->getBuffer());
        $result = $codec->decode($dec);
        expect($result)->toBe(['a' => 42, 'b' => 'test']);
    });

    it('encodes union with active field', function () {
        $def = new StructureDefinition(
            StructureDefinition::UNION,
            [
                new StructureField('intVal', NodeId::numeric(0, 6)),
                new StructureField('strVal', NodeId::numeric(0, 12)),
            ],
            NodeId::numeric(2, 7001),
        );
        $codec = new DynamicCodec($def);

        $enc = new BinaryEncoder();
        $codec->encode($enc, ['_switchField' => 1, 'intVal' => 99]);

        $dec = new BinaryDecoder($enc->getBuffer());
        $result = $codec->decode($dec);
        expect($result['_switchField'])->toBe(1);
        expect($result['intVal'])->toBe(99);
    });

    it('encodes union with no active field', function () {
        $def = new StructureDefinition(
            StructureDefinition::UNION,
            [new StructureField('val', NodeId::numeric(0, 6))],
            NodeId::numeric(2, 7001),
        );
        $codec = new DynamicCodec($def);

        $enc = new BinaryEncoder();
        $codec->encode($enc, ['_switchField' => 0]);

        $dec = new BinaryDecoder($enc->getBuffer());
        $result = $codec->decode($dec);
        expect($result['_switchField'])->toBe(0);
    });

    it('encodes array fields', function () {
        $def = new StructureDefinition(
            StructureDefinition::STRUCTURE,
            [new StructureField('values', NodeId::numeric(0, 6), valueRank: 1)],
            NodeId::numeric(2, 5001),
        );
        $codec = new DynamicCodec($def);

        $enc = new BinaryEncoder();
        $codec->encode($enc, ['values' => [1, 2, 3]]);

        $dec = new BinaryDecoder($enc->getBuffer());
        $result = $codec->decode($dec);
        expect($result['values'])->toBe([1, 2, 3]);
    });
});

describe('DynamicCodec edge cases', function () {

    it('handles union with switchField pointing to non-existent field', function () {
        $def = new StructureDefinition(
            StructureDefinition::UNION,
            [new StructureField('val', NodeId::numeric(0, 6))],
            NodeId::numeric(2, 7001),
        );
        $codec = new DynamicCodec($def);

        $encoder = new BinaryEncoder();
        $encoder->writeUInt32(99);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $codec->decode($decoder);

        expect($result['_switchField'])->toBe(99);
    });

    it('handles field with custom dataType (null BuiltinType) via readExtensionObject', function () {
        $def = new StructureDefinition(
            StructureDefinition::STRUCTURE,
            [new StructureField('nested', NodeId::numeric(2, 9999))],
            NodeId::numeric(2, 5001),
        );
        $codec = new DynamicCodec($def);

        $encoder = new BinaryEncoder();
        $encoder->writeNodeId(NodeId::numeric(2, 9999));
        $encoder->writeByte(0x01);
        $encoder->writeByteString('raw-body');

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $codec->decode($decoder);

        expect($result['nested'])->toBeArray();
        expect($result['nested']['typeId']->identifier)->toBe(9999);
    });

    it('encode skips field with null BuiltinType (custom nested)', function () {
        $def = new StructureDefinition(
            StructureDefinition::STRUCTURE,
            [
                new StructureField('val', NodeId::numeric(0, 6)),
                new StructureField('nested', NodeId::numeric(2, 9999)),
            ],
            NodeId::numeric(2, 5001),
        );
        $codec = new DynamicCodec($def);

        $encoder = new BinaryEncoder();
        $codec->encode($encoder, ['val' => 42, 'nested' => null]);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        expect($decoder->readInt32())->toBe(42);
    });
});

describe('StructureDefinitionParser with arrayDimensions', function () {

    it('parses a field with arrayDimensions', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeNodeId(NodeId::numeric(2, 5001));
        $encoder->writeNodeId(NodeId::numeric(0, 22));
        $encoder->writeUInt32(StructureDefinition::STRUCTURE);
        $encoder->writeInt32(1);

        $encoder->writeString('matrix');
        $encoder->writeLocalizedText(new \Gianfriaur\OpcuaPhpClient\Types\LocalizedText(null, null));
        $encoder->writeNodeId(NodeId::numeric(0, 11));
        $encoder->writeInt32(2);
        $encoder->writeInt32(2);
        $encoder->writeUInt32(3);
        $encoder->writeUInt32(4);
        $encoder->writeUInt32(0);
        $encoder->writeBoolean(false);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $def = \Gianfriaur\OpcuaPhpClient\Encoding\StructureDefinitionParser::parse($decoder);

        expect($def->fields)->toHaveCount(1);
        expect($def->fields[0]->name)->toBe('matrix');
        expect($def->fields[0]->valueRank)->toBe(2);
    });
});
