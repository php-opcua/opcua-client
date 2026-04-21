<?php

declare(strict_types=1);

use PhpOpcua\Client\Exception\EncodingException;
use PhpOpcua\Client\Exception\InvalidNodeIdException;
use PhpOpcua\Client\Types\BrowseNode;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\ExtensionObject;
use PhpOpcua\Client\Types\LocalizedText;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\QualifiedName;
use PhpOpcua\Client\Types\ReferenceDescription;
use PhpOpcua\Client\Types\Variant;

describe('NodeId::fromWireArray validation', function () {

    it('rejects payloads missing the "v" field', function () {
        expect(fn () => NodeId::fromWireArray([]))
            ->toThrow(InvalidNodeIdException::class);
    });

    it('rejects payloads where "v" is not a string', function () {
        expect(fn () => NodeId::fromWireArray(['v' => 123]))
            ->toThrow(InvalidNodeIdException::class);
    });
});

describe('QualifiedName::fromWireArray validation', function () {

    it('rejects payloads missing the "ns" or "n" fields', function () {
        expect(fn () => QualifiedName::fromWireArray(['n' => 'only-name']))
            ->toThrow(EncodingException::class);
    });

    it('rejects payloads where "ns" is not an int or "n" is not a string', function () {
        expect(fn () => QualifiedName::fromWireArray(['ns' => '0', 'n' => 'x']))
            ->toThrow(EncodingException::class);

        expect(fn () => QualifiedName::fromWireArray(['ns' => 0, 'n' => 42]))
            ->toThrow(EncodingException::class);
    });
});

describe('ReferenceDescription::fromWireArray validation', function () {

    it('rejects payloads missing a required decoded field', function () {
        expect(fn () => ReferenceDescription::fromWireArray([
            'refType' => NodeId::numeric(0, 35),
            'isForward' => true,
            'nodeId' => NodeId::numeric(0, 85),
            'browseName' => new QualifiedName(0, 'Objects'),
            'displayName' => 'not-a-LocalizedText',
            'nodeClass' => NodeClass::Object,
        ]))->toThrow(EncodingException::class);
    });

    it('rejects payloads where "nodeClass" is not a NodeClass instance', function () {
        expect(fn () => ReferenceDescription::fromWireArray([
            'refType' => NodeId::numeric(0, 35),
            'isForward' => true,
            'nodeId' => NodeId::numeric(0, 85),
            'browseName' => new QualifiedName(0, 'Objects'),
            'displayName' => new LocalizedText(null, 'Objects'),
            'nodeClass' => 1,
        ]))->toThrow(EncodingException::class);
    });
});

describe('BrowseNode::fromWireArray validation', function () {

    it('rejects payloads missing the "reference" field', function () {
        expect(fn () => BrowseNode::fromWireArray([]))
            ->toThrow(EncodingException::class);
    });

    it('rejects payloads where "children" contains a non-BrowseNode element', function () {
        $ref = new ReferenceDescription(
            NodeId::numeric(0, 35),
            true,
            NodeId::numeric(0, 85),
            new QualifiedName(0, 'Objects'),
            new LocalizedText(null, 'Objects'),
            NodeClass::Object,
            null,
        );

        expect(fn () => BrowseNode::fromWireArray([
            'reference' => $ref,
            'children' => ['not-a-BrowseNode'],
        ]))->toThrow(EncodingException::class);
    });
});

describe('ExtensionObject::fromWireArray validation', function () {

    it('rejects payloads missing the "typeId" field', function () {
        expect(fn () => ExtensionObject::fromWireArray([]))
            ->toThrow(EncodingException::class);
    });

    it('rejects payloads where "bodyB64" is not valid base64', function () {
        expect(fn () => ExtensionObject::fromWireArray([
            'typeId' => NodeId::numeric(2, 5001),
            'encoding' => 1,
            'bodyB64' => '!!!not-valid-base64!!!',
        ]))->toThrow(EncodingException::class);
    });
});

describe('Variant::fromWireArray validation', function () {

    it('rejects payloads where "type" is not a BuiltinType instance', function () {
        expect(fn () => Variant::fromWireArray([
            'type' => 'Int32',
            'value' => 42,
        ]))->toThrow(EncodingException::class);
    });

    it('rejects ByteString Variant payloads with invalid base64 in "bytesB64"', function () {
        expect(fn () => Variant::fromWireArray([
            'type' => BuiltinType::ByteString,
            'bytesB64' => '!!!not-valid-base64!!!',
        ]))->toThrow(EncodingException::class);
    });
});
