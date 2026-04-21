<?php

declare(strict_types=1);

use PhpOpcua\Client\Cache\WireCacheCodec;
use PhpOpcua\Client\Exception\CacheCorruptedException;
use PhpOpcua\Client\Exception\EncodingException;
use PhpOpcua\Client\Types\BrowseNode;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\EndpointDescription;
use PhpOpcua\Client\Types\LocalizedText;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\QualifiedName;
use PhpOpcua\Client\Types\ReferenceDescription;
use PhpOpcua\Client\Types\StructureDefinition;
use PhpOpcua\Client\Types\StructureField;
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Wire\CoreWireTypes;
use PhpOpcua\Client\Wire\WireTypeRegistry;

function makeCacheCodec(): WireCacheCodec
{
    $registry = new WireTypeRegistry();
    CoreWireTypes::registerForCache($registry);

    return new WireCacheCodec($registry);
}

describe('WireCacheCodec round-trip', function () {

    it('round-trips a plain scalar', function () {
        $codec = makeCacheCodec();
        $raw = $codec->encode(42);
        expect($codec->decode($raw))->toBe(42);
    });

    it('round-trips a plain array of scalars', function () {
        $codec = makeCacheCodec();
        $raw = $codec->encode(['a' => 1, 'b' => 'two', 'c' => false]);
        expect($codec->decode($raw))->toBe(['a' => 1, 'b' => 'two', 'c' => false]);
    });

    it('round-trips a NodeId', function () {
        $codec = makeCacheCodec();
        $nodeId = NodeId::string(2, 'Demo.Temperature');
        $raw = $codec->encode($nodeId);
        $decoded = $codec->decode($raw);
        expect($decoded)->toBeInstanceOf(NodeId::class);
        expect($decoded->namespaceIndex)->toBe(2);
        expect($decoded->identifier)->toBe('Demo.Temperature');
        expect($decoded->type)->toBe(NodeId::TYPE_STRING);
    });

    it('round-trips a BuiltinType enum', function () {
        $codec = makeCacheCodec();
        $raw = $codec->encode(BuiltinType::Double);
        expect($codec->decode($raw))->toBe(BuiltinType::Double);
    });

    it('round-trips a ReferenceDescription[] (cached by browse)', function () {
        $codec = makeCacheCodec();
        $refs = [
            new ReferenceDescription(
                NodeId::numeric(0, 35),
                true,
                NodeId::numeric(0, 2253),
                new QualifiedName(0, 'Server'),
                new LocalizedText(null, 'Server'),
                NodeClass::Object,
                NodeId::numeric(0, 2004),
            ),
        ];

        $decoded = $codec->decode($codec->encode($refs));
        expect($decoded)->toHaveCount(1);
        expect($decoded[0])->toBeInstanceOf(ReferenceDescription::class);
        expect($decoded[0]->browseName->name)->toBe('Server');
        expect($decoded[0]->isForward)->toBeTrue();
    });

    it('round-trips a DataValue[] (cached by readMeta)', function () {
        $codec = makeCacheCodec();
        $dv = new DataValue(new Variant(BuiltinType::Double, 42.5));
        $raw = $codec->encode([$dv]);
        $decoded = $codec->decode($raw);
        expect($decoded)->toHaveCount(1);
        expect($decoded[0])->toBeInstanceOf(DataValue::class);
        expect($decoded[0]->getVariant()->value)->toBe(42.5);
    });

    it('round-trips an EndpointDescription[]', function () {
        $codec = makeCacheCodec();
        $ep = new EndpointDescription(
            'opc.tcp://example:4840',
            '',
            1,
            'http://opcfoundation.org/UA/SecurityPolicy#None',
            [],
            'http://opcfoundation.org/UA-Profile/Transport/uatcp-uasc-uabinary',
            0,
        );

        $decoded = $codec->decode($codec->encode([$ep]));
        expect($decoded)->toHaveCount(1);
        expect($decoded[0])->toBeInstanceOf(EndpointDescription::class);
        expect($decoded[0]->endpointUrl)->toBe('opc.tcp://example:4840');
    });

    it('round-trips the discoverDataTypes cache payload (array of assoc arrays)', function () {
        $codec = makeCacheCodec();
        $definition = new StructureDefinition(
            StructureDefinition::STRUCTURE,
            [
                new StructureField('X', NodeId::numeric(0, 11), -1, false),
                new StructureField('Y', NodeId::numeric(0, 11), -1, false),
            ],
            NodeId::numeric(2, 5001),
        );

        $payload = [['encodingId' => NodeId::numeric(2, 5001), 'definition' => $definition]];
        $decoded = $codec->decode($codec->encode($payload));

        expect($decoded)->toHaveCount(1);
        expect($decoded[0]['encodingId'])->toBeInstanceOf(NodeId::class);
        expect($decoded[0]['encodingId']->identifier)->toBe(5001);
        expect($decoded[0]['definition'])->toBeInstanceOf(StructureDefinition::class);
        expect($decoded[0]['definition']->fields)->toHaveCount(2);
        expect($decoded[0]['definition']->fields[0])->toBeInstanceOf(StructureField::class);
        expect($decoded[0]['definition']->fields[0]->name)->toBe('X');
    });

    it('round-trips a BrowseNode tree', function () {
        $codec = makeCacheCodec();
        $ref = new ReferenceDescription(
            NodeId::numeric(0, 35),
            true,
            NodeId::numeric(0, 85),
            new QualifiedName(0, 'Objects'),
            new LocalizedText(null, 'Objects'),
            NodeClass::Object,
            NodeId::numeric(0, 61),
        );
        $node = new BrowseNode($ref);

        $decoded = $codec->decode($codec->encode($node));
        expect($decoded)->toBeInstanceOf(BrowseNode::class);
        expect($decoded->reference->browseName->name)->toBe('Objects');
    });
});

