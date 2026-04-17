<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module;

use PhpOpcua\Client\Exception\MissingModuleDependencyException;
use PhpOpcua\Client\Kernel\ClientKernelInterface;
use PhpOpcua\Client\Protocol\SessionService;

/**
 * Manages the lifecycle of service modules: registration, dependency resolution,
 * boot, and reset.
 *
 * Modules are registered via {@see add()} and booted via {@see bootAll()}.
 * The registry resolves the dependency graph (topological sort) and boots modules
 * in the correct order. On disconnect, {@see resetAll()} resets modules in reverse order.
 */
class ModuleRegistry
{
    /** @var array<class-string<ServiceModule>, ServiceModule> */
    private array $modules = [];

    /** @var class-string<ServiceModule>[] Boot order (set after topological sort) */
    private array $bootOrder = [];

    /**
     * Register a module instance.
     *
     * @param ServiceModule $module
     * @return void
     */
    public function add(ServiceModule $module): void
    {
        $this->modules[get_class($module)] = $module;
    }

    /**
     * Replace a registered module with a new instance.
     *
     * The replacement does not need to be the same class, but it takes the slot
     * of the original. Other modules that declared a dependency on the original
     * class will find the replacement under that key.
     *
     * @param class-string<ServiceModule> $originalClass
     * @param ServiceModule $replacement
     * @return void
     */
    public function replace(string $originalClass, ServiceModule $replacement): void
    {
        $this->modules[$originalClass] = $replacement;
    }

    /**
     * Check whether a module class is registered.
     *
     * @param class-string<ServiceModule> $moduleClass
     * @return bool
     */
    public function has(string $moduleClass): bool
    {
        return isset($this->modules[$moduleClass]);
    }

    /**
     * Get a module instance by class name.
     *
     * @param class-string<ServiceModule> $moduleClass
     * @return ServiceModule
     */
    public function get(string $moduleClass): ServiceModule
    {
        return $this->modules[$moduleClass];
    }

    /**
     * Validate dependencies, topological sort, then boot all modules.
     *
     * For each module (in dependency order):
     * 1. setKernel() — inject the kernel
     * 2. setClient() — inject the client (for cross-module calls)
     * 3. register() — module registers its methods on the client
     * 4. boot() — module creates its protocol services
     *
     * @param ClientKernelInterface $kernel
     * @param object $client
     * @param SessionService $session
     * @return void
     *
     * @throws MissingModuleDependencyException If a required module is not registered.
     */
    public function bootAll(ClientKernelInterface $kernel, object $client, SessionService $session): void
    {
        $this->bootOrder = $this->topologicalSort();

        foreach ($this->bootOrder as $moduleClass) {
            $module = $this->modules[$moduleClass];
            $module->setKernel($kernel);
            $module->setClient($client);

            if (method_exists($client, 'setCurrentModuleClass')) {
                $client->setCurrentModuleClass($moduleClass);
            }

            $module->register();
            $module->boot($session);
        }
    }

    /**
     * Reset all modules in reverse boot order.
     *
     * @return void
     */
    public function resetAll(): void
    {
        foreach (array_reverse($this->bootOrder) as $moduleClass) {
            $this->modules[$moduleClass]->reset();
        }
    }

    /**
     * Re-boot all modules (after reconnect). Calls boot() on each module
     * in the original boot order without re-registering methods.
     *
     * @param SessionService $session
     * @return void
     */
    public function rebootAll(SessionService $session): void
    {
        foreach ($this->bootOrder as $moduleClass) {
            $this->modules[$moduleClass]->boot($session);
        }
    }

    /**
     * Get all registered module class names.
     *
     * @return class-string<ServiceModule>[]
     */
    public function getModuleClasses(): array
    {
        return array_keys($this->modules);
    }

    /**
     * Perform a topological sort on the module dependency graph.
     *
     * @return class-string<ServiceModule>[]
     *
     * @throws MissingModuleDependencyException If a dependency is not registered.
     */
    private function topologicalSort(): array
    {
        $visited = [];
        $sorted = [];

        foreach ($this->modules as $class => $module) {
            $this->visit($class, $visited, $sorted, []);
        }

        return $sorted;
    }

    /**
     * DFS visitor for topological sort.
     *
     * @param class-string<ServiceModule> $class
     * @param array<string, bool> $visited
     * @param class-string<ServiceModule>[] $sorted
     * @param array<string, bool> $stack Cycle detection
     * @return void
     *
     * @throws MissingModuleDependencyException
     */
    private function visit(string $class, array &$visited, array &$sorted, array $stack): void
    {
        if (isset($visited[$class])) {
            return;
        }

        if (isset($stack[$class])) {
            throw new MissingModuleDependencyException(
                "Circular dependency detected involving {$class}",
            );
        }

        $stack[$class] = true;

        $module = $this->modules[$class] ?? null;
        if ($module === null) {
            throw new MissingModuleDependencyException(
                "Module {$class} is not registered",
            );
        }

        foreach ($module->requires() as $dependency) {
            if (! isset($this->modules[$dependency])) {
                throw new MissingModuleDependencyException(
                    get_class($module) . " requires {$dependency}, but it is not registered",
                );
            }
            $this->visit($dependency, $visited, $sorted, $stack);
        }

        $visited[$class] = true;
        $sorted[] = $class;
    }
}
