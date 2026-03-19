<?php /** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Encoding\ExtensionObjectCodec;
use Gianfriaur\OpcuaPhpClient\Repository\ExtensionObjectRepository;
use Gianfriaur\OpcuaPhpClient\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

class ServerStatusCodec implements ExtensionObjectCodec
{
    public function decode(BinaryDecoder $decoder): array
    {
        $startTime = $decoder->readDateTime();
        $currentTime = $decoder->readDateTime();
        $state = $decoder->readInt32();

        $buildInfo = [
            'productUri' => $decoder->readString(),
            'manufacturerName' => $decoder->readString(),
            'productName' => $decoder->readString(),
            'softwareVersion' => $decoder->readString(),
            'buildNumber' => $decoder->readString(),
            'buildDate' => $decoder->readDateTime(),
        ];

        $secondsTillShutdown = $decoder->readUInt32();
        $shutdownReason = $decoder->readLocalizedText();

        return [
            'startTime' => $startTime,
            'currentTime' => $currentTime,
            'state' => $state,
            'buildInfo' => $buildInfo,
            'secondsTillShutdown' => $secondsTillShutdown,
            'shutdownReason' => $shutdownReason,
        ];
    }

    public function encode(BinaryEncoder $encoder, mixed $value): void
    {
        // Not needed for this test
    }
}

beforeEach(function () {
    ExtensionObjectRepository::clear();
});

afterEach(function () {
    ExtensionObjectRepository::clear();
});

describe('ExtensionObject codec with real server', function () {

    it('reads ServerStatus as raw blob without codec', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $dv = $client->read(NodeId::numeric(0, 2256));
            expect(StatusCode::isGood($dv->getStatusCode()))->toBeTrue();

            $value = $dv->getValue();
            expect($value)->toBeArray();
            expect($value)->toHaveKeys(['typeId', 'encoding', 'body']);
            expect($value['encoding'])->toBe(1);
            expect($value['typeId']->getIdentifier())->toBe(864);
            expect($value['body'])->toBeString()->not->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('reads ServerStatus decoded with codec', function () {
        ExtensionObjectRepository::register(NodeId::numeric(0, 864), ServerStatusCodec::class);

        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $dv = $client->read(NodeId::numeric(0, 2256));
            expect(StatusCode::isGood($dv->getStatusCode()))->toBeTrue();

            $status = $dv->getValue();
            expect($status)->toBeArray();
            expect($status)->toHaveKeys(['startTime', 'currentTime', 'state', 'buildInfo', 'secondsTillShutdown', 'shutdownReason']);

            expect($status['state'])->toBe(0);

            expect($status['startTime'])->toBeInstanceOf(DateTimeImmutable::class);
            expect($status['currentTime'])->toBeInstanceOf(DateTimeImmutable::class);

            $now = new DateTimeImmutable();
            $diff = abs($now->getTimestamp() - $status['currentTime']->getTimestamp());
            expect($diff)->toBeLessThan(60);

            expect($status['secondsTillShutdown'])->toBe(0);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('decoded ServerStatus has valid BuildInfo fields', function () {
        ExtensionObjectRepository::register(NodeId::numeric(0, 864), ServerStatusCodec::class);

        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $dv = $client->read(NodeId::numeric(0, 2256));
            $status = $dv->getValue();

            expect($status)->toHaveKey('buildInfo');
            expect($status['buildInfo'])->toBeArray();
            expect($status['buildInfo'])->toHaveKeys(['productUri', 'manufacturerName', 'productName', 'softwareVersion', 'buildNumber', 'buildDate']);

            expect($status['buildInfo']['productName'])->toBeString();
            expect($status['buildInfo']['softwareVersion'])->toBeString();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('unregistered codec falls back to raw blob', function () {
        ExtensionObjectRepository::register(NodeId::numeric(0, 864), ServerStatusCodec::class);
        ExtensionObjectRepository::unregister(NodeId::numeric(0, 864));

        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $dv = $client->read(NodeId::numeric(0, 2256));
            $value = $dv->getValue();

            expect($value)->toBeArray();
            expect($value)->toHaveKeys(['typeId', 'encoding', 'body']);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');

describe('Custom ExtensionObject nodes (TestPointXYZ)', function () {

    it('browses ExtensionObjects folder and finds PointValue and RangeValue', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $extId = $client->resolveNodeId('/Objects/1:TestServer/1:ExtensionObjects');
            $refs = $client->browse($extId);

            $names = array_map(fn($r) => $r->getBrowseName()->getName(), $refs);
            expect($names)->toContain('PointValue');
            expect($names)->toContain('RangeValue');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('resolves PointValue node via path', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $nodeId = $client->resolveNodeId('/Objects/1:TestServer/1:ExtensionObjects/1:PointValue');
            expect($nodeId)->toBeInstanceOf(NodeId::class);
            expect($nodeId->getNamespaceIndex())->toBe(1);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('reads PointValue as raw ExtensionObject without codec', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $nodeId = $client->resolveNodeId('/Objects/1:TestServer/1:ExtensionObjects/1:PointValue');
            $dv = $client->read($nodeId);
            expect(StatusCode::isGood($dv->getStatusCode()))->toBeTrue();
            expect($dv->getVariant()->getType()->name)->toBe('ExtensionObject');

            $value = $dv->getValue();
            expect($value)->toBeArray();
            expect($value)->toHaveKeys(['typeId', 'encoding', 'body']);
            expect($value['encoding'])->toBe(1);
            expect($value['typeId']->getIdentifier())->toBe(3010);
            expect($value['body'])->toBeString();
            expect(strlen($value['body']))->toBe(24);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('reads PointValue decoded with TestPointXYZCodec', function () {
        ExtensionObjectRepository::register(NodeId::numeric(3, 3010), new class implements ExtensionObjectCodec {
            public function decode(BinaryDecoder $decoder): array
            {
                return [
                    'x' => $decoder->readDouble(),
                    'y' => $decoder->readDouble(),
                    'z' => $decoder->readDouble(),
                ];
            }

            public function encode(BinaryEncoder $encoder, mixed $value): void
            {
                $encoder->writeDouble($value['x']);
                $encoder->writeDouble($value['y']);
                $encoder->writeDouble($value['z']);
            }
        });

        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $nodeId = $client->resolveNodeId('/Objects/1:TestServer/1:ExtensionObjects/1:PointValue');
            $dv = $client->read($nodeId);
            expect(StatusCode::isGood($dv->getStatusCode()))->toBeTrue();

            $point = $dv->getValue();
            expect($point)->toBeArray();
            expect($point)->toHaveKeys(['x', 'y', 'z']);
            expect($point['x'])->toBe(1.5);
            expect($point['y'])->toBe(2.5);
            expect($point['z'])->toBe(3.5);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('reads RangeValue decoded with codec', function () {
        ExtensionObjectRepository::register(NodeId::numeric(3, 3011), new class implements ExtensionObjectCodec {
            public function decode(BinaryDecoder $decoder): array
            {
                return [
                    'min' => $decoder->readDouble(),
                    'max' => $decoder->readDouble(),
                    'value' => $decoder->readDouble(),
                ];
            }

            public function encode(BinaryEncoder $encoder, mixed $value): void
            {
                $encoder->writeDouble($value['min']);
                $encoder->writeDouble($value['max']);
                $encoder->writeDouble($value['value']);
            }
        });

        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $nodeId = $client->resolveNodeId('/Objects/1:TestServer/1:ExtensionObjects/1:RangeValue');
            $dv = $client->read($nodeId);
            expect(StatusCode::isGood($dv->getStatusCode()))->toBeTrue();

            $range = $dv->getValue();
            expect($range)->toBeArray();
            expect($range['min'])->toBe(0.0);
            expect($range['max'])->toBe(100.0);
            expect($range['value'])->toBe(42.5);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');
