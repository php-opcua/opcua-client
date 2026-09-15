<?php

declare(strict_types=1);

require_once __DIR__ . '/ClientTraitsCoverageTest.php';

use PhpOpcua\Client\Event\ClientReconnecting;
use PhpOpcua\Client\Event\RetryAttempt;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Tests\Unit\Helpers\InMemoryEventDispatcher;
use PhpOpcua\Client\Types\NodeId;

describe('Auto-retry default', function () {

    it('does not retry after a connection loss when autoRetry was never set', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $client = setupConnectedClient(new MockTransport());
        setClientProperty($client, 'eventDispatcher', $dispatcher);

        expect($client->getAutoRetry())->toBe(0);
        expect(fn () => $client->read(NodeId::numeric(0, 2259)))->toThrow(ConnectionException::class);

        expect($dispatcher->getEventsOfType(RetryAttempt::class))->toBe([]);
        expect($dispatcher->getEventsOfType(ClientReconnecting::class))->toBe([]);
    });

    it('retries once when autoRetry is 1', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $client = setupConnectedClient(new MockTransport());
        setClientProperty($client, 'eventDispatcher', $dispatcher);
        setClientProperty($client, 'autoRetry', 1);
        setClientProperty($client, 'lastEndpointUrl', 'opc.tcp://127.0.0.1:1');

        expect(fn () => $client->read(NodeId::numeric(0, 2259)))->toThrow(ConnectionException::class);

        expect($dispatcher->getEventsOfType(RetryAttempt::class))->toHaveCount(1);
        expect($dispatcher->getEventsOfType(ClientReconnecting::class))->toHaveCount(1);
    });
});
