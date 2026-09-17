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

The library dispatches **58** event classes. Every event extends a
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

## Secure channel (3)

| Event                  | Fires when                            | Key fields                                  |
| ---------------------- | ------------------------------------- | ------------------------------------------- |
| `SecureChannelOpened`  | OPN exchange completed                | `channelId`, `securityPolicy` (enum), `securityMode` |
| `SecureChannelClosed`  | CLO sent or socket dropped            | `channelId`                                 |
| `SecureChannelRenewed` | Security token renewed (75% of its lifetime) | `channelId`, `tokenId`, `revisedLifetime` |

## Session (4)

| Event              | Fires when                       | Key fields                  |
| ------------------ | -------------------------------- | --------------------------- |
| `SessionCreated`   | After CreateSession              | `sessionId`, `sessionName`  |
| `SessionActivated` | After ActivateSession            | `sessionId`                 |
| `SessionClosed`    | After CloseSession               | `sessionId`                 |
| `SessionReactivated` | An existing session was reactivated on a new secure channel | `endpointUrl` |

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
| `SubscriptionKeepAlive`  | `publish()` got an empty response           | `subscriptionId`, `sequenceNumber` |
| `SubscriptionTransferred`| `transferSubscriptions()` returned, once per subscription | `subscriptionId`, `statusCode` |

## Monitored items (3)

| Event                   | Fires when                                  | Key fields                                  |
| ----------------------- | ------------------------------------------- | ------------------------------------------- |
| `MonitoredItemCreated`  | `createMonitoredItems()` / `createEventMonitoredItem()` returned, once per item | `subscriptionId`, `monitoredItemId`, `nodeId`, `statusCode` |
| `MonitoredItemModified` | `modifyMonitoredItems()` returned, once per item | `subscriptionId`, `monitoredItemId`, `statusCode` |
| `MonitoredItemDeleted`  | `deleteMonitoredItems()` returned, once per item | `subscriptionId`, `monitoredItemId`, `statusCode` |

The monitored item events fire for every result, including rejected
items: check `statusCode`.

## Publish (3)

| Event                       | Fires when                                  | Key fields                              |
| --------------------------- | ------------------------------------------- | --------------------------------------- |
| `DataChangeReceived`        | `publish()` / `republish()` delivered a data-change notification | `subscriptionId`, `sequenceNumber`, `clientHandle`, `dataValue`, `republished` |
| `EventNotificationReceived` | `publish()` / `republish()` delivered an event notification | `subscriptionId`, `sequenceNumber`, `clientHandle`, `eventFields`, `republished` |
| `PublishResponseReceived`   | Any `publish()` response (including keep-alives) | `subscriptionId`, `sequenceNumber`, `notificationCount`, `moreNotifications` |

`republished` is `true` when the notification came from `republish()`.
A retransmission can repeat a notification `publish()` already
delivered, so check it when a value must not be processed twice.
`PublishResponseReceived` and `SubscriptionKeepAlive` are dispatched by
`publish()` only.

## Triggering (1)

| Event                  | Fires when                              | Key fields                          |
| ---------------------- | --------------------------------------- | ----------------------------------- |
| `TriggeringConfigured` | `setTriggering()` returned              | `subscriptionId`, `triggeringItemId`, `addResults`, `removeResults` |

## Alarms (9)

These are deduced from event notification payloads, in addition to
`EventNotificationReceived`. The deduction reads the fields by position,
so it assumes the default select clauses of `createEventMonitoredItem()`
(`EventId`, `EventType`, `SourceName`, `Time`, `Message`, `Severity`)
followed by any state fields. Every alarm event also carries
`subscriptionId`, `clientHandle` and `republished`.

| Event                      | Fires when                                  | Key fields                                    |
| -------------------------- | ------------------------------------------- | --------------------------------------------- |
| `AlarmEventReceived`       | The event has a `Severity` or an `EventType` | `eventFields`, `severity`, `sourceName`, `message`, `eventType`, `time` |
| `AlarmSeverityChanged`     | An alarm-shaped event carries a `Severity` (every time, not only on change) | `sourceName`, `severity` |
| `LimitAlarmExceeded`       | `EventType` is a LimitAlarmType variant     | `sourceName`, `limitState`, `severity`        |
| `OffNormalAlarmTriggered`  | `EventType` is an OffNormalAlarmType variant | `sourceName`, `severity`                     |
| `AlarmActivated`           | The first state field is `true`, or a string starting with `Active` | `sourceName`, `severity`, `message` |
| `AlarmDeactivated`         | The first state field is `false`, or a string starting with `Inactive` | `sourceName`, `message` |
| `AlarmAcknowledged`        | The first state field is a string containing `Acknowledged` or `Acked` | `sourceName` |
| `AlarmConfirmed`           | The first state field is a string containing `Confirmed` | `sourceName`                    |
| `AlarmShelved`             | The first state field is a string containing `Shelved` | `sourceName`                      |

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
