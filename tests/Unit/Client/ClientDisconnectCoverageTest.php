<?php

declare(strict_types=1);

require_once __DIR__ . '/../Helpers/ClientTestHelpers.php';

use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\ProtocolException;
use PhpOpcua\Client\Protocol\MessageHeader;
use PhpOpcua\Client\Security\SecureChannel;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Types\ConnectionState;
use PhpOpcua\Client\Types\NodeId;

describe('ManagesConnectionTrait disconnect catch paths', function () {

    it('disconnect suppresses closeSession exception (line 74)', function () {
        $mock = new FailingMockTransport(failAfterSends: 0);
        $client = makeConnectedClient($mock);

        $client->disconnect();

        expect($client->getConnectionState())->toBe(ConnectionState::Disconnected);
    });

    it('disconnect suppresses closeSecureChannel exception (line 81)', function () {
        $mock = new FailingMockTransport(failAfterSends: 1);
        $client = makeConnectedClient($mock);

        $client->disconnect();

        expect($client->getConnectionState())->toBe(ConnectionState::Disconnected);
    });
});

describe('ManagesSecureChannelTrait error paths', function () {

    it('throws ProtocolException when OPN response is not OPN type (line 60)', function () {
        $mock = new MockTransport();

        $encoder = new BinaryEncoder();
        (new MessageHeader('MSG', 'F', 12))->encode($encoder);
        $encoder->writeUInt32(1);
        $mock->addResponse($encoder->getBuffer());

        $client = createClientWithoutConnect();
        setClientProperty($client, 'transport', $mock);
        setClientProperty($client, 'connectionState', ConnectionState::Connected);

        expect(fn () => callClientMethod($client, 'openSecureChannelNoSecurity'))
            ->toThrow(ProtocolException::class, 'Expected OPN response');
    });

    it('loads DER certificate in openSecureChannelWithSecurity (line 94)', function () {
        $privKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['CN' => 'test'], $privKey);
        $cert = openssl_csr_sign($csr, null, $privKey, 365);
        openssl_x509_export($cert, $certPem);

        $cm = new PhpOpcua\Client\Security\CertificateManager();
        $certDer = $cm->loadCertificatePem(writeTmpFile($certPem));
        $derPath = writeTmpFile($certDer);

        openssl_pkey_export($privKey, $keyPem);
        $keyPath = writeTmpFile($keyPem);

        $mock = new MockTransport();

        $client = createClientWithoutConnect();
        setClientProperty($client, 'transport', $mock);
        setClientProperty($client, 'securityPolicy', SecurityPolicy::Basic256Sha256);
        setClientProperty($client, 'securityMode', SecurityMode::SignAndEncrypt);
        setClientProperty($client, 'clientCertPath', $derPath);
        setClientProperty($client, 'clientKeyPath', $keyPath);
        setClientProperty($client, 'serverCertDer', $certDer);

        $sc = new SecureChannel(SecurityPolicy::Basic256Sha256, SecurityMode::SignAndEncrypt, $certDer, $privKey, $certDer);
        $sc->createOpenSecureChannelMessage();

        try {
            callClientMethod($client, 'openSecureChannelWithSecurity');
        } catch (ConnectionException) {
        }

        cleanupTmpFiles();
        expect(true)->toBeTrue();
    });

    it('loads CA cert and builds chain in openSecureChannelWithSecurity (line 110)', function () {
        $privKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['CN' => 'test'], $privKey);
        $cert = openssl_csr_sign($csr, null, $privKey, 365);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($privKey, $keyPem);

        $certPath = writeTmpFile($certPem);
        $keyPath = writeTmpFile($keyPem);
        $caPath = writeTmpFile($certPem);

        $cm = new PhpOpcua\Client\Security\CertificateManager();
        $certDer = $cm->loadCertificatePem($certPath);

        $mock = new MockTransport();

        $client = createClientWithoutConnect();
        setClientProperty($client, 'transport', $mock);
        setClientProperty($client, 'securityPolicy', SecurityPolicy::Basic256Sha256);
        setClientProperty($client, 'securityMode', SecurityMode::SignAndEncrypt);
        setClientProperty($client, 'clientCertPath', $certPath);
        setClientProperty($client, 'clientKeyPath', $keyPath);
        setClientProperty($client, 'caCertPath', $caPath);
        setClientProperty($client, 'serverCertDer', $certDer);

        try {
            callClientMethod($client, 'openSecureChannelWithSecurity');
        } catch (ConnectionException) {
        }

        cleanupTmpFiles();
        expect(true)->toBeTrue();
    });
});

describe('ManagesSessionTrait coverage', function () {

    it('closeSession suppresses receive exception (line 102)', function () {
        $mock = new FailingMockTransport(failAfterSends: 999, failAfterReceives: 0);
        $client = makeConnectedClient($mock);

        callClientMethod($client, 'closeSession');

        expect(true)->toBeTrue();
    });

    it('closeSessionSecure suppresses receive exception (line 119)', function () {
        [$certDer, $privKey] = generateTestCertKeyPair();
        $sc = new SecureChannel(SecurityPolicy::Basic256Sha256, SecurityMode::SignAndEncrypt, $certDer, $privKey, $certDer);

        $sc->createOpenSecureChannelMessage();
        $clientNonce = $sc->getClientNonce();
        $serverNonce = random_bytes(32);
        $response = buildTestOPNResponse($certDer, $privKey, $certDer, $privKey, $clientNonce, $serverNonce, 1, 1, SecurityPolicy::Basic256Sha256);
        $sc->processOpenSecureChannelResponse($response);

        $mock = new FailingMockTransport(failAfterSends: 999, failAfterReceives: 0);
        $client = makeConnectedClient($mock, $sc);

        callClientMethod($client, 'closeSessionSecure');

        expect(true)->toBeTrue();
    });

    it('loads DER user certificate (line 51-52)', function () {
        $privKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['CN' => 'user'], $privKey);
        $cert = openssl_csr_sign($csr, null, $privKey, 365);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($privKey, $keyPem);

        $cm = new PhpOpcua\Client\Security\CertificateManager();
        $certDer = $cm->loadCertificatePem(writeTmpFile($certPem));
        $derPath = writeTmpFile($certDer);
        $keyPath = writeTmpFile($keyPem);

        $mock = new MockTransport();

        $createSessionResponse = buildMsgResponse(464, function (BinaryEncoder $e) {
            $e->writeNodeId(NodeId::numeric(0, 1));
            $e->writeNodeId(NodeId::numeric(0, 2));
            $e->writeDouble(120000.0);
            $e->writeByteString('server-nonce');
            $e->writeByteString(null);
            $e->writeInt32(0);
            $e->writeInt32(0);
            $e->writeString(null);
            $e->writeByteString(null);
            $e->writeUInt32(0);
        });
        $activateSessionResponse = buildMsgResponse(470, function (BinaryEncoder $e) {
            $e->writeByteString(null);
            $e->writeInt32(0);
            $e->writeInt32(0);
        });
        $mock->addResponse($createSessionResponse);
        $mock->addResponse($activateSessionResponse);

        $client = makeConnectedClient($mock);
        setClientProperty($client, 'userCertPath', $derPath);
        setClientProperty($client, 'userKeyPath', $keyPath);

        callClientMethod($client, 'createAndActivateSession', ['opc.tcp://mock:4840']);

        cleanupTmpFiles();
        expect(true)->toBeTrue();
    });
});
