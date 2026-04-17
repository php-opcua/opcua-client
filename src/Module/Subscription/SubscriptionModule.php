<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Subscription;

use DateTimeImmutable;
use PhpOpcua\Client\Event\AlarmAcknowledged;
use PhpOpcua\Client\Event\AlarmActivated;
use PhpOpcua\Client\Event\AlarmConfirmed;
use PhpOpcua\Client\Event\AlarmDeactivated;
use PhpOpcua\Client\Event\AlarmEventReceived;
use PhpOpcua\Client\Event\AlarmSeverityChanged;
use PhpOpcua\Client\Event\AlarmShelved;
use PhpOpcua\Client\Event\DataChangeReceived;
use PhpOpcua\Client\Event\EventNotificationReceived;
use PhpOpcua\Client\Event\LimitAlarmExceeded;
use PhpOpcua\Client\Event\MonitoredItemCreated;
use PhpOpcua\Client\Event\MonitoredItemDeleted;
use PhpOpcua\Client\Event\MonitoredItemModified;
use PhpOpcua\Client\Event\OffNormalAlarmTriggered;
use PhpOpcua\Client\Event\PublishResponseReceived;
use PhpOpcua\Client\Event\SubscriptionCreated;
use PhpOpcua\Client\Event\SubscriptionDeleted;
use PhpOpcua\Client\Event\SubscriptionKeepAlive;
use PhpOpcua\Client\Event\SubscriptionTransferred;
use PhpOpcua\Client\Event\TriggeringConfigured;
use PhpOpcua\Client\Module\ServiceModule;
use PhpOpcua\Client\Protocol\ServiceTypeId;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Wire\WireTypeRegistry;

/**
 * Provides subscription, monitored item, and publish operations.
 */
class SubscriptionModule extends ServiceModule
{
    private ?SubscriptionService $subscriptionService = null;

    private ?MonitoredItemService $monitoredItemService = null;

    private ?PublishService $publishService = null;

    public function register(): void
    {
        $this->client->registerMethod('createSubscription', $this->createSubscription(...));
        $this->client->registerMethod('createMonitoredItems', $this->createMonitoredItems(...));
        $this->client->registerMethod('createEventMonitoredItem', $this->createEventMonitoredItem(...));
        $this->client->registerMethod('deleteMonitoredItems', $this->deleteMonitoredItems(...));
        $this->client->registerMethod('modifyMonitoredItems', $this->modifyMonitoredItems(...));
        $this->client->registerMethod('setTriggering', $this->setTriggering(...));
        $this->client->registerMethod('deleteSubscription', $this->deleteSubscription(...));
        $this->client->registerMethod('publish', $this->publish(...));
        $this->client->registerMethod('republish', $this->republish(...));
        $this->client->registerMethod('transferSubscriptions', $this->transferSubscriptions(...));
    }

    public function boot(SessionService $session): void
    {
        $this->subscriptionService = new SubscriptionService($session);
        $this->monitoredItemService = new MonitoredItemService($session);
        $this->publishService = new PublishService($session);
    }

    public function reset(): void
    {
        $this->subscriptionService = null;
        $this->monitoredItemService = null;
        $this->publishService = null;
    }

    public function registerWireTypes(WireTypeRegistry $registry): void
    {
        $registry->register(SubscriptionResult::class);
        $registry->register(TransferResult::class);
        $registry->register(MonitoredItemResult::class);
        $registry->register(MonitoredItemModifyResult::class);
        $registry->register(PublishResult::class);
        $registry->register(SetTriggeringResult::class);
    }

