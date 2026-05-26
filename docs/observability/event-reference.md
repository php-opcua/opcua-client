---
eyebrow: 'Docs · Observability'
lede:    'The full event catalogue — 56 classes grouped by lifecycle. Each event carries a $client reference plus a handful of typed fields.'

see_also:
  - { href: './events.md',          meta: '6 min' }
  - { href: './logging.md',         meta: '5 min' }
  - { href: '../operations/monitored-items.md', meta: '7 min' }

prev: { label: 'Events',   href: './events.md' }
next: { label: 'Caching',  href: './caching.md' }
---

# Event reference

The library dispatches **56** event classes. Every event extends a
common shape with a `$client` property; the per-event fields below are
the additions on top of that.

All event classes live in `PhpOpcua\Client\Event\`.

## Connection lifecycle (6)

| Event                | Fires when                                          | Key fields                          |
| -------------------- | --------------------------------------------------- | ----------------------------------- |
| `ClientConnecting`   | `connect()` starts                                  | `endpoint`                          |
| `ClientConnected`    | Session is activated                                | `endpoint`, `sessionId`             |
| `ClientDisconnecting`| `disconnect()` starts                               | —                                   |
| `ClientDisconnected` | Disconnect completed (clean or broken)              | — (only `$client`)                  |
| `ClientReconnecting` | `reconnect()` starts                                | `endpoint`                          |
| `ConnectionFailed`   | `connect()` raised                                  | `endpoint`, `exception`             |

## Secure channel (2)

| Event                  | Fires when                            | Key fields                                  |
| ---------------------- | ------------------------------------- | ------------------------------------------- |
| `SecureChannelOpened`  | OPN exchange completed                | `channelId`, `securityPolicy` (enum), `securityMode` |
| `SecureChannelClosed`  | CLO sent or socket dropped            | `channelId`                                 |

## Session (3)

| Event              | Fires when                       | Key fields                  |
| ------------------ | -------------------------------- | --------------------------- |
| `SessionCreated`   | After CreateSession              | `sessionId`, `sessionName`  |
| `SessionActivated` | After ActivateSession            | `sessionId`                 |
| `SessionClosed`    | After CloseSession               | `sessionId`                 |

## Read / Write (3)

| Event                  | Fires when                  | Key fields                              |
| ---------------------- | --------------------------- | --------------------------------------- |
| `NodeValueRead`        | `read()` succeeded          | `nodeId`, `attributeId`, `dataValue`    |
| `NodeValueWritten`     | `write()` returned Good     | `nodeId`, `value`, `type`               |
| `NodeValueWriteFailed` | `write()` returned non-Good | `nodeId`, `value`, `type`, `statusCode` |

## Write type detection (2)

| Event                | Fires when                                  | Key fields                              |
| -------------------- | ------------------------------------------- | --------------------------------------- |
| `WriteTypeDetecting` | Auto-detection started for a node           | `nodeId`                                |
| `WriteTypeDetected`  | Auto-detection produced a `BuiltinType`     | `nodeId`, `detectedType`, `fromCache`   |

## Browse (1)

| Event         | Fires when                  | Key fields                                            |
| ------------- | --------------------------- | ----------------------------------------------------- |
| `NodeBrowsed` | `browse()` succeeded        | `nodeId`, `direction`, `referenceCount`               |

## Subscriptions (4)

| Event                    | Fires when                                  | Key fields                       |
| ------------------------ | ------------------------------------------- | -------------------------------- |
| `SubscriptionCreated`    | `createSubscription()` succeeded            | `subscriptionId`, `revisedPublishingInterval` |
| `SubscriptionDeleted`    | `deleteSubscription()` succeeded            | `subscriptionId`                 |
| `SubscriptionKeepAlive`  | Server sent an empty publish response       | `subscriptionId`                 |
| `SubscriptionTransferred`| `transferSubscriptions()` returned Good     | `subscriptionId`                 |

## Monitored items (3)

| Event                   | Fires when                                  | Key fields                                  |
| ----------------------- | ------------------------------------------- | ------------------------------------------- |
| `MonitoredItemCreated`  | `createMonitoredItems()` returned Good      | `subscriptionId`, `monitoredItemId`, `nodeId`, `statusCode` |
| `MonitoredItemModified` | `modifyMonitoredItems()` returned Good      | `subscriptionId`, `monitoredItemId`         |
| `MonitoredItemDeleted`  | `deleteMonitoredItems()` returned Good      | `subscriptionId`, `monitoredItemId`         |

## Publish (3)

| Event                       | Fires when                                  | Key fields                              |
| --------------------------- | ------------------------------------------- | --------------------------------------- |
| `DataChangeReceived`        | A data-change notification was delivered    | `subscriptionId`, `sequenceNumber`, `clientHandle`, `dataValue` |
| `EventNotificationReceived` | An event notification was delivered         | `subscriptionId`, `sequenceNumber`, `clientHandle`, `eventFields` |
| `PublishResponseReceived`   | Any publish response (including keep-alives)| `subscriptionId`, `sequenceNumber`, `notificationCount`, `moreNotifications` |

## Triggering (1)

| Event                  | Fires when                              | Key fields                          |
| ---------------------- | --------------------------------------- | ----------------------------------- |
| `TriggeringConfigured` | `setTriggering()` returned Good         | `subscriptionId`, `triggeringItemId`, `addResults`, `removeResults` |

## Alarms (9)

These are auto-deduced from event notification payloads — when an
`EventNotificationReceived` event carries alarm-shaped fields, the
library dispatches one of the specific events below in addition.

| Event                      | Fires when                                  | Key fields                                    |
| -------------------------- | ------------------------------------------- | --------------------------------------------- |
| `AlarmEventReceived`       | Any alarm-shaped event                      | `sourceName`, `message`, `severity`, `eventType` |
| `AlarmActivated`           | `ActiveState` → `Active`                    | `subscriptionId`, `clientHandle`, `sourceName`, `severity`, `message` |
| `AlarmDeactivated`         | `ActiveState` → `Inactive`                  | `sourceName`                                  |
| `AlarmAcknowledged`        | `AckedState` → acknowledged                 | `sourceName`, `acknowledger`                  |
| `AlarmConfirmed`           | `ConfirmedState` → confirmed                | `sourceName`                                  |
| `AlarmShelved`             | Shelved/Unshelved state transition          | `sourceName`, `shelved`                       |
| `AlarmSeverityChanged`     | Severity changed                            | `sourceName`, `oldSeverity`, `newSeverity`    |
| `LimitAlarmExceeded`       | LimitAlarmType variants tripped a limit     | `subscriptionId`, `clientHandle`, `sourceName`, `limitState`, `severity` |
| `OffNormalAlarmTriggered`  | OffNormalAlarmType variants tripped         | `subscriptionId`, `clientHandle`, `sourceName`, `severity` |

## Type discovery (1)

| Event                  | Fires when                                  | Key fields                |
| ---------------------- | ------------------------------------------- | ------------------------- |
| `DataTypesDiscovered`  | `discoverDataTypes()` completed             | `namespaceIndex`, `count` |

## History updates (4)

Dispatched after each HistoryUpdate call returns.

| Event                  | Fires when                                                          | Key fields                                                                                       |
| ---------------------- | ------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| `HistoryDataUpdated`   | `historyInsertData()` / `historyReplaceData()` / `historyUpdateData()` returned | `nodeId`, `operation` (`PerformUpdateType`), `valueCount`, `operationResults` (`int[]`)         |
| `HistoryDataDeleted`   | `historyDeleteRawModified()` (`kind: 'rawModified'`) or `historyDeleteAtTime()` (`kind: 'atTime'`) returned | `nodeId`, `kind` (`string`), `statusCode` (`int`), `operationResults` (`int[]`, populated only for `atTime`) |
| `HistoryEventUpdated`  | `historyInsertEvent()` / `historyReplaceEvent()` / `historyUpdateEvent()` returned | `nodeId`, `operation` (`PerformUpdateType`), `eventCount`, `operationResults` (`int[]`)         |
| `HistoryEventDeleted`  | `historyDeleteEvent()` returned                                     | `nodeId`, `eventCount`, `operationResults` (`int[]`)                                             |

`PerformUpdateType` is an int-backed enum
(`Insert=1, Replace=2, Update=3, Remove=4`) — see
[Operations · History writes](../operations/history-writes.md#performupdatetype-enum).

## Aggregates (1)

Dispatched after the client-side aggregator finishes a windowed
computation.

| Event              | Fires when                                          | Key fields                                                                                            |
| ------------------ | --------------------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| `AggregateComputed`| `aggregate()` or `historyAggregate()` returned      | `function` (`AggregateFunction`), `rawInputCount`, `intervalCount`, `nodeId` (`?NodeId`, null for in-memory) |

See [Operations · Client-side aggregates](../operations/client-side-aggregates.md).

## File Transfer (4)

Dispatched after each File Transfer call returns.

| Event              | Fires when                          | Key fields                                                                |
| ------------------ | ----------------------------------- | ------------------------------------------------------------------------- |
| `FileOpened`       | `openFile()` returned                | `fileNodeId`, `fileHandle`, `mode` (Byte bit-field per Part 5 §C.2.1)     |
| `FileClosed`       | `closeFile()` returned               | `fileNodeId`, `fileHandle`                                                |
| `FileBytesRead`    | `readFile()` returned                | `fileNodeId`, `fileHandle`, `bytesRead`, `requestedLength` (may differ on EOF) |
| `FileBytesWritten` | `writeFile()` returned               | `fileNodeId`, `fileHandle`, `bytesWritten`                                |

`getFilePosition()` and `setFilePosition()` do not dispatch events
— they're considered low-noise diagnostics. See
[Operations · File Transfer](../operations/file-transfer.md).

## Cache (2)

| Event       | Fires when                          | Key fields                          |
| ----------- | ----------------------------------- | ----------------------------------- |
| `CacheHit`  | A cached value was returned         | `key`, `operation`, `nodeId`        |
| `CacheMiss` | A miss triggered a server round-trip| `key`, `operation`, `nodeId`        |

## Retry (2)

| Event             | Fires when                                       | Key fields                              |
| ----------------- | ------------------------------------------------ | --------------------------------------- |
| `RetryAttempt`    | A retry is about to run                          | `operation`, `attempt`, `exception`     |
| `RetryExhausted`  | The retry budget is exhausted and the call fails | `operation`, `attempts`, `exception`    |

## Trust store (5)

| Event                              | Fires when                                  | Key fields                  |
| ---------------------------------- | ------------------------------------------- | --------------------------- |
| `ServerCertificateTrusted`         | Cert was already in store, accepted         | `fingerprint`               |
| `ServerCertificateAutoAccepted`    | TOFU recorded a new cert                    | `fingerprint`               |
| `ServerCertificateRejected`        | Cert was rejected (validation failed)       | `fingerprint`, `reason`     |
| `ServerCertificateManuallyTrusted` | `trustCertificate()` was called             | `fingerprint`               |
| `ServerCertificateRemoved`         | `untrustCertificate()` was called           | `fingerprint`               |

## Putting them in context

| You want                                 | Listen for                                      |
| ---------------------------------------- | ----------------------------------------------- |
| "Tell me when the connection is up"      | `ClientConnected`                               |
| "Tell me when it breaks"                 | `ConnectionFailed` (raised exception); `ClientDisconnected` covers both clean and broken |
| "Count reads"                            | `NodeValueRead`                                 |
| "Count failed writes"                    | `NodeValueWriteFailed`                          |
| "Page on critical alarms"                | `AlarmActivated` filtered by `severity`         |
| "Measure cache effectiveness"            | `CacheHit` and `CacheMiss`                      |
| "Track retry behaviour"                  | `RetryAttempt`, `RetryExhausted`                |
| "Audit certificate trust decisions"      | The five trust-store events                     |

For wiring patterns, see [Events](./events.md). For events you want to
trigger programmatically (in tests), see [Testing ·
MockClient](../testing/mock-client.md).
