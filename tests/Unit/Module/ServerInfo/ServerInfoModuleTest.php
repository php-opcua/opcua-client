<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Helpers/ClientTestHelpers.php';

use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Module\ReadWrite\ReadWriteModule;
use PhpOpcua\Client\Module\ServerInfo\BuildInfo;
use PhpOpcua\Client\Module\ServerInfo\ServerInfoModule;
use PhpOpcua\Client\Types\BuiltinType;

function serverInfoStringResponse(string $value): string
{
    return buildMsgResponse(634, function (BinaryEncoder $e) use ($value) {
        $e->writeInt32(1);
        $e->writeByte(0x01);
        $e->writeByte(BuiltinType::String->value);
        $e->writeString($value);
        $e->writeInt32(0);
    });
}

function serverInfoDateTimeResponse(?DateTimeImmutable $value): string
{
    return buildMsgResponse(634, function (BinaryEncoder $e) use ($value) {
        $e->writeInt32(1);
        $e->writeByte(0x01);
        $e->writeByte(BuiltinType::DateTime->value);
        $e->writeDateTime($value);
        $e->writeInt32(0);
    });
}

function serverInfoIntResponse(int $value): string
{
    return buildMsgResponse(634, function (BinaryEncoder $e) use ($value) {
        $e->writeInt32(1);
        $e->writeByte(0x01);
        $e->writeByte(BuiltinType::Int32->value);
        $e->writeInt32($value);
        $e->writeInt32(0);
    });
}

function serverInfoBuildInfoReadMultiResponse(
    string $productName,
    string $manufacturer,
    string $softwareVersion,
    string $buildNumber,
    DateTimeImmutable $buildDate,
): string {
    return buildMsgResponse(634, function (BinaryEncoder $e) use ($productName, $manufacturer, $softwareVersion, $buildNumber, $buildDate) {
        $e->writeInt32(5);
        foreach ([$productName, $manufacturer, $softwareVersion, $buildNumber] as $str) {
            $e->writeByte(0x01);
            $e->writeByte(BuiltinType::String->value);
            $e->writeString($str);
        }
        $e->writeByte(0x01);
        $e->writeByte(BuiltinType::DateTime->value);
        $e->writeDateTime($buildDate);
        $e->writeInt32(0);
    });
}

describe('ServerInfoModule', function () {

    it('registers 6 methods', function () {
        $module = new ServerInfoModule();

        $registeredMethods = [];
        $client = new class($registeredMethods) {
            public function __construct(private array &$methods)
            {
            }

            public function registerMethod(string $name, callable $handler): void
            {
                $this->methods[] = $name;
            }
        };

        $kernel = $this->createMock(PhpOpcua\Client\Kernel\ClientKernelInterface::class);
        $module->setKernel($kernel);
        $module->setClient($client);
        $module->register();

        expect($registeredMethods)->toBe([
            'getServerProductName',
            'getServerManufacturerName',
            'getServerSoftwareVersion',
            'getServerBuildNumber',
            'getServerBuildDate',
            'getServerBuildInfo',
        ]);
    });

    it('requires ReadWriteModule', function () {
        $module = new ServerInfoModule();
        expect($module->requires())->toBe([ReadWriteModule::class]);
    });
});

describe('ServerInfo BuildInfo DTO', function () {

    it('stores all fields', function () {
        $date = new DateTimeImmutable('2025-01-01');
        $info = new BuildInfo('Product', 'Manufacturer', '1.0.0', '42', $date);
        expect($info->productName)->toBe('Product');
        expect($info->manufacturerName)->toBe('Manufacturer');
        expect($info->softwareVersion)->toBe('1.0.0');
        expect($info->buildNumber)->toBe('42');
        expect($info->buildDate)->toBe($date);
    });

    it('accepts null values', function () {
        $info = new BuildInfo(null, null, null, null, null);
        expect($info->productName)->toBeNull();
        expect($info->manufacturerName)->toBeNull();
        expect($info->softwareVersion)->toBeNull();
        expect($info->buildNumber)->toBeNull();
        expect($info->buildDate)->toBeNull();
    });

    it('is readonly', function () {
        $info = new BuildInfo(null, null, null, null, null);
        $ref = new ReflectionClass($info);
        expect($ref->isReadOnly())->toBeTrue();
    });
});

describe('ServerInfoModule execution via connected client', function () {

    it('getServerProductName reads ns=0;i=2262 and returns the string', function () {
        $mock = new MockTransport();
        $mock->addResponse(serverInfoStringResponse('Kepware'));

        $client = setupConnectedClient($mock);
        expect($client->getServerProductName())->toBe('Kepware');
    });

    it('getServerProductName returns null when the server sends a non-string value', function () {
        $mock = new MockTransport();
        $mock->addResponse(serverInfoIntResponse(42));

        $client = setupConnectedClient($mock);
        expect($client->getServerProductName())->toBeNull();
    });

    it('getServerManufacturerName reads ns=0;i=2263 and returns the string', function () {
        $mock = new MockTransport();
        $mock->addResponse(serverInfoStringResponse('Acme Corp'));

        $client = setupConnectedClient($mock);
        expect($client->getServerManufacturerName())->toBe('Acme Corp');
    });

    it('getServerSoftwareVersion reads ns=0;i=2264 and returns the string', function () {
        $mock = new MockTransport();
        $mock->addResponse(serverInfoStringResponse('2.1.3'));

        $client = setupConnectedClient($mock);
        expect($client->getServerSoftwareVersion())->toBe('2.1.3');
    });

    it('getServerBuildNumber reads ns=0;i=2265 and returns the string', function () {
        $mock = new MockTransport();
        $mock->addResponse(serverInfoStringResponse('build.4567'));

        $client = setupConnectedClient($mock);
        expect($client->getServerBuildNumber())->toBe('build.4567');
    });

    it('getServerBuildDate reads ns=0;i=2266 and returns the DateTimeImmutable', function () {
        $date = new DateTimeImmutable('2025-03-15T10:00:00+00:00');
        $mock = new MockTransport();
        $mock->addResponse(serverInfoDateTimeResponse($date));

        $client = setupConnectedClient($mock);
        $result = $client->getServerBuildDate();
        expect($result)->toBeInstanceOf(DateTimeImmutable::class);
        expect($result->format('Y-m-d'))->toBe('2025-03-15');
    });

    it('getServerBuildDate returns null when the server sends a non-DateTime value', function () {
        $mock = new MockTransport();
        $mock->addResponse(serverInfoStringResponse('not-a-date'));

        $client = setupConnectedClient($mock);
        expect($client->getServerBuildDate())->toBeNull();
    });

    it('getServerBuildInfo reads all 5 BuildInfo nodes in a single readMulti', function () {
        $date = new DateTimeImmutable('2025-03-15T10:00:00+00:00');
        $mock = new MockTransport();
        $mock->addResponse(serverInfoBuildInfoReadMultiResponse(
            'Kepware',
            'Acme',
            '2.1.3',
            'build.4567',
            $date,
        ));

        $client = setupConnectedClient($mock);
        $info = $client->getServerBuildInfo();

        expect($info)->toBeInstanceOf(BuildInfo::class);
        expect($info->productName)->toBe('Kepware');
        expect($info->manufacturerName)->toBe('Acme');
        expect($info->softwareVersion)->toBe('2.1.3');
        expect($info->buildNumber)->toBe('build.4567');
        expect($info->buildDate)->toBeInstanceOf(DateTimeImmutable::class);
        expect($info->buildDate->format('Y-m-d'))->toBe('2025-03-15');
    });
});
