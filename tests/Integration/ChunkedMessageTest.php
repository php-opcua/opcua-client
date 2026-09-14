<?php

declare(strict_types=1);

use PhpOpcua\Client\Client;
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Module\FileTransfer\OpenFileMode;
use PhpOpcua\Client\Module\ReadWrite\ReadService;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\BuiltinType;

dataset('chunkedConnections', [
    'no security' => [fn (): Client => TestHelper::connectNoSecurity()],
    'Basic256Sha256 Sign' => [fn (): Client => (new ClientBuilder())
        ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
        ->setSecurityMode(SecurityMode::Sign)
        ->setClientCertificate(TestHelper::getClientCertPath(), TestHelper::getClientKeyPath(), TestHelper::getCaCertPath())
        ->connect(TestHelper::ENDPOINT_SIGN_ONLY)],
    'Basic256Sha256 SignAndEncrypt' => [fn (): Client => TestHelper::connectWithCertificate(TestHelper::ENDPOINT_ALL_SECURITY, SecurityPolicy::Basic256Sha256, SecurityMode::SignAndEncrypt)],
]);

describe('Chunked messages against a real server', function () {

    it('receives a response larger than one chunk in a single call', function (Closure $connect) {
        $client = null;
        try {
            $client = $connect();
            $node = TestHelper::browseToNode($client, ['TestServer', 'Files', 'LargeFile']);

            $handle = $client->openFile($node, OpenFileMode::Read);
            $content = $client->readFile($node, $handle, 262144);
            $client->closeFile($node, $handle);

            expect(strlen($content))->toBe(262144);
            expect($content)->toBe(str_repeat(implode('', array_map('chr', range(0, 255))), 1024));

            $stringNode = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'StringValue']);
            expect($client->read($stringNode)->getValue())->toBeString();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->with('chunkedConnections')->group('integration');

    it('skips the response to an abandoned request', function (Closure $connect) {
        $client = null;
        try {
            $client = $connect();
            $int32Node = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'Int32Value']);
            $stringNode = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'StringValue']);
            $client->write($stringNode, 'chunked-message-test', BuiltinType::String);

            $session = (fn () => $this->session)->call($client);
            $abandoned = (new ReadService($session))->encodeReadRequest($client->nextRequestId(), $int32Node, $client->getAuthToken());
            $client->send($abandoned);

            expect($client->read($stringNode)->getValue())->toBe('chunked-message-test');
            expect($client->read($int32Node)->getValue())->toBeInt();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->with('chunkedConnections')->group('integration');
});