    /**
     * @param float $publishingInterval Requested publishing interval in milliseconds.
     * @param int $lifetimeCount Requested lifetime count (number of publishing intervals before expiry).
     * @param int $maxKeepAliveCount Maximum keep-alive count.
     * @param int $maxNotificationsPerPublish Maximum notifications per publish response (0 = unlimited).
     * @param bool $publishingEnabled Whether publishing is initially enabled.
     * @param int $priority Relative priority of the subscription.
     * @return SubscriptionResult
     *
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     *
     * @see SubscriptionResult
     */
    public function createSubscription(float $publishingInterval = 500.0, int $lifetimeCount = 2400, int $maxKeepAliveCount = 10, int $maxNotificationsPerPublish = 0, bool $publishingEnabled = true, int $priority = 0): SubscriptionResult
    {
        return $this->kernel->executeWithRetry(function () use ($publishingInterval, $lifetimeCount, $maxKeepAliveCount, $maxNotificationsPerPublish, $publishingEnabled, $priority) {
            $this->kernel->ensureConnected();

            $requestId = $this->kernel->nextRequestId();
            $request = $this->subscriptionService->encodeCreateSubscriptionRequest(
                $requestId,
                $this->kernel->getAuthToken(),
                $publishingInterval,
                $lifetimeCount,
                $maxKeepAliveCount,
                $maxNotificationsPerPublish,
                $publishingEnabled,
                $priority,
            );
            $this->kernel->log()->debug('CreateSubscription request (interval={interval}ms)', $this->kernel->logContext(['interval' => $publishingInterval]));
            $this->kernel->send($request);

            $response = $this->kernel->receive();
            $responseBody = $this->kernel->unwrapResponse($response);
            $decoder = $this->kernel->createDecoder($responseBody);

            $result = $this->subscriptionService->decodeCreateSubscriptionResponse($decoder);
            $this->kernel->log()->debug('CreateSubscription response: subscriptionId={subId}, revisedInterval={interval}ms', $this->kernel->logContext([
                'subId' => $result->subscriptionId,
                'interval' => $result->revisedPublishingInterval,
            ]));

            $this->kernel->dispatch(fn () => new SubscriptionCreated(
                $this->client,
                $result->subscriptionId,
                $result->revisedPublishingInterval,
                $result->revisedLifetimeCount,
                $result->revisedMaxKeepAliveCount,
            ));

            return $result;
        });
    }

    /**
     * @param int $subscriptionId The subscription to add items to.
     * @param ?array<array{nodeId: NodeId|string, attributeId?: int, samplingInterval?: float, queueSize?: int, clientHandle?: int, monitoringMode?: int}> $monitoredItems Items to monitor, or null to get a fluent builder.
     * @return ($monitoredItems is null ? \PhpOpcua\Client\Builder\MonitoredItemsBuilder : MonitoredItemResult[])
     *
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     *
     * @see MonitoredItemResult
     */
    public function createMonitoredItems(int $subscriptionId, ?array $monitoredItems = null): array|\PhpOpcua\Client\Builder\MonitoredItemsBuilder
    {
        if ($monitoredItems === null) {
            return new \PhpOpcua\Client\Builder\MonitoredItemsBuilder($this->client, $subscriptionId);
        }

        $this->kernel->resolveNodeIdArray($monitoredItems);

        return $this->kernel->executeWithRetry(function () use ($subscriptionId, $monitoredItems) {
            $this->kernel->ensureConnected();

            $requestId = $this->kernel->nextRequestId();
            $request = $this->monitoredItemService->encodeCreateMonitoredItemsRequest(
                $requestId,
                $this->kernel->getAuthToken(),
                $subscriptionId,
                $monitoredItems,
            );
            $this->kernel->log()->debug('CreateMonitoredItems request: {count} item(s) for subscription {subId}', $this->kernel->logContext([
                'count' => count($monitoredItems),
                'subId' => $subscriptionId,
            ]));
            $this->kernel->send($request);

            $response = $this->kernel->receive();
            $responseBody = $this->kernel->unwrapResponse($response);
            $decoder = $this->kernel->createDecoder($responseBody);

            $results = $this->monitoredItemService->decodeCreateMonitoredItemsResponse($decoder);
            $this->kernel->log()->debug('CreateMonitoredItems response: {count} result(s)', $this->kernel->logContext(['count' => count($results)]));

            foreach ($results as $i => $result) {
                $itemNodeId = $monitoredItems[$i]['nodeId'] ?? NodeId::numeric(0, ServiceTypeId::NULL);
                $this->kernel->dispatch(fn () => new MonitoredItemCreated(
                    $this->client,
                    $subscriptionId,
                    $result->monitoredItemId,
                    $itemNodeId,
                    $result->statusCode,
                ));
            }

            return $results;
        });
    }

