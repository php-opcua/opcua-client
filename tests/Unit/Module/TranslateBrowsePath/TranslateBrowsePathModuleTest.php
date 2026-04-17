<?php

declare(strict_types=1);

use PhpOpcua\Client\Module\TranslateBrowsePath\BrowsePathResult;
use PhpOpcua\Client\Module\TranslateBrowsePath\BrowsePathTarget;
use PhpOpcua\Client\Module\TranslateBrowsePath\TranslateBrowsePathModule;
use PhpOpcua\Client\Module\TranslateBrowsePath\TranslateBrowsePathService;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Types\NodeId;

describe('TranslateBrowsePathModule', function () {

    it('registers 2 methods', function () {
        $module = new TranslateBrowsePathModule();

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

        expect($registeredMethods)->toBe(['translateBrowsePaths', 'resolveNodeId']);
    });

    it('boots protocol services', function () {
        $module = new TranslateBrowsePathModule();
        $session = new SessionService(1, 1);

        $module->boot($session);

        $ref = new ReflectionClass($module);

        $serviceProp = $ref->getProperty('translateBrowsePathService');
        expect($serviceProp->getValue($module))->toBeInstanceOf(TranslateBrowsePathService::class);
    });

    it('resets protocol services to null', function () {
        $module = new TranslateBrowsePathModule();
        $session = new SessionService(1, 1);

        $module->boot($session);
        $module->reset();

        $ref = new ReflectionClass($module);

        $serviceProp = $ref->getProperty('translateBrowsePathService');
        expect($serviceProp->getValue($module))->toBeNull();
    });

    it('has no dependencies', function () {
        $module = new TranslateBrowsePathModule();
        expect($module->requires())->toBe([]);
    });
});

describe('TranslateBrowsePath BrowsePathResult DTO', function () {

    it('stores all fields', function () {
        $target = new BrowsePathTarget(NodeId::numeric(0, 85), 0);
        $result = new BrowsePathResult(0, [$target]);
        expect($result->statusCode)->toBe(0);
        expect($result->targets)->toHaveCount(1);
        expect($result->targets[0]->targetId)->toEqual(NodeId::numeric(0, 85));
        expect($result->targets[0]->remainingPathIndex)->toBe(0);
    });

    it('is readonly', function () {
        $result = new BrowsePathResult(0, []);
        $ref = new ReflectionClass($result);
        expect($ref->isReadOnly())->toBeTrue();
    });
});

describe('TranslateBrowsePath BrowsePathTarget DTO', function () {

    it('stores all fields', function () {
        $target = new BrowsePathTarget(NodeId::numeric(2, 1234), 5);
        expect($target->targetId)->toEqual(NodeId::numeric(2, 1234));
        expect($target->remainingPathIndex)->toBe(5);
    });

    it('is readonly', function () {
        $target = new BrowsePathTarget(NodeId::numeric(0, 1), 0);
        $ref = new ReflectionClass($target);
        expect($ref->isReadOnly())->toBeTrue();
    });
});
