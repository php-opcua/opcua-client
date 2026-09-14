<?php

declare(strict_types=1);

use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\BrowseDirection;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;

function browseStatusCode(Closure $call): int
{
    try {
        $call();

        return StatusCode::Good;
    } catch (ServiceException $e) {
        return $e->getStatusCode();
    }
}

describe('Browse result status codes against a real server', function () {

    it('raises BadNodeIdUnknown for a node that does not exist, without caching the failure', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $missing = NodeId::parse('ns=1;s=TestServer/DoesNotExist');

            expect(browseStatusCode(fn () => $client->browse($missing)))->toBe(StatusCode::BadNodeIdUnknown);
            expect(browseStatusCode(fn () => $client->browse($missing)))->toBe(StatusCode::BadNodeIdUnknown);
            expect(browseStatusCode(fn () => $client->browseAll($missing)))->toBe(StatusCode::BadNodeIdUnknown);
            expect(browseStatusCode(fn () => $client->browseRecursive($missing)))->toBe(StatusCode::BadNodeIdUnknown);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('raises BadReferenceTypeIdInvalid for an unknown reference type', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            expect(browseStatusCode(fn () => $client->browse(NodeId::numeric(0, 85), BrowseDirection::Forward, NodeId::numeric(0, 99999))))
                ->toBe(0x804C0000);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('raises BadContinuationPointInvalid for BrowseNext with an unknown continuation point', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            expect(browseStatusCode(fn () => $client->browseNext('not-a-continuation-point')))->toBe(0x804A0000);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('still returns an empty array for an existing node without children', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $leaf = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'Int32Value']);

            expect($client->browse($leaf, useCache: false))->toBe([]);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');
});
