<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaPhpClient\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

describe('Access Control', function () {

    describe('Anonymous access on no-security server (4840)', function () {

        it('browses the AccessControl folder', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'AccessControl']);
                $refs = $client->browse($nodeId);

                expect($refs)->toBeArray()->not->toBeEmpty();

                $names = array_map(fn($r) => $r->getBrowseName()->getName(), $refs);
                expect($names)->toContain('AccessLevels');
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads CurrentRead_Only variable', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'AccessControl', 'AccessLevels', 'CurrentRead_Only']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->not->toBeNull();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads ReadWrite variable', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'AccessControl', 'AccessLevels', 'ReadWrite']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->not->toBeNull();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads ViewerLevel variables', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();

                $viewerNodeId = TestHelper::browseToNode($client, ['TestServer', 'AccessControl', 'ViewerLevel']);
                $refs = $client->browse($viewerNodeId);
                expect($refs)->toBeArray()->not->toBeEmpty();

                $names = array_map(fn($r) => $r->getBrowseName()->getName(), $refs);

                // Read available ViewerLevel variables
                foreach ($refs as $ref) {
                    $dv = $client->read($ref->getNodeId());
                    expect($dv->getStatusCode())->toBe(StatusCode::Good);
                    expect($dv->getValue())->not->toBeNull();
                }
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

    describe('Role-based access on auto-accept server (4845)', function () {

        it('admin can read AdminOnly > SecretConfig', function () {
            $client = null;
            try {
                $client = TestHelper::connectWithUserPass(
                    TestHelper::ENDPOINT_AUTO_ACCEPT,
                    TestHelper::USER_ADMIN['username'],
                    TestHelper::USER_ADMIN['password'],
                    SecurityPolicy::Basic256Sha256,
                    SecurityMode::SignAndEncrypt,
                );

                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'AccessControl', 'AdminOnly', 'SecretConfig']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->not->toBeNull();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('admin can write AdminOnly > SystemParameter', function () {
            $client = null;
            try {
                $client = TestHelper::connectWithUserPass(
                    TestHelper::ENDPOINT_AUTO_ACCEPT,
                    TestHelper::USER_ADMIN['username'],
                    TestHelper::USER_ADMIN['password'],
                    SecurityPolicy::Basic256Sha256,
                    SecurityMode::SignAndEncrypt,
                );

                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'AccessControl', 'AdminOnly', 'SystemParameter']);

                // Read current value first
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);

                // Write a new value
                $currentValue = $dv->getValue();
                $newValue = is_int($currentValue) ? $currentValue + 1 : 42;
                $status = $client->write($nodeId, $newValue, BuiltinType::Int32);
                expect(StatusCode::isGood($status))->toBeTrue();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('viewer cannot write OperatorLevel > Setpoint', function () {
            $client = null;
            try {
                $client = TestHelper::connectWithUserPass(
                    TestHelper::ENDPOINT_AUTO_ACCEPT,
                    TestHelper::USER_VIEWER['username'],
                    TestHelper::USER_VIEWER['password'],
                    SecurityPolicy::Basic256Sha256,
                    SecurityMode::SignAndEncrypt,
                );

                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'AccessControl', 'OperatorLevel', 'Setpoint']);

                // Attempt to write - should fail with BadUserAccessDenied or BadNotWritable
                $status = $client->write($nodeId, 99.0, BuiltinType::Double);
                expect(StatusCode::isBad($status))->toBeTrue();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

})->group('integration');
