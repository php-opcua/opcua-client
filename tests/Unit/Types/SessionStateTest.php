<?php

declare(strict_types=1);

use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Exception\ConfigurationException;
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\SessionState;

describe('SessionState', function () {

    it('round-trips through JSON with an opaque token and a binary nonce', function () {
        $state = new SessionState('opc.tcp://plc:4840', NodeId::parse('b=27dce93edef1'), random_bytes(32), 60000.0);

        $restored = SessionState::fromArray(json_decode(json_encode($state), true));

        expect($restored->endpointUrl)->toBe('opc.tcp://plc:4840');
        expect((string) $restored->authenticationToken)->toBe('b=27dce93edef1');
        expect($restored->serverNonce)->toBe($state->serverNonce);
        expect($restored->sessionTimeout)->toBe(60000.0);
    });

    it('keeps a missing server nonce', function () {
        $restored = SessionState::fromArray(json_decode(json_encode(new SessionState('opc.tcp://plc:4840', NodeId::numeric(1, 7), null, 120000.0)), true));

        expect($restored->serverNonce)->toBeNull();
        expect((string) $restored->authenticationToken)->toBe('ns=1;i=7');
    });

    it('rejects incomplete or malformed data', function () {
        expect(fn () => SessionState::fromArray(['endpointUrl' => 'opc.tcp://plc:4840']))->toThrow(ConfigurationException::class);
        expect(fn () => SessionState::fromArray(['endpointUrl' => 'opc.tcp://plc:4840', 'authenticationToken' => 'i=1', 'sessionTimeout' => 1000, 'serverNonce' => '***']))->toThrow(ConfigurationException::class);
    });

    it('is configured on the builder and the mock client', function () {
        $builder = new ClientBuilder();
        expect($builder->isReactivateSession())->toBeTrue();
        expect($builder->setReactivateSession(false)->isReactivateSession())->toBeFalse();
        expect($builder->resumeSession(new SessionState('opc.tcp://plc:4840', NodeId::numeric(0, 1), null, 1.0)))->toBe($builder);
        expect(MockClient::create()->setReactivateSession(false)->isReactivateSession())->toBeFalse();
    });
});
