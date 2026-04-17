<?php

declare(strict_types=1);

require_once __DIR__ . '/Client/ClientTraitsCoverageTest.php';

use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Module\TranslateBrowsePath\TranslateBrowsePathModule;
use PhpOpcua\Client\OpcUaClientInterface;
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
