<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Exception\ConnectionException;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

describe('Client operations that exercise error paths', function () {

    it('throws ConnectionException on browseAll when not connected', function () {
        $client = new Client();
        expect(fn() => $client->browseAll(NodeId::numeric(0, 85)))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws ConnectionException on browseRecursive when not connected', function () {
        $client = new Client();
        expect(fn() => $client->browseRecursive(NodeId::numeric(0, 85)))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws ConnectionException on getEndpoints when not connected', function () {
        $client = new Client();
        expect(fn() => $client->getEndpoints('opc.tcp://localhost:4840'))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws ConnectionException on historyReadProcessed when not connected', function () {
        $client = new Client();
        expect(fn() => $client->historyReadProcessed(
            NodeId::numeric(2, 1001),
            new DateTimeImmutable('-1 hour'),
            new DateTimeImmutable(),
            3600000.0,
            NodeId::numeric(0, 2342),
        ))->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws ConnectionException on historyReadAtTime when not connected', function () {
        $client = new Client();
        expect(fn() => $client->historyReadAtTime(
            NodeId::numeric(2, 1001),
            [new DateTimeImmutable()],
        ))->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws ConnectionException on translateBrowsePaths when not connected', function () {
        $client = new Client();
        expect(fn() => $client->translateBrowsePaths([
            [
                'startingNodeId' => NodeId::numeric(0, 85),
                'relativePath' => [
                    ['targetName' => new \Gianfriaur\OpcuaPhpClient\Types\QualifiedName(0, 'Server')],
                ],
            ],
        ]))->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });
});