    /**
     * @param int $subscriptionId The subscription to add the item to.
     * @param NodeId|string $nodeId The node to monitor for events.
     * @param string[] $selectFields Event fields to include in notifications.
     * @param int $clientHandle Client-assigned handle for correlating notifications.
     * @return MonitoredItemResult
     *
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     *
     * @see MonitoredItemResult
     */
    public function createEventMonitoredItem(
        int $subscriptionId,
        NodeId|string $nodeId,
        array $selectFields = ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'],
        int $clientHandle = 1,
    ): MonitoredItemResult {
        $nodeId = $this->kernel->resolveNodeId($nodeId);

        return $this->kernel->executeWithRetry(function () use ($subscriptionId, $nodeId, $selectFields, $clientHandle) {
            $this->kernel->ensureConnected();

            $requestId = $this->kernel->nextRequestId();
            $request = $this->monitoredItemService->encodeCreateEventMonitoredItemRequest(
                $requestId,
                $this->kernel->getAuthToken(),
                $subscriptionId,
                $nodeId,
                $selectFields,
                $clientHandle,
            );
            $this->kernel->log()->debug('CreateEventMonitoredItem request for node {nodeId} on subscription {subId}', $this->kernel->logContext([
                'nodeId' => (string) $nodeId,
                'subId' => $subscriptionId,
            ]));
            $this->kernel->send($request);

            $response = $this->kernel->receive();
            $responseBody = $this->kernel->unwrapResponse($response);
            $decoder = $this->kernel->createDecoder($responseBody);

            $results = $this->monitoredItemService->decodeCreateMonitoredItemsResponse($decoder);
            $this->kernel->log()->debug('CreateEventMonitoredItem response received', $this->kernel->logContext());
            $result = $results[0] ?? new MonitoredItemResult(0, 0, 0.0, 0);

            $this->kernel->dispatch(fn () => new MonitoredItemCreated(
                $this->client,
                $subscriptionId,
                $result->monitoredItemId,
                $nodeId,
                $result->statusCode,
            ));

            return $result;
        });
    }

    /**
     * @param int $subscriptionId The subscription owning the monitored items.
     * @param int[] $monitoredItemIds IDs of the monitored items to delete.
     * @return int[] OPC UA status codes for each deletion.
     *
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     */
    public function deleteMonitoredItems(int $subscriptionId, array $monitoredItemIds): array
    {
        return $this->kernel->executeWithRetry(function () use ($subscriptionId, $monitoredItemIds) {
            $this->kernel->ensureConnected();

            $requestId = $this->kernel->nextRequestId();
            $request = $this->monitoredItemService->encodeDeleteMonitoredItemsRequest(
                $requestId,
                $this->kernel->getAuthToken(),
                $subscriptionId,
                $monitoredItemIds,
            );
            $this->kernel->log()->debug('DeleteMonitoredItems request: {count} item(s) from subscription {subId}', $this->kernel->logContext([
                'count' => count($monitoredItemIds),
                'subId' => $subscriptionId,
            ]));
            $this->kernel->send($request);

            $response = $this->kernel->receive();
            $responseBody = $this->kernel->unwrapResponse($response);
            $decoder = $this->kernel->createDecoder($responseBody);

            $results = $this->monitoredItemService->decodeDeleteMonitoredItemsResponse($decoder);
            $this->kernel->log()->debug('DeleteMonitoredItems response: {count} result(s)', $this->kernel->logContext(['count' => count($results)]));

            foreach ($results as $i => $statusCode) {
                $monItemId = $monitoredItemIds[$i] ?? 0;
                $this->kernel->dispatch(fn () => new MonitoredItemDeleted($this->client, $subscriptionId, $monItemId, $statusCode));
            }

            return $results;
        });
    }