describe('WireCacheCodec security', function () {

    it('rejects a legacy serialize() payload outright', function () {
        $codec = makeCacheCodec();
        $legacyLikePhpSerialize = 'O:8:"stdClass":0:{}';

        expect(fn () => $codec->decode($legacyLikePhpSerialize))
            ->toThrow(CacheCorruptedException::class);
    });

    it('rejects the pre-4.3.0 CACHE_SAFE_PREFIX wrapper', function () {
        $codec = makeCacheCodec();
        $preV430 = "\x00opcua\x00" . base64_encode('O:8:"stdClass":0:{}');

        expect(fn () => $codec->decode($preV430))
            ->toThrow(CacheCorruptedException::class);
    });

    it('rejects malformed JSON inside the prefix', function () {
        $codec = makeCacheCodec();
        $bad = 'opcua.wire.v1:{not-valid-json';

        expect(fn () => $codec->decode($bad))
            ->toThrow(CacheCorruptedException::class);
    });

    it('rejects an unknown wire type id', function () {
        $codec = makeCacheCodec();
        $bad = 'opcua.wire.v1:' . json_encode(['__t' => 'NotRegistered', 'v' => 1]);

        expect(fn () => $codec->decode($bad))
            ->toThrow(CacheCorruptedException::class);
    });

    it('refuses to encode an object that is not on the allowlist', function () {
        $codec = makeCacheCodec();
        $bad = new stdClass();

        expect(fn () => $codec->encode($bad))
            ->toThrow(EncodingException::class);
    });

    it('throws EncodingException when json_encode fails (invalid UTF-8 string)', function () {
        $codec = makeCacheCodec();
        $invalidUtf8 = "\xB1\x31";

        expect(fn () => $codec->encode($invalidUtf8))
            ->toThrow(EncodingException::class);
    });

    it('produces a stable, JSON-shaped output', function () {
        $codec = makeCacheCodec();
        $raw = $codec->encode(['k' => 1]);

        expect($raw)->toStartWith('opcua.wire.v1:');
        expect(fn () => json_decode(substr($raw, strlen('opcua.wire.v1:')), true, flags: JSON_THROW_ON_ERROR))
            ->not->toThrow(JsonException::class);
    });
});

describe('StructureDefinition wire payload validation', function () {

    it('rejects payloads with a missing required field (dataType)', function () {
        $codec = makeCacheCodec();
        $bad = 'opcua.wire.v1:' . json_encode([
            '__t' => 'StructureField',
            'name' => 'x',
            'valueRank' => -1,
            'isOptional' => false,
        ]);

        expect(fn () => $codec->decode($bad))
            ->toThrow(CacheCorruptedException::class);
    });

    it('rejects a StructureDefinition with a non-StructureField in fields', function () {
        $codec = makeCacheCodec();
        $bad = 'opcua.wire.v1:' . json_encode([
            '__t' => 'StructureDefinition',
            'structureType' => 0,
            'fields' => [['not' => 'a field']],
            'defaultEncodingId' => ['__t' => 'NodeId', 'v' => 'i=1'],
        ]);

        expect(fn () => $codec->decode($bad))
            ->toThrow(CacheCorruptedException::class);
    });
});
