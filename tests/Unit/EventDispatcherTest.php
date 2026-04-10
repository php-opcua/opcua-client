<?php

declare(strict_types=1);

use PhpOpcua\Client\Event\AlarmAcknowledged;
use PhpOpcua\Client\Event\AlarmActivated;
use PhpOpcua\Client\Event\AlarmConfirmed;
use PhpOpcua\Client\Event\AlarmDeactivated;
use PhpOpcua\Client\Event\AlarmEventReceived;
use PhpOpcua\Client\Event\AlarmSeverityChanged;
use PhpOpcua\Client\Event\AlarmShelved;
use PhpOpcua\Client\Event\CacheHit;
use PhpOpcua\Client\Event\CacheMiss;
use PhpOpcua\Client\Event\ClientConnected;
use PhpOpcua\Client\Event\ClientConnecting;
use PhpOpcua\Client\Event\ClientDisconnected;
use PhpOpcua\Client\Event\ClientDisconnecting;
use PhpOpcua\Client\Event\ClientReconnecting;
use PhpOpcua\Client\Event\ConnectionFailed;
use PhpOpcua\Client\Event\DataChangeReceived;
use PhpOpcua\Client\Event\DataTypesDiscovered;
use PhpOpcua\Client\Event\EventNotificationReceived;
use PhpOpcua\Client\Event\LimitAlarmExceeded;
use PhpOpcua\Client\Event\MonitoredItemCreated;
use PhpOpcua\Client\Event\MonitoredItemDeleted;
use PhpOpcua\Client\Event\NodeBrowsed;
use PhpOpcua\Client\Event\NodeValueRead;
use PhpOpcua\Client\Event\NodeValueWriteFailed;
use PhpOpcua\Client\Event\NodeValueWritten;
use PhpOpcua\Client\Event\NullEventDispatcher;
use PhpOpcua\Client\Event\OffNormalAlarmTriggered;
use PhpOpcua\Client\Event\PublishResponseReceived;
use PhpOpcua\Client\Event\RetryAttempt;
use PhpOpcua\Client\Event\RetryExhausted;
use PhpOpcua\Client\Event\SecureChannelClosed;
use PhpOpcua\Client\Event\SecureChannelOpened;
use PhpOpcua\Client\Event\SessionActivated;
use PhpOpcua\Client\Event\SessionClosed;
use PhpOpcua\Client\Event\SessionCreated;
use PhpOpcua\Client\Event\SubscriptionCreated;
use PhpOpcua\Client\Event\SubscriptionDeleted;
use PhpOpcua\Client\Event\SubscriptionKeepAlive;
use PhpOpcua\Client\Event\SubscriptionTransferred;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Tests\Unit\Helpers\InMemoryEventDispatcher;
use PhpOpcua\Client\Types\BrowseDirection;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\Variant;

describe('NullEventDispatcher', function () {

    it('uses NullEventDispatcher by default', function () {
        $client = MockClient::create();
        expect($client->getEventDispatcher())->toBeInstanceOf(NullEventDispatcher::class);
    });

    it('returns the event unchanged', function () {
        $dispatcher = new NullEventDispatcher();
        $event = new ClientConnected(MockClient::create(), 'opc.tcp://localhost:4840');
        expect($dispatcher->dispatch($event))->toBe($event);
    });

    it('does not cause errors on mock client operations', function () {
        $client = MockClient::create();
        $client->connect('opc.tcp://localhost:4840');
        $client->disconnect();
        expect(true)->toBeTrue();
    });

});

describe('EventDispatcher configuration', function () {

    it('allows setting a custom dispatcher', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $client = MockClient::create()->setEventDispatcher($dispatcher);
        expect($client->getEventDispatcher())->toBe($dispatcher);
    });

    it('setEventDispatcher returns self for fluent chaining', function () {
        $client = MockClient::create();
        $result = $client->setEventDispatcher(new NullEventDispatcher());
        expect($result)->toBe($client);
    });

});

