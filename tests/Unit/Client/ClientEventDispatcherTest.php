<?php

declare(strict_types=1);

require_once __DIR__ . '/ClientTraitsCoverageTest.php';

use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Event\AlarmAcknowledged;
use PhpOpcua\Client\Event\AlarmActivated;
use PhpOpcua\Client\Event\AlarmConfirmed;
use PhpOpcua\Client\Event\AlarmDeactivated;
use PhpOpcua\Client\Event\AlarmEventReceived;
use PhpOpcua\Client\Event\AlarmSeverityChanged;
use PhpOpcua\Client\Event\AlarmShelved;
use PhpOpcua\Client\Event\CacheHit;
use PhpOpcua\Client\Event\CacheMiss;
use PhpOpcua\Client\Event\ClientDisconnected;
use PhpOpcua\Client\Event\ClientDisconnecting;
use PhpOpcua\Client\Event\DataChangeReceived;
use PhpOpcua\Client\Event\EventNotificationReceived;
use PhpOpcua\Client\Event\LimitAlarmExceeded;
use PhpOpcua\Client\Event\NodeBrowsed;
use PhpOpcua\Client\Event\NodeValueRead;
use PhpOpcua\Client\Event\NodeValueWriteFailed;
use PhpOpcua\Client\Event\NodeValueWritten;
use PhpOpcua\Client\Event\NullEventDispatcher;
use PhpOpcua\Client\Event\OffNormalAlarmTriggered;
use PhpOpcua\Client\Event\PublishResponseReceived;
use PhpOpcua\Client\Event\RetryExhausted;
use PhpOpcua\Client\Event\SubscriptionCreated;
use PhpOpcua\Client\Event\SubscriptionDeleted;
use PhpOpcua\Client\Event\SubscriptionKeepAlive;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Tests\Unit\Helpers\InMemoryEventDispatcher;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\NodeId;

function writeDataChangeNotificationData(BinaryEncoder $e, int $clientHandle, int $value): void
{
    $e->writeNodeId(NodeId::numeric(0, 811));
    $e->writeByte(0x01);
    $bodyEncoder = new BinaryEncoder();
    $bodyEncoder->writeInt32(1);
    $bodyEncoder->writeUInt32($clientHandle);
    $bodyEncoder->writeByte(0x01);
    $bodyEncoder->writeByte(BuiltinType::Int32->value);
    $bodyEncoder->writeInt32($value);
    $bodyEncoder->writeInt32(0);
    $body = $bodyEncoder->getBuffer();
    $e->writeInt32(strlen($body));
    $e->writeRawBytes($body);
}

