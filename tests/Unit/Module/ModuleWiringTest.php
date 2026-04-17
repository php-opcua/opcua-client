<?php

declare(strict_types=1);

use PhpOpcua\Client\Exception\MissingModuleDependencyException;
use PhpOpcua\Client\Exception\ModuleConflictException;
use PhpOpcua\Client\Kernel\ClientKernel;
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
use PhpOpcua\Client\Protocol\SessionService;

// ─── Concrete stub modules for wiring tests ───────────────────────

class WiringStubIndependentModule extends ServiceModule
{
    /** @var string[] */
    public array $bootLog = [];

    public function register(): void
    {
        $this->client->registerMethod('independentOp', fn () => 'independent');
        $this->bootLog[] = 'register';
    }

    public function boot(SessionService $session): void
    {
        $this->bootLog[] = 'boot';
    }

    public function reset(): void
    {
        $this->bootLog[] = 'reset';
    }
}

class WiringStubDependentModule extends ServiceModule
{
    /** @var string[] */
    public array $bootLog = [];

    /**
     * @return array<class-string<ServiceModule>>
     */
    public function requires(): array
    {
        return [WiringStubIndependentModule::class];
    }

    public function register(): void
    {
        $this->client->registerMethod('dependentOp', fn () => 'dependent');
        $this->bootLog[] = 'register';
    }

    public function boot(SessionService $session): void
    {
        $this->bootLog[] = 'boot';
    }

    public function reset(): void
    {
        $this->bootLog[] = 'reset';
    }
}

class WiringStubConflictModuleA extends ServiceModule
{
    public function register(): void
    {
        $this->client->registerMethod('read', fn () => 'conflict-a');
    }
}

class WiringStubConflictModuleB extends ServiceModule
{
    public function register(): void
    {
        $this->client->registerMethod('read', fn () => 'conflict-b');
    }
}

class WiringStubMissingDepModule extends ServiceModule
{
    /**
     * @return array<class-string<ServiceModule>>
     */
    public function requires(): array
    {
        return ['NonExistent\\FakeModule'];
    }

    public function register(): void
    {
    }
}

class WiringStubReplacementModule extends ServiceModule
{
    public function register(): void
    {
        $this->client->registerMethod('read', fn () => 'replaced-read');
        $this->client->registerMethod('readMulti', fn () => 'replaced-readMulti');
        $this->client->registerMethod('write', fn () => 'replaced-write');
        $this->client->registerMethod('writeMulti', fn () => 'replaced-writeMulti');
        $this->client->registerMethod('call', fn () => 'replaced-call');
    }
}

// ─── Helper: creates a mock client object that tracks registerMethod calls ──

/**
 * @param array<string, callable> &$handlers
 * @return object
 */
function createMethodTrackingClient(array &$handlers = []): object
{
    return new class($handlers) {
        /** @var array<string, callable> */
        private array $handlers;

        /** @var array<string, string> */
        private array $owners = [];

        private string $currentModuleClass = '';

        /**
         * @param array<string, callable> $handlers
         */
        public function __construct(array &$handlers)
        {
            $this->handlers = &$handlers;
        }

        /**
         * @param string $name
         * @param callable $handler
         * @return void
         *
         * @throws ModuleConflictException
         */
        public function registerMethod(string $name, callable $handler): void
        {
            if (isset($this->handlers[$name])) {
                throw new ModuleConflictException(
                    "Method '{$name}' is already registered by {$this->owners[$name]}",
                );
            }
            $this->handlers[$name] = $handler;
            $this->owners[$name] = $this->currentModuleClass;
        }

        /**
         * @param string $class
         * @return void
         */
        public function setCurrentModuleClass(string $class): void
        {
            $this->currentModuleClass = $class;
        }
    };
}

// ─── Tests ────────────────────────────────────────────────────────

