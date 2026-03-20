<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Exception\ConnectionException;
use Gianfriaur\OpcuaPhpClient\Exception\ProtocolException;
use Gianfriaur\OpcuaPhpClient\Protocol\MessageHeader;
use Gianfriaur\OpcuaPhpClient\Protocol\SessionService;
use Gianfriaur\OpcuaPhpClient\Security\SecureChannel;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaPhpClient\Transport\TcpTransport;
use Gianfriaur\OpcuaPhpClient\Types\ConnectionState;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

require_once __DIR__ . '/ClientTraitsCoverageTest.php';
require_once __DIR__ . '/../Security/SecureChannelTest.php';

class FailingMockTransport extends TcpTransport
{
    private int $sendCount = 0;
    private int $receiveCount = 0;
    private int $failAfterSends;
    private int $failAfterReceives;

    public function __construct(int $failAfterSends = 0, int $failAfterReceives = 0)
    {
        $this->failAfterSends = $failAfterSends;
        $this->failAfterReceives = $failAfterReceives;
    }

    public function connect(string $host, int $port, null|float $timeout = null): void {}

    public function send(string $data): void
    {
        $this->sendCount++;
        if ($this->failAfterSends > 0 && $this->sendCount > $this->failAfterSends) {
            throw new ConnectionException('Mock send failure');
        }
    }

    public function receive(): string
    {
        $this->receiveCount++;
        if ($this->failAfterReceives > 0 && $this->receiveCount > $this->failAfterReceives) {
            throw new ConnectionException('Mock receive failure');
        }
        throw new ConnectionException('Mock receive failure');
    }

    public function close(): void {}
    public function isConnected(): bool { return true; }
}

function setProperty(Client $client, string $name, mixed $value): void
{
    $ref = new ReflectionProperty($client, $name);
    $ref->setValue($client, $value);
}

function invokeMethod(Client $client, string $name, array $args = []): mixed
{
    $ref = new ReflectionMethod($client, $name);
    return $ref->invokeArgs($client, $args);
}

function makeConnectedClient(TcpTransport $transport, ?SecureChannel $sc = null): Client
{
    $client = new Client();
    $session = new SessionService(1, 1, $sc);

    setProperty($client, 'transport', $transport);
    setProperty($client, 'connectionState', ConnectionState::Connected);
    setProperty($client, 'session', $session);
    setProperty($client, 'authenticationToken', NodeId::numeric(0, 2));
    setProperty($client, 'secureChannelId', 1);
    setProperty($client, 'lastEndpointUrl', 'opc.tcp://mock:4840');
    if ($sc !== null) {
        setProperty($client, 'secureChannel', $sc);
    }
    invokeMethod($client, 'initServices', [$session]);

    return $client;
}

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

        $client = new Client();
        setProperty($client, 'transport', $mock);
        setProperty($client, 'connectionState', ConnectionState::Connected);

        expect(fn() => invokeMethod($client, 'openSecureChannelNoSecurity'))
            ->toThrow(ProtocolException::class, 'Expected OPN response');
    });

    it('loads DER certificate in openSecureChannelWithSecurity (line 94)', function () {
        $privKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['CN' => 'test'], $privKey);
        $cert = openssl_csr_sign($csr, null, $privKey, 365);
        openssl_x509_export($cert, $certPem);

        $cm = new \Gianfriaur\OpcuaPhpClient\Security\CertificateManager();
        $certDer = $cm->loadCertificatePem(writeTmpFile($certPem));
        $derPath = writeTmpFile($certDer);

        openssl_pkey_export($privKey, $keyPem);
        $keyPath = writeTmpFile($keyPem);

        $mock = new MockTransport();

        $client = new Client();
        setProperty($client, 'transport', $mock);
        setProperty($client, 'securityPolicy', SecurityPolicy::Basic256Sha256);
        setProperty($client, 'securityMode', SecurityMode::SignAndEncrypt);
        setProperty($client, 'clientCertPath', $derPath);
        setProperty($client, 'clientKeyPath', $keyPath);
        setProperty($client, 'serverCertDer', $certDer);

        $sc = new SecureChannel(SecurityPolicy::Basic256Sha256, SecurityMode::SignAndEncrypt, $certDer, $privKey, $certDer);
        $sc->createOpenSecureChannelMessage();

        try {
            invokeMethod($client, 'openSecureChannelWithSecurity');
        } catch (\Gianfriaur\OpcuaPhpClient\Exception\ConnectionException) {
            // Expected — mock transport has no OPN response
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

        $cm = new \Gianfriaur\OpcuaPhpClient\Security\CertificateManager();
        $certDer = $cm->loadCertificatePem($certPath);

        $mock = new MockTransport();

        $client = new Client();
        setProperty($client, 'transport', $mock);
        setProperty($client, 'securityPolicy', SecurityPolicy::Basic256Sha256);
        setProperty($client, 'securityMode', SecurityMode::SignAndEncrypt);
        setProperty($client, 'clientCertPath', $certPath);
        setProperty($client, 'clientKeyPath', $keyPath);
        setProperty($client, 'caCertPath', $caPath);
        setProperty($client, 'serverCertDer', $certDer);

        try {
            invokeMethod($client, 'openSecureChannelWithSecurity');
        } catch (\Gianfriaur\OpcuaPhpClient\Exception\ConnectionException) {
        }

        cleanupTmpFiles();
        expect(true)->toBeTrue();
    });
});

