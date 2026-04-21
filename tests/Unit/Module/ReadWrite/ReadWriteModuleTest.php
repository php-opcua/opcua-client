<?php

declare(strict_types=1);

use PhpOpcua\Client\Module\ReadWrite\CallResult;
use PhpOpcua\Client\Module\ReadWrite\CallService;
use PhpOpcua\Client\Module\ReadWrite\ReadService;
use PhpOpcua\Client\Module\ReadWrite\ReadWriteModule;
use PhpOpcua\Client\Module\ReadWrite\WriteService;
use PhpOpcua\Client\Protocol\SessionService;

describe('ReadWriteModule', function () {

    it('registers 5 methods', function () {
        $module = new ReadWriteModule();

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

        $kernel = $this->createMock(PhpOpcua\Client\Kernel\ClientKernelInterface::class);
        $module->setKernel($kernel);
        $module->setClient($client);
        $module->register();

        expect($registeredMethods)->toBe(['read', 'readMulti', 'write', 'writeMulti', 'call']);
    });

    it('boots protocol services', function () {
        $module = new ReadWriteModule();
        $session = new SessionService(1, 1);

        $module->boot($session);

        $ref = new ReflectionClass($module);

        $readProp = $ref->getProperty('readService');
        expect($readProp->getValue($module))->toBeInstanceOf(ReadService::class);

        $writeProp = $ref->getProperty('writeService');
        expect($writeProp->getValue($module))->toBeInstanceOf(WriteService::class);

        $callProp = $ref->getProperty('callService');
        expect($callProp->getValue($module))->toBeInstanceOf(CallService::class);
    });

    it('resets protocol services to null', function () {
        $module = new ReadWriteModule();
        $session = new SessionService(1, 1);

        $module->boot($session);
        $module->reset();

        $ref = new ReflectionClass($module);

        $readProp = $ref->getProperty('readService');
        expect($readProp->getValue($module))->toBeNull();

        $writeProp = $ref->getProperty('writeService');
        expect($writeProp->getValue($module))->toBeNull();

        $callProp = $ref->getProperty('callService');
        expect($callProp->getValue($module))->toBeNull();
    });

    it('has no dependencies', function () {
        $module = new ReadWriteModule();
        expect($module->requires())->toBe([]);
    });
});

describe('ReadWrite CallResult DTO', function () {

    it('stores all fields', function () {
        $result = new CallResult(0, [0, 0], []);
        expect($result->statusCode)->toBe(0);
        expect($result->inputArgumentResults)->toBe([0, 0]);
        expect($result->outputArguments)->toBe([]);
    });

    it('is readonly', function () {
        $result = new CallResult(0, [], []);
        $ref = new ReflectionClass($result);
        expect($ref->isReadOnly())->toBeTrue();
    });
});