describe('Module wiring', function () {

    it('boots modules in correct dependency order', function () {
        $registry = new ModuleRegistry();

        $dependent = new WiringStubDependentModule();
        $independent = new WiringStubIndependentModule();

        $registry->add($dependent);
        $registry->add($independent);

        $handlers = [];
        $client = createMethodTrackingClient($handlers);
        $kernel = $this->createMock(ClientKernel::class);
        $session = new SessionService(1, 1);

        $registry->bootAll($kernel, $client, $session);

        expect($independent->bootLog[0])->toBe('register');
        expect($independent->bootLog[1])->toBe('boot');
        expect($dependent->bootLog[0])->toBe('register');
        expect($dependent->bootLog[1])->toBe('boot');

        expect($handlers)->toHaveKeys(['independentOp', 'dependentOp']);
    });

    it('detects method conflict between modules', function () {
        $registry = new ModuleRegistry();

        $moduleA = new WiringStubConflictModuleA();
        $moduleB = new WiringStubConflictModuleB();

        $registry->add($moduleA);
        $registry->add($moduleB);

        $handlers = [];
        $client = createMethodTrackingClient($handlers);
        $kernel = $this->createMock(ClientKernel::class);
        $session = new SessionService(1, 1);

        expect(fn () => $registry->bootAll($kernel, $client, $session))
            ->toThrow(ModuleConflictException::class);
    });

    it('detects missing module dependency', function () {
        $registry = new ModuleRegistry();

        $module = new WiringStubMissingDepModule();
        $registry->add($module);

        $handlers = [];
        $client = createMethodTrackingClient($handlers);
        $kernel = $this->createMock(ClientKernel::class);
        $session = new SessionService(1, 1);

        expect(fn () => $registry->bootAll($kernel, $client, $session))
            ->toThrow(MissingModuleDependencyException::class);
    });

    it('replaces a module and uses the replacement', function () {
        $registry = new ModuleRegistry();

        $original = new ReadWriteModule();
        $replacement = new WiringStubReplacementModule();

        $registry->add($original);
        $registry->replace(ReadWriteModule::class, $replacement);

        $handlers = [];
        $client = createMethodTrackingClient($handlers);
        $kernel = $this->createMock(ClientKernel::class);
        $session = new SessionService(1, 1);

        $registry->bootAll($kernel, $client, $session);

        expect($handlers)->toHaveKey('read');
        expect(($handlers['read'])())->toBe('replaced-read');
    });

    it('resets all modules in reverse boot order', function () {
        $registry = new ModuleRegistry();

        $independent = new WiringStubIndependentModule();
        $dependent = new WiringStubDependentModule();

        $registry->add($dependent);
        $registry->add($independent);

        $handlers = [];
        $client = createMethodTrackingClient($handlers);
        $kernel = $this->createMock(ClientKernel::class);
        $session = new SessionService(1, 1);

        $registry->bootAll($kernel, $client, $session);

        $registry->resetAll();

        expect($independent->bootLog)->toContain('reset');
        expect($dependent->bootLog)->toContain('reset');
    });
});

