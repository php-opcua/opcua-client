<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Client;

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
use PhpOpcua\Client\Protocol\ServiceTypeId;
use PhpOpcua\Client\Types\MonitoredItemModifyResult;
use PhpOpcua\Client\Types\MonitoredItemResult;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\PublishResult;
use PhpOpcua\Client\Types\SetTriggeringResult;
use PhpOpcua\Client\Types\SubscriptionResult;
use PhpOpcua\Client\Types\TransferResult;

/**
 * Provides subscription and monitored item management for OPC UA data change and event notifications.
 */
trait ManagesSubscriptionsTrait
{
    /**
     * Create a subscription for receiving data change or event notifications.
     *
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
        return $this->executeWithRetry(function () use ($publishingInterval, $lifetimeCount, $maxKeepAliveCount, $maxNotificationsPerPublish, $publishingEnabled, $priority) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->subscriptionService->encodeCreateSubscriptionRequest(
                $requestId,
                $this->authenticationToken,
                $publishingInterval,
                $lifetimeCount,
                $maxKeepAliveCount,
                $maxNotificationsPerPublish,
                $publishingEnabled,
                $priority,
            );
            $this->logger->debug('CreateSubscription request (interval={interval}ms)', ['interval' => $publishingInterval]);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            $result = $this->subscriptionService->decodeCreateSubscriptionResponse($decoder);
            $this->logger->debug('CreateSubscription response: subscriptionId={subId}, revisedInterval={interval}ms', [
                'subId' => $result->subscriptionId,
                'interval' => $result->revisedPublishingInterval,
            ]);

            $this->dispatch(fn () => new SubscriptionCreated(
                $this,
                $result->subscriptionId,
                $result->revisedPublishingInterval,
                $result->revisedLifetimeCount,
                $result->revisedMaxKeepAliveCount,
            ));

            return $result;
        });
    }

    /**
     * Create monitored items within an existing subscription for data change notifications.
     *
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
            return new \PhpOpcua\Client\Builder\MonitoredItemsBuilder($this, $subscriptionId);
        }

        $this->resolveNodeIdArrayParam($monitoredItems);

        return $this->executeWithRetry(function () use ($subscriptionId, $monitoredItems) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->monitoredItemService->encodeCreateMonitoredItemsRequest(
                $requestId,
                $this->authenticationToken,
                $subscriptionId,
                $monitoredItems,
            );
            $this->logger->debug('CreateMonitoredItems request: {count} item(s) for subscription {subId}', [
                'count' => count($monitoredItems),
                'subId' => $subscriptionId,
            ]);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            $results = $this->monitoredItemService->decodeCreateMonitoredItemsResponse($decoder);
            $this->logger->debug('CreateMonitoredItems response: {count} result(s)', ['count' => count($results)]);

            foreach ($results as $i => $result) {
                $itemNodeId = $monitoredItems[$i]['nodeId'] ?? NodeId::numeric(0, ServiceTypeId::NULL);
                $this->dispatch(fn () => new MonitoredItemCreated(
                    $this,
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
     * Create a single event-based monitored item within an existing subscription.
     *
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
        $nodeId = $this->resolveNodeIdParam($nodeId);

        return $this->executeWithRetry(function () use ($subscriptionId, $nodeId, $selectFields, $clientHandle) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->monitoredItemService->encodeCreateEventMonitoredItemRequest(
                $requestId,
                $this->authenticationToken,
                $subscriptionId,
                $nodeId,
                $selectFields,
                $clientHandle,
            );
            $this->logger->debug('CreateEventMonitoredItem request for node {nodeId} on subscription {subId}', [
                'nodeId' => (string) $nodeId,
                'subId' => $subscriptionId,
            ]);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            $results = $this->monitoredItemService->decodeCreateMonitoredItemsResponse($decoder);
            $this->logger->debug('CreateEventMonitoredItem response received');
            $result = $results[0] ?? new MonitoredItemResult(0, 0, 0.0, 0);

            $this->dispatch(fn () => new MonitoredItemCreated(
                $this,
                $subscriptionId,
                $result->monitoredItemId,
                $nodeId,
                $result->statusCode,
            ));

            return $result;
        });
    }

    /**
     * Delete monitored items from a subscription.
     *
     * @param int $subscriptionId The subscription owning the monitored items.
     * @param int[] $monitoredItemIds IDs of the monitored items to delete.
     * @return int[] OPC UA status codes for each deletion.
     *
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     */
    public function deleteMonitoredItems(int $subscriptionId, array $monitoredItemIds): array
    {
        return $this->executeWithRetry(function () use ($subscriptionId, $monitoredItemIds) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->monitoredItemService->encodeDeleteMonitoredItemsRequest(
                $requestId,
                $this->authenticationToken,
                $subscriptionId,
                $monitoredItemIds,
            );
            $this->logger->debug('DeleteMonitoredItems request: {count} item(s) from subscription {subId}', [
                'count' => count($monitoredItemIds),
                'subId' => $subscriptionId,
            ]);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            $results = $this->monitoredItemService->decodeDeleteMonitoredItemsResponse($decoder);
            $this->logger->debug('DeleteMonitoredItems response: {count} result(s)', ['count' => count($results)]);

            foreach ($results as $i => $statusCode) {
                $monItemId = $monitoredItemIds[$i] ?? 0;
                $this->dispatch(fn () => new MonitoredItemDeleted($this, $subscriptionId, $monItemId, $statusCode));
            }

            return $results;
        });
    }

    /**
     * Modify parameters of existing monitored items without recreating them.
     *
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
        return $this->executeWithRetry(function () use ($subscriptionId, $itemsToModify) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->monitoredItemService->encodeModifyMonitoredItemsRequest(
                $requestId,
                $this->authenticationToken,
                $subscriptionId,
                $itemsToModify,
            );
            $this->logger->debug('ModifyMonitoredItems request: {count} item(s) on subscription {subId}', [
                'count' => count($itemsToModify),
                'subId' => $subscriptionId,
            ]);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            $results = $this->monitoredItemService->decodeModifyMonitoredItemsResponse($decoder);
            $this->logger->debug('ModifyMonitoredItems response: {count} result(s)', ['count' => count($results)]);

            foreach ($results as $i => $result) {
                $monItemId = $itemsToModify[$i]['monitoredItemId'] ?? 0;
                $this->dispatch(fn () => new MonitoredItemModified($this, $subscriptionId, $monItemId, $result->statusCode));
            }

            return $results;
        });
    }

    /**
     * Configure triggering links between monitored items.
     *
     * The triggering item controls when linked items are sampled and reported.
     * Linked items only produce notifications when the triggering item changes.
     *
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
        return $this->executeWithRetry(function () use ($subscriptionId, $triggeringItemId, $linksToAdd, $linksToRemove) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->monitoredItemService->encodeSetTriggeringRequest(
                $requestId,
                $this->authenticationToken,
                $subscriptionId,
                $triggeringItemId,
                $linksToAdd,
                $linksToRemove,
            );
            $this->logger->debug('SetTriggering request: triggerItem={triggerId} on subscription {subId}', [
                'triggerId' => $triggeringItemId,
                'subId' => $subscriptionId,
            ]);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            $result = $this->monitoredItemService->decodeSetTriggeringResponse($decoder);
            $this->logger->debug('SetTriggering response received');

            $this->dispatch(fn () => new TriggeringConfigured(
                $this,
                $subscriptionId,
                $triggeringItemId,
                $result->addResults,
                $result->removeResults,
            ));

            return $result;
        });
    }

    /**
     * Delete a subscription and all its monitored items.
     *
     * @param int $subscriptionId The subscription to delete.
     * @return int The OPC UA status code for the deletion.
     *
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     */
    public function deleteSubscription(int $subscriptionId): int
    {
        return $this->executeWithRetry(function () use ($subscriptionId) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->subscriptionService->encodeDeleteSubscriptionsRequest(
                $requestId,
                $this->authenticationToken,
                [$subscriptionId],
            );
            $this->logger->debug('DeleteSubscription request: subscriptionId={subId}', ['subId' => $subscriptionId]);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            $results = $this->subscriptionService->decodeDeleteSubscriptionsResponse($decoder);
            $this->logger->debug('DeleteSubscription response received');
            $statusCode = $results[0] ?? 0;

            $this->dispatch(fn () => new SubscriptionDeleted($this, $subscriptionId, $statusCode));

            return $statusCode;
        });
    }

    /**
     * Send a publish request to receive pending notifications from subscriptions.
     *
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
        return $this->executeWithRetry(function () use ($acknowledgements) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->publishService->encodePublishRequest(
                $requestId,
                $this->authenticationToken,
                $acknowledgements,
            );
            $this->logger->debug('Publish request ({ackCount} acknowledgement(s))', ['ackCount' => count($acknowledgements)]);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            $result = $this->publishService->decodePublishResponse($decoder);
            $this->logger->debug('Publish response: subscriptionId={subId}, {count} notification(s)', [
                'subId' => $result->subscriptionId,
                'count' => count($result->notifications),
            ]);

            $this->dispatchPublishEvents($result);

            return $result;
        });
    }

    /**
     * Transfer subscriptions from another session to this session.
     *
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
        return $this->executeWithRetry(function () use ($subscriptionIds, $sendInitialValues) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->subscriptionService->encodeTransferSubscriptionsRequest(
                $requestId,
                $this->authenticationToken,
                $subscriptionIds,
                $sendInitialValues,
            );
            $this->logger->debug('TransferSubscriptions request: {count} subscription(s)', ['count' => count($subscriptionIds)]);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            $results = $this->subscriptionService->decodeTransferSubscriptionsResponse($decoder);
            $this->logger->debug('TransferSubscriptions response: {count} result(s)', ['count' => count($results)]);

            foreach ($results as $i => $transferResult) {
                $subId = $subscriptionIds[$i] ?? 0;
                $this->dispatch(fn () => new SubscriptionTransferred($this, $subId, $transferResult->statusCode));
            }

            return $results;
        });
    }

    /**
     * Request the server to re-send a previously published notification message.
     *
     * @param int $subscriptionId The subscription ID.
     * @param int $retransmitSequenceNumber The sequence number to retransmit.
     * @return array{sequenceNumber: int, publishTime: ?DateTimeImmutable, notifications: array}
     *
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     */
    public function republish(int $subscriptionId, int $retransmitSequenceNumber): array
    {
        return $this->executeWithRetry(function () use ($subscriptionId, $retransmitSequenceNumber) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->subscriptionService->encodeRepublishRequest(
                $requestId,
                $this->authenticationToken,
                $subscriptionId,
                $retransmitSequenceNumber,
            );
            $this->logger->debug('Republish request: subscriptionId={subId}, sequenceNumber={seqNum}', [
                'subId' => $subscriptionId,
                'seqNum' => $retransmitSequenceNumber,
            ]);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            $result = $this->subscriptionService->decodeRepublishResponse($decoder);
            $this->logger->debug('Republish response received');

            return $result;
        });
    }

    /**
     * Dispatch events for a publish response: per-notification events, keep-alive, and alarm deduction.
     *
     * @param PublishResult $result The publish result to dispatch events for.
     * @return void
     */
    private function dispatchPublishEvents(PublishResult $result): void
    {
        $this->dispatch(fn () => new PublishResponseReceived(
            $this,
            $result->subscriptionId,
            $result->sequenceNumber,
            count($result->notifications),
            $result->moreNotifications,
        ));

        if (empty($result->notifications)) {
            $this->dispatch(fn () => new SubscriptionKeepAlive($this, $result->subscriptionId, $result->sequenceNumber));

            return;
        }

        foreach ($result->notifications as $notification) {
            if ($notification['type'] === 'DataChange') {
                $this->dispatch(fn () => new DataChangeReceived(
                    $this,
                    $result->subscriptionId,
                    $result->sequenceNumber,
                    $notification['clientHandle'],
                    $notification['dataValue'],
                ));
            } elseif ($notification['type'] === 'Event') {
                $eventFields = $notification['eventFields'];
                $clientHandle = $notification['clientHandle'];

                $this->dispatch(fn () => new EventNotificationReceived(
                    $this,
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
     * Analyze event notification fields and dispatch alarm-specific events.
     *
     * @param int $subscriptionId The subscription ID.
     * @param int $clientHandle The client handle for the monitored item.
     * @param \PhpOpcua\Client\Types\Variant[] $eventFields The event field values.
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

        $this->dispatch(fn () => new AlarmEventReceived(
            $this,
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
            $this->dispatch(fn () => new AlarmSeverityChanged($this, $subscriptionId, $clientHandle, $sourceName, $severity));
        }

        if ($eventType !== null && $eventType->namespaceIndex === 0) {
            $typeId = $eventType->getIdentifier();

            if (in_array($typeId, self::LIMIT_ALARM_TYPE_IDS, true)) {
                $limitState = is_string($fieldValues[6] ?? null) ? $fieldValues[6] : null;
                $this->dispatch(fn () => new LimitAlarmExceeded($this, $subscriptionId, $clientHandle, $sourceName, $limitState, $severity));
            }

            if (in_array($typeId, self::OFF_NORMAL_ALARM_TYPE_IDS, true)) {
                $this->dispatch(fn () => new OffNormalAlarmTriggered($this, $subscriptionId, $clientHandle, $sourceName, $severity));
            }
        }

        $this->dispatchStateAlarmEvents($subscriptionId, $clientHandle, $sourceName, $severity, $message, $eventFields);
    }

    /**
     * Dispatch state-transition alarm events from extended event fields.
     *
     * @param int $subscriptionId The subscription ID.
     * @param int $clientHandle The client handle.
     * @param ?string $sourceName The alarm source name.
     * @param ?int $severity The alarm severity.
     * @param ?string $message The alarm message.
     * @param \PhpOpcua\Client\Types\Variant[] $eventFields The event field values.
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
                $this->dispatch(fn () => new AlarmActivated($this, $subscriptionId, $clientHandle, $sourceName, $severity, $message));
                break;
            } elseif ($val === false) {
                $this->dispatch(fn () => new AlarmDeactivated($this, $subscriptionId, $clientHandle, $sourceName, $message));
                break;
            } elseif (is_string($val)) {
                $lower = strtolower($val);
                if (str_contains($lower, 'acknowledged') || str_contains($lower, 'acked')) {
                    $this->dispatch(fn () => new AlarmAcknowledged($this, $subscriptionId, $clientHandle, $sourceName));
                    break;
                }
                if (str_contains($lower, 'confirmed')) {
                    $this->dispatch(fn () => new AlarmConfirmed($this, $subscriptionId, $clientHandle, $sourceName));
                    break;
                }
                if (str_contains($lower, 'shelved')) {
                    $this->dispatch(fn () => new AlarmShelved($this, $subscriptionId, $clientHandle, $sourceName));
                    break;
                }
                if (str_starts_with($lower, 'active')) {
                    $this->dispatch(fn () => new AlarmActivated($this, $subscriptionId, $clientHandle, $sourceName, $severity, $message));
                    break;
                }
                if (str_starts_with($lower, 'inactive')) {
                    $this->dispatch(fn () => new AlarmDeactivated($this, $subscriptionId, $clientHandle, $sourceName, $message));
                    break;
                }
            }
        }
    }
}
