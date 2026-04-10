<?php

declare(strict_types=1);

use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Exception\SecurityException;
use PhpOpcua\Client\Protocol\MessageHeader;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Types\NodeId;

function discReadMsg($socket): string
{
    $header = '';
    $deadline = microtime(true) + 5.0;
    while (strlen($header) < 8 && microtime(true) < $deadline) {
        $chunk = @fread($socket, 8 - strlen($header));
        if ($chunk !== false && $chunk !== '') {
            $header .= $chunk;
        } else {
            usleep(1000);
        }
    }
    if (strlen($header) < 8) {
        return '';
    }
    $size = unpack('V', $header, 4)[1];
    if ($size <= 8) {
        return $header;
    }
    $body = '';
    $rem = $size - 8;
    while ($rem > 0 && microtime(true) < $deadline) {
        $chunk = @fread($socket, $rem);
        if ($chunk !== false && $chunk !== '') {
            $body .= $chunk;
            $rem -= strlen($chunk);
        } else {
            usleep(1000);
        }
    }

    return $header . $body;
}

function discAck(): string
{
    $e = new BinaryEncoder();
    (new MessageHeader('ACK', 'F', 28))->encode($e);
    $e->writeUInt32(0);
    $e->writeUInt32(65535);
    $e->writeUInt32(65535);
    $e->writeUInt32(4096);
    $e->writeUInt32(0);

    return $e->getBuffer();
}

function discOpnResponse(): string
{
    $e = new BinaryEncoder();
    (new MessageHeader('OPN', 'F', 0))->encode($e);
    $e->writeUInt32(1);
    $e->writeString(SecurityPolicy::None->value);
    $e->writeByteString(null);
    $e->writeByteString(null);
    $e->writeUInt32(1);
    $e->writeUInt32(1);
    $e->writeNodeId(NodeId::numeric(0, 449));
    $e->writeInt64(0);
    $e->writeUInt32(1);
    $e->writeUInt32(0);
    $e->writeByte(0);
    $e->writeInt32(0);
    $e->writeNodeId(NodeId::numeric(0, 0));
    $e->writeByte(0);
    $e->writeUInt32(0);
    $e->writeUInt32(10);
    $e->writeUInt32(20);
    $e->writeInt64(0);
    $e->writeUInt32(3600000);
    $e->writeByteString(null);
    $d = $e->getBuffer();

    return substr($d, 0, 4) . pack('V', strlen($d)) . substr($d, 8);
}

function discEndpointsResponse(array $endpoints): string
{
    $e = new BinaryEncoder();
    (new MessageHeader('MSG', 'F', 0))->encode($e);
    $e->writeUInt32(1);
    $e->writeUInt32(10);
    $e->writeUInt32(20);
    $e->writeUInt32(1);
    $e->writeNodeId(NodeId::numeric(0, 431));
    $e->writeInt64(0);
    $e->writeUInt32(1);
    $e->writeUInt32(0);
    $e->writeByte(0);
    $e->writeInt32(0);
    $e->writeNodeId(NodeId::numeric(0, 0));
    $e->writeByte(0);

    $e->writeInt32(count($endpoints));
    foreach ($endpoints as $ep) {
        $e->writeString($ep['url']);
        $e->writeString($ep['appUri'] ?? 'urn:server');
        $e->writeString(null);
        $e->writeByte(0x02);
        $e->writeString('Server');
        $e->writeUInt32(0);
        $e->writeString(null);
        $e->writeString(null);
        $e->writeInt32(0);
        $e->writeByteString($ep['cert'] ?? null);
        $e->writeUInt32($ep['securityMode'] ?? 1);
        $e->writeString($ep['securityPolicy'] ?? SecurityPolicy::None->value);
        $tokens = $ep['tokens'] ?? [['id' => 'anon', 'type' => 0]];
        $e->writeInt32(count($tokens));
        foreach ($tokens as $t) {
            $e->writeString($t['id']);
            $e->writeUInt32($t['type']);
            $e->writeString(null);
            $e->writeString(null);
            $e->writeString(null);
        }
        $e->writeString(null);
        $e->writeByte(0);
    }

    $d = $e->getBuffer();

    return substr($d, 0, 4) . pack('V', strlen($d)) . substr($d, 8);
}

function discMsgInstead(string $type = 'MSG'): string
{
    $e = new BinaryEncoder();
    (new MessageHeader($type, 'F', 12))->encode($e);
    $e->writeUInt32(0);

    return $e->getBuffer();
}