describe('Built-in modules register expected methods', function () {

    it('ReadWriteModule registers read, readMulti, write, writeMulti, call', function () {
        $module = new ReadWriteModule();

        $registeredMethods = [];
        $client = new class($registeredMethods) {
            /**
             * @param string[] $methods
             */
            public function __construct(private array &$methods)
            {
            }

            /**
             * @param string $name
             * @param callable $handler
             * @return void
             */
            public function registerMethod(string $name, callable $handler): void
            {
                $this->methods[] = $name;
            }
        };

        $kernel = $this->createMock(ClientKernel::class);
        $module->setKernel($kernel);
        $module->setClient($client);
        $module->register();

        expect($registeredMethods)->toBe(['read', 'readMulti', 'write', 'writeMulti', 'call']);
    });

    it('BrowseModule registers browse, browseAll, browseRecursive, browseWithContinuation, browseNext, getEndpoints', function () {
        $module = new BrowseModule();

        $registeredMethods = [];
        $client = new class($registeredMethods) {
            /**
             * @param string[] $methods
             */
            public function __construct(private array &$methods)
            {
            }

            /**
             * @param string $name
             * @param callable $handler
             * @return void
             */
            public function registerMethod(string $name, callable $handler): void
            {
                $this->methods[] = $name;
            }
        };

        $kernel = $this->createMock(ClientKernel::class);
        $module->setKernel($kernel);
        $module->setClient($client);
        $module->register();

        expect($registeredMethods)->toBe([
            'browse',
            'browseAll',
            'browseRecursive',
            'browseWithContinuation',
            'browseNext',
            'getEndpoints',
        ]);
    });

    it('ServerInfoModule registers server info methods', function () {
        $module = new ServerInfoModule();

        $registeredMethods = [];
        $client = new class($registeredMethods) {
            /**
             * @param string[] $methods
             */
            public function __construct(private array &$methods)
            {
            }

            /**
             * @param string $name
             * @param callable $handler
             * @return void
             */
            public function registerMethod(string $name, callable $handler): void
            {
                $this->methods[] = $name;
            }
        };

        $kernel = $this->createMock(ClientKernel::class);
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

    it('SubscriptionModule registers subscription methods', function () {
        $module = new SubscriptionModule();

        $registeredMethods = [];
        $client = new class($registeredMethods) {
            /**
             * @param string[] $methods
             */
            public function __construct(private array &$methods)
            {
            }

            /**
             * @param string $name
             * @param callable $handler
             * @return void
             */
            public function registerMethod(string $name, callable $handler): void
            {
                $this->methods[] = $name;
            }
        };

        $kernel = $this->createMock(ClientKernel::class);
        $module->setKernel($kernel);
        $module->setClient($client);
        $module->register();

        expect($registeredMethods)->toBe([
            'createSubscription',
            'createMonitoredItems',
            'createEventMonitoredItem',
            'deleteMonitoredItems',
            'modifyMonitoredItems',
            'setTriggering',
            'deleteSubscription',
            'publish',
            'republish',
            'transferSubscriptions',
        ]);
    });

    it('HistoryModule registers history methods', function () {
        $module = new HistoryModule();

        $registeredMethods = [];
        $client = new class($registeredMethods) {
            /**
             * @param string[] $methods
             */
            public function __construct(private array &$methods)
            {
            }

            /**
             * @param string $name
             * @param callable $handler
             * @return void
             */
            public function registerMethod(string $name, callable $handler): void
            {
                $this->methods[] = $name;
            }
        };

        $kernel = $this->createMock(ClientKernel::class);
        $module->setKernel($kernel);
        $module->setClient($client);
        $module->register();

        expect($registeredMethods)->toBe([
            'historyReadRaw',
            'historyReadProcessed',
            'historyReadAtTime',
        ]);
    });

    it('NodeManagementModule registers node management methods', function () {
        $module = new NodeManagementModule();

        $registeredMethods = [];
        $client = new class($registeredMethods) {
            /**
             * @param string[] $methods
             */
            public function __construct(private array &$methods)
            {
            }

            /**
             * @param string $name
             * @param callable $handler
             * @return void
             */
            public function registerMethod(string $name, callable $handler): void
            {
                $this->methods[] = $name;
            }
        };

        $kernel = $this->createMock(ClientKernel::class);
        $module->setKernel($kernel);
        $module->setClient($client);
        $module->register();

        expect($registeredMethods)->toBe([
            'addNodes',
            'deleteNodes',
            'addReferences',
            'deleteReferences',
        ]);
    });

    it('TranslateBrowsePathModule registers translate methods', function () {
        $module = new TranslateBrowsePathModule();

        $registeredMethods = [];
        $client = new class($registeredMethods) {
            /**
             * @param string[] $methods
             */
            public function __construct(private array &$methods)
            {
            }

            /**
             * @param string $name
             * @param callable $handler
             * @return void
             */
            public function registerMethod(string $name, callable $handler): void
            {
                $this->methods[] = $name;
            }
        };

        $kernel = $this->createMock(ClientKernel::class);
        $module->setKernel($kernel);
        $module->setClient($client);
        $module->register();

        expect($registeredMethods)->toBe([
            'translateBrowsePaths',
            'resolveNodeId',
        ]);
    });

    it('TypeDiscoveryModule registers discovery methods', function () {
        $module = new TypeDiscoveryModule();

        $registeredMethods = [];
        $client = new class($registeredMethods) {
            /**
             * @param string[] $methods
             */
            public function __construct(private array &$methods)
            {
            }

            /**
             * @param string $name
             * @param callable $handler
             * @return void
             */
            public function registerMethod(string $name, callable $handler): void
            {
                $this->methods[] = $name;
            }
        };

        $kernel = $this->createMock(ClientKernel::class);
        $module->setKernel($kernel);
        $module->setClient($client);
        $module->register();

        expect($registeredMethods)->toBe([
            'discoverDataTypes',
            'registerTypeCodec',
        ]);
    });
});
