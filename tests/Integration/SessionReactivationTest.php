<?php

declare(strict_types=1);

use PhpOpcua\Client\Client;
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Event\SessionReactivated;
use PhpOpcua\Client\Module\Subscription\DataChangeNotification;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Tests\Unit\Helpers\InMemoryEventDispatcher;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\SessionState;

dataset('reactivationConnections', [
    'no security' => [fn (): ClientBuilder => new ClientBuilder(), TestHelper::ENDPOINT_NO_SECURITY],
    'Basic256Sha256 SignAndEncrypt' => [fn (): ClientBuilder => (new ClientBuilder())
        ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
        ->setSecurityMode(SecurityMode::SignAndEncrypt)
        ->setClientCertificate(TestHelper::getClientCertPath(), TestHelper::getClientKeyPath(), TestHelper::getCaCertPath()), TestHelper::ENDPOINT_ALL_SECURITY],
]);

function counterSubscription(Client $client): int
{
    $subscriptionId = $client->createSubscription(500.0, 1200, 10)->subscriptionId;
    $client->createMonitoredItems($subscriptionId, [[
        'nodeId' => NodeId::parse('ns=1;s=TestServer/Dynamic/Counter'),
        'samplingInterval' => 1000.0,
        'queueSize' => 100,
        'discardOldest' => false,
    ]]);

    return $subscriptionId;
}

/**
 * Publishes for $seconds, acknowledging as it goes and republishing every sequence number the
 * server still holds unacknowledged, and returns the counter values received.
 *
 * @param list<array{subscriptionId: int, sequenceNumber: int}> $acks
 * @return list<int>
 */
function collectCounter(Client $client, int $subscriptionId, array &$acks, float $seconds, bool $republishAvailable = false): array
{
    $values = [];
    $republished = [];
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        $result = $client->publish($acks);
        $acks = [['subscriptionId' => $result->subscriptionId, 'sequenceNumber' => $result->sequenceNumber]];
        foreach ($result->notifications as $notification) {
            if ($notification instanceof DataChangeNotification) {
                $values[] = $notification->dataValue->getValue();
            }
        }
        if ($republishAvailable) {
            foreach ($result->availableSequenceNumbers as $sequenceNumber) {
                if ($sequenceNumber === $result->sequenceNumber || isset($republished[$sequenceNumber])) {
                    continue;
                }
                $republished[$sequenceNumber] = true;
                foreach ($client->republish($subscriptionId, $sequenceNumber)['notifications'] as $notification) {
                    if ($notification instanceof DataChangeNotification) {
                        $values[] = $notification->dataValue->getValue();
                    }
                }
            }
        }
    }

    return $values;
}

/**
 * @param list<int> $values
 * @return list<int>
 */
function missingAfter(int $last, array $values): array
{
    $unique = array_unique($values);
    $missing = [];
    for ($value = $last + 1; $value <= max($unique); $value++) {
        if (! in_array($value, $unique, true)) {
            $missing[] = $value;
        }
    }

    return $missing;
}

