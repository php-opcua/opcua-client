<?php

declare(strict_types=1);

use PhpOpcua\Client\Module\History\HistoryModule;
use PhpOpcua\Client\Module\History\HistoryReadService;
use PhpOpcua\Client\Protocol\SessionService;

describe('HistoryModule', function () {

    it('registers 3 methods', function () {
        $module = new HistoryModule();

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

        expect($registeredMethods)->toBe([
            'historyReadRaw',
            'historyReadProcessed',
            'historyReadAtTime',
        ]);
    });

    it('boots 1 protocol service', function () {
        $module = new HistoryModule();
        $session = new SessionService(1, 1);

        $module->boot($session);

        $ref = new ReflectionClass($module);

        $prop = $ref->getProperty('historyReadService');
        expect($prop->getValue($module))->toBeInstanceOf(HistoryReadService::class);
    });

    it('resets protocol services to null', function () {
        $module = new HistoryModule();
        $session = new SessionService(1, 1);

        $module->boot($session);
        $module->reset();

        $ref = new ReflectionClass($module);

        $prop = $ref->getProperty('historyReadService');
        expect($prop->getValue($module))->toBeNull();
    });

    it('has no dependencies', function () {
        $module = new HistoryModule();
        expect($module->requires())->toBe([]);
    });
});
