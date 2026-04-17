<?php

declare(strict_types=1);

require_once __DIR__ . '/Client/ClientTraitsCoverageTest.php';

use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Module\TranslateBrowsePath\TranslateBrowsePathModule;
use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\QualifiedName;

describe('resolveNodeId path parsing', function () {

    beforeEach(function () {
        $this->parse = (new ReflectionMethod(TranslateBrowsePathModule::class, 'parseQualifiedName'))->getClosure();
    });

    it('parses a simple name as namespace 0', function () {
        $qn = ($this->parse)('Server');
        expect($qn)->toBeInstanceOf(QualifiedName::class);
        expect($qn->getNamespaceIndex())->toBe(0);
        expect($qn->getName())->toBe('Server');
    });

    it('parses a namespaced name', function () {
        $qn = ($this->parse)('2:Temperature');
        expect($qn->getNamespaceIndex())->toBe(2);
        expect($qn->getName())->toBe('Temperature');
    });

    it('parses namespace 0 explicitly', function () {
        $qn = ($this->parse)('0:Server');
        expect($qn->getNamespaceIndex())->toBe(0);
        expect($qn->getName())->toBe('Server');
    });

    it('parses high namespace index', function () {
        $qn = ($this->parse)('100:MyNode');
        expect($qn->getNamespaceIndex())->toBe(100);
        expect($qn->getName())->toBe('MyNode');
    });

    it('handles name with colon but non-numeric prefix as namespace 0', function () {
        $qn = ($this->parse)('http://example.com');
        expect($qn->getNamespaceIndex())->toBe(0);
        expect($qn->getName())->toBe('http://example.com');
    });

    it('handles name containing colon after namespace', function () {
        $qn = ($this->parse)('2:My:Node:With:Colons');
        expect($qn->getNamespaceIndex())->toBe(2);
        expect($qn->getName())->toBe('My:Node:With:Colons');
    });

    it('handles empty name after namespace', function () {
        $qn = ($this->parse)('2:');
        expect($qn->getNamespaceIndex())->toBe(2);
        expect($qn->getName())->toBe('');
    });
});

describe('resolveNodeId interface', function () {

    it('implements resolveNodeId on OpcUaClientInterface', function () {
        $reflection = new ReflectionClass(OpcUaClientInterface::class);
        expect($reflection->hasMethod('resolveNodeId'))->toBeTrue();
        expect($reflection->hasMethod('translateBrowsePaths'))->toBeTrue();
    });

    it('throws when not connected', function () {
        $client = createClientWithoutConnect();
        registerClientModules($client);
        expect(fn () => $client->resolveNodeId('/Objects/Server'))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws translateBrowsePaths when not connected', function () {
        $client = createClientWithoutConnect();
        registerClientModules($client);
        expect(fn () => $client->translateBrowsePaths([]))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });
});

describe('resolveNodeId string dispatch', function () {

    it('returns a NodeId instance as-is without touching the browse-path handler', function () {
        $client = createClientWithoutConnect();
        registerClientModules($client);
        $nodeId = NodeId::numeric(2, 10);

        expect($client->resolveNodeId($nodeId))->toBe($nodeId);
    });

    it('parses a NodeId string whose identifier contains slashes without routing to the browse-path handler', function () {
        $client = createClientWithoutConnect();
        registerClientModules($client);

        $resolved = $client->resolveNodeId('ns=1;s=TestServer/Dynamic/Counter');

        expect($resolved)->toBeInstanceOf(NodeId::class);
        expect((string) $resolved)->toBe('ns=1;s=TestServer/Dynamic/Counter');
    });

    it('parses a NodeId string with no namespace prefix and slashes in the identifier', function () {
        $client = createClientWithoutConnect();
        registerClientModules($client);

        $resolved = $client->resolveNodeId('s=TestServer/Dynamic/Counter');

        expect($resolved)->toBeInstanceOf(NodeId::class);
        expect((string) $resolved)->toBe('s=TestServer/Dynamic/Counter');
    });

    it('parses a plain numeric NodeId string', function () {
        $client = createClientWithoutConnect();
        registerClientModules($client);

        $resolved = $client->resolveNodeId('ns=0;i=85');

        expect($resolved)->toBeInstanceOf(NodeId::class);
        expect($resolved->getNamespaceIndex())->toBe(0);
        expect($resolved->getIdentifier())->toBe(85);
    });

    it('routes a bare browse-path string (no NodeId prefix, contains slash) to the browse-path handler', function () {
        $client = createClientWithoutConnect();
        registerClientModules($client);

        expect(fn () => $client->resolveNodeId('/Objects/Server'))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('routes when an explicit startingNodeId is provided regardless of the string shape', function () {
        $client = createClientWithoutConnect();
        registerClientModules($client);

        expect(fn () => $client->resolveNodeId('ns=1;s=Whatever', NodeId::numeric(0, 85)))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });
});
