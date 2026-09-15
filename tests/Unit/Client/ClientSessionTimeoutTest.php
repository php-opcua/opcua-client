<?php

declare(strict_types=1);

use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Testing\MockClient;

describe('Session timeout', function () {

    it('requests 120 s by default and accepts another value on the builder', function () {
        $builder = new ClientBuilder();
        expect($builder->getSessionTimeout())->toBe(120000.0);
        expect($builder->setSessionTimeout(15000.0))->toBe($builder);
        expect($builder->getSessionTimeout())->toBe(15000.0);
    });

    it('writes the requested session timeout into the CreateSession request', function () {
        $session = new SessionService(1, 1);

        expect(str_contains($session->encodeCreateSessionRequest(1, 'opc.tcp://localhost:4840', 15000.0), pack('e', 15000.0)))->toBeTrue();
        expect(str_contains($session->encodeCreateSessionRequest(1, 'opc.tcp://localhost:4840'), pack('e', 120000.0)))->toBeTrue();
    });

    it('recreates expired sessions by default and lets the builder turn it off', function () {
        $builder = new ClientBuilder();
        expect($builder->isRecreateExpiredSession())->toBeTrue();
        expect($builder->setRecreateExpiredSession(false))->toBe($builder);
        expect($builder->isRecreateExpiredSession())->toBeFalse();

        $mock = MockClient::create();
        expect($mock->isRecreateExpiredSession())->toBeTrue();
        expect($mock->setRecreateExpiredSession(false)->isRecreateExpiredSession())->toBeFalse();
    });

    it('exposes the session timeout on the mock client', function () {
        $mock = MockClient::create();
        expect($mock->getSessionTimeout())->toBe(120000.0);
        expect($mock->setSessionTimeout(30000.0)->getSessionTimeout())->toBe(30000.0);
    });
});