    /**
     * @param int $subscriptionId The subscription owning the monitored items.
     * @param array<array{monitoredItemId: int, samplingInterval?: float, queueSize?: int, clientHandle?: int, discardOldest?: bool}> $itemsToModify Items to modify.
     * @return MonitoredItemModifyResult[]
     *
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     *
     * @see MonitoredItemModifyResult
     */
    public function modifyMonitoredItems(int $subscriptionId, array $itemsToModify): array
    {
        return $this->kernel->executeWithRetry(function () use ($subscriptionId, $itemsToModify) {
            $this->kernel->ensureConnected();

            $requestId = $this->kernel->nextRequestId();
            $request = $this->monitoredItemService->encodeModifyMonitoredItemsRequest(
                $requestId,
                $this->kernel->getAuthToken(),
                $subscriptionId,
                $itemsToModify,
            );
            $this->kernel->log()->debug('ModifyMonitoredItems request: {count} item(s) on subscription {subId}', $this->kernel->logContext([
                'count' => count($itemsToModify),
                'subId' => $subscriptionId,
            ]));
            $this->kernel->send($request);

            $response = $this->kernel->receive();
            $responseBody = $this->kernel->unwrapResponse($response);
            $decoder = $this->kernel->createDecoder($responseBody);

            $results = $this->monitoredItemService->decodeModifyMonitoredItemsResponse($decoder);
            $this->kernel->log()->debug('ModifyMonitoredItems response: {count} result(s)', $this->kernel->logContext(['count' => count($results)]));

            foreach ($results as $i => $result) {
                $monItemId = $itemsToModify[$i]['monitoredItemId'] ?? 0;
                $this->kernel->dispatch(fn () => new MonitoredItemModified($this->client, $subscriptionId, $monItemId, $result->statusCode));
            }

            return $results;
        });
    }

    /**
     * @param int $subscriptionId The subscription owning the items.
     * @param int $triggeringItemId The monitored item that acts as the trigger.
     * @param int[] $linksToAdd Monitored item IDs to link as triggered items.
     * @param int[] $linksToRemove Monitored item IDs to unlink.
     * @return SetTriggeringResult
     *
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     *
     * @see SetTriggeringResult
     */
    public function setTriggering(int $subscriptionId, int $triggeringItemId, array $linksToAdd = [], array $linksToRemove = []): SetTriggeringResult
    {
        return $this->kernel->executeWithRetry(function () use ($subscriptionId, $triggeringItemId, $linksToAdd, $linksToRemove) {
            $this->kernel->ensureConnected();

            $requestId = $this->kernel->nextRequestId();
            $request = $this->monitoredItemService->encodeSetTriggeringRequest(
                $requestId,
                $this->kernel->getAuthToken(),
                $subscriptionId,
                $triggeringItemId,
                $linksToAdd,
                $linksToRemove,
            );
            $this->kernel->log()->debug('SetTriggering request: triggerItem={triggerId} on subscription {subId}', $this->kernel->logContext([
                'triggerId' => $triggeringItemId,
                'subId' => $subscriptionId,
            ]));
            $this->kernel->send($request);

            $response = $this->kernel->receive();
            $responseBody = $this->kernel->unwrapResponse($response);
            $decoder = $this->kernel->createDecoder($responseBody);

            $result = $this->monitoredItemService->decodeSetTriggeringResponse($decoder);
            $this->kernel->log()->debug('SetTriggering response received', $this->kernel->logContext());

            $this->kernel->dispatch(fn () => new TriggeringConfigured(
                $this->client,
                $subscriptionId,
                $triggeringItemId,
                $result->addResults,
                $result->removeResults,
            ));

            return $result;
        });
    }

