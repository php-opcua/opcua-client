<?php

declare(strict_types=1);

use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Event\ClientReconnecting;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Tests\Unit\Helpers\InMemoryEventDispatcher;
use PhpOpcua\Client\Types\ConnectionState;

describe('Auto-retry default against a real server', function () {

    it('does not reconnect after the connection drops when autoRetry was never set', function () {
        $client = null;
        try {
            $client = (new ClientBuilder())->connect(TestHelper::ENDPOINT_NO_SECURITY);
            expect($client->getAutoRetry())->toBe(0);

            (fn () => $this->transport->close())->call($client);

            expect(fn () => $client->read('i=2258'))->toThrow(ConnectionException::class);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('reconnects once after the connection drops with setAutoRetry(1)', function () {
        $client = null;
        try {
            $dispatcher = new InMemoryEventDispatcher();
            $client = (new ClientBuilder())->setAutoRetry(1)->setEventDispatcher($dispatcher)->connect(TestHelper::ENDPOINT_NO_SECURITY);

            (fn () => $this->transport->close())->call($client);

            expect($client->read('i=2258')->getValue())->toBeInstanceOf(DateTimeImmutable::class);
            expect($dispatcher->getEventsOfType(ClientReconnecting::class))->toHaveCount(1);
            expect($client->getConnectionState())->toBe(ConnectionState::Connected);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');
});
