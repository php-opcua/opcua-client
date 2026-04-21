<?php

declare(strict_types=1);

require_once __DIR__ . '/../Helpers/ClientTestHelpers.php';

use PhpOpcua\Client\Client;
use PhpOpcua\Client\Exception\ModuleConflictException;
use PhpOpcua\Client\Module\Browse\BrowseModule;
use PhpOpcua\Client\Module\NodeManagement\AddNodesResult;
use PhpOpcua\Client\Module\ReadWrite\ReadWriteModule;
use PhpOpcua\Client\Types\NodeId;

describe('Client introspection methods', function () {

    it('hasMethod returns true for a built-in method name', function () {
        $client = setupConnectedClient(new MockTransport());
        expect($client->hasMethod('read'))->toBeTrue();
        expect($client->hasMethod('browse'))->toBeTrue();
    });

    it('hasMethod returns false for a name that no module registered', function () {
        $client = setupConnectedClient(new MockTransport());
        expect($client->hasMethod('thisMethodDoesNotExist'))->toBeFalse();
    });

    it('getRegisteredMethods lists the built-in method names', function () {
        $client = setupConnectedClient(new MockTransport());
        $methods = $client->getRegisteredMethods();

        expect($methods)->toBeArray();
        expect($methods)->toContain('read');
        expect($methods)->toContain('write');
        expect($methods)->toContain('browse');
    });

    it('hasModule returns true for a loaded built-in module and false otherwise', function () {
        $client = setupConnectedClient(new MockTransport());
        expect($client->hasModule(ReadWriteModule::class))->toBeTrue();
        expect($client->hasModule(BrowseModule::class))->toBeTrue();
        expect($client->hasModule('Some\\NotLoaded\\Module'))->toBeFalse();
    });

    it('getLoadedModules returns the list of module class names', function () {
        $client = setupConnectedClient(new MockTransport());
        $modules = $client->getLoadedModules();

        expect($modules)->toBeArray();
        expect($modules)->toContain(ReadWriteModule::class);
        expect($modules)->toContain(BrowseModule::class);
    });
});

describe('Client::__call dispatch', function () {

    it('dispatches to a registered custom handler', function () {
        $client = setupConnectedClient(new MockTransport());
        $client->setCurrentModuleClass('CustomStubModule');
        $client->registerMethod('myCustomOp', fn (int $x, int $y) => $x + $y);

        expect($client->myCustomOp(2, 3))->toBe(5); // @phpstan-ignore-line
    });

    it('throws BadMethodCallException for an unknown method', function () {
        $client = setupConnectedClient(new MockTransport());
        expect(fn () => $client->thisMethodIsNotRegistered()) // @phpstan-ignore-line
            ->toThrow(BadMethodCallException::class, 'is not registered');
    });
});

describe('Client::registerMethod collision detection', function () {

    it('throws ModuleConflictException when two modules register the same method', function () {
        $client = setupConnectedClient(new MockTransport());

        $client->setCurrentModuleClass('ModuleA');
        $client->registerMethod('sharedMethod', fn () => 'A');

        $client->setCurrentModuleClass('ModuleB');
        expect(fn () => $client->registerMethod('sharedMethod', fn () => 'B'))
            ->toThrow(ModuleConflictException::class, 'already registered by');
    });

    it('allows the same module to re-register the same method (reconnect idempotence)', function () {
        $client = setupConnectedClient(new MockTransport());

        $client->setCurrentModuleClass('ModuleA');
        $client->registerMethod('shared', fn () => 'first');
        $client->registerMethod('shared', fn () => 'second');

        expect($client->hasMethod('shared'))->toBeTrue();
    });
});

describe('Client NodeManagement proxy delegation', function () {

    function installStubHandler(Client $client, string $name, callable $handler): void
    {
        $ref = new ReflectionProperty(Client::class, 'methodHandlers');
        $handlers = $ref->getValue($client);
        $handlers[$name] = $handler;
        $ref->setValue($client, $handlers);
    }

    it('addNodes delegates to the methodHandlers["addNodes"] proxy', function () {
        $client = setupConnectedClient(new MockTransport());
        $captured = null;
        installStubHandler($client, 'addNodes', function (array $nodes) use (&$captured) {
            $captured = $nodes;

            return [new AddNodesResult(0, NodeId::numeric(2, 1))];
        });

        $result = $client->addNodes([['parentNodeId' => 'i=85']]);
        expect($captured)->toBe([['parentNodeId' => 'i=85']]);
        expect($result)->toHaveCount(1);
        expect($result[0])->toBeInstanceOf(AddNodesResult::class);
    });

    it('deleteNodes delegates to the methodHandlers["deleteNodes"] proxy', function () {
        $client = setupConnectedClient(new MockTransport());
        $captured = null;
        installStubHandler($client, 'deleteNodes', function (array $nodes) use (&$captured) {
            $captured = $nodes;

            return [0];
        });

        expect($client->deleteNodes([['nodeId' => 'ns=2;i=1']]))->toBe([0]);
        expect($captured)->toBe([['nodeId' => 'ns=2;i=1']]);
    });

    it('addReferences delegates to the methodHandlers["addReferences"] proxy', function () {
        $client = setupConnectedClient(new MockTransport());
        $captured = null;
        installStubHandler($client, 'addReferences', function (array $refs) use (&$captured) {
            $captured = $refs;

            return [0];
        });

        expect($client->addReferences([['sourceNodeId' => 'i=85']]))->toBe([0]);
        expect($captured)->toBe([['sourceNodeId' => 'i=85']]);
    });

    it('deleteReferences delegates to the methodHandlers["deleteReferences"] proxy', function () {
        $client = setupConnectedClient(new MockTransport());
        $captured = null;
        installStubHandler($client, 'deleteReferences', function (array $refs) use (&$captured) {
            $captured = $refs;

            return [0];
        });

        expect($client->deleteReferences([['sourceNodeId' => 'i=85']]))->toBe([0]);
        expect($captured)->toBe([['sourceNodeId' => 'i=85']]);
    });
});
