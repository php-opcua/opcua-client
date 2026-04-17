<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Client;

use Closure;
use PhpOpcua\Client\Event\NullEventDispatcher;

/**
 * Provides lazy event dispatching for the connected client.
 *
 * Events are dispatched at key lifecycle points. A {@see NullEventDispatcher} is used
 * by default, ensuring zero overhead when no custom dispatcher is configured.
 * Event objects are lazily instantiated via closures so that no allocation occurs
 * unless a real dispatcher is listening.
 *
 * @see NullEventDispatcher
 */
trait ManagesEventDispatchTrait
{
    /**
     * Dispatch an event or lazily create it from a closure.
     *
     * When the dispatcher is a {@see NullEventDispatcher}, this method returns immediately
     * without instantiating the event object, ensuring zero overhead.
     *
     * @param object $event The event object or a Closure that creates one.
     * @return void
     */
    public function dispatch(object $event): void
    {
        if ($this->eventDispatcher instanceof NullEventDispatcher) {
            return;
        }

        $this->eventDispatcher->dispatch($event instanceof Closure ? $event() : $event);
    }
}
