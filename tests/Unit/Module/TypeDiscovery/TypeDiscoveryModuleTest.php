<?php

declare(strict_types=1);

use PhpOpcua\Client\Encoding\DynamicCodec;
use PhpOpcua\Client\Module\ReadWrite\ReadWriteModule;
use PhpOpcua\Client\Module\TypeDiscovery\TypeDiscoveryModule;
use PhpOpcua\Client\Repository\ExtensionObjectRepository;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StructureDefinition;
use PhpOpcua\Client\Types\StructureField;

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

        $kernel = $this->createMock(PhpOpcua\Client\Kernel\ClientKernelInterface::class);
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

    it('registerTypeCodec forwards to the kernel ExtensionObjectRepository', function () {
        $module = new TypeDiscoveryModule();
        $repository = new ExtensionObjectRepository();

        $kernel = $this->createMock(PhpOpcua\Client\Kernel\ClientKernelInterface::class);
        $kernel->method('getExtensionObjectRepository')->willReturn($repository);

        $module->setKernel($kernel);

        $encodingId = NodeId::numeric(2, 5001);
        $definition = new StructureDefinition(
            StructureDefinition::STRUCTURE,
            [new StructureField('X', NodeId::numeric(0, 11), -1, false)],
            $encodingId,
        );
        $codec = new DynamicCodec($definition);

        $module->registerTypeCodec($encodingId, $codec);

        expect($repository->has($encodingId))->toBeTrue();
    });
});
