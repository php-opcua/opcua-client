<?php

declare(strict_types=1);

use PhpOpcua\Client\Client;
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;

describe('ECC NIST Security Connection', function () {

    describe('ECC_nistP256 SignAndEncrypt on ECC server (4848)', function () {

        it('connects anonymously with auto-generated P-256 cert and browses root', function () {
            $client = null;
            try {
                $client = (new ClientBuilder())
                    ->setSecurityPolicy(SecurityPolicy::EccNistP256)
                    ->setSecurityMode(SecurityMode::SignAndEncrypt)
                    ->connect(TestHelper::ENDPOINT_ECC_NIST);
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
                    ->setSecurityPolicy(SecurityPolicy::EccNistP256)
                    ->setSecurityMode(SecurityMode::SignAndEncrypt)
                    ->connect(TestHelper::ENDPOINT_ECC_NIST);
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
                    ->setSecurityPolicy(SecurityPolicy::EccNistP256)
                    ->setSecurityMode(SecurityMode::SignAndEncrypt)
                    ->setUserCredentials(
                        TestHelper::USER_ADMIN['username'],
                        TestHelper::USER_ADMIN['password'],
                    )
                    ->connect(TestHelper::ENDPOINT_ECC_NIST);
                expect($client)->toBeInstanceOf(Client::class);

                $dv = $client->read(NodeId::numeric(0, 2259));
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeInt()->toBe(0);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

    describe('ECC_nistP256 Sign mode on ECC server (4848)', function () {

        it('connects anonymously with auto-generated P-256 cert in Sign mode', function () {
            $client = null;
            try {
                $client = (new ClientBuilder())
                    ->setSecurityPolicy(SecurityPolicy::EccNistP256)
                    ->setSecurityMode(SecurityMode::Sign)
                    ->connect(TestHelper::ENDPOINT_ECC_NIST);
                expect($client)->toBeInstanceOf(Client::class);

                $refs = $client->browse(NodeId::numeric(0, 85));
                expect($refs)->toBeArray()->not->toBeEmpty();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

    describe('ECC_nistP384 SignAndEncrypt on ECC server (4848)', function () {

        it('connects anonymously with auto-generated P-384 cert', function () {
            $client = null;
            try {
                $client = (new ClientBuilder())
                    ->setSecurityPolicy(SecurityPolicy::EccNistP384)
                    ->setSecurityMode(SecurityMode::SignAndEncrypt)
                    ->connect(TestHelper::ENDPOINT_ECC_NIST);
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
                    ->setSecurityPolicy(SecurityPolicy::EccNistP384)
                    ->setSecurityMode(SecurityMode::SignAndEncrypt)
                    ->setUserCredentials(
                        TestHelper::USER_ADMIN['username'],
                        TestHelper::USER_ADMIN['password'],
                    )
                    ->connect(TestHelper::ENDPOINT_ECC_NIST);
                expect($client)->toBeInstanceOf(Client::class);

                $dv = $client->read(NodeId::numeric(0, 2259));
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

    describe('ECC_nistP384 Sign mode on ECC server (4848)', function () {

        it('connects anonymously with auto-generated P-384 cert in Sign mode', function () {
            $client = null;
            try {
                $client = (new ClientBuilder())
                    ->setSecurityPolicy(SecurityPolicy::EccNistP384)
                    ->setSecurityMode(SecurityMode::Sign)
                    ->connect(TestHelper::ENDPOINT_ECC_NIST);
                expect($client)->toBeInstanceOf(Client::class);

                $refs = $client->browse(NodeId::numeric(0, 85));
                expect($refs)->toBeArray()->not->toBeEmpty();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

})->group('integration');