    /**
     * @param int $subscriptionId The subscription to delete.
     * @return int The OPC UA status code for the deletion.
     *
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     */
    public function deleteSubscription(int $subscriptionId): int
    {
        return $this->kernel->executeWithRetry(function () use ($subscriptionId) {
            $this->kernel->ensureConnected();

            $requestId = $this->kernel->nextRequestId();
            $request = $this->subscriptionService->encodeDeleteSubscriptionsRequest(
                $requestId,
                $this->kernel->getAuthToken(),
                [$subscriptionId],
            );
            $this->kernel->log()->debug('DeleteSubscription request: subscriptionId={subId}', $this->kernel->logContext(['subId' => $subscriptionId]));
            $this->kernel->send($request);

            $response = $this->kernel->receive();
            $responseBody = $this->kernel->unwrapResponse($response);
            $decoder = $this->kernel->createDecoder($responseBody);

            $results = $this->subscriptionService->decodeDeleteSubscriptionsResponse($decoder);
            $this->kernel->log()->debug('DeleteSubscription response received', $this->kernel->logContext());
            $statusCode = $results[0] ?? 0;

            $this->kernel->dispatch(fn () => new SubscriptionDeleted($this->client, $subscriptionId, $statusCode));

            return $statusCode;
        });
    }

    /**
     * @param array<array{subscriptionId: int, sequenceNumber: int}> $acknowledgements Previously received notifications to acknowledge.
     * @return PublishResult
     *
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     *
     * @see PublishResult
     */
    public function publish(array $acknowledgements = []): PublishResult
    {
        return $this->kernel->executeWithRetry(function () use ($acknowledgements) {
            $this->kernel->ensureConnected();

            $requestId = $this->kernel->nextRequestId();
            $request = $this->publishService->encodePublishRequest(
                $requestId,
                $this->kernel->getAuthToken(),
                $acknowledgements,
            );
            $this->kernel->log()->debug('Publish request ({ackCount} acknowledgement(s))', $this->kernel->logContext(['ackCount' => count($acknowledgements)]));
            $this->kernel->send($request);

            $response = $this->kernel->receive();
            $responseBody = $this->kernel->unwrapResponse($response);
            $decoder = $this->kernel->createDecoder($responseBody);

            $result = $this->publishService->decodePublishResponse($decoder);
            $this->kernel->log()->debug('Publish response: subscriptionId={subId}, {count} notification(s)', $this->kernel->logContext([
                'subId' => $result->subscriptionId,
                'count' => count($result->notifications),
            ]));

            $this->dispatchPublishEvents($result);

            return $result;
        });
    }

    /**
     * @param int $subscriptionId The subscription ID.
     * @param int $retransmitSequenceNumber The sequence number to retransmit.
     * @return array{sequenceNumber: int, publishTime: ?DateTimeImmutable, notifications: array}
     *
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     */
    public function republish(int $subscriptionId, int $retransmitSequenceNumber): array
    {
        return $this->kernel->executeWithRetry(function () use ($subscriptionId, $retransmitSequenceNumber) {
            $this->kernel->ensureConnected();

            $requestId = $this->kernel->nextRequestId();
            $request = $this->subscriptionService->encodeRepublishRequest(
                $requestId,
                $this->kernel->getAuthToken(),
                $subscriptionId,
                $retransmitSequenceNumber,
            );
            $this->kernel->log()->debug('Republish request: subscriptionId={subId}, sequenceNumber={seqNum}', $this->kernel->logContext([
                'subId' => $subscriptionId,
                'seqNum' => $retransmitSequenceNumber,
            ]));
            $this->kernel->send($request);

            $response = $this->kernel->receive();
            $responseBody = $this->kernel->unwrapResponse($response);
            $decoder = $this->kernel->createDecoder($responseBody);

            $result = $this->subscriptionService->decodeRepublishResponse($decoder);
            $this->kernel->log()->debug('Republish response received', $this->kernel->logContext());

            return $result;
        });
    }

