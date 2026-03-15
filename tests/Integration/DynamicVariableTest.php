<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

describe('Dynamic Variables', function () {

    it('reads Counter as UInt32', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'Counter']);
            $dv = $client->read($nodeId);
            expect($dv->getStatusCode())->toBe(StatusCode::Good);
            expect($dv->getValue())->toBeInt();
            expect($dv->getValue())->toBeGreaterThanOrEqual(0);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('reads Counter twice with delay and verifies it incremented', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'Counter']);

            $dv1 = $client->read($nodeId);
            expect($dv1->getStatusCode())->toBe(StatusCode::Good);
            $value1 = $dv1->getValue();

            // Wait 1.5 seconds for the counter to increment
            usleep(1_500_000);

            $dv2 = $client->read($nodeId);
            expect($dv2->getStatusCode())->toBe(StatusCode::Good);
            $value2 = $dv2->getValue();

            expect($value2)->toBeGreaterThan($value1);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('reads Random as a Double in [0, 1)', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'Random']);
            $dv = $client->read($nodeId);
            expect($dv->getStatusCode())->toBe(StatusCode::Good);
            expect($dv->getValue())->toBeFloat();
            expect($dv->getValue())->toBeGreaterThanOrEqual(0.0);
            expect($dv->getValue())->toBeLessThan(1.0);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('reads RandomInt as an Int32 in [-1000, 1000]', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'RandomInt']);
            $dv = $client->read($nodeId);
            expect($dv->getStatusCode())->toBe(StatusCode::Good);
            expect($dv->getValue())->toBeInt();
            expect($dv->getValue())->toBeGreaterThanOrEqual(-1000);
            expect($dv->getValue())->toBeLessThanOrEqual(1000);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('reads SineWave as a Double in [-1, 1]', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'SineWave']);
            $dv = $client->read($nodeId);
            expect($dv->getStatusCode())->toBe(StatusCode::Good);
            expect($dv->getValue())->toBeFloat();
            expect($dv->getValue())->toBeGreaterThanOrEqual(-1.0);
            expect($dv->getValue())->toBeLessThanOrEqual(1.0);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('reads Timestamp as a DateTime close to now', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'Timestamp']);
            $dv = $client->read($nodeId);
            expect($dv->getStatusCode())->toBe(StatusCode::Good);
            expect($dv->getValue())->toBeInstanceOf(DateTimeImmutable::class);

            // Should be within 60 seconds of now
            $now = new DateTimeImmutable();
            $diff = abs($now->getTimestamp() - $dv->getValue()->getTimestamp());
            expect($diff)->toBeLessThan(60);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('reads RandomString as a non-empty string', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'RandomString']);
            $dv = $client->read($nodeId);
            expect($dv->getStatusCode())->toBe(StatusCode::Good);
            expect($dv->getValue())->toBeString()->not->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('reads StatusVariable as a UInt32 status code', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'StatusVariable']);
            $dv = $client->read($nodeId);
            expect($dv->getStatusCode())->toBe(StatusCode::Good);
            expect($dv->getValue())->toBeInt();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');
