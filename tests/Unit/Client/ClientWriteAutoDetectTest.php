<?php

declare(strict_types=1);

require_once __DIR__ . '/ClientTraitsCoverageTest.php';

use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Exception\WriteTypeDetectionException;
use Gianfriaur\OpcuaPhpClient\Exception\WriteTypeMismatchException;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

function writeResponseMsg(int $statusCode = 0): string
{
    return buildMsgResponse(676, function (BinaryEncoder $e) use ($statusCode) {
        $e->writeInt32(1);
        $e->writeUInt32($statusCode);
        $e->writeInt32(0);
    });
}

function readResponseMsgTyped(BuiltinType $type, mixed $value): string
{
    return buildMsgResponse(634, function (BinaryEncoder $e) use ($type, $value) {
        $e->writeInt32(1);
        $e->writeByte(0x01);
        $e->writeByte($type->value);
        match ($type) {
            BuiltinType::Boolean => $e->writeBoolean($value),
            BuiltinType::Int32 => $e->writeInt32($value),
            BuiltinType::Double => $e->writeDouble($value),
            BuiltinType::String => $e->writeString($value),
            default => $e->writeInt32($value),
        };
        $e->writeInt32(0);
    });
}

function readResponseMsgNoValue(): string
{
    return buildMsgResponse(634, function (BinaryEncoder $e) {
        $e->writeInt32(1);
        $e->writeByte(0x00);
        $e->writeInt32(0);
    });
}

function readMultiResponseMsg(array $types): string
{
    return buildMsgResponse(634, function (BinaryEncoder $e) use ($types) {
        $e->writeInt32(count($types));
        foreach ($types as $type) {
            $e->writeByte(0x01);
            $e->writeByte($type->value);
            match ($type) {
                BuiltinType::Boolean => $e->writeBoolean(false),
                BuiltinType::Int32 => $e->writeInt32(0),
                BuiltinType::Double => $e->writeDouble(0.0),
                BuiltinType::String => $e->writeString(''),
                default => $e->writeInt32(0),
            };
        }
        $e->writeInt32(0);
    });
}

function writeMultiResponseMsg(int $count): string
{
    return buildMsgResponse(676, function (BinaryEncoder $e) use ($count) {
        $e->writeInt32($count);
        for ($i = 0; $i < $count; $i++) {
            $e->writeUInt32(0);
        }
        $e->writeInt32(0);
    });
}

describe('Write auto-detect type', function () {

    it('auto-detects type via read-before-write when type is null', function () {
        $mock = new MockTransport();
        $mock->addResponse(readResponseMsgTyped(BuiltinType::Int32, 0));
        $mock->addResponse(writeResponseMsg());

        $client = setupConnectedClient($mock);
        $statusCode = $client->write('i=1001', 42);

        expect(StatusCode::isGood($statusCode))->toBeTrue();
    });

    it('uses explicit type directly without read', function () {
        $mock = new MockTransport();
        $mock->addResponse(writeResponseMsg());

        $client = setupConnectedClient($mock);
        $statusCode = $client->write('i=1001', 42, BuiltinType::Int32);

        expect(StatusCode::isGood($statusCode))->toBeTrue();
        expect(count($mock->sent))->toBe(1);
    });

    it('throws WriteTypeDetectionException when node has no value', function () {
        $mock = new MockTransport();
        $mock->addResponse(readResponseMsgNoValue());

        $client = setupConnectedClient($mock);

        expect(fn () => $client->write('i=1001', 42))
            ->toThrow(WriteTypeDetectionException::class);
    });

    it('throws WriteTypeDetectionException when auto-detect is off and no type provided', function () {
        $client = new Client();
        $ref = new ReflectionProperty($client, 'connectionState');
        $ref->setValue($client, Gianfriaur\OpcuaPhpClient\Types\ConnectionState::Connected);

        $client->setAutoDetectWriteType(false);

        expect(fn () => $client->write('i=1001', 42))
            ->toThrow(WriteTypeDetectionException::class);
    });

    it('passes through explicit type when auto-detect is off', function () {
        $mock = new MockTransport();
        $mock->addResponse(writeResponseMsg());

        $client = setupConnectedClient($mock);
        $client->setAutoDetectWriteType(false);
        $statusCode = $client->write('i=1001', 42, BuiltinType::Int32);

        expect(StatusCode::isGood($statusCode))->toBeTrue();
    });

    it('caches detected type for subsequent writes', function () {
        $mock = new MockTransport();
        $mock->addResponse(readResponseMsgTyped(BuiltinType::Int32, 0));
        $mock->addResponse(writeResponseMsg());
        $mock->addResponse(writeResponseMsg());

        $client = setupConnectedClient($mock);

        $client->write('i=1001', 42);
        $client->write('i=1001', 99);

        expect(count($mock->sent))->toBe(3);
    });

    it('setAutoDetectWriteType returns self for fluent chaining', function () {
        $client = new Client();
        $result = $client->setAutoDetectWriteType(true);

        expect($result)->toBe($client);
    });
});