    /**
     * @param int[] $subscriptionIds Subscription IDs to transfer.
     * @param bool $sendInitialValues Whether the server should send initial values after transfer.
     * @return TransferResult[]
     *
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     *
     * @see TransferResult
     */
    public function transferSubscriptions(array $subscriptionIds, bool $sendInitialValues = false): array
    {
        return $this->kernel->executeWithRetry(function () use ($subscriptionIds, $sendInitialValues) {
            $this->kernel->ensureConnected();

            $requestId = $this->kernel->nextRequestId();
            $request = $this->subscriptionService->encodeTransferSubscriptionsRequest(
                $requestId,
                $this->kernel->getAuthToken(),
                $subscriptionIds,
                $sendInitialValues,
            );
            $this->kernel->log()->debug('TransferSubscriptions request: {count} subscription(s)', $this->kernel->logContext(['count' => count($subscriptionIds)]));
            $this->kernel->send($request);

            $response = $this->kernel->receive();
            $responseBody = $this->kernel->unwrapResponse($response);
            $decoder = $this->kernel->createDecoder($responseBody);

            $results = $this->subscriptionService->decodeTransferSubscriptionsResponse($decoder);
            $this->kernel->log()->debug('TransferSubscriptions response: {count} result(s)', $this->kernel->logContext(['count' => count($results)]));

            foreach ($results as $i => $transferResult) {
                $subId = $subscriptionIds[$i] ?? 0;
                $this->kernel->dispatch(fn () => new SubscriptionTransferred($this->client, $subId, $transferResult->statusCode));
            }

            return $results;
        });
    }

    /**
     * Well-known OPC UA LimitAlarm type NodeId identifiers (namespace 0).
     */
    private const LIMIT_ALARM_TYPE_IDS = [
        2955, 9341, 9906, 9482, 13225, 9764, 10368, 9623,
    ];

    /**
     * Well-known OPC UA OffNormalAlarm type NodeId identifiers (namespace 0).
     */
    private const OFF_NORMAL_ALARM_TYPE_IDS = [10637, 10523];

    /**
     * @param PublishResult $result
     * @return void
     */
    private function dispatchPublishEvents(PublishResult $result): void
    {
        $this->kernel->dispatch(fn () => new PublishResponseReceived(
            $this->client,
            $result->subscriptionId,
            $result->sequenceNumber,
            count($result->notifications),
            $result->moreNotifications,
        ));

        if (empty($result->notifications)) {
            $this->kernel->dispatch(fn () => new SubscriptionKeepAlive($this->client, $result->subscriptionId, $result->sequenceNumber));

            return;
        }

        foreach ($result->notifications as $notification) {
            if ($notification['type'] === 'DataChange') {
                $this->kernel->dispatch(fn () => new DataChangeReceived(
                    $this->client,
                    $result->subscriptionId,
                    $result->sequenceNumber,
                    $notification['clientHandle'],
                    $notification['dataValue'],
                ));
            } elseif ($notification['type'] === 'Event') {
                $eventFields = $notification['eventFields'];
                $clientHandle = $notification['clientHandle'];

                $this->kernel->dispatch(fn () => new EventNotificationReceived(
                    $this->client,
                    $result->subscriptionId,
                    $result->sequenceNumber,
                    $clientHandle,
                    $eventFields,
                ));

                $this->dispatchAlarmEvents($result->subscriptionId, $clientHandle, $eventFields);
            }
        }
    }

