<?php

declare(strict_types=1);

use PhpOpcua\Client\Module\Browse\BrowseModule;
use PhpOpcua\Client\Module\Browse\BrowseResultSet;
use PhpOpcua\Client\Module\Browse\BrowseService;
use PhpOpcua\Client\Module\Browse\GetEndpointsService;
use PhpOpcua\Client\Protocol\SessionService;

describe('BrowseModule', function () {

    it('registers 6 methods', function () {
        $module = new BrowseModule();

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

        expect($registeredMethods)->toBe(['browse', 'browseAll', 'browseRecursive', 'browseWithContinuation', 'browseNext', 'getEndpoints']);
    });

    it('boots protocol services', function () {
        $module = new BrowseModule();
        $session = new SessionService(1, 1);

        $module->boot($session);

        $ref = new ReflectionClass($module);

        $browseProp = $ref->getProperty('browseService');
        expect($browseProp->getValue($module))->toBeInstanceOf(BrowseService::class);

        $endpointsProp = $ref->getProperty('getEndpointsService');
        expect($endpointsProp->getValue($module))->toBeInstanceOf(GetEndpointsService::class);
    });

    it('resets protocol services to null', function () {
        $module = new BrowseModule();
        $session = new SessionService(1, 1);

        $module->boot($session);
        $module->reset();

        $ref = new ReflectionClass($module);

        $browseProp = $ref->getProperty('browseService');
        expect($browseProp->getValue($module))->toBeNull();

        $endpointsProp = $ref->getProperty('getEndpointsService');
        expect($endpointsProp->getValue($module))->toBeNull();
    });

    it('has no dependencies', function () {
        $module = new BrowseModule();
        expect($module->requires())->toBe([]);
    });
});

describe('Browse BrowseResultSet DTO', function () {

    it('stores all fields', function () {
        $result = new BrowseResultSet([], 'abc123');
        expect($result->references)->toBe([]);
        expect($result->continuationPoint)->toBe('abc123');
    });

    it('allows null continuation point', function () {
        $result = new BrowseResultSet([], null);
        expect($result->continuationPoint)->toBeNull();
    });

    it('is readonly', function () {
        $result = new BrowseResultSet([], null);
        $ref = new ReflectionClass($result);
        expect($ref->isReadOnly())->toBeTrue();
    });
});