describe('Connection events', function () {

    it('creates ClientConnecting with client and endpoint', function () {
        $client = MockClient::create();
        $event = new ClientConnecting($client, 'opc.tcp://host:4840');
        expect($event->client)->toBe($client);
        expect($event->endpointUrl)->toBe('opc.tcp://host:4840');
    });

    it('creates ClientConnected with client and endpoint', function () {
        $client = MockClient::create();
        $event = new ClientConnected($client, 'opc.tcp://host:4840');
        expect($event->client)->toBe($client);
        expect($event->endpointUrl)->toBe('opc.tcp://host:4840');
    });

    it('creates ConnectionFailed with exception', function () {
        $client = MockClient::create();
        $ex = new RuntimeException('timeout');
        $event = new ConnectionFailed($client, 'opc.tcp://host:4840', $ex);
        expect($event->client)->toBe($client);
        expect($event->endpointUrl)->toBe('opc.tcp://host:4840');
        expect($event->exception)->toBe($ex);
    });

    it('creates ClientReconnecting', function () {
        $client = MockClient::create();
        $event = new ClientReconnecting($client, 'opc.tcp://host:4840');
        expect($event->client)->toBe($client);
        expect($event->endpointUrl)->toBe('opc.tcp://host:4840');
    });

    it('creates ClientDisconnecting with nullable endpoint', function () {
        $client = MockClient::create();
        $event = new ClientDisconnecting($client, null);
        expect($event->client)->toBe($client);
        expect($event->endpointUrl)->toBeNull();
    });

    it('creates ClientDisconnecting with endpoint', function () {
        $client = MockClient::create();
        $event = new ClientDisconnecting($client, 'opc.tcp://host:4840');
        expect($event->endpointUrl)->toBe('opc.tcp://host:4840');
    });

    it('creates ClientDisconnected', function () {
        $client = MockClient::create();
        $event = new ClientDisconnected($client);
        expect($event->client)->toBe($client);
    });

});

describe('Session events', function () {

    it('creates SessionCreated with token', function () {
        $client = MockClient::create();
        $token = NodeId::numeric(0, 12345);
        $event = new SessionCreated($client, 'opc.tcp://host:4840', $token);
        expect($event->client)->toBe($client);
        expect($event->endpointUrl)->toBe('opc.tcp://host:4840');
        expect($event->authenticationToken)->toBe($token);
    });

    it('creates SessionActivated', function () {
        $client = MockClient::create();
        $event = new SessionActivated($client, 'opc.tcp://host:4840');
        expect($event->client)->toBe($client);
        expect($event->endpointUrl)->toBe('opc.tcp://host:4840');
    });

    it('creates SessionClosed', function () {
        $client = MockClient::create();
        $event = new SessionClosed($client);
        expect($event->client)->toBe($client);
    });

});

describe('Secure channel events', function () {

    it('creates SecureChannelOpened', function () {
        $client = MockClient::create();
        $event = new SecureChannelOpened($client, 42, SecurityPolicy::Basic256Sha256, SecurityMode::SignAndEncrypt);
        expect($event->client)->toBe($client);
        expect($event->channelId)->toBe(42);
        expect($event->securityPolicy)->toBe(SecurityPolicy::Basic256Sha256);
        expect($event->securityMode)->toBe(SecurityMode::SignAndEncrypt);
    });

    it('creates SecureChannelClosed', function () {
        $client = MockClient::create();
        $event = new SecureChannelClosed($client, 42);
        expect($event->client)->toBe($client);
        expect($event->channelId)->toBe(42);
    });

});

describe('Subscription events', function () {

    it('creates SubscriptionCreated', function () {
        $client = MockClient::create();
        $event = new SubscriptionCreated($client, 1, 500.0, 2400, 10);
        expect($event->client)->toBe($client);
        expect($event->subscriptionId)->toBe(1);
        expect($event->revisedPublishingInterval)->toBe(500.0);
        expect($event->revisedLifetimeCount)->toBe(2400);
        expect($event->revisedMaxKeepAliveCount)->toBe(10);
    });

    it('creates SubscriptionDeleted', function () {
        $client = MockClient::create();
        $event = new SubscriptionDeleted($client, 1, 0);
        expect($event->subscriptionId)->toBe(1);
        expect($event->statusCode)->toBe(0);
    });

    it('creates SubscriptionTransferred', function () {
        $client = MockClient::create();
        $event = new SubscriptionTransferred($client, 5, 0);
        expect($event->subscriptionId)->toBe(5);
        expect($event->statusCode)->toBe(0);
    });

    it('creates MonitoredItemCreated', function () {
        $client = MockClient::create();
        $nodeId = NodeId::numeric(2, 1001);
        $event = new MonitoredItemCreated($client, 1, 42, $nodeId, 0);
        expect($event->subscriptionId)->toBe(1);
        expect($event->monitoredItemId)->toBe(42);
        expect($event->nodeId)->toBe($nodeId);
        expect($event->statusCode)->toBe(0);
    });

    it('creates MonitoredItemDeleted', function () {
        $client = MockClient::create();
        $event = new MonitoredItemDeleted($client, 1, 42, 0);
        expect($event->subscriptionId)->toBe(1);
        expect($event->monitoredItemId)->toBe(42);
        expect($event->statusCode)->toBe(0);
    });

});

