<?php

declare(strict_types=1);

use PhpOpcua\Client\Cache\InMemoryCache;
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Repository\ExtensionObjectRepository;
use PhpOpcua\Client\Repository\GeneratedTypeRegistrar;
use PhpOpcua\Client\Types\BuiltinType;

describe('ClientBuilder: create factory', function () {
    it('creates a builder via static factory', function () {
        expect(ClientBuilder::create())->toBeInstanceOf(ClientBuilder::class);
    });

    it('creates a builder with custom repository and logger', function () {
        $repo = new ExtensionObjectRepository();
        $logger = new Psr\Log\NullLogger();
        expect(ClientBuilder::create($repo, $logger))->toBeInstanceOf(ClientBuilder::class);
    });
});

describe('ClientBuilder: ManagesCacheTrait', function () {
    it('setCache stores cache and getCache returns it', function () {
        $builder = ClientBuilder::create();
        $cache = new InMemoryCache(60);
        expect($builder->setCache($cache))->toBe($builder);
        expect($builder->getCache())->toBe($cache);
    });

    it('setCache(null) disables caching', function () {
        $builder = ClientBuilder::create();
        $builder->setCache(null);
        expect($builder->getCache())->toBeNull();
    });

    it('getCache returns default InMemoryCache when not configured', function () {
        expect(ClientBuilder::create()->getCache())->toBeInstanceOf(InMemoryCache::class);
    });
});

describe('ClientBuilder: ManagesReadWriteConfigTrait', function () {
    it('loadGeneratedTypes registers codecs and enum mappings', function () {
        $builder = ClientBuilder::create();

        $registrar = new class() implements GeneratedTypeRegistrar {
            public bool $registered = false;
            public function registerCodecs(ExtensionObjectRepository $repository): void { $this->registered = true; }
            public function getEnumMappings(): array { return ['ns=2;i=100' => BuiltinType::class]; }
            public function dependencyRegistrars(): array { return []; }
        };

        expect($builder->loadGeneratedTypes($registrar))->toBe($builder);
        expect($registrar->registered)->toBeTrue();
    });

    it('loadGeneratedTypes loads dependencies recursively', function () {
        $builder = ClientBuilder::create();

        $depRegistrar = new class() implements GeneratedTypeRegistrar {
            public bool $registered = false;
            public function registerCodecs(ExtensionObjectRepository $repository): void { $this->registered = true; }
            public function getEnumMappings(): array { return []; }
            public function dependencyRegistrars(): array { return []; }
        };

        $mainRegistrar = new class($depRegistrar) implements GeneratedTypeRegistrar {
            public bool $registered = false;
            public function __construct(private GeneratedTypeRegistrar $dep) {}
            public function registerCodecs(ExtensionObjectRepository $repository): void { $this->registered = true; }
            public function getEnumMappings(): array { return []; }
            public function dependencyRegistrars(): array { return [$this->dep]; }
        };

        $builder->loadGeneratedTypes($mainRegistrar);
        expect($mainRegistrar->registered)->toBeTrue();
        expect($depRegistrar->registered)->toBeTrue();
    });
});