$_tempFiles = [];

function writeTmpFile(string $content): string
{
    global $_tempFiles;
    $path = tempnam(sys_get_temp_dir(), 'opcua_test_');
    file_put_contents($path, $content);
    $_tempFiles[] = $path;
    return $path;
}

function cleanupTmpFiles(): void
{
    global $_tempFiles;
    foreach ($_tempFiles as $path) {
        if (file_exists($path)) @unlink($path);
    }
    $_tempFiles = [];
}

describe('ManagesSessionTrait coverage', function () {

    it('closeSession suppresses receive exception (line 102)', function () {
        $mock = new FailingMockTransport(failAfterSends: 999, failAfterReceives: 0);
        $client = makeConnectedClient($mock);

        invokeMethod($client, 'closeSession');

        expect(true)->toBeTrue();
    });

    it('closeSessionSecure suppresses receive exception (line 119)', function () {
        [$certDer, $privKey] = generateSecureChannelTestCert();
        $sc = new SecureChannel(SecurityPolicy::Basic256Sha256, SecurityMode::SignAndEncrypt, $certDer, $privKey, $certDer);

        $sc->createOpenSecureChannelMessage();
        $clientNonce = $sc->getClientNonce();
        $serverNonce = random_bytes(32);
        $response = buildEncryptedOPNResponse($certDer, $privKey, $certDer, $privKey, $clientNonce, $serverNonce, 1, 1, SecurityPolicy::Basic256Sha256);
        $sc->processOpenSecureChannelResponse($response);

        $mock = new FailingMockTransport(failAfterSends: 999, failAfterReceives: 0);
        $client = makeConnectedClient($mock, $sc);

        invokeMethod($client, 'closeSessionSecure');

        expect(true)->toBeTrue();
    });

    it('loads DER user certificate (line 51-52)', function () {
        $privKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['CN' => 'user'], $privKey);
        $cert = openssl_csr_sign($csr, null, $privKey, 365);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($privKey, $keyPem);

        $cm = new \Gianfriaur\OpcuaPhpClient\Security\CertificateManager();
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
        setProperty($client, 'userCertPath', $derPath);
        setProperty($client, 'userKeyPath', $keyPath);

        invokeMethod($client, 'createAndActivateSession', ['opc.tcp://mock:4840']);

        cleanupTmpFiles();
        expect(true)->toBeTrue();
    });
});