describe('ManagesEventDispatcherTrait on Client', function () {

    it('uses NullEventDispatcher by default', function () {
        $client = createClientWithoutConnect();
        expect($client->getEventDispatcher())->toBeInstanceOf(NullEventDispatcher::class);
    });

    it('setEventDispatcher is fluent on builder', function () {
        $builder = new PhpOpcua\Client\ClientBuilder();
        $dispatcher = new InMemoryEventDispatcher();
        $result = $builder->setEventDispatcher($dispatcher);
        expect($result)->toBe($builder);
        expect($builder->getEventDispatcher())->toBe($dispatcher);
    });

    it('dispatches NodeValueRead on read', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $mock = new MockTransport();
        $mock->addResponse(readResponseMsg(42));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'eventDispatcher', $dispatcher);

        $client->read('i=2259');

        expect($dispatcher->hasEvent(NodeValueRead::class))->toBeTrue();
        $event = $dispatcher->getEventsOfType(NodeValueRead::class)[0];
        expect($event->nodeId->getIdentifier())->toBe(2259);
        expect($event->dataValue->getValue())->toBe(42);
        expect($event->client)->toBe($client);
    });

    it('dispatches NodeBrowsed and CacheMiss on first browse', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $mock = new MockTransport();
        $mock->addResponse(browseResponseMsg());

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'eventDispatcher', $dispatcher);

        $client->browse('i=85');

        expect($dispatcher->hasEvent(NodeBrowsed::class))->toBeTrue();
        expect($dispatcher->hasEvent(CacheMiss::class))->toBeTrue();
    });

    it('dispatches CacheHit on second browse', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $mock = new MockTransport();
        $mock->addResponse(browseResponseMsg());

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'eventDispatcher', $dispatcher);

        $client->browse('i=85');
        $dispatcher->reset();
        $client->browse('i=85');

        expect($dispatcher->hasEvent(CacheHit::class))->toBeTrue();
    });

    it('dispatches NodeValueWritten on successful write', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(676, function (BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeUInt32(0);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'autoDetectWriteType', false);
        setClientProperty($client, 'eventDispatcher', $dispatcher);

        $client->write('ns=2;i=1001', 42, BuiltinType::Int32);

        expect($dispatcher->hasEvent(NodeValueWritten::class))->toBeTrue();
        expect($dispatcher->hasEvent(NodeValueWriteFailed::class))->toBeFalse();
    });

    it('dispatches NodeValueWriteFailed on bad status', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(676, function (BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeUInt32(0x803B0000);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'autoDetectWriteType', false);
        setClientProperty($client, 'eventDispatcher', $dispatcher);

        $client->write('ns=2;i=1001', 42, BuiltinType::Int32);

        expect($dispatcher->hasEvent(NodeValueWriteFailed::class))->toBeTrue();
        expect($dispatcher->hasEvent(NodeValueWritten::class))->toBeFalse();
    });

    it('dispatches ClientDisconnecting and ClientDisconnected on disconnect', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $client = createClientWithoutConnect();
        setClientProperty($client, 'eventDispatcher', $dispatcher);

        $client->disconnect();

        expect($dispatcher->hasEvent(ClientDisconnecting::class))->toBeTrue();
        expect($dispatcher->hasEvent(ClientDisconnected::class))->toBeTrue();
    });

    it('dispatches RetryExhausted when retries fail', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $mock = new MockTransport();

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'eventDispatcher', $dispatcher);
        setClientProperty($client, 'autoRetry', 0);

        try {
            $client->read('i=2259');
        } catch (ConnectionException) {
        }

        expect($dispatcher->hasEvent(RetryExhausted::class))->toBeTrue();
    });

    it('does not dispatch events with NullEventDispatcher', function () {
        $mock = new MockTransport();
        $mock->addResponse(readResponseMsg(42));

        $client = setupConnectedClient($mock);

        $closureCalled = false;
        $wrappingDispatcher = new class($closureCalled) implements Psr\EventDispatcher\EventDispatcherInterface {
            public function __construct(private bool &$called)
            {
            }

            public function dispatch(object $event): object
            {
                $this->called = true;

                return $event;
            }
        };

        $client->read('i=2259');
        expect($closureCalled)->toBeFalse();
    });

    it('dispatches SubscriptionCreated on createSubscription', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(790, function (BinaryEncoder $e) {
            $e->writeUInt32(1);
            $e->writeDouble(500.0);
            $e->writeUInt32(2400);
            $e->writeUInt32(10);
        }));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'eventDispatcher', $dispatcher);

        $result = $client->createSubscription(500.0);

        expect($dispatcher->hasEvent(SubscriptionCreated::class))->toBeTrue();
        $event = $dispatcher->getEventsOfType(SubscriptionCreated::class)[0];
        expect($event->subscriptionId)->toBe(1);
    });

    it('dispatches SubscriptionDeleted on deleteSubscription', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(848, function (BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeUInt32(0);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'eventDispatcher', $dispatcher);

        $client->deleteSubscription(1);

        expect($dispatcher->hasEvent(SubscriptionDeleted::class))->toBeTrue();
    });

    it('dispatches SubscriptionKeepAlive when publish has no notifications', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(829, function (BinaryEncoder $e) {
            $e->writeUInt32(1);
            $e->writeInt32(0);
            $e->writeBoolean(false);
            $e->writeUInt32(5);
            $e->writeDateTime(new DateTimeImmutable());
            $e->writeInt32(0);
            $e->writeInt32(0);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'eventDispatcher', $dispatcher);

        $client->publish();

        expect($dispatcher->hasEvent(PublishResponseReceived::class))->toBeTrue();
        expect($dispatcher->hasEvent(SubscriptionKeepAlive::class))->toBeTrue();
    });

    it('dispatches DataChangeReceived for data change notifications', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(829, function (BinaryEncoder $e) {
            $e->writeUInt32(1);
            $e->writeInt32(0);
            $e->writeBoolean(false);
            $e->writeUInt32(5);
            $e->writeDateTime(new DateTimeImmutable());
            $e->writeInt32(1);
            $e->writeNodeId(NodeId::numeric(0, 811));
            $e->writeByte(0x01);
            $bodyEncoder = new BinaryEncoder();
            $bodyEncoder->writeInt32(1);
            $bodyEncoder->writeUInt32(1);
            $bodyEncoder->writeByte(0x01);
            $bodyEncoder->writeByte(BuiltinType::Int32->value);
            $bodyEncoder->writeInt32(99);
            $bodyEncoder->writeInt32(0);
            $body = $bodyEncoder->getBuffer();
            $e->writeInt32(strlen($body));
            $e->writeRawBytes($body);
            $e->writeInt32(0);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'eventDispatcher', $dispatcher);

        $result = $client->publish();

        expect($dispatcher->hasEvent(DataChangeReceived::class))->toBeTrue();
        $event = $dispatcher->getEventsOfType(DataChangeReceived::class)[0];
        expect($event->dataValue->getValue())->toBe(99);
        expect($event->clientHandle)->toBe(1);
    });

    it('flags DataChangeReceived from publish as not republished', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(829, function (BinaryEncoder $e) {
            $e->writeUInt32(1);
            $e->writeInt32(0);
            $e->writeBoolean(false);
            $e->writeUInt32(5);
            $e->writeDateTime(new DateTimeImmutable());
            $e->writeInt32(1);
            writeDataChangeNotificationData($e, 1, 99);
            $e->writeInt32(0);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'eventDispatcher', $dispatcher);

        $client->publish();

        expect($dispatcher->getEventsOfType(DataChangeReceived::class)[0]->republished)->toBeFalse();
    });

    it('dispatches DataChangeReceived flagged as republished for republish, without publish-only events', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(835, function (BinaryEncoder $e) {
            $e->writeUInt32(41);
            $e->writeDateTime(new DateTimeImmutable());
            $e->writeInt32(2);
            writeDataChangeNotificationData($e, 3, 7);
            writeDataChangeNotificationData($e, 4, 8);
        }));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'eventDispatcher', $dispatcher);

        $client->republish(12, 41);

        $events = $dispatcher->getEventsOfType(DataChangeReceived::class);
        expect($events)->toHaveCount(2);
        expect($events[0]->republished)->toBeTrue();
        expect($events[0]->subscriptionId)->toBe(12);
        expect($events[0]->sequenceNumber)->toBe(41);
        expect($events[0]->clientHandle)->toBe(3);
        expect($events[0]->dataValue->getValue())->toBe(7);
        expect($events[1]->clientHandle)->toBe(4);
        expect($dispatcher->hasEvent(PublishResponseReceived::class))->toBeFalse();
        expect($dispatcher->hasEvent(SubscriptionKeepAlive::class))->toBeFalse();
    });

    it('dispatches no events for a republish without notifications', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(835, function (BinaryEncoder $e) {
            $e->writeUInt32(41);
            $e->writeDateTime(null);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'eventDispatcher', $dispatcher);

        $client->republish(12, 41);

        expect($dispatcher->getEvents())->toBe([]);
    });

    it('flags event and alarm events from republish as republished', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(835, function (BinaryEncoder $e) {
            $e->writeUInt32(9);
            $e->writeDateTime(new DateTimeImmutable());
            $e->writeInt32(1);
            $e->writeNodeId(NodeId::numeric(0, 916));
            $e->writeByte(0x01);
            $bodyEncoder = new BinaryEncoder();
            $bodyEncoder->writeInt32(1);
            $bodyEncoder->writeUInt32(1);
            $bodyEncoder->writeInt32(7);
            $bodyEncoder->writeByte(BuiltinType::ByteString->value);
            $bodyEncoder->writeByteString('event-id-123');
            $bodyEncoder->writeByte(BuiltinType::NodeId->value);
            $bodyEncoder->writeNodeId(NodeId::numeric(0, 2955));
            $bodyEncoder->writeByte(BuiltinType::String->value);
            $bodyEncoder->writeString('TempSensor');
            $bodyEncoder->writeByte(BuiltinType::DateTime->value);
            $bodyEncoder->writeDateTime(new DateTimeImmutable());
            $bodyEncoder->writeByte(BuiltinType::String->value);
            $bodyEncoder->writeString('Temperature high');
            $bodyEncoder->writeByte(BuiltinType::UInt16->value);
            $bodyEncoder->writeUInt16(800);
            $bodyEncoder->writeByte(BuiltinType::Boolean->value);
            $bodyEncoder->writeBoolean(true);
            $body = $bodyEncoder->getBuffer();
            $e->writeInt32(strlen($body));
            $e->writeRawBytes($body);
        }));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'eventDispatcher', $dispatcher);

        $client->republish(1, 9);

        $classes = [
            EventNotificationReceived::class,
            AlarmEventReceived::class,
            AlarmSeverityChanged::class,
            LimitAlarmExceeded::class,
            AlarmActivated::class,
        ];
        foreach ($classes as $class) {
            $events = $dispatcher->getEventsOfType($class);
            expect($events)->toHaveCount(1);
            expect($events[0]->republished)->toBeTrue();
        }
    });

    it('dispatches EventNotificationReceived and AlarmEventReceived for event notifications with severity', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(829, function (BinaryEncoder $e) {
            $e->writeUInt32(1);
            $e->writeInt32(0);
            $e->writeBoolean(false);
            $e->writeUInt32(5);
            $e->writeDateTime(new DateTimeImmutable());
            $e->writeInt32(1);
            $e->writeNodeId(NodeId::numeric(0, 916));
            $e->writeByte(0x01);
            $bodyEncoder = new BinaryEncoder();
            $bodyEncoder->writeInt32(1);
            $bodyEncoder->writeUInt32(1);
            $bodyEncoder->writeInt32(6);
            $bodyEncoder->writeByte(BuiltinType::ByteString->value);
            $bodyEncoder->writeByteString('event-id-123');
            $bodyEncoder->writeByte(BuiltinType::NodeId->value);
            $bodyEncoder->writeNodeId(NodeId::numeric(0, 2955));
            $bodyEncoder->writeByte(BuiltinType::String->value);
            $bodyEncoder->writeString('TempSensor');
            $bodyEncoder->writeByte(BuiltinType::DateTime->value);
            $bodyEncoder->writeDateTime(new DateTimeImmutable());
            $bodyEncoder->writeByte(BuiltinType::String->value);
            $bodyEncoder->writeString('Temperature high');
            $bodyEncoder->writeByte(BuiltinType::UInt16->value);
            $bodyEncoder->writeUInt16(800);
            $body = $bodyEncoder->getBuffer();
            $e->writeInt32(strlen($body));
            $e->writeRawBytes($body);
            $e->writeInt32(0);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'eventDispatcher', $dispatcher);

        $client->publish();

        expect($dispatcher->hasEvent(EventNotificationReceived::class))->toBeTrue();
        expect($dispatcher->hasEvent(AlarmEventReceived::class))->toBeTrue();
        expect($dispatcher->hasEvent(AlarmSeverityChanged::class))->toBeTrue();
        expect($dispatcher->hasEvent(LimitAlarmExceeded::class))->toBeTrue();
    });

    it('dispatches AlarmActivated for event with boolean true ActiveState', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(829, function (BinaryEncoder $e) {
            $e->writeUInt32(1);
            $e->writeInt32(0);
            $e->writeBoolean(false);
            $e->writeUInt32(5);
            $e->writeDateTime(new DateTimeImmutable());
            $e->writeInt32(1);
            $e->writeNodeId(NodeId::numeric(0, 916));
            $e->writeByte(0x01);
            $bodyEncoder = new BinaryEncoder();
            $bodyEncoder->writeInt32(1);
            $bodyEncoder->writeUInt32(1);
            $bodyEncoder->writeInt32(7);
            $bodyEncoder->writeByte(BuiltinType::ByteString->value);
            $bodyEncoder->writeByteString('eid');
            $bodyEncoder->writeByte(BuiltinType::NodeId->value);
            $bodyEncoder->writeNodeId(NodeId::numeric(0, 2955));
            $bodyEncoder->writeByte(BuiltinType::String->value);
            $bodyEncoder->writeString('Sensor1');
            $bodyEncoder->writeByte(BuiltinType::DateTime->value);
            $bodyEncoder->writeDateTime(new DateTimeImmutable());
            $bodyEncoder->writeByte(BuiltinType::String->value);
            $bodyEncoder->writeString('Active');
            $bodyEncoder->writeByte(BuiltinType::UInt16->value);
            $bodyEncoder->writeUInt16(500);
            $bodyEncoder->writeByte(BuiltinType::Boolean->value);
            $bodyEncoder->writeBoolean(true);
            $body = $bodyEncoder->getBuffer();
            $e->writeInt32(strlen($body));
            $e->writeRawBytes($body);
            $e->writeInt32(0);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'eventDispatcher', $dispatcher);
        $client->publish();

        expect($dispatcher->hasEvent(AlarmActivated::class))->toBeTrue();
    });

    it('dispatches AlarmDeactivated for event with boolean false ActiveState', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(829, function (BinaryEncoder $e) {
            $e->writeUInt32(1);
            $e->writeInt32(0);
            $e->writeBoolean(false);
            $e->writeUInt32(5);
            $e->writeDateTime(new DateTimeImmutable());
            $e->writeInt32(1);
            $e->writeNodeId(NodeId::numeric(0, 916));
            $e->writeByte(0x01);
            $bodyEncoder = new BinaryEncoder();
            $bodyEncoder->writeInt32(1);
            $bodyEncoder->writeUInt32(1);
            $bodyEncoder->writeInt32(7);
            $bodyEncoder->writeByte(BuiltinType::ByteString->value);
            $bodyEncoder->writeByteString('eid');
            $bodyEncoder->writeByte(BuiltinType::NodeId->value);
            $bodyEncoder->writeNodeId(NodeId::numeric(0, 2955));
            $bodyEncoder->writeByte(BuiltinType::String->value);
            $bodyEncoder->writeString('Sensor1');
            $bodyEncoder->writeByte(BuiltinType::DateTime->value);
            $bodyEncoder->writeDateTime(new DateTimeImmutable());
            $bodyEncoder->writeByte(BuiltinType::String->value);
            $bodyEncoder->writeString('Inactive');
            $bodyEncoder->writeByte(BuiltinType::UInt16->value);
            $bodyEncoder->writeUInt16(0);
            $bodyEncoder->writeByte(BuiltinType::Boolean->value);
            $bodyEncoder->writeBoolean(false);
            $body = $bodyEncoder->getBuffer();
            $e->writeInt32(strlen($body));
            $e->writeRawBytes($body);
            $e->writeInt32(0);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'eventDispatcher', $dispatcher);
        $client->publish();

        expect($dispatcher->hasEvent(AlarmDeactivated::class))->toBeTrue();
    });

    it('dispatches AlarmAcknowledged for event with acknowledged string state', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(829, function (BinaryEncoder $e) {
            $e->writeUInt32(1);
            $e->writeInt32(0);
            $e->writeBoolean(false);
            $e->writeUInt32(5);
            $e->writeDateTime(new DateTimeImmutable());
            $e->writeInt32(1);
            $e->writeNodeId(NodeId::numeric(0, 916));
            $e->writeByte(0x01);
            $bodyEncoder = new BinaryEncoder();
            $bodyEncoder->writeInt32(1);
            $bodyEncoder->writeUInt32(1);
            $bodyEncoder->writeInt32(7);
            $bodyEncoder->writeByte(BuiltinType::ByteString->value);
            $bodyEncoder->writeByteString('eid');
            $bodyEncoder->writeByte(BuiltinType::NodeId->value);
            $bodyEncoder->writeNodeId(NodeId::numeric(0, 9906));
            $bodyEncoder->writeByte(BuiltinType::String->value);
            $bodyEncoder->writeString('Sensor1');
            $bodyEncoder->writeByte(BuiltinType::DateTime->value);
            $bodyEncoder->writeDateTime(new DateTimeImmutable());
            $bodyEncoder->writeByte(BuiltinType::String->value);
            $bodyEncoder->writeString('Msg');
            $bodyEncoder->writeByte(BuiltinType::UInt16->value);
            $bodyEncoder->writeUInt16(100);
            $bodyEncoder->writeByte(BuiltinType::String->value);
            $bodyEncoder->writeString('Acknowledged');
            $body = $bodyEncoder->getBuffer();
            $e->writeInt32(strlen($body));
            $e->writeRawBytes($body);
            $e->writeInt32(0);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'eventDispatcher', $dispatcher);
        $client->publish();

        expect($dispatcher->hasEvent(AlarmAcknowledged::class))->toBeTrue();
    });

    it('dispatches AlarmConfirmed for event with confirmed string state', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(829, function (BinaryEncoder $e) {
            $e->writeUInt32(1);
            $e->writeInt32(0);
            $e->writeBoolean(false);
            $e->writeUInt32(5);
            $e->writeDateTime(new DateTimeImmutable());
            $e->writeInt32(1);
            $e->writeNodeId(NodeId::numeric(0, 916));
            $e->writeByte(0x01);
            $bodyEncoder = new BinaryEncoder();
            $bodyEncoder->writeInt32(1);
            $bodyEncoder->writeUInt32(1);
            $bodyEncoder->writeInt32(7);
            $bodyEncoder->writeByte(BuiltinType::ByteString->value);
            $bodyEncoder->writeByteString('eid');
            $bodyEncoder->writeByte(BuiltinType::NodeId->value);
            $bodyEncoder->writeNodeId(NodeId::numeric(0, 2955));
            $bodyEncoder->writeByte(BuiltinType::String->value);
            $bodyEncoder->writeString('Sensor1');
            $bodyEncoder->writeByte(BuiltinType::DateTime->value);
            $bodyEncoder->writeDateTime(new DateTimeImmutable());
            $bodyEncoder->writeByte(BuiltinType::String->value);
            $bodyEncoder->writeString('Msg');
            $bodyEncoder->writeByte(BuiltinType::UInt16->value);
            $bodyEncoder->writeUInt16(100);
            $bodyEncoder->writeByte(BuiltinType::String->value);
            $bodyEncoder->writeString('Confirmed');
            $body = $bodyEncoder->getBuffer();
            $e->writeInt32(strlen($body));
            $e->writeRawBytes($body);
            $e->writeInt32(0);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'eventDispatcher', $dispatcher);
        $client->publish();

        expect($dispatcher->hasEvent(AlarmConfirmed::class))->toBeTrue();
    });

    it('dispatches AlarmShelved for event with shelved string state', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(829, function (BinaryEncoder $e) {
            $e->writeUInt32(1);
            $e->writeInt32(0);
            $e->writeBoolean(false);
            $e->writeUInt32(5);
            $e->writeDateTime(new DateTimeImmutable());
            $e->writeInt32(1);
            $e->writeNodeId(NodeId::numeric(0, 916));
            $e->writeByte(0x01);
            $bodyEncoder = new BinaryEncoder();
            $bodyEncoder->writeInt32(1);
            $bodyEncoder->writeUInt32(1);
            $bodyEncoder->writeInt32(7);
            $bodyEncoder->writeByte(BuiltinType::ByteString->value);
            $bodyEncoder->writeByteString('eid');
            $bodyEncoder->writeByte(BuiltinType::NodeId->value);
            $bodyEncoder->writeNodeId(NodeId::numeric(0, 2955));
            $bodyEncoder->writeByte(BuiltinType::String->value);
            $bodyEncoder->writeString('Sensor1');
            $bodyEncoder->writeByte(BuiltinType::DateTime->value);
            $bodyEncoder->writeDateTime(new DateTimeImmutable());
            $bodyEncoder->writeByte(BuiltinType::String->value);
            $bodyEncoder->writeString('Msg');
            $bodyEncoder->writeByte(BuiltinType::UInt16->value);
            $bodyEncoder->writeUInt16(100);
            $bodyEncoder->writeByte(BuiltinType::String->value);
            $bodyEncoder->writeString('TimedShelved');
            $body = $bodyEncoder->getBuffer();
            $e->writeInt32(strlen($body));
            $e->writeRawBytes($body);
            $e->writeInt32(0);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'eventDispatcher', $dispatcher);
        $client->publish();

        expect($dispatcher->hasEvent(AlarmShelved::class))->toBeTrue();
    });

    it('dispatches OffNormalAlarmTriggered for OffNormal alarm type', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(829, function (BinaryEncoder $e) {
            $e->writeUInt32(1);
            $e->writeInt32(0);
            $e->writeBoolean(false);
            $e->writeUInt32(5);
            $e->writeDateTime(new DateTimeImmutable());
            $e->writeInt32(1);
            $e->writeNodeId(NodeId::numeric(0, 916));
            $e->writeByte(0x01);
            $bodyEncoder = new BinaryEncoder();
            $bodyEncoder->writeInt32(1);
            $bodyEncoder->writeUInt32(1);
            $bodyEncoder->writeInt32(6);
            $bodyEncoder->writeByte(BuiltinType::ByteString->value);
            $bodyEncoder->writeByteString('eid');
            $bodyEncoder->writeByte(BuiltinType::NodeId->value);
            $bodyEncoder->writeNodeId(NodeId::numeric(0, 10637));
            $bodyEncoder->writeByte(BuiltinType::String->value);
            $bodyEncoder->writeString('Switch1');
            $bodyEncoder->writeByte(BuiltinType::DateTime->value);
            $bodyEncoder->writeDateTime(new DateTimeImmutable());
            $bodyEncoder->writeByte(BuiltinType::String->value);
            $bodyEncoder->writeString('Off normal');
            $bodyEncoder->writeByte(BuiltinType::UInt16->value);
            $bodyEncoder->writeUInt16(500);
            $body = $bodyEncoder->getBuffer();
            $e->writeInt32(strlen($body));
            $e->writeRawBytes($body);
            $e->writeInt32(0);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'eventDispatcher', $dispatcher);
        $client->publish();

        expect($dispatcher->hasEvent(OffNormalAlarmTriggered::class))->toBeTrue();
    });

    it('dispatches AlarmActivated for string Active state', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(829, function (BinaryEncoder $e) {
            $e->writeUInt32(1);
            $e->writeInt32(0);
            $e->writeBoolean(false);
            $e->writeUInt32(5);
            $e->writeDateTime(new DateTimeImmutable());
            $e->writeInt32(1);
            $e->writeNodeId(NodeId::numeric(0, 916));
            $e->writeByte(0x01);
            $bodyEncoder = new BinaryEncoder();
            $bodyEncoder->writeInt32(1);
            $bodyEncoder->writeUInt32(1);
            $bodyEncoder->writeInt32(7);
            $bodyEncoder->writeByte(BuiltinType::ByteString->value);
            $bodyEncoder->writeByteString('eid');
            $bodyEncoder->writeByte(BuiltinType::NodeId->value);
            $bodyEncoder->writeNodeId(NodeId::numeric(0, 2955));
            $bodyEncoder->writeByte(BuiltinType::String->value);
            $bodyEncoder->writeString('Sensor');
            $bodyEncoder->writeByte(BuiltinType::DateTime->value);
            $bodyEncoder->writeDateTime(new DateTimeImmutable());
            $bodyEncoder->writeByte(BuiltinType::String->value);
            $bodyEncoder->writeString('Msg');
            $bodyEncoder->writeByte(BuiltinType::UInt16->value);
            $bodyEncoder->writeUInt16(500);
            $bodyEncoder->writeByte(BuiltinType::String->value);
            $bodyEncoder->writeString('Active');
            $body = $bodyEncoder->getBuffer();
            $e->writeInt32(strlen($body));
            $e->writeRawBytes($body);
            $e->writeInt32(0);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'eventDispatcher', $dispatcher);
        $client->publish();

        expect($dispatcher->hasEvent(AlarmActivated::class))->toBeTrue();
    });

    it('dispatches AlarmDeactivated for string Inactive state', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $mock = new MockTransport();
        $mock->addResponse(buildMsgResponse(829, function (BinaryEncoder $e) {
            $e->writeUInt32(1);
            $e->writeInt32(0);
            $e->writeBoolean(false);
            $e->writeUInt32(5);
            $e->writeDateTime(new DateTimeImmutable());
            $e->writeInt32(1);
            $e->writeNodeId(NodeId::numeric(0, 916));
            $e->writeByte(0x01);
            $bodyEncoder = new BinaryEncoder();
            $bodyEncoder->writeInt32(1);
            $bodyEncoder->writeUInt32(1);
            $bodyEncoder->writeInt32(7);
            $bodyEncoder->writeByte(BuiltinType::ByteString->value);
            $bodyEncoder->writeByteString('eid');
            $bodyEncoder->writeByte(BuiltinType::NodeId->value);
            $bodyEncoder->writeNodeId(NodeId::numeric(0, 2955));
            $bodyEncoder->writeByte(BuiltinType::String->value);
            $bodyEncoder->writeString('Sensor');
            $bodyEncoder->writeByte(BuiltinType::DateTime->value);
            $bodyEncoder->writeDateTime(new DateTimeImmutable());
            $bodyEncoder->writeByte(BuiltinType::String->value);
            $bodyEncoder->writeString('Msg');
            $bodyEncoder->writeByte(BuiltinType::UInt16->value);
            $bodyEncoder->writeUInt16(0);
            $bodyEncoder->writeByte(BuiltinType::String->value);
            $bodyEncoder->writeString('Inactive');
            $body = $bodyEncoder->getBuffer();
            $e->writeInt32(strlen($body));
            $e->writeRawBytes($body);
            $e->writeInt32(0);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'eventDispatcher', $dispatcher);
        $client->publish();

        expect($dispatcher->hasEvent(AlarmDeactivated::class))->toBeTrue();
    });

});