    /**
     * @param int $subscriptionId
     * @param int $clientHandle
     * @param Variant[] $eventFields
     * @return void
     */
    private function dispatchAlarmEvents(int $subscriptionId, int $clientHandle, array $eventFields): void
    {
        $fieldValues = [];
        foreach ($eventFields as $i => $variant) {
            $fieldValues[$i] = $variant->getValue();
        }

        $eventType = (isset($eventFields[1]) && $eventFields[1]->getValue() instanceof NodeId) ? $eventFields[1]->getValue() : null;
        $sourceName = is_string($fieldValues[2] ?? null) ? $fieldValues[2] : null;
        $time = ($fieldValues[3] ?? null) instanceof DateTimeImmutable ? $fieldValues[3] : null;
        $message = is_string($fieldValues[4] ?? null) ? $fieldValues[4] : null;
        $severity = is_int($fieldValues[5] ?? null) ? $fieldValues[5] : null;

        $hasAlarmData = $severity !== null || $eventType !== null;
        if (! $hasAlarmData) {
            return;
        }

        $this->kernel->dispatch(fn () => new AlarmEventReceived(
            $this->client,
            $subscriptionId,
            $clientHandle,
            $eventFields,
            $severity,
            $sourceName,
            $message,
            $eventType,
            $time,
        ));

        if ($severity !== null) {
            $this->kernel->dispatch(fn () => new AlarmSeverityChanged($this->client, $subscriptionId, $clientHandle, $sourceName, $severity));
        }

        if ($eventType !== null && $eventType->namespaceIndex === 0) {
            $typeId = $eventType->getIdentifier();

            if (in_array($typeId, self::LIMIT_ALARM_TYPE_IDS, true)) {
                $limitState = is_string($fieldValues[6] ?? null) ? $fieldValues[6] : null;
                $this->kernel->dispatch(fn () => new LimitAlarmExceeded($this->client, $subscriptionId, $clientHandle, $sourceName, $limitState, $severity));
            }

            if (in_array($typeId, self::OFF_NORMAL_ALARM_TYPE_IDS, true)) {
                $this->kernel->dispatch(fn () => new OffNormalAlarmTriggered($this->client, $subscriptionId, $clientHandle, $sourceName, $severity));
            }
        }

        $this->dispatchStateAlarmEvents($subscriptionId, $clientHandle, $sourceName, $severity, $message, $eventFields);
    }

    /**
     * @param int $subscriptionId
     * @param int $clientHandle
     * @param ?string $sourceName
     * @param ?int $severity
     * @param ?string $message
     * @param Variant[] $eventFields
     * @return void
     */
    private function dispatchStateAlarmEvents(
        int $subscriptionId,
        int $clientHandle,
        ?string $sourceName,
        ?int $severity,
        ?string $message,
        array $eventFields,
    ): void {
        for ($i = 6; $i < count($eventFields); $i++) {
            $val = $eventFields[$i]->getValue();

            if ($val === true) {
                $this->kernel->dispatch(fn () => new AlarmActivated($this->client, $subscriptionId, $clientHandle, $sourceName, $severity, $message));
                break;
            } elseif ($val === false) {
                $this->kernel->dispatch(fn () => new AlarmDeactivated($this->client, $subscriptionId, $clientHandle, $sourceName, $message));
                break;
            } elseif (is_string($val)) {
                $lower = strtolower($val);
                if (str_contains($lower, 'acknowledged') || str_contains($lower, 'acked')) {
                    $this->kernel->dispatch(fn () => new AlarmAcknowledged($this->client, $subscriptionId, $clientHandle, $sourceName));
                    break;
                }
                if (str_contains($lower, 'confirmed')) {
                    $this->kernel->dispatch(fn () => new AlarmConfirmed($this->client, $subscriptionId, $clientHandle, $sourceName));
                    break;
                }
                if (str_contains($lower, 'shelved')) {
                    $this->kernel->dispatch(fn () => new AlarmShelved($this->client, $subscriptionId, $clientHandle, $sourceName));
                    break;
                }
                if (str_starts_with($lower, 'active')) {
                    $this->kernel->dispatch(fn () => new AlarmActivated($this->client, $subscriptionId, $clientHandle, $sourceName, $severity, $message));
                    break;
                }
                if (str_starts_with($lower, 'inactive')) {
                    $this->kernel->dispatch(fn () => new AlarmDeactivated($this->client, $subscriptionId, $clientHandle, $sourceName, $message));
                    break;
                }
            }
        }
    }
}
