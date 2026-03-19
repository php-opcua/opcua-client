<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Exception\InvalidNodeIdException;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

describe('NodeId::parse', function () {

    it('parses a numeric NodeId without namespace', function () {
        $nodeId = NodeId::parse('i=85');

        expect($nodeId->getNamespaceIndex())->toBe(0);
        expect($nodeId->getIdentifier())->toBe(85);
        expect($nodeId->isNumeric())->toBeTrue();
    });

    it('parses a numeric NodeId with namespace 0', function () {
        $nodeId = NodeId::parse('ns=0;i=2253');

        expect($nodeId->getNamespaceIndex())->toBe(0);
        expect($nodeId->getIdentifier())->toBe(2253);
        expect($nodeId->isNumeric())->toBeTrue();
    });

    it('parses a numeric NodeId with namespace 2', function () {
        $nodeId = NodeId::parse('ns=2;i=1001');

        expect($nodeId->getNamespaceIndex())->toBe(2);
        expect($nodeId->getIdentifier())->toBe(1001);
        expect($nodeId->isNumeric())->toBeTrue();
    });

    it('parses a string NodeId', function () {
        $nodeId = NodeId::parse('ns=2;s=MyNode');

        expect($nodeId->getNamespaceIndex())->toBe(2);
        expect($nodeId->getIdentifier())->toBe('MyNode');
        expect($nodeId->isString())->toBeTrue();
    });

    it('parses a string NodeId with special characters', function () {
        $nodeId = NodeId::parse('ns=1;s=My.Node/With=Chars');

        expect($nodeId->getNamespaceIndex())->toBe(1);
        expect($nodeId->getIdentifier())->toBe('My.Node/With=Chars');
        expect($nodeId->isString())->toBeTrue();
    });

    it('parses a guid NodeId', function () {
        $guid = '72962B91-FA75-4AE6-8D28-B404DC7DAF63';
        $nodeId = NodeId::parse("ns=0;g={$guid}");

        expect($nodeId->getNamespaceIndex())->toBe(0);
        expect($nodeId->getIdentifier())->toBe($guid);
        expect($nodeId->isGuid())->toBeTrue();
    });

    it('parses an opaque NodeId', function () {
        $nodeId = NodeId::parse('ns=0;b=AQID');

        expect($nodeId->getNamespaceIndex())->toBe(0);
        expect($nodeId->getIdentifier())->toBe('AQID');
        expect($nodeId->isOpaque())->toBeTrue();
    });

    it('throws on missing identifier type', function () {
        NodeId::parse('ns=2');
    })->throws(InvalidNodeIdException::class);

    it('throws on unknown type character', function () {
        NodeId::parse('ns=2;x=123');
    })->throws(InvalidNodeIdException::class, 'Unknown NodeId type identifier: x');

    it('throws on completely invalid format', function () {
        NodeId::parse('garbage');
    })->throws(InvalidNodeIdException::class);
});

describe('NodeId::toString', function () {

    it('serializes numeric NodeId without namespace prefix when ns=0', function () {
        $nodeId = NodeId::numeric(0, 85);

        expect($nodeId->toString())->toBe('i=85');
        expect((string)$nodeId)->toBe('i=85');
    });

    it('serializes numeric NodeId with namespace prefix when ns>0', function () {
        $nodeId = NodeId::numeric(2, 1001);

        expect($nodeId->toString())->toBe('ns=2;i=1001');
    });

    it('serializes string NodeId', function () {
        $nodeId = NodeId::string(2, 'MyNode');

        expect($nodeId->toString())->toBe('ns=2;s=MyNode');
    });

    it('serializes guid NodeId', function () {
        $guid = '72962B91-FA75-4AE6-8D28-B404DC7DAF63';
        $nodeId = NodeId::guid(0, $guid);

        expect($nodeId->toString())->toBe("g={$guid}");
    });

    it('serializes opaque NodeId', function () {
        $nodeId = NodeId::opaque(1, 'AQID');

        expect($nodeId->toString())->toBe('ns=1;b=AQID');
    });
});

describe('NodeId parse/toString roundtrip', function () {

    it('roundtrips numeric NodeId', function () {
        $original = 'ns=2;i=1001';
        expect(NodeId::parse($original)->toString())->toBe($original);
    });

    it('roundtrips string NodeId', function () {
        $original = 'ns=3;s=My.Variable';
        expect(NodeId::parse($original)->toString())->toBe($original);
    });

    it('roundtrips numeric NodeId without namespace', function () {
        $original = 'i=85';
        expect(NodeId::parse($original)->toString())->toBe($original);
    });

    it('roundtrips guid NodeId', function () {
        $original = 'ns=0;g=72962B91-FA75-4AE6-8D28-B404DC7DAF63';
        // ns=0 is omitted in output
        expect(NodeId::parse($original)->toString())->toBe('g=72962B91-FA75-4AE6-8D28-B404DC7DAF63');
    });
});
