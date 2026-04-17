<?php

declare(strict_types=1);

use PhpOpcua\Client\Module\ReadWrite\ReadWriteModule;
use PhpOpcua\Client\Module\TypeDiscovery\TypeDiscoveryModule;

describe('TypeDiscoveryModule', function () {

    it('registers 2 methods', function () {
        $module = new TypeDiscoveryModule();

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

        expect($registeredMethods)->toBe(['discoverDataTypes', 'registerTypeCodec']);
    });

    it('requires ReadWriteModule and BrowseModule', function () {
        $module = new TypeDiscoveryModule();
        $requires = $module->requires();
        expect($requires[0])->toBe(ReadWriteModule::class);
        expect($requires[1])->toBe('PhpOpcua\Client\Module\Browse\BrowseModule');
        expect($requires)->toHaveCount(2);
    });
});