describe('Session reactivation against a real server', function () {

    it('keeps the session and its subscription when the connection drops', function (Closure $builder, string $endpoint) {
        $client = null;
        try {
            $dispatcher = new InMemoryEventDispatcher();
            $client = $builder()->setAutoRetry(1)->setEventDispatcher($dispatcher)->connect($endpoint);
            $subscriptionId = counterSubscription($client);
            $acks = [];
            $before = collectCounter($client, $subscriptionId, $acks, 3.0);
            $token = (string) $client->getSessionState()?->authenticationToken;

            (fn () => $this->transport->close())->call($client);

            expect($client->read('i=2258')->getValue())->toBeInstanceOf(DateTimeImmutable::class);
            expect($dispatcher->getEventsOfType(SessionReactivated::class))->toHaveCount(1);
            expect((string) $client->getSessionState()?->authenticationToken)->toBe($token);

            $after = collectCounter($client, $subscriptionId, $acks, 3.0, true);
            expect($after)->not->toBe([]);
            expect(missingAfter(max($before), $after))->toBe([]);

            $client->deleteSubscription($subscriptionId);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->with('reactivationConnections')->group('integration');

    it('creates a new session on reconnect when reactivation is disabled', function () {
        $client = null;
        try {
            $dispatcher = new InMemoryEventDispatcher();
            $client = (new ClientBuilder())->setAutoRetry(1)->setReactivateSession(false)->setEventDispatcher($dispatcher)->connect(TestHelper::ENDPOINT_NO_SECURITY);
            $token = (string) $client->getSessionState()?->authenticationToken;

            (fn () => $this->transport->close())->call($client);

            expect($client->read('i=2258')->getValue())->toBeInstanceOf(DateTimeImmutable::class);
            expect($dispatcher->getEventsOfType(SessionReactivated::class))->toBe([]);
            expect((string) $client->getSessionState()?->authenticationToken)->not->toBe($token);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('resumes the session of a crashed client and receives every value it missed', function (Closure $builder, string $endpoint) {
        $crashed = null;
        $resumed = null;
        try {
            $crashed = $builder()->connect($endpoint);
            $subscriptionId = counterSubscription($crashed);
            $acks = [];
            $before = collectCounter($crashed, $subscriptionId, $acks, 3.0);
            $state = SessionState::fromArray(json_decode(json_encode($crashed->getSessionState()), true));

            (fn () => $this->transport->close())->call($crashed);
            usleep(15_000_000);

            $dispatcher = new InMemoryEventDispatcher();
            $resumed = $builder()->setEventDispatcher($dispatcher)->resumeSession($state)->connect($endpoint);
            expect($dispatcher->getEventsOfType(SessionReactivated::class))->toHaveCount(1);
            expect((string) $resumed->getSessionState()?->authenticationToken)->toBe((string) $state->authenticationToken);

            $acks = [];
            $after = collectCounter($resumed, $subscriptionId, $acks, 5.0, true);
            expect(count(array_unique($after)))->toBeGreaterThan(15);
            expect(missingAfter(max($before), $after))->toBe([]);

            $resumed->deleteSubscription($subscriptionId);
        } finally {
            TestHelper::safeDisconnect($resumed);
        }
    })->with('reactivationConnections')->group('integration');

    it('resumes a suspended session', function () {
        $resumed = null;
        try {
            $client = (new ClientBuilder())->connect(TestHelper::ENDPOINT_NO_SECURITY);
            $subscriptionId = counterSubscription($client);
            $state = $client->suspend();
            expect($state)->toBeInstanceOf(SessionState::class);
            expect($client->isConnected())->toBeFalse();

            $dispatcher = new InMemoryEventDispatcher();
            $resumed = (new ClientBuilder())->setEventDispatcher($dispatcher)->resumeSession($state)->connect(TestHelper::ENDPOINT_NO_SECURITY);
            expect($dispatcher->getEventsOfType(SessionReactivated::class))->toHaveCount(1);

            $acks = [];
            expect(collectCounter($resumed, $subscriptionId, $acks, 2.5))->not->toBe([]);
            $resumed->deleteSubscription($subscriptionId);
        } finally {
            TestHelper::safeDisconnect($resumed);
        }
    })->group('integration');

    it('creates a new session when the saved session is unknown to the server', function () {
        $client = null;
        try {
            $dispatcher = new InMemoryEventDispatcher();
            $unknown = new SessionState(TestHelper::ENDPOINT_NO_SECURITY, NodeId::parse('b=00112233445566778899aabbccddeeff'), null, 120000.0);
            $client = (new ClientBuilder())->setEventDispatcher($dispatcher)->resumeSession($unknown)->connect(TestHelper::ENDPOINT_NO_SECURITY);

            expect($client->isConnected())->toBeTrue();
            expect($dispatcher->getEventsOfType(SessionReactivated::class))->toBe([]);
            expect((string) $client->getSessionState()?->authenticationToken)->not->toBe('b=00112233445566778899aabbccddeeff');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');
});
