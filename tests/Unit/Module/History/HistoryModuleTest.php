<?php

declare(strict_types=1);

use PhpOpcua\Client\Module\History\HistoryModule;
use PhpOpcua\Client\Module\History\HistoryReadService;
use PhpOpcua\Client\Module\History\HistoryUpdateService;
use PhpOpcua\Client\Protocol\SessionService;

describe('HistoryModule', function () {

    it('registers 12 methods', function () {
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
            'historyInsertData',
            'historyReplaceData',
            'historyUpdateData',
            'historyDeleteRawModified',
            'historyDeleteAtTime',
            'historyInsertEvent',
            'historyReplaceEvent',
            'historyUpdateEvent',
            'historyDeleteEvent',
        ]);
    });

    it('boots 2 protocol services', function () {
        $module = new HistoryModule();
        $session = new SessionService(1, 1);

        $module->boot($session);

        $ref = new ReflectionClass($module);

        expect($ref->getProperty('historyReadService')->getValue($module))
            ->toBeInstanceOf(HistoryReadService::class);
        expect($ref->getProperty('historyUpdateService')->getValue($module))
            ->toBeInstanceOf(HistoryUpdateService::class);
    });

    it('resets protocol services to null', function () {
        $module = new HistoryModule();
        $session = new SessionService(1, 1);

        $module->boot($session);
        $module->reset();

        $ref = new ReflectionClass($module);

        expect($ref->getProperty('historyReadService')->getValue($module))->toBeNull();
        expect($ref->getProperty('historyUpdateService')->getValue($module))->toBeNull();
    });

    it('has no dependencies', function () {
        $module = new HistoryModule();
        expect($module->requires())->toBe([]);
    });
});
