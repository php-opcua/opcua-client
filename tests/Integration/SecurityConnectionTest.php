<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Exception\ServiceException;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaPhpClient\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

describe('Security Connection', function () {

    describe('Username/Password auth on auto-accept server (4845)', function () {

        it('connects with admin/admin123 and browses root', function () {
            $client = null;
            try {
                $client = TestHelper::connectWithUserPass(
                    TestHelper::ENDPOINT_AUTO_ACCEPT,
                    TestHelper::USER_ADMIN['username'],
                    TestHelper::USER_ADMIN['password'],
                    SecurityPolicy::Basic256Sha256,
                    SecurityMode::SignAndEncrypt,
                );
                expect($client)->toBeInstanceOf(Client::class);

                $refs = $client->browse(NodeId::numeric(0, 85));
                expect($refs)->toBeArray()->not->toBeEmpty();

                $names = array_map(fn($r) => $r->getBrowseName()->getName(), $refs);
                expect($names)->toContain('Server');
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('connects with operator/operator123', function () {
            $client = null;
            try {
                $client = TestHelper::connectWithUserPass(
                    TestHelper::ENDPOINT_AUTO_ACCEPT,
                    TestHelper::USER_OPERATOR['username'],
                    TestHelper::USER_OPERATOR['password'],
                    SecurityPolicy::Basic256Sha256,
                    SecurityMode::SignAndEncrypt,
                );
                expect($client)->toBeInstanceOf(Client::class);

                $refs = $client->browse(NodeId::numeric(0, 85));
                expect($refs)->toBeArray()->not->toBeEmpty();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('connects with viewer/viewer123', function () {
            $client = null;
            try {
                $client = TestHelper::connectWithUserPass(
                    TestHelper::ENDPOINT_AUTO_ACCEPT,
                    TestHelper::USER_VIEWER['username'],
                    TestHelper::USER_VIEWER['password'],
                    SecurityPolicy::Basic256Sha256,
                    SecurityMode::SignAndEncrypt,
                );
                expect($client)->toBeInstanceOf(Client::class);

                $refs = $client->browse(NodeId::numeric(0, 85));
                expect($refs)->toBeArray()->not->toBeEmpty();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('connects with test/test', function () {
            $client = null;
            try {
                $client = TestHelper::connectWithUserPass(
                    TestHelper::ENDPOINT_AUTO_ACCEPT,
                    TestHelper::USER_TEST['username'],
                    TestHelper::USER_TEST['password'],
                    SecurityPolicy::Basic256Sha256,
                    SecurityMode::SignAndEncrypt,
                );
                expect($client)->toBeInstanceOf(Client::class);

                $refs = $client->browse(NodeId::numeric(0, 85));
                expect($refs)->toBeArray()->not->toBeEmpty();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('throws on wrong credentials', function () {
            expect(function () {
                $client = TestHelper::connectWithUserPass(
                    TestHelper::ENDPOINT_AUTO_ACCEPT,
                    'wronguser',
                    'wrongpassword',
                    SecurityPolicy::Basic256Sha256,
                    SecurityMode::SignAndEncrypt,
                );
                TestHelper::safeDisconnect($client);
            })->toThrow(ServiceException::class);
        })->group('integration');

    });

    describe('Certificate auth on auto-accept server (4845)', function () {

        it('connects with certificate authentication and reads a value', function () {
            $client = null;
            try {
                $client = TestHelper::connectWithCertificate(
                    TestHelper::ENDPOINT_AUTO_ACCEPT,
                    SecurityPolicy::Basic256Sha256,
                    SecurityMode::SignAndEncrypt,
                );
                expect($client)->toBeInstanceOf(Client::class);

                // Read ServerStatus.State to verify the connection works
                $dv = $client->read(NodeId::numeric(0, 2259));
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeInt()->toBe(0); // Running
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

    describe('Auto-generated certificate on auto-accept server (4845)', function () {

        it('connects with auto-generated certificate when none specified', function () {
            $client = null;
            try {
                $client = new Client();
                $client->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
                $client->setSecurityMode(SecurityMode::SignAndEncrypt);
                // No setClientCertificate call - should auto-generate
                $client->connect(TestHelper::ENDPOINT_AUTO_ACCEPT);
                expect($client)->toBeInstanceOf(Client::class);

                $refs = $client->browse(NodeId::numeric(0, 85));
                expect($refs)->toBeArray()->not->toBeEmpty();

                $names = array_map(fn($r) => $r->getBrowseName()->getName(), $refs);
                expect($names)->toContain('Server');
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('connects with auto-generated certificate and username/password auth', function () {
            $client = null;
            try {
                $client = new Client();
                $client->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
                $client->setSecurityMode(SecurityMode::SignAndEncrypt);
                // No setClientCertificate call - should auto-generate
                $client->setUserCredentials(
                    TestHelper::USER_ADMIN['username'],
                    TestHelper::USER_ADMIN['password'],
                );
                $client->connect(TestHelper::ENDPOINT_AUTO_ACCEPT);
                expect($client)->toBeInstanceOf(Client::class);

                $dv = $client->read(NodeId::numeric(0, 2259));
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('connects with auto-generated certificate in Sign mode', function () {
            $client = null;
            try {
                $client = new Client();
                $client->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
                $client->setSecurityMode(SecurityMode::Sign);
                // No setClientCertificate call - should auto-generate
                $client->connect(TestHelper::ENDPOINT_SIGN_ONLY);
                expect($client)->toBeInstanceOf(Client::class);

                $refs = $client->browse(NodeId::numeric(0, 85));
                expect($refs)->toBeArray()->not->toBeEmpty();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

    describe('Sign-only mode on sign-only server (4846)', function () {

        it('connects anonymously with Basic256Sha256/Sign', function () {
            $client = null;
            try {
                $client = new Client();
                $client->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
                $client->setSecurityMode(SecurityMode::Sign);
                $client->setClientCertificate(TestHelper::getClientCertPath(), TestHelper::getClientKeyPath(), TestHelper::getCaCertPath());
                $client->connect(TestHelper::ENDPOINT_SIGN_ONLY);
                expect($client)->toBeInstanceOf(Client::class);

                $refs = $client->browse(NodeId::numeric(0, 85));
                expect($refs)->toBeArray()->not->toBeEmpty();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('connects with username/password with Basic256Sha256/Sign', function () {
            $client = null;
            try {
                $client = TestHelper::connectWithUserPass(
                    TestHelper::ENDPOINT_SIGN_ONLY,
                    TestHelper::USER_ADMIN['username'],
                    TestHelper::USER_ADMIN['password'],
                    SecurityPolicy::Basic256Sha256,
                    SecurityMode::Sign,
                );
                expect($client)->toBeInstanceOf(Client::class);

                $refs = $client->browse(NodeId::numeric(0, 85));
                expect($refs)->toBeArray()->not->toBeEmpty();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

})->group('integration');
