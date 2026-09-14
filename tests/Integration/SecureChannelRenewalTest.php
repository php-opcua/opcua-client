<?php

declare(strict_types=1);

use PhpOpcua\Client\Client;
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Event\SecureChannelRenewed;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Tests\Unit\Helpers\InMemoryEventDispatcher;
use PhpOpcua\Client\Types\StatusCode;

function shortTokenLifetimeClient(SecurityMode $mode, InMemoryEventDispatcher $dispatcher): Client
{
    $builder = (new ClientBuilder())->setEventDispatcher($dispatcher);
    if ($mode !== SecurityMode::None) {
        $builder->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
            ->setSecurityMode($mode)
            ->setClientCertificate(TestHelper::getClientCertPath(), TestHelper::getClientKeyPath(), TestHelper::getCaCertPath());
    }

    return $builder->connect(TestHelper::ENDPOINT_SHORT_TOKEN_LIFETIME);
}

describe('Secure channel token renewal against a real server', function () {

    it('renews the token before it expires and keeps the session, in every security mode', function () {
        $modes = ['None' => SecurityMode::None, 'Sign' => SecurityMode::Sign, 'SignAndEncrypt' => SecurityMode::SignAndEncrypt];
        $clients = [];
        $dispatchers = [];
        $sessions = [];
        try {
            foreach ($modes as $label => $mode) {
                $dispatchers[$label] = new InMemoryEventDispatcher();
                $clients[$label] = shortTokenLifetimeClient($mode, $dispatchers[$label]);
                $sessions[$label] = (string) (fn () => $this->authenticationToken)->call($clients[$label]);
            }

            $deadline = microtime(true) + 45;
            while (microtime(true) < $deadline) {
                foreach ($clients as $client) {
                    expect($client->read('i=2258')->getValue())->toBeInstanceOf(DateTimeImmutable::class);
                }
                usleep(5_000_000);
            }

            foreach ($clients as $label => $client) {
                $renewals = $dispatchers[$label]->getEventsOfType(SecureChannelRenewed::class);
                expect($renewals)->not->toBeEmpty();
                expect($renewals[0]->revisedLifetime)->toBe(30000);
                expect((string) (fn () => $this->authenticationToken)->call($client))->toBe($sessions[$label]);
            }
        } finally {
            foreach ($clients as $client) {
                TestHelper::safeDisconnect($client);
            }
        }
    })->group('integration');

    it('keeps the session and its subscription delivering data across token renewals', function () {
        $modes = ['None' => SecurityMode::None, 'SignAndEncrypt' => SecurityMode::SignAndEncrypt];
        $clients = [];
        $dispatchers = [];
        $sessions = [];
        $subscriptions = [];
        try {
            foreach ($modes as $label => $mode) {
                $dispatchers[$label] = new InMemoryEventDispatcher();
                $clients[$label] = shortTokenLifetimeClient($mode, $dispatchers[$label]);
                $sessions[$label] = (string) (fn () => $this->authenticationToken)->call($clients[$label]);
                $subscriptions[$label] = $clients[$label]->createSubscription(500.0)->subscriptionId;
                $counter = TestHelper::browseToNode($clients[$label], ['TestServer', 'Dynamic', 'Counter']);
                $clients[$label]->createMonitoredItems($subscriptions[$label], [['nodeId' => $counter, 'samplingInterval' => 250.0]]);
            }

            $afterRenewal = array_fill_keys(array_keys($modes), 0);
            $deadline = microtime(true) + 45;
            while (microtime(true) < $deadline) {
                foreach ($clients as $label => $client) {
                    $result = $client->publish();
                    expect($result->subscriptionId)->toBe($subscriptions[$label]);
                    if ($dispatchers[$label]->hasEvent(SecureChannelRenewed::class)) {
                        $afterRenewal[$label] += count($result->notifications);
                    }
                }
            }

            foreach ($clients as $label => $client) {
                expect($dispatchers[$label]->getEventsOfType(SecureChannelRenewed::class))->not->toBeEmpty();
                expect($afterRenewal[$label])->toBeGreaterThan(0);
                expect((string) (fn () => $this->authenticationToken)->call($client))->toBe($sessions[$label]);
                expect(StatusCode::isGood($client->deleteSubscription($subscriptions[$label])))->toBeTrue();
            }
        } finally {
            foreach ($clients as $client) {
                TestHelper::safeDisconnect($client);
            }
        }
    })->group('integration');

    it('renews an expired token on the first call after the connection sat idle', function () {
        $modes = ['None' => SecurityMode::None, 'SignAndEncrypt' => SecurityMode::SignAndEncrypt];
        $clients = [];
        $dispatchers = [];
        $sessions = [];
        try {
            foreach ($modes as $label => $mode) {
                $dispatchers[$label] = new InMemoryEventDispatcher();
                $clients[$label] = shortTokenLifetimeClient($mode, $dispatchers[$label]);
                $sessions[$label] = (string) (fn () => $this->authenticationToken)->call($clients[$label]);
            }

            usleep(40_000_000);

            foreach ($clients as $label => $client) {
                expect($client->read('i=2258')->getValue())->toBeInstanceOf(DateTimeImmutable::class);
                expect($dispatchers[$label]->getEventsOfType(SecureChannelRenewed::class))->toHaveCount(1);
                expect((string) (fn () => $this->authenticationToken)->call($client))->toBe($sessions[$label]);
            }
        } finally {
            foreach ($clients as $client) {
                TestHelper::safeDisconnect($client);
            }
        }
    })->group('integration');
});
