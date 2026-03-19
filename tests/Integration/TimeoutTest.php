<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Exception\ConnectionException;
use Gianfriaur\OpcuaPhpClient\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

describe('Timeout', function () {

    it('connects and operates with a custom timeout', function () {
        $client = null;
        try {
            $client = new Client();
            $client->setTimeout(10.0);
            $client->connect(TestHelper::ENDPOINT_NO_SECURITY);

            expect($client->getTimeout())->toBe(10.0);

            $dataValue = $client->read(NodeId::numeric(0, 2259));
            expect($dataValue->getStatusCode())->toBe(StatusCode::Good);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('connects with a short but sufficient timeout', function () {
        $client = null;
        try {
            $client = new Client();
            $client->setTimeout(2.0);
            $client->connect(TestHelper::ENDPOINT_NO_SECURITY);

            $refs = $client->browse(NodeId::numeric(0, 85));
            expect($refs)->toBeArray()->not->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('throws ConnectionException when timeout is too short for unreachable host', function () {
        $client = new Client();
        $client->setTimeout(0.1);

        $start = microtime(true);
        $threw = false;
        try {
            @$client->connect('opc.tcp://192.0.2.1:4840/UA/TestServer');
        } catch (ConnectionException) {
            $threw = true;
        }
        $elapsed = microtime(true) - $start;

        expect($threw)->toBeTrue();
        expect($elapsed)->toBeLessThan(3.0);
    })->group('integration');

    it('preserves timeout across multiple operations', function () {
        $client = null;
        try {
            $client = new Client();
            $client->setTimeout(10.0);
            $client->connect(TestHelper::ENDPOINT_NO_SECURITY);

            $client->read(NodeId::numeric(0, 2259));
            $client->browse(NodeId::numeric(0, 85));
            $client->read(NodeId::numeric(0, 2259));

            expect($client->getTimeout())->toBe(10.0);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');