describe('Publish events', function () {

    it('creates DataChangeReceived', function () {
        $client = MockClient::create();
        $dv = DataValue::ofInt32(42);
        $event = new DataChangeReceived($client, 1, 10, 5, $dv);
        expect($event->subscriptionId)->toBe(1);
        expect($event->sequenceNumber)->toBe(10);
        expect($event->clientHandle)->toBe(5);
        expect($event->dataValue)->toBe($dv);
    });

    it('creates EventNotificationReceived', function () {
        $client = MockClient::create();
        $fields = [new Variant(BuiltinType::String, 'test')];
        $event = new EventNotificationReceived($client, 1, 10, 5, $fields);
        expect($event->subscriptionId)->toBe(1);
        expect($event->eventFields)->toBe($fields);
    });

    it('creates PublishResponseReceived', function () {
        $client = MockClient::create();
        $event = new PublishResponseReceived($client, 1, 10, 3, true);
        expect($event->subscriptionId)->toBe(1);
        expect($event->sequenceNumber)->toBe(10);
        expect($event->notificationCount)->toBe(3);
        expect($event->moreNotifications)->toBeTrue();
    });

    it('creates SubscriptionKeepAlive', function () {
        $client = MockClient::create();
        $event = new SubscriptionKeepAlive($client, 1, 10);
        expect($event->subscriptionId)->toBe(1);
        expect($event->sequenceNumber)->toBe(10);
    });

});

describe('Read/Write/Browse events', function () {

    it('creates NodeValueRead', function () {
        $client = MockClient::create();
        $nodeId = NodeId::numeric(0, 2259);
        $dv = DataValue::ofInt32(0);
        $event = new NodeValueRead($client, $nodeId, 13, $dv);
        expect($event->nodeId)->toBe($nodeId);
        expect($event->attributeId)->toBe(13);
        expect($event->dataValue)->toBe($dv);
    });

    it('creates NodeValueWritten', function () {
        $client = MockClient::create();
        $nodeId = NodeId::numeric(2, 1001);
        $event = new NodeValueWritten($client, $nodeId, 42, BuiltinType::Int32, 0);
        expect($event->nodeId)->toBe($nodeId);
        expect($event->value)->toBe(42);
        expect($event->type)->toBe(BuiltinType::Int32);
        expect($event->statusCode)->toBe(0);
    });

    it('creates NodeValueWriteFailed', function () {
        $client = MockClient::create();
        $nodeId = NodeId::numeric(2, 1001);
        $event = new NodeValueWriteFailed($client, $nodeId, 0x803B0000);
        expect($event->nodeId)->toBe($nodeId);
        expect($event->statusCode)->toBe(0x803B0000);
    });

    it('creates NodeBrowsed', function () {
        $client = MockClient::create();
        $nodeId = NodeId::numeric(0, 85);
        $event = new NodeBrowsed($client, $nodeId, BrowseDirection::Forward, 5);
        expect($event->nodeId)->toBe($nodeId);
        expect($event->direction)->toBe(BrowseDirection::Forward);
        expect($event->resultCount)->toBe(5);
    });

});

describe('Cache events', function () {

    it('creates CacheHit', function () {
        $client = MockClient::create();
        $event = new CacheHit($client, 'opcua:abc:browse:i=85');
        expect($event->client)->toBe($client);
        expect($event->key)->toBe('opcua:abc:browse:i=85');
    });

    it('creates CacheMiss', function () {
        $client = MockClient::create();
        $event = new CacheMiss($client, 'opcua:abc:browse:i=85');
        expect($event->client)->toBe($client);
        expect($event->key)->toBe('opcua:abc:browse:i=85');
    });

});

describe('Retry events', function () {

    it('creates RetryAttempt', function () {
        $client = MockClient::create();
        $ex = new RuntimeException('lost');
        $event = new RetryAttempt($client, 2, 5, $ex);
        expect($event->attempt)->toBe(2);
        expect($event->maxRetries)->toBe(5);
        expect($event->exception)->toBe($ex);
    });

    it('creates RetryExhausted', function () {
        $client = MockClient::create();
        $ex = new RuntimeException('gone');
        $event = new RetryExhausted($client, 5, $ex);
        expect($event->attempts)->toBe(5);
        expect($event->exception)->toBe($ex);
    });

});

describe('Type discovery events', function () {

    it('creates DataTypesDiscovered', function () {
        $client = MockClient::create();
        $event = new DataTypesDiscovered($client, 2, 15);
        expect($event->namespaceIndex)->toBe(2);
        expect($event->count)->toBe(15);
    });

    it('creates DataTypesDiscovered with null namespace', function () {
        $client = MockClient::create();
        $event = new DataTypesDiscovered($client, null, 5);
        expect($event->namespaceIndex)->toBeNull();
        expect($event->count)->toBe(5);
    });

});

