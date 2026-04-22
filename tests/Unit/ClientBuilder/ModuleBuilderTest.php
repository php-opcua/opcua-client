<?php

declare(strict_types=1);

use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Module\Browse\BrowseModule;
use PhpOpcua\Client\Module\History\HistoryModule;
use PhpOpcua\Client\Module\ModuleRegistry;
use PhpOpcua\Client\Module\NodeManagement\NodeManagementModule;
use PhpOpcua\Client\Module\ReadWrite\ReadWriteModule;
use PhpOpcua\Client\Module\ServerInfo\ServerInfoModule;
use PhpOpcua\Client\Module\ServiceModule;
use PhpOpcua\Client\Module\Subscription\SubscriptionModule;
use PhpOpcua\Client\Module\TranslateBrowsePath\TranslateBrowsePathModule;
use PhpOpcua\Client\Module\TypeDiscovery\TypeDiscoveryModule;

// ─── Concrete stub module for builder tests ───────────────────────

class BuilderTestCustomModule extends ServiceModule
{
    public function register(): void
    {
        $this->client->registerMethod('builderTestOp', fn () => 'custom');
    }
}

class BuilderTestReplacementModule extends ServiceModule
{
    public function register(): void
    {
        $this->client->registerMethod('read', fn () => 'replaced');
        $this->client->registerMethod('readMulti', fn () => 'replaced');
        $this->client->registerMethod('write', fn () => 'replaced');
        $this->client->registerMethod('writeMulti', fn () => 'replaced');
        $this->client->registerMethod('call', fn () => 'replaced');
    }
}

// ─── Tests ────────────────────────────────────────────────────────

describe('ClientBuilder module configuration', function () {

    it('registers all 8 default built-in modules', function () {
        $builder = ClientBuilder::create();

        $ref = new ReflectionProperty(ClientBuilder::class, 'moduleRegistry');
        /** @var ModuleRegistry $registry */
        $registry = $ref->getValue($builder);

        $expectedModules = [
            ReadWriteModule::class,
            BrowseModule::class,
            SubscriptionModule::class,
            HistoryModule::class,
            NodeManagementModule::class,
            TranslateBrowsePathModule::class,
            ServerInfoModule::class,
            TypeDiscoveryModule::class,
        ];

        foreach ($expectedModules as $moduleClass) {
            expect($registry->has($moduleClass))->toBeTrue("Expected module {$moduleClass} to be registered");
        }

        expect($registry->getModuleClasses())->toHaveCount(8);
    });

    it('addModule adds a custom module to the registry', function () {
        $builder = ClientBuilder::create();
        $custom = new BuilderTestCustomModule();

        $result = $builder->addModule($custom);

        expect($result)->toBe($builder);

        $ref = new ReflectionProperty(ClientBuilder::class, 'moduleRegistry');
        /** @var ModuleRegistry $registry */
        $registry = $ref->getValue($builder);

        expect($registry->has(BuilderTestCustomModule::class))->toBeTrue();
        expect($registry->getModuleClasses())->toHaveCount(9);
    });

    it('replaceModule swaps a built-in module', function () {
        $builder = ClientBuilder::create();
        $replacement = new BuilderTestReplacementModule();

        $result = $builder->replaceModule(ReadWriteModule::class, $replacement);

        expect($result)->toBe($builder);

        $ref = new ReflectionProperty(ClientBuilder::class, 'moduleRegistry');
        /** @var ModuleRegistry $registry */
        $registry = $ref->getValue($builder);

        expect($registry->has(ReadWriteModule::class))->toBeTrue();
        expect($registry->get(ReadWriteModule::class))->toBe($replacement);
        expect($registry->getModuleClasses())->toHaveCount(8);
    });

    it('addModule returns builder for fluent chaining', function () {
        $builder = ClientBuilder::create();
        $custom = new BuilderTestCustomModule();

        $returned = $builder->addModule($custom);

        expect($returned)->toBeInstanceOf(ClientBuilder::class);
        expect($returned)->toBe($builder);
    });

    it('replaceModule returns builder for fluent chaining', function () {
        $builder = ClientBuilder::create();
        $replacement = new BuilderTestReplacementModule();

        $returned = $builder->replaceModule(ReadWriteModule::class, $replacement);

        expect($returned)->toBeInstanceOf(ClientBuilder::class);
        expect($returned)->toBe($builder);
    });

    it('each new builder gets its own module registry', function () {
        $builder1 = ClientBuilder::create();
        $builder2 = ClientBuilder::create();

        $ref = new ReflectionProperty(ClientBuilder::class, 'moduleRegistry');

        $registry1 = $ref->getValue($builder1);
        $registry2 = $ref->getValue($builder2);

        expect($registry1)->not->toBe($registry2);
    });
});