describe('Write auto-detect events', function () {

    it('dispatches WriteTypeDetecting and WriteTypeDetected events', function () {
        $mock = new MockTransport();
        $mock->addResponse(readResponseMsgTyped(BuiltinType::Int32, 0));
        $mock->addResponse(writeResponseMsg());

        $client = setupConnectedClient($mock);

        $events = [];
        $dispatcher = new class($events) implements Psr\EventDispatcher\EventDispatcherInterface {
            public function __construct(private array &$events)
            {
            }

            public function dispatch(object $event): object
            {
                $this->events[] = $event;

                return $event;
            }
        };
        $client->setEventDispatcher($dispatcher);

        $client->write('i=1001', 42);

        $detecting = array_filter($events, fn ($e) => $e instanceof Gianfriaur\OpcuaPhpClient\Event\WriteTypeDetecting);
        $detected = array_filter($events, fn ($e) => $e instanceof Gianfriaur\OpcuaPhpClient\Event\WriteTypeDetected);

        expect($detecting)->toHaveCount(1);
        expect($detected)->toHaveCount(1);

        $detectedEvent = array_values($detected)[0];
        expect($detectedEvent->detectedType)->toBe(BuiltinType::Int32);
        expect($detectedEvent->fromCache)->toBeFalse();
    });

    it('dispatches WriteTypeDetected with fromCache=true on second write', function () {
        $mock = new MockTransport();
        $mock->addResponse(readResponseMsgTyped(BuiltinType::Int32, 0));
        $mock->addResponse(writeResponseMsg());
        $mock->addResponse(writeResponseMsg());

        $client = setupConnectedClient($mock);

        $events = [];
        $dispatcher = new class($events) implements Psr\EventDispatcher\EventDispatcherInterface {
            public function __construct(private array &$events)
            {
            }

            public function dispatch(object $event): object
            {
                $this->events[] = $event;

                return $event;
            }
        };
        $client->setEventDispatcher($dispatcher);

        $client->write('i=1001', 42);
        $client->write('i=1001', 99);

        $detected = array_values(array_filter($events, fn ($e) => $e instanceof Gianfriaur\OpcuaPhpClient\Event\WriteTypeDetected));
        expect($detected)->toHaveCount(2);
        expect($detected[0]->fromCache)->toBeFalse();
        expect($detected[1]->fromCache)->toBeTrue();
    });
});

describe('Write auto-detect batch prefetch', function () {

    it('prefetches types via readMulti for writeMulti with multiple items', function () {
        $mock = new MockTransport();
        $mock->addResponse(readMultiResponseMsg([BuiltinType::Int32, BuiltinType::Double]));
        $mock->addResponse(writeMultiResponseMsg(2));

        $client = setupConnectedClient($mock);
        $results = $client->writeMulti([
            ['nodeId' => 'ns=2;i=1001', 'value' => 42],
            ['nodeId' => 'ns=2;i=1002', 'value' => 3.14],
        ]);

        expect($results)->toBe([0, 0]);
        expect(count($mock->sent))->toBe(2);
    });

    it('skips prefetch when all types are cached', function () {
        $mock = new MockTransport();
        $mock->addResponse(readMultiResponseMsg([BuiltinType::Int32, BuiltinType::Double]));
        $mock->addResponse(writeMultiResponseMsg(2));
        $mock->addResponse(writeMultiResponseMsg(2));

        $client = setupConnectedClient($mock);

        $client->writeMulti([
            ['nodeId' => 'ns=2;i=1001', 'value' => 42],
            ['nodeId' => 'ns=2;i=1002', 'value' => 3.14],
        ]);

        $results = $client->writeMulti([
            ['nodeId' => 'ns=2;i=1001', 'value' => 99],
            ['nodeId' => 'ns=2;i=1002', 'value' => 6.28],
        ]);

        expect($results)->toBe([0, 0]);
        expect(count($mock->sent))->toBe(3);
    });

    it('deduplicates nodes in prefetch', function () {
        $mock = new MockTransport();
        $mock->addResponse(readResponseMsgTyped(BuiltinType::Int32, 0));
        $mock->addResponse(writeMultiResponseMsg(2));

        $client = setupConnectedClient($mock);
        $results = $client->writeMulti([
            ['nodeId' => 'ns=2;i=1001', 'value' => 42],
            ['nodeId' => 'ns=2;i=1001', 'value' => 99],
        ]);

        expect($results)->toBe([0, 0]);
    });
});