describe('Alarm events', function () {

    it('creates AlarmEventReceived with all fields', function () {
        $client = MockClient::create();
        $eventType = NodeId::numeric(0, 2955);
        $time = new DateTimeImmutable();
        $event = new AlarmEventReceived($client, 1, 2, [], 500, 'Sensor1', 'High temp', $eventType, $time);
        expect($event->severity)->toBe(500);
        expect($event->sourceName)->toBe('Sensor1');
        expect($event->message)->toBe('High temp');
        expect($event->eventType)->toBe($eventType);
        expect($event->time)->toBe($time);
    });

    it('creates AlarmEventReceived with nullable fields', function () {
        $client = MockClient::create();
        $event = new AlarmEventReceived($client, 1, 2, []);
        expect($event->severity)->toBeNull();
        expect($event->sourceName)->toBeNull();
        expect($event->eventType)->toBeNull();
    });

    it('creates AlarmActivated', function () {
        $client = MockClient::create();
        $event = new AlarmActivated($client, 1, 2, 'Temp', 800, 'Over');
        expect($event->sourceName)->toBe('Temp');
        expect($event->severity)->toBe(800);
        expect($event->message)->toBe('Over');
    });

    it('creates AlarmDeactivated', function () {
        $client = MockClient::create();
        $event = new AlarmDeactivated($client, 1, 2, 'Temp', 'Normal');
        expect($event->sourceName)->toBe('Temp');
        expect($event->message)->toBe('Normal');
    });

    it('creates AlarmAcknowledged', function () {
        $client = MockClient::create();
        $event = new AlarmAcknowledged($client, 1, 2, 'Pump1');
        expect($event->sourceName)->toBe('Pump1');
    });

    it('creates AlarmConfirmed', function () {
        $client = MockClient::create();
        $event = new AlarmConfirmed($client, 1, 2, 'Pump1');
        expect($event->sourceName)->toBe('Pump1');
    });

    it('creates AlarmShelved', function () {
        $client = MockClient::create();
        $event = new AlarmShelved($client, 1, 2, 'Pump1');
        expect($event->sourceName)->toBe('Pump1');
    });

    it('creates AlarmSeverityChanged', function () {
        $client = MockClient::create();
        $event = new AlarmSeverityChanged($client, 1, 2, 'Pump1', 900);
        expect($event->severity)->toBe(900);
    });

    it('creates LimitAlarmExceeded', function () {
        $client = MockClient::create();
        $event = new LimitAlarmExceeded($client, 1, 2, 'Level', 'HighHigh', 1000);
        expect($event->limitState)->toBe('HighHigh');
        expect($event->severity)->toBe(1000);
    });

    it('creates OffNormalAlarmTriggered', function () {
        $client = MockClient::create();
        $event = new OffNormalAlarmTriggered($client, 1, 2, 'Switch1', 500);
        expect($event->sourceName)->toBe('Switch1');
        expect($event->severity)->toBe(500);
    });

    it('creates MonitoredItemModified', function () {
        $client = MockClient::create();
        $event = new PhpOpcua\Client\Event\MonitoredItemModified($client, 1, 42, 0);
        expect($event->client)->toBe($client);
        expect($event->subscriptionId)->toBe(1);
        expect($event->monitoredItemId)->toBe(42);
        expect($event->statusCode)->toBe(0);
    });

    it('creates ServerCertificateManuallyTrusted', function () {
        $client = MockClient::create();
        $event = new PhpOpcua\Client\Event\ServerCertificateManuallyTrusted($client, 'ab:cd:ef', 'CN=test');
        expect($event->fingerprint)->toBe('ab:cd:ef');
        expect($event->subject)->toBe('CN=test');
    });

    it('creates ServerCertificateManuallyTrusted with null subject', function () {
        $client = MockClient::create();
        $event = new PhpOpcua\Client\Event\ServerCertificateManuallyTrusted($client, 'ab:cd:ef');
        expect($event->subject)->toBeNull();
    });

    it('creates ServerCertificateRemoved', function () {
        $client = MockClient::create();
        $event = new PhpOpcua\Client\Event\ServerCertificateRemoved($client, 'ab:cd:ef');
        expect($event->fingerprint)->toBe('ab:cd:ef');
    });

    it('creates TriggeringConfigured', function () {
        $client = MockClient::create();
        $event = new PhpOpcua\Client\Event\TriggeringConfigured($client, 1, 42, [0, 0], [0]);
        expect($event->subscriptionId)->toBe(1);
        expect($event->triggeringItemId)->toBe(42);
        expect($event->addResults)->toBe([0, 0]);
        expect($event->removeResults)->toBe([0]);
    });

});
