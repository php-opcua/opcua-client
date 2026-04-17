<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module;

use PhpOpcua\Client\Kernel\ClientKernelInterface;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Wire\WireTypeRegistry;

/**
 * Base class for all OPC UA service modules.
 *
 * A module is a self-contained unit that provides one or more client methods
 * (e.g., read, write, browse). Modules register their methods on the Client
 * during {@see register()}, create protocol services during {@see boot()},
 * and clean up during {@see reset()}.
 *
 * @see ModuleRegistry
 */
abstract class ServiceModule
{
    protected ClientKernelInterface $kernel;

    /**
     * The Client instance. Used for cross-module calls (e.g., ServerInfoModule
     * calling $this->client->readMulti() which is provided by ReadWriteModule).
     *
     * @var object
     */
    protected object $client;

    /**
     * @param ClientKernelInterface $kernel
     */
    public function setKernel(ClientKernelInterface $kernel): void
    {
        $this->kernel = $kernel;
    }

    /**
     * @param object $client
     */
    public function setClient(object $client): void
    {
        $this->client = $client;
    }

    /**
     * Return the module classes that this module depends on.
     *
     * The ModuleRegistry guarantees that required modules are registered and
     * booted before this module. If a required module is missing, a
     * {@see \PhpOpcua\Client\Exception\MissingModuleDependencyException} is thrown.
     *
     * @return array<class-string<ServiceModule>>
     */
    public function requires(): array
    {
        return [];
    }

    /**
     * Register methods on the Client.
     *
     * Called once during module initialization. Use
     * `$this->client->registerMethod('name', $this->method(...))` to inject
     * methods onto the Client.
     *
     * @return void
     */
    abstract public function register(): void;

    /**
     * Initialize protocol services after the secure channel and session are established.
     *
     * @param SessionService $session
     * @return void
     */
    public function boot(SessionService $session): void
    {
    }

    /**
     * Clean up on disconnect. Reset protocol services to null.
     *
     * @return void
     */
    public function reset(): void
    {
    }

    /**
     * Register the module's DTOs and enums on the shared {@see WireTypeRegistry}
     * so that remote peers can safely encode/decode them over IPC.
     *
     * @param WireTypeRegistry $registry
     * @return void
     */
    public function registerWireTypes(WireTypeRegistry $registry): void
    {
    }
}