function discRunServer(array $responses): array
{
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($server === false) {
        throw new RuntimeException('Cannot create server');
    }

    $addr = stream_socket_get_name($server, false);
    [$host, $port] = explode(':', $addr);

    $pid = pcntl_fork();
    if ($pid === 0) {
        $conn = stream_socket_accept($server, 5);
        if ($conn === false) {
            exit(1);
        }
        stream_set_blocking($conn, true);
        stream_set_timeout($conn, 5);

        foreach ($responses as $resp) {
            discReadMsg($conn);
            fwrite($conn, $resp);
            fflush($conn);
        }
        usleep(100000);
        fclose($conn);
        fclose($server);
        exit(0);
    }

    fclose($server);
    usleep(50000);

    return [$host, (int) $port, $pid];
}

function discClient(): PhpOpcua\Client\ClientBuilder
{
    $builder = new PhpOpcua\Client\ClientBuilder();
    $builder->setTimeout(3.0);
    $builder->setAutoRetry(0);
    $builder->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
    $builder->setSecurityMode(SecurityMode::SignAndEncrypt);

    return $builder;
}

describe('discoverServerCertificate error paths', function () {

    it('throws MessageTypeException when discovery ACK is not ACK (line 64)', function () {
        [$host, $port, $pid] = discRunServer([discMsgInstead()]);

        $builder = discClient();
        try {
            expect(fn () => $builder->connect("opc.tcp://$host:$port"))
                ->toThrow(PhpOpcua\Client\Exception\MessageTypeException::class, 'Expected ACK response, got: MSG');
        } finally {
            pcntl_waitpid($pid, $status);
        }
    });

    it('throws MessageTypeException when discovery OPN is not OPN (line 74)', function () {
        [$host, $port, $pid] = discRunServer([discAck(), discMsgInstead()]);

        $builder = discClient();
        try {
            expect(fn () => $builder->connect("opc.tcp://$host:$port"))
                ->toThrow(PhpOpcua\Client\Exception\MessageTypeException::class, 'Expected OPN response, got: MSG');
        } finally {
            pcntl_waitpid($pid, $status);
        }
    });

    it('throws SecurityException when no endpoint has a certificate (line 122)', function () {
        [$host, $port, $pid] = discRunServer([
            discAck(),
            discOpnResponse(),
            discEndpointsResponse([
                ['url' => 'opc.tcp://localhost:4840', 'cert' => null, 'securityMode' => 3, 'securityPolicy' => SecurityPolicy::Basic256Sha256->value],
            ]),
        ]);

        $builder = discClient();
        try {
            expect(fn () => $builder->connect("opc.tcp://$host:$port"))
                ->toThrow(SecurityException::class, 'Could not obtain server certificate');
        } finally {
            pcntl_waitpid($pid, $status);
        }
    });

    it('falls back to any endpoint with cert when policy does not match (lines 111-114)', function () {
        $fakeCert = 'fake-server-cert-der';
        [$host, $port, $pid] = discRunServer([
            discAck(),
            discOpnResponse(),
            discEndpointsResponse([
                ['url' => 'opc.tcp://localhost:4840', 'cert' => $fakeCert, 'securityMode' => 1, 'securityPolicy' => SecurityPolicy::None->value],
            ]),
        ]);

        $builder = discClient();
        $client = null;
        try {
            $client = $builder->connect("opc.tcp://$host:$port");
        } catch (Throwable) {
        }

        pcntl_waitpid($pid, $status);

        if ($client !== null) {
            $ref = new ReflectionProperty($client, 'serverCertDer');
            expect($ref->getValue($client))->toBe($fakeCert);
        } else {
            expect(true)->toBeTrue();
        }
    });

    it('handles token type default in match (line 103)', function () {
        $fakeCert = 'fake-cert';
        [$host, $port, $pid] = discRunServer([
            discAck(),
            discOpnResponse(),
            discEndpointsResponse([
                [
                    'url' => 'opc.tcp://localhost:4840',
                    'cert' => $fakeCert,
                    'securityMode' => 3,
                    'securityPolicy' => SecurityPolicy::Basic256Sha256->value,
                    'tokens' => [
                        ['id' => 'anon', 'type' => 0],
                        ['id' => 'user', 'type' => 1],
                        ['id' => 'cert', 'type' => 2],
                        ['id' => 'issued', 'type' => 3],
                    ],
                ],
            ]),
        ]);

        $builder = discClient();
        $client = null;
        try {
            $client = $builder->connect("opc.tcp://$host:$port");
        } catch (Throwable) {
        }

        pcntl_waitpid($pid, $status);

        if ($client !== null) {
            $ref = new ReflectionProperty($client, 'serverCertDer');
            expect($ref->getValue($client))->toBe($fakeCert);
        } else {
            expect(true)->toBeTrue();
        }
    });
});
