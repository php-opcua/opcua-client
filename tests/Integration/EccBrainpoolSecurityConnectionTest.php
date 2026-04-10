<?php

declare(strict_types=1);

use PhpOpcua\Client\Client;
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;

describe('ECC Brainpool Security Connection', function () {

    describe('ECC_brainpoolP256r1 SignAndEncrypt on Brainpool server (4849)', function () {

        it('connects anonymously with auto-generated brainpoolP256r1 cert and browses root', function () {
            $client = null;
            try {
                $client = (new ClientBuilder())
                    ->setSecurityPolicy(SecurityPolicy::EccBrainpoolP256r1)
                    ->setSecurityMode(SecurityMode::SignAndEncrypt)
                    ->connect(TestHelper::ENDPOINT_ECC_BRAINPOOL);
                expect($client)->toBeInstanceOf(Client::class);

                $refs = $client->browse(NodeId::numeric(0, 85));
                expect($refs)->toBeArray()->not->toBeEmpty();

                $names = array_map(fn ($r) => $r->getBrowseName()->getName(), $refs);
                expect($names)->toContain('Server');
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('connects anonymously and reads ServerState', function () {
            $client = null;
            try {
                $client = (new ClientBuilder())
                    ->setSecurityPolicy(SecurityPolicy::EccBrainpoolP256r1)
                    ->setSecurityMode(SecurityMode::SignAndEncrypt)
                    ->connect(TestHelper::ENDPOINT_ECC_BRAINPOOL);
                expect($client)->toBeInstanceOf(Client::class);

                $dv = $client->read(NodeId::numeric(0, 2259));
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeInt()->toBe(0);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('connects with admin credentials and reads ServerState', function () {
            $client = null;
            try {
                $client = (new ClientBuilder())
                    ->setSecurityPolicy(SecurityPolicy::EccBrainpoolP256r1)
                    ->setSecurityMode(SecurityMode::SignAndEncrypt)
                    ->setUserCredentials(
                        TestHelper::USER_ADMIN['username'],
                        TestHelper::USER_ADMIN['password'],
                    )
                    ->connect(TestHelper::ENDPOINT_ECC_BRAINPOOL);
                expect($client)->toBeInstanceOf(Client::class);

                $dv = $client->read(NodeId::numeric(0, 2259));
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeInt()->toBe(0);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

    describe('ECC_brainpoolP256r1 Sign mode on Brainpool server (4849)', function () {

        it('connects anonymously with auto-generated brainpoolP256r1 cert in Sign mode', function () {
            $client = null;
            try {
                $client = (new ClientBuilder())
                    ->setSecurityPolicy(SecurityPolicy::EccBrainpoolP256r1)
                    ->setSecurityMode(SecurityMode::Sign)
                    ->connect(TestHelper::ENDPOINT_ECC_BRAINPOOL);
                expect($client)->toBeInstanceOf(Client::class);

                $refs = $client->browse(NodeId::numeric(0, 85));
                expect($refs)->toBeArray()->not->toBeEmpty();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

    describe('ECC_brainpoolP384r1 SignAndEncrypt on Brainpool server (4849)', function () {

        it('connects anonymously with auto-generated brainpoolP384r1 cert', function () {
            $client = null;
            try {
                $client = (new ClientBuilder())
                    ->setSecurityPolicy(SecurityPolicy::EccBrainpoolP384r1)
                    ->setSecurityMode(SecurityMode::SignAndEncrypt)
                    ->connect(TestHelper::ENDPOINT_ECC_BRAINPOOL);
                expect($client)->toBeInstanceOf(Client::class);

                $refs = $client->browse(NodeId::numeric(0, 85));
                expect($refs)->toBeArray()->not->toBeEmpty();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('connects with admin credentials', function () {
            $client = null;
            try {
                $client = (new ClientBuilder())
                    ->setSecurityPolicy(SecurityPolicy::EccBrainpoolP384r1)
                    ->setSecurityMode(SecurityMode::SignAndEncrypt)
                    ->setUserCredentials(
                        TestHelper::USER_ADMIN['username'],
                        TestHelper::USER_ADMIN['password'],
                    )
                    ->connect(TestHelper::ENDPOINT_ECC_BRAINPOOL);
                expect($client)->toBeInstanceOf(Client::class);

                $dv = $client->read(NodeId::numeric(0, 2259));
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

    describe('ECC_brainpoolP384r1 Sign mode on Brainpool server (4849)', function () {

        it('connects anonymously with auto-generated brainpoolP384r1 cert in Sign mode', function () {
            $client = null;
            try {
                $client = (new ClientBuilder())
                    ->setSecurityPolicy(SecurityPolicy::EccBrainpoolP384r1)
                    ->setSecurityMode(SecurityMode::Sign)
                    ->connect(TestHelper::ENDPOINT_ECC_BRAINPOOL);
                expect($client)->toBeInstanceOf(Client::class);

                $refs = $client->browse(NodeId::numeric(0, 85));
                expect($refs)->toBeArray()->not->toBeEmpty();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

})->group('integration');
