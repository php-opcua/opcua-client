<?php

declare(strict_types=1);

use PhpOpcua\Client\Module\NodeManagement\AddNodesResult;
use PhpOpcua\Client\Module\NodeManagement\NodeManagementModule;
use PhpOpcua\Client\Module\NodeManagement\NodeManagementService;
use PhpOpcua\Client\Protocol\SessionService;

describe('NodeManagementModule', function () {

    it('registers 4 methods', function () {
        $module = new NodeManagementModule();

        $registeredMethods = [];
        $client = new class($registeredMethods) {
            public function __construct(private array &$methods)
            {
            }

            public function registerMethod(string $name, callable $handler): void
            {
                $this->methods[] = $name;
            }
        };

        $kernel = $this->createMock(PhpOpcua\Client\Kernel\ClientKernel::class);
        $module->setKernel($kernel);
        $module->setClient($client);
        $module->register();

        expect($registeredMethods)->toBe(['addNodes', 'deleteNodes', 'addReferences', 'deleteReferences']);
    });

    it('boots protocol service', function () {
        $module = new NodeManagementModule();
        $session = new SessionService(1, 1);

        $module->boot($session);

        $ref = new ReflectionClass($module);

        $serviceProp = $ref->getProperty('service');
        expect($serviceProp->getValue($module))->toBeInstanceOf(NodeManagementService::class);
    });

    it('resets protocol service to null', function () {
        $module = new NodeManagementModule();
        $session = new SessionService(1, 1);

        $module->boot($session);
        $module->reset();

        $ref = new ReflectionClass($module);

        $serviceProp = $ref->getProperty('service');
        expect($serviceProp->getValue($module))->toBeNull();
    });

    it('has no dependencies', function () {
        $module = new NodeManagementModule();
        expect($module->requires())->toBe([]);
    });
});

describe('NodeManagement AddNodesResult DTO', function () {

    it('stores all fields', function () {
        $nodeId = PhpOpcua\Client\Types\NodeId::numeric(0, 1000);
        $result = new AddNodesResult(0, $nodeId);
        expect($result->statusCode)->toBe(0);
        expect($result->addedNodeId)->toBe($nodeId);
    });

    it('is readonly', function () {
        $nodeId = PhpOpcua\Client\Types\NodeId::numeric(0, 1000);
        $result = new AddNodesResult(0, $nodeId);
        $ref = new ReflectionClass($result);
        expect($ref->isReadOnly())->toBeTrue();
    });
});
