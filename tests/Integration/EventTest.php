<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;
use Gianfriaur\OpcuaPhpClient\Types\Variant;

describe('Event', function () {

    it('creates an event monitored item on EventEmitter node', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $sub = $client->createSubscription(250.0);
            $subId = $sub['subscriptionId'];

            $eventEmitterNodeId = TestHelper::browseToNode($client, ['TestServer', 'Events', 'EventEmitter']);

            $result = $client->createEventMonitoredItem(
                $subId,
                $eventEmitterNodeId,
                ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'],
                1,
            );

            expect(StatusCode::isGood($result['statusCode']))->toBeTrue();
            expect($result['monitoredItemId'])->toBeInt()->toBeGreaterThan(0);

            // Cleanup
            $client->deleteMonitoredItems($subId, [$result['monitoredItemId']]);
            $client->deleteSubscription($subId);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('triggers an event and receives event notification via publish', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $sub = $client->createSubscription(250.0);
            $subId = $sub['subscriptionId'];

            $eventEmitterNodeId = TestHelper::browseToNode($client, ['TestServer', 'Events', 'EventEmitter']);

            $monResult = $client->createEventMonitoredItem(
                $subId,
                $eventEmitterNodeId,
                ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'],
                1,
            );
            $monId = $monResult['monitoredItemId'];

            // Call GenerateEvent method to trigger an event
            $eventsNodeId = TestHelper::browseToNode($client, ['TestServer', 'Events']);
            $refs = $client->browse($eventsNodeId);
            $generateRef = TestHelper::findRefByName($refs, 'GenerateEvent');

            if ($generateRef !== null) {
                $client->call($eventsNodeId, $generateRef->getNodeId(), []);
            }

            // Wait and publish to receive the event notification
            usleep(500_000);

            $receivedEvent = false;
            for ($i = 0; $i < 5; $i++) {
                $pub = $client->publish();
                if (!empty($pub['notifications'])) {
                    $receivedEvent = true;
                    break;
                }
                usleep(300_000);
            }

            expect($receivedEvent)->toBeTrue();

            // Cleanup
            $client->deleteMonitoredItems($subId, [$monId]);
            $client->deleteSubscription($subId);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');
