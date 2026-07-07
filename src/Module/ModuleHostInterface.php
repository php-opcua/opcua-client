<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module;

/**
 * Contract for the host client that modules attach their methods to.
 *
 * During {@see ServiceModule::register()} each module injects its public
 * methods onto the host via {@see self::registerMethod()}.
 */
interface ModuleHostInterface
{
    /**
     * Register a callable under the given method name, making it available
     * as a dynamic client method.
     */
    public function registerMethod(string $name, callable $handler): void;
}
