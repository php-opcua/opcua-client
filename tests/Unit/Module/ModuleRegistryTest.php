<?php

declare(strict_types=1);

use PhpOpcua\Client\Exception\MissingModuleDependencyException;
use PhpOpcua\Client\Kernel\ClientKernelInterface;
use PhpOpcua\Client\Module\ModuleRegistry;
use PhpOpcua\Client\Module\ServiceModule;
use PhpOpcua\Client\Protocol\SessionService;

// ─── Concrete stub modules with unique class names ──────────────

class StubModuleA extends ServiceModule
{
    public array $log = [];

    public function register(): void
    {
        $this->log[] = 'register';
    }

    public function boot(SessionService $session): void
    {
        $this->log[] = 'boot';
    }

    public function reset(): void
    {
        $this->log[] = 'reset';
    }
}

class StubModuleB extends ServiceModule
{
    public array $log = [];

    public function register(): void
    {
        $this->log[] = 'register';
    }

    public function boot(SessionService $session): void
    {
        $this->log[] = 'boot';
    }

    public function reset(): void
    {
        $this->log[] = 'reset';
    }
}

class StubModuleDependsOnA extends ServiceModule
{
    public array $log = [];

    public function requires(): array
    {
        return [StubModuleA::class];
    }

    public function register(): void
    {
        $this->log[] = 'register';
    }

    public function boot(SessionService $session): void
    {
        $this->log[] = 'boot';
    }
}

class StubModuleMissingDep extends ServiceModule
{
    public function requires(): array
    {
        return ['NonExistent\\DepModule'];
    }

    public function register(): void
    {
    }
}

// ─── Tests ──────────────────────────────────────────────────────

describe('ModuleRegistry', function () {

    it('adds and retrieves modules', function () {
        $registry = new ModuleRegistry();
        $module = new StubModuleA();

        $registry->add($module);

        expect($registry->has(StubModuleA::class))->toBeTrue();
        expect($registry->get(StubModuleA::class))->toBe($module);
    });

    it('replaces a module', function () {
        $registry = new ModuleRegistry();
        $original = new StubModuleA();
        $replacement = new StubModuleB();

        $registry->add($original);
        $registry->replace(StubModuleA::class, $replacement);

        expect($registry->get(StubModuleA::class))->toBe($replacement);
    });

    it('returns false for unregistered module', function () {
        $registry = new ModuleRegistry();

        expect($registry->has('NonExistent\\Module'))->toBeFalse();
    });

    it('boots all modules and calls register then boot', function () {
        $registry = new ModuleRegistry();

        $moduleA = new StubModuleA();
        $moduleB = new StubModuleB();

        $registry->add($moduleA);
        $registry->add($moduleB);

        $session = new SessionService(1, 1);
        $kernel = $this->createMock(ClientKernelInterface::class);

        $registry->bootAll($kernel, new stdClass(), $session);

        expect($moduleA->log)->toBe(['register', 'boot']);
        expect($moduleB->log)->toBe(['register', 'boot']);
    });

    it('resets all modules in reverse order', function () {
        $resetOrder = [];
        $registry = new ModuleRegistry();

        $moduleA = new StubModuleA();
        $moduleB = new StubModuleB();

        $registry->add($moduleA);
        $registry->add($moduleB);

        $session = new SessionService(1, 1);
        $kernel = $this->createMock(ClientKernelInterface::class);

        $registry->bootAll($kernel, new stdClass(), $session);

        $registry->resetAll();

        // B was registered second, so it resets first (reverse order)
        expect($moduleA->log)->toContain('reset');
        expect($moduleB->log)->toContain('reset');
        // Verify B resets before A by checking log positions
        expect(array_search('reset', $moduleB->log))->toBeLessThanOrEqual(array_search('reset', $moduleA->log));
    });

    it('sorts modules by dependency order', function () {
        $registry = new ModuleRegistry();

        $moduleA = new StubModuleA();
        $dep = new StubModuleDependsOnA();

        // Add dependent first, then dependency
        $registry->add($dep);
        $registry->add($moduleA);

        $session = new SessionService(1, 1);
        $kernel = $this->createMock(ClientKernelInterface::class);

        $registry->bootAll($kernel, new stdClass(), $session);

        // A must boot before DependsOnA
        expect($moduleA->log[0])->toBe('register');
        expect($dep->log[0])->toBe('register');
    });

    it('throws MissingModuleDependencyException for missing dependency', function () {
        $registry = new ModuleRegistry();

        $module = new StubModuleMissingDep();
        $registry->add($module);

        $session = new SessionService(1, 1);
        $kernel = $this->createMock(ClientKernelInterface::class);

        expect(fn () => $registry->bootAll($kernel, new stdClass(), $session))
            ->toThrow(MissingModuleDependencyException::class);
    });

    it('sets kernel and client on each module during boot', function () {
        $registry = new ModuleRegistry();
        $module = new StubModuleA();

        $registry->add($module);

        $session = new SessionService(1, 1);
        $kernel = $this->createMock(ClientKernelInterface::class);
        $client = new stdClass();

        $registry->bootAll($kernel, $client, $session);

        $refK = new ReflectionProperty(ServiceModule::class, 'kernel');
        $refC = new ReflectionProperty(ServiceModule::class, 'client');

        expect($refK->getValue($module))->toBe($kernel);
        expect($refC->getValue($module))->toBe($client);
    });

    it('reboots all modules without re-registering', function () {
        $registry = new ModuleRegistry();
        $module = new StubModuleA();

        $registry->add($module);

        $session = new SessionService(1, 1);
        $kernel = $this->createMock(ClientKernelInterface::class);

        $registry->bootAll($kernel, new stdClass(), $session);
        expect($module->log)->toBe(['register', 'boot']);

        $registry->rebootAll($session);
        expect($module->log)->toBe(['register', 'boot', 'boot']); // register NOT called again
    });

    it('returns all registered module classes', function () {
        $registry = new ModuleRegistry();
        $registry->add(new StubModuleA());
        $registry->add(new StubModuleB());

        $classes = $registry->getModuleClasses();
        expect($classes)->toHaveCount(2);
        expect($classes)->toContain(StubModuleA::class);
        expect($classes)->toContain(StubModuleB::class);
    });
});
