<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Exception\ServiceException;
use Gianfriaur\OpcuaPhpClient\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\QualifiedName;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

describe('translateBrowsePaths', function () {

    it('translates a single browse path to a NodeId', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $results = $client->translateBrowsePaths([
                [
                    'startingNodeId' => NodeId::numeric(0, 85),
                    'relativePath' => [
                        ['targetName' => new QualifiedName(0, 'Server')],
                    ],
                ],
            ]);

            expect($results)->toHaveCount(1);
            expect(StatusCode::isGood($results[0]['statusCode']))->toBeTrue();
            expect($results[0]['targets'])->not->toBeEmpty();
            expect($results[0]['targets'][0]['targetId']->getIdentifier())->toBe(2253);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('translates a multi-segment path', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $results = $client->translateBrowsePaths([
                [
                    'startingNodeId' => NodeId::numeric(0, 85),
                    'relativePath' => [
                        ['targetName' => new QualifiedName(0, 'Server')],
                        ['targetName' => new QualifiedName(0, 'ServerStatus')],
                    ],
                ],
            ]);

            expect($results)->toHaveCount(1);
            expect(StatusCode::isGood($results[0]['statusCode']))->toBeTrue();
            expect($results[0]['targets'])->not->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('translates multiple paths in a single request', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $results = $client->translateBrowsePaths([
                [
                    'startingNodeId' => NodeId::numeric(0, 85),
                    'relativePath' => [
                        ['targetName' => new QualifiedName(0, 'Server')],
                    ],
                ],
                [
                    'startingNodeId' => NodeId::numeric(0, 84),
                    'relativePath' => [
                        ['targetName' => new QualifiedName(0, 'Objects')],
                    ],
                ],
            ]);

            expect($results)->toHaveCount(2);
            expect(StatusCode::isGood($results[0]['statusCode']))->toBeTrue();
            expect(StatusCode::isGood($results[1]['statusCode']))->toBeTrue();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('returns bad status for non-existent path', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $results = $client->translateBrowsePaths([
                [
                    'startingNodeId' => NodeId::numeric(0, 85),
                    'relativePath' => [
                        ['targetName' => new QualifiedName(0, 'NonExistentNode12345')],
                    ],
                ],
            ]);

            expect($results)->toHaveCount(1);
            expect(StatusCode::isBad($results[0]['statusCode']))->toBeTrue();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');

describe('resolveNodeId', function () {

    it('resolves a simple path from Root', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $nodeId = $client->resolveNodeId('/Objects/Server');
            expect($nodeId->getIdentifier())->toBe(2253);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('resolves a path without leading slash', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $nodeId = $client->resolveNodeId('Objects/Server');
            expect($nodeId->getIdentifier())->toBe(2253);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('resolves a deep path', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $nodeId = $client->resolveNodeId('/Objects/Server/ServerStatus');
            expect($nodeId)->toBeInstanceOf(NodeId::class);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('resolves a path with custom starting node', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $nodeId = $client->resolveNodeId('Server', NodeId::numeric(0, 85));
            expect($nodeId->getIdentifier())->toBe(2253);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('resolves and then reads a value', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $nodeId = $client->resolveNodeId('/Objects/Server/ServerStatus/State');
            $dataValue = $client->read($nodeId);

            expect(StatusCode::isGood($dataValue->getStatusCode()))->toBeTrue();
            expect($dataValue->getValue())->toBe(0);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('throws ServiceException for non-existent path', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            expect(fn() => $client->resolveNodeId('/Objects/NonExistentNode12345'))
                ->toThrow(ServiceException::class);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');
