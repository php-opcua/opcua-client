<?php

declare(strict_types=1);

use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Event\ClientReconnecting;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Tests\Unit\Helpers\InMemoryEventDispatcher;
use PhpOpcua\Client\Types\ConnectionState;
use PhpOpcua\Client\Types\StatusCode;

/**
 * UA-.NETStandard checks for expired sessions every MinSessionTimeout (10 s), so a
 * session is closed between its timeout and one check cycle later. A 10 s session
 * idle for 30 s is always gone.
 */
describe('Session timeout against a real server', function () {

    it('requests the configured session timeout and the server applies it', function () {
        $default = null;
        $short = null;
        $minimum = null;
        try {
            $default = (new ClientBuilder())->connect(TestHelper::ENDPOINT_NO_SECURITY);
            $short = (new ClientBuilder())->setSessionTimeout(15000.0)->connect(TestHelper::ENDPOINT_NO_SECURITY);
            $minimum = (new ClientBuilder())->setSessionTimeout(1000.0)->setRecreateExpiredSession(false)->connect(TestHelper::ENDPOINT_NO_SECURITY);

            expect($default->getSessionTimeout())->toBe(120000.0);
            expect($short->getSessionTimeout())->toBe(15000.0);
            expect($minimum->getSessionTimeout())->toBe(10000.0);

            usleep(30_000_000);

            expect($default->read('i=2258')->getValue())->toBeInstanceOf(DateTimeImmutable::class);

            try {
                $minimum->read('i=2258');
                $statusCode = StatusCode::Good;
            } catch (ServiceException $e) {
                $statusCode = $e->getStatusCode();
            }
            expect($statusCode)->toBe(StatusCode::BadSessionIdInvalid);
        } finally {
            TestHelper::safeDisconnect($default);
            TestHelper::safeDisconnect($short);
            TestHelper::safeDisconnect($minimum);
        }
    })->group('integration');

    it('recreates the expired session and repeats the call', function () {
        $client = null;
        try {
            $dispatcher = new InMemoryEventDispatcher();
            $client = (new ClientBuilder())->setSessionTimeout(10000.0)->setEventDispatcher($dispatcher)->connect(TestHelper::ENDPOINT_NO_SECURITY);
            $expiredSession = (string) (fn () => $this->authenticationToken)->call($client);

            usleep(30_000_000);

            expect($client->read('i=2258')->getValue())->toBeInstanceOf(DateTimeImmutable::class);
            expect($dispatcher->getEventsOfType(ClientReconnecting::class))->toHaveCount(1);
            expect((string) (fn () => $this->authenticationToken)->call($client))->not->toBe($expiredSession);
            expect($client->getConnectionState())->toBe(ConnectionState::Connected);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');
});
