<?php

declare(strict_types=1);

use PhpOpcua\Client\Module\Subscription\MonitoredItemService;
use PhpOpcua\Client\Module\Subscription\PublishService;
use PhpOpcua\Client\Module\Subscription\SubscriptionModule;
use PhpOpcua\Client\Module\Subscription\SubscriptionService;
use PhpOpcua\Client\Protocol\SessionService;

describe('SubscriptionModule', function () {

    it('registers 10 methods', function () {
        $module = new SubscriptionModule();

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

    it('boots 3 protocol services', function () {
        $module = new SubscriptionModule();
        $session = new SessionService(1, 1);

        $module->boot($session);

        $ref = new ReflectionClass($module);

        $subProp = $ref->getProperty('subscriptionService');
        expect($subProp->getValue($module))->toBeInstanceOf(SubscriptionService::class);

        $monProp = $ref->getProperty('monitoredItemService');
        expect($monProp->getValue($module))->toBeInstanceOf(MonitoredItemService::class);

        $pubProp = $ref->getProperty('publishService');
        expect($pubProp->getValue($module))->toBeInstanceOf(PublishService::class);
    });

    it('resets protocol services to null', function () {
        $module = new SubscriptionModule();
        $session = new SessionService(1, 1);

        $module->boot($session);
        $module->reset();

        $ref = new ReflectionClass($module);

        $subProp = $ref->getProperty('subscriptionService');
        expect($subProp->getValue($module))->toBeNull();

        $monProp = $ref->getProperty('monitoredItemService');
        expect($monProp->getValue($module))->toBeNull();

        $pubProp = $ref->getProperty('publishService');
        expect($pubProp->getValue($module))->toBeNull();
    });

    it('has no dependencies', function () {
        $module = new SubscriptionModule();
        expect($module->requires())->toBe([]);
    });
});
