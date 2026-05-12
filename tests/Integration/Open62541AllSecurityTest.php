<?php

declare(strict_types=1);

use PhpOpcua\Client\Client;
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\NodeId;

describe('open62541 all-security (extra-test-suite :24841)', function () {

    describe('Anonymous', function () {

        it('connects with SecurityPolicy::None + SecurityMode::None and reads ServerStatus.State', function () {
            $client = null;
            try {
                $client = (new ClientBuilder())
                    ->connect(TestHelper::ENDPOINT_ALL_SECURITY_OPEN62541);

                expect($client)->toBeInstanceOf(Client::class);

                $dv = $client->read(NodeId::numeric(0, 2259));
                expect($dv->getValue())->toBe(0);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('connects with Basic256Sha256 + SignAndEncrypt using an auto-generated client certificate', function () {
            $client = null;
            try {
                $client = (new ClientBuilder())
                    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
                    ->setSecurityMode(SecurityMode::SignAndEncrypt)
                    ->connect(TestHelper::ENDPOINT_ALL_SECURITY_OPEN62541);

                expect($client)->toBeInstanceOf(Client::class);

                $refs = $client->browse(NodeId::numeric(0, 85));
                expect($refs)->toBeArray()->not->toBeEmpty();

                $names = array_map(fn ($r) => $r->getBrowseName()->getName(), $refs);
                expect($names)->toContain('Server');
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');
    });

    describe('Username/Password — None channel', function () {

        it('connects with admin/admin123 on SecurityPolicy::None and reads ServerStatus.State', function () {
            $client = null;
            try {
                $client = TestHelper::connectWithUserPass(
                    TestHelper::ENDPOINT_ALL_SECURITY_OPEN62541,
                    TestHelper::USER_ADMIN['username'],
                    TestHelper::USER_ADMIN['password'],
                );

                expect($client)->toBeInstanceOf(Client::class);

                $dv = $client->read(NodeId::numeric(0, 2259));
                expect($dv->getValue())->toBe(0);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('connects with operator/operator123 on SecurityPolicy::None', function () {
            $client = null;
            try {
                $client = TestHelper::connectWithUserPass(
                    TestHelper::ENDPOINT_ALL_SECURITY_OPEN62541,
                    TestHelper::USER_OPERATOR['username'],
                    TestHelper::USER_OPERATOR['password'],
                );

                expect($client)->toBeInstanceOf(Client::class);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('connects with viewer/viewer123 on SecurityPolicy::None', function () {
            $client = null;
            try {
                $client = TestHelper::connectWithUserPass(
                    TestHelper::ENDPOINT_ALL_SECURITY_OPEN62541,
                    TestHelper::USER_VIEWER['username'],
                    TestHelper::USER_VIEWER['password'],
                );

                expect($client)->toBeInstanceOf(Client::class);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('connects with test/test on SecurityPolicy::None', function () {
            $client = null;
            try {
                $client = TestHelper::connectWithUserPass(
                    TestHelper::ENDPOINT_ALL_SECURITY_OPEN62541,
                    TestHelper::USER_TEST['username'],
                    TestHelper::USER_TEST['password'],
                );

                expect($client)->toBeInstanceOf(Client::class);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('rejects wrong credentials with ServiceException', function () {
            expect(function () {
                $client = TestHelper::connectWithUserPass(
                    TestHelper::ENDPOINT_ALL_SECURITY_OPEN62541,
                    'admin',
                    'definitely-wrong-password',
                );
                TestHelper::safeDisconnect($client);
            })->toThrow(ServiceException::class);
        })->group('integration');
    });

    describe('Username/Password — Basic256Sha256 + SignAndEncrypt', function () {

        it('connects with admin/admin123 and reads ServerStatus.State', function () {
            $client = null;
            try {
                $client = TestHelper::connectWithUserPass(
                    TestHelper::ENDPOINT_ALL_SECURITY_OPEN62541,
                    TestHelper::USER_ADMIN['username'],
                    TestHelper::USER_ADMIN['password'],
                    SecurityPolicy::Basic256Sha256,
                    SecurityMode::SignAndEncrypt,
                );

                expect($client)->toBeInstanceOf(Client::class);

                $dv = $client->read(NodeId::numeric(0, 2259));
                expect($dv->getValue())->toBe(0);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('connects with operator/operator123', function () {
            $client = null;
            try {
                $client = TestHelper::connectWithUserPass(
                    TestHelper::ENDPOINT_ALL_SECURITY_OPEN62541,
                    TestHelper::USER_OPERATOR['username'],
                    TestHelper::USER_OPERATOR['password'],
                    SecurityPolicy::Basic256Sha256,
                    SecurityMode::SignAndEncrypt,
                );

                expect($client)->toBeInstanceOf(Client::class);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('connects with viewer/viewer123', function () {
            $client = null;
            try {
                $client = TestHelper::connectWithUserPass(
                    TestHelper::ENDPOINT_ALL_SECURITY_OPEN62541,
                    TestHelper::USER_VIEWER['username'],
                    TestHelper::USER_VIEWER['password'],
                    SecurityPolicy::Basic256Sha256,
                    SecurityMode::SignAndEncrypt,
                );

                expect($client)->toBeInstanceOf(Client::class);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('connects with test/test', function () {
            $client = null;
            try {
                $client = TestHelper::connectWithUserPass(
                    TestHelper::ENDPOINT_ALL_SECURITY_OPEN62541,
                    TestHelper::USER_TEST['username'],
                    TestHelper::USER_TEST['password'],
                    SecurityPolicy::Basic256Sha256,
                    SecurityMode::SignAndEncrypt,
                );

                expect($client)->toBeInstanceOf(Client::class);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('rejects wrong credentials with ServiceException', function () {
            expect(function () {
                $client = TestHelper::connectWithUserPass(
                    TestHelper::ENDPOINT_ALL_SECURITY_OPEN62541,
                    'admin',
                    'definitely-wrong-password',
                    SecurityPolicy::Basic256Sha256,
                    SecurityMode::SignAndEncrypt,
                );
                TestHelper::safeDisconnect($client);
            })->toThrow(ServiceException::class);
        })->group('integration');
    });

    describe('v4.3.1 regression — server-advertised policyId', function () {

        it('stores the open62541 non-standard usernamePolicyId after discovery, not the hardcoded default', function () {
            $client = null;
            try {
                $client = TestHelper::connectWithUserPass(
                    TestHelper::ENDPOINT_ALL_SECURITY_OPEN62541,
                    TestHelper::USER_ADMIN['username'],
                    TestHelper::USER_ADMIN['password'],
                );

                $ref = new ReflectionProperty($client, 'usernamePolicyId');
                $stored = $ref->getValue($client);

                expect($stored)->toBeString();
                expect($stored)->toStartWith('open62541-username-policy-');
                expect($stored)->not->toBe('username');
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');
    });
});
