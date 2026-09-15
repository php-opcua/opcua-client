<?php

declare(strict_types=1);

use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;

/**
 * open62541 validates event select clauses and reports one status per clause in the
 * EventFilterResult. UA-.NETStandard accepts unknown clauses without a filter result.
 */
describe('Event filter select clause results (integration vs open62541)', function () {

    it('reports the status of every select clause', function () {
        $client = null;
        try {
            $client = (new ClientBuilder())->connect(TestHelper::ENDPOINT_NODE_MANAGEMENT);
            $subscriptionId = $client->createSubscription(500.0)->subscriptionId;
            $server = NodeId::numeric(0, 2253);

            $valid = $client->createEventMonitoredItem($subscriptionId, $server, ['EventId', 'SourceName', 'Severity'], 1);
            expect($valid->statusCode)->toBe(StatusCode::Good);
            expect($valid->selectClauseResults)->toBe([]);

            $mixed = $client->createEventMonitoredItem($subscriptionId, $server, ['EventId', 'DoesNotExist', 'Severity'], 2);
            expect($mixed->statusCode)->toBe(StatusCode::BadNodeIdUnknown);
            expect($mixed->selectClauseResults)->toBe([StatusCode::Good, StatusCode::BadNodeIdUnknown, StatusCode::Good]);

            $invalid = $client->createEventMonitoredItem($subscriptionId, $server, ['Nope1', 'Nope2'], 3);
            expect($invalid->selectClauseResults)->toBe([StatusCode::BadNodeIdUnknown, StatusCode::BadNodeIdUnknown]);

            $client->deleteSubscription($subscriptionId);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');
});
