<?php

declare(strict_types=1);

use PhpOpcua\Client\Module\ReadWrite\ReadWriteModule;
use PhpOpcua\Client\Module\ServerInfo\BuildInfo;
use PhpOpcua\Client\Module\ServerInfo\ServerInfoModule;

describe('ServerInfoModule', function () {

    it('registers 6 methods', function () {
        $module = new ServerInfoModule();

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

        expect($registeredMethods)->toBe([
            'getServerProductName',
            'getServerManufacturerName',
            'getServerSoftwareVersion',
            'getServerBuildNumber',
            'getServerBuildDate',
            'getServerBuildInfo',
        ]);
    });

    it('requires ReadWriteModule', function () {
        $module = new ServerInfoModule();
        expect($module->requires())->toBe([ReadWriteModule::class]);
    });
});

describe('ServerInfo BuildInfo DTO', function () {

    it('stores all fields', function () {
        $date = new DateTimeImmutable('2025-01-01');
        $info = new BuildInfo('Product', 'Manufacturer', '1.0.0', '42', $date);
        expect($info->productName)->toBe('Product');
        expect($info->manufacturerName)->toBe('Manufacturer');
        expect($info->softwareVersion)->toBe('1.0.0');
        expect($info->buildNumber)->toBe('42');
        expect($info->buildDate)->toBe($date);
    });

    it('accepts null values', function () {
        $info = new BuildInfo(null, null, null, null, null);
        expect($info->productName)->toBeNull();
        expect($info->manufacturerName)->toBeNull();
        expect($info->softwareVersion)->toBeNull();
        expect($info->buildNumber)->toBeNull();
        expect($info->buildDate)->toBeNull();
    });

    it('is readonly', function () {
        $info = new BuildInfo(null, null, null, null, null);
        $ref = new ReflectionClass($info);
        expect($ref->isReadOnly())->toBeTrue();
    });
});
