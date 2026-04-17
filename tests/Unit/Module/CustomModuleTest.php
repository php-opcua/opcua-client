<?php

declare(strict_types=1);

use PhpOpcua\Client\Exception\ModuleConflictException;
use PhpOpcua\Client\Module\ServiceModule;

// ─── Concrete custom module stubs ─────────────────────────────────

class CustomGreetingModule extends ServiceModule
{
    public function register(): void
    {
        $this->client->registerMethod('greet', $this->greet(...));
        $this->client->registerMethod('farewell', $this->farewell(...));
    }

    /**
     * @param string $name
     * @return string
     */
    public function greet(string $name): string
    {
        return "Hello, {$name}!";
    }

    /**
     * @param string $name
     * @return string
     */
    public function farewell(string $name): string
    {
        return "Goodbye, {$name}!";
    }
}

class CustomMathModule extends ServiceModule
{
    public function register(): void
    {
        $this->client->registerMethod('add', $this->add(...));
    }

    /**
     * @param int $a
     * @param int $b
     * @return int
     */
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
}

// ─── Helper: a minimal client-like object that mimics registerMethod + __call ─

class FakeClientForCustomModuleTest
{
    /** @var array<string, callable> */
    private array $methodHandlers = [];

    /** @var array<string, string> */
    private array $methodOwners = [];

    private string $currentModuleClass = '';

    /**
     * @param string $name
     * @param callable $handler
     * @return void
     *
     * @throws ModuleConflictException
     */
    public function registerMethod(string $name, callable $handler): void
    {
        if (isset($this->methodHandlers[$name])) {
            throw new ModuleConflictException(
                "Method '{$name}' is already registered by {$this->methodOwners[$name]}",
            );
        }
        $this->methodHandlers[$name] = $handler;
        $this->methodOwners[$name] = $this->currentModuleClass;
    }

    /**
     * @param string $class
     * @return void
     */
    public function setCurrentModuleClass(string $class): void
    {
        $this->currentModuleClass = $class;
    }

    /**
     * @param string $name
     * @return bool
     */
    public function hasMethod(string $name): bool
    {
        return isset($this->methodHandlers[$name]);
    }

    /**
     * @param string $method
     * @param array<int, mixed> $args
     * @return mixed
     *
     * @throws BadMethodCallException
     */
    public function __call(string $method, array $args): mixed
    {
        if (isset($this->methodHandlers[$method])) {
            return ($this->methodHandlers[$method])(...$args);
        }
        throw new BadMethodCallException("Method '{$method}' is not registered. Is the module loaded?");
    }
}

// ─── Tests ────────────────────────────────────────────────────────

describe('Custom module via __call', function () {

    it('registers methods and they are callable via __call', function () {
        $module = new CustomGreetingModule();
        $client = new FakeClientForCustomModuleTest();

        $kernel = $this->createMock(PhpOpcua\Client\Kernel\ClientKernel::class);
        $module->setKernel($kernel);
        $module->setClient($client);
        $module->register();

        expect($client->hasMethod('greet'))->toBeTrue();
        expect($client->hasMethod('farewell'))->toBeTrue();
        expect($client->greet('World'))->toBe('Hello, World!');
        expect($client->farewell('World'))->toBe('Goodbye, World!');
    });

    it('supports multiple custom modules', function () {
        $greeting = new CustomGreetingModule();
        $math = new CustomMathModule();
        $client = new FakeClientForCustomModuleTest();

        $kernel = $this->createMock(PhpOpcua\Client\Kernel\ClientKernel::class);

        $greeting->setKernel($kernel);
        $greeting->setClient($client);
        $greeting->register();

        $math->setKernel($kernel);
        $math->setClient($client);
        $math->register();

        expect($client->greet('Test'))->toBe('Hello, Test!');
        expect($client->add(2, 3))->toBe(5);
    });

    it('throws BadMethodCallException for unregistered method', function () {
        $client = new FakeClientForCustomModuleTest();

        expect(fn () => $client->nonExistentMethod())
            ->toThrow(BadMethodCallException::class, "Method 'nonExistentMethod' is not registered. Is the module loaded?");
    });

    it('throws ModuleConflictException when registering duplicate method', function () {
        $greeting1 = new CustomGreetingModule();
        $greeting2 = new CustomGreetingModule();
        $client = new FakeClientForCustomModuleTest();

        $kernel = $this->createMock(PhpOpcua\Client\Kernel\ClientKernel::class);

        $greeting1->setKernel($kernel);
        $greeting1->setClient($client);
        $greeting1->register();

        $greeting2->setKernel($kernel);
        $greeting2->setClient($client);

        expect(fn () => $greeting2->register())
            ->toThrow(ModuleConflictException::class);
    });
});
