<?php

declare(strict_types=1);

use PhpOpcua\Client\Client;
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;

describe('Certificate Validation', function () {

    describe('Certificate auth on strict server (4842) - no auto-accept', function () {

        it('connects with trusted client certificate and reads a value', function () {
            $client = null;
            try {
                $client = (new ClientBuilder())
                    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
                    ->setSecurityMode(SecurityMode::SignAndEncrypt)
                    ->setClientCertificate(
                        TestHelper::getClientCertPath(),
                        TestHelper::getClientKeyPath(),
                        TestHelper::getCaCertPath(),
                    )
                    ->setUserCertificate(
                        TestHelper::getClientCertPath(),
                        TestHelper::getClientKeyPath(),
                    )
                    ->connect(TestHelper::ENDPOINT_CERTIFICATE);

                expect($client)->toBeInstanceOf(Client::class);

                // Verify connection works by reading ServerStatus.State
                $dv = $client->read(NodeId::numeric(0, 2259));
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeInt()->toBe(0); // Running
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('rejects connection with untrusted self-signed certificate', function () {
            expect(function () {
                $client = (new ClientBuilder())
                    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
                    ->setSecurityMode(SecurityMode::SignAndEncrypt)
                    ->setClientCertificate(
                        TestHelper::getSelfSignedCertPath(),
                        TestHelper::getSelfSignedKeyPath(),
                    )
                    ->setUserCertificate(
                        TestHelper::getSelfSignedCertPath(),
                        TestHelper::getSelfSignedKeyPath(),
                    )
                    ->connect(TestHelper::ENDPOINT_CERTIFICATE);
            })->toThrow(Exception::class);
        })->group('integration');

        it('rejects anonymous connection (no credentials)', function () {
            expect(function () {
                $client = (new ClientBuilder())
                    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
                    ->setSecurityMode(SecurityMode::SignAndEncrypt)
                    ->setClientCertificate(
                        TestHelper::getClientCertPath(),
                        TestHelper::getClientKeyPath(),
                        TestHelper::getCaCertPath(),
                    )
                    // No setUserCertificate, no setUserCredentials → anonymous identity
                    ->connect(TestHelper::ENDPOINT_CERTIFICATE);
            })->toThrow(Exception::class);
        })->group('integration');

    });

    describe('Trusted vs untrusted on auto-accept server (4845)', function () {

        it('connects with trusted certificate', function () {
            $client = null;
            try {
                $client = TestHelper::connectWithCertificate(
                    TestHelper::ENDPOINT_AUTO_ACCEPT,
                    SecurityPolicy::Basic256Sha256,
                    SecurityMode::SignAndEncrypt,
                );
                expect($client)->toBeInstanceOf(Client::class);

                $dv = $client->read(NodeId::numeric(0, 2259));
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('rejects self-signed certificate without OPC UA SAN (even with auto-accept)', function () {
            // Self-signed cert lacks the ApplicationUri SAN required by OPC UA,
            // so the connection fails during session establishment
            expect(function () {
                (new ClientBuilder())
                    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
                    ->setSecurityMode(SecurityMode::SignAndEncrypt)
                    ->setClientCertificate(
                        TestHelper::getSelfSignedCertPath(),
                        TestHelper::getSelfSignedKeyPath(),
                    )
                    ->setUserCertificate(
                        TestHelper::getSelfSignedCertPath(),
                        TestHelper::getSelfSignedKeyPath(),
                    )
                    ->connect(TestHelper::ENDPOINT_AUTO_ACCEPT);
            })->toThrow(Exception::class);
        })->group('integration');

    });

})->group('integration');
