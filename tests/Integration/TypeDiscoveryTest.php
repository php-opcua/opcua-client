<?php

declare(strict_types=1);

use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\NodeId;

describe('Automatic DataType discovery', function () {

    it('discoverDataTypes discovers the custom types exposed by the test server', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $count = $client->discoverDataTypes();

            expect($count)->toBeGreaterThanOrEqual(1);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('decodes an ExtensionObject value via a discovered codec', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $count = $client->discoverDataTypes();
            expect($count)->toBeGreaterThanOrEqual(1);

            $nodeId = $client->resolveNodeId('/Objects/1:TestServer/1:ExtensionObjects/1:PointValue');
            $dv = $client->read($nodeId);

            $point = $dv->getValue();
            expect($point)->toBeArray();
            expect($point)->toHaveCount(3);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('discoverDataTypes with namespace filter returns without error', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $count = $client->discoverDataTypes(namespaceIndex: 3);

            expect($count)->toBeInt();
            expect($count)->toBeGreaterThanOrEqual(0);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('returns 0 for a namespace with no custom types', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $count = $client->discoverDataTypes(namespaceIndex: 99);

            expect($count)->toBe(0);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('does not overwrite manually registered codecs', function () {
        $client = null;
        try {
            $repo = new PhpOpcua\Client\Repository\ExtensionObjectRepository();
            $repo->register(NodeId::numeric(3, 3010), new class() implements PhpOpcua\Client\Encoding\ExtensionObjectCodec {
                public function decode(PhpOpcua\Client\Encoding\BinaryDecoder $decoder): array
                {
                    return ['custom' => true, 'x' => $decoder->readDouble(), 'y' => $decoder->readDouble(), 'z' => $decoder->readDouble()];
                }

                public function encode(PhpOpcua\Client\Encoding\BinaryEncoder $encoder, mixed $value): void
                {
                }
            });

            $client = (new ClientBuilder($repo))->connect(TestHelper::ENDPOINT_NO_SECURITY);
            $client->discoverDataTypes();

            $nodeId = $client->resolveNodeId('/Objects/1:TestServer/1:ExtensionObjects/1:PointValue');
            $dv = $client->read($nodeId);

            $point = $dv->getValue();
            expect($point['custom'])->toBeTrue();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('two clients have isolated repositories', function () {
        $client1 = null;
        $client2 = null;
        try {
            $client1 = TestHelper::connectNoSecurity();
            $client2 = TestHelper::connectNoSecurity();

            $client1->getExtensionObjectRepository()->register(
                NodeId::numeric(99, 9999),
                new class() implements PhpOpcua\Client\Encoding\ExtensionObjectCodec {
                    public function decode(PhpOpcua\Client\Encoding\BinaryDecoder $d): array
                    {
                        return [];
                    }

                    public function encode(PhpOpcua\Client\Encoding\BinaryEncoder $e, mixed $v): void
                    {
                    }
                },
            );

            expect($client1->getExtensionObjectRepository()->has(NodeId::numeric(99, 9999)))->toBeTrue();
            expect($client2->getExtensionObjectRepository()->has(NodeId::numeric(99, 9999)))->toBeFalse();
        } finally {
            TestHelper::safeDisconnect($client1);
            TestHelper::safeDisconnect($client2);
        }
    })->group('integration');

})->group('integration');
