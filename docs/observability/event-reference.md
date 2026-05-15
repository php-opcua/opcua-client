---
eyebrow: 'Docs · Observability'
lede:    'The full event catalogue — 47 classes grouped by lifecycle. Each event carries a $client reference plus a handful of typed fields.'

see_also:
  - { href: './events.md',          meta: '6 min' }
  - { href: './logging.md',         meta: '5 min' }
  - { href: '../operations/monitored-items.md', meta: '7 min' }

prev: { label: 'Events',   href: './events.md' }
next: { label: 'Caching',  href: './caching.md' }
---

# Event reference

The library dispatches **47** event classes. Every event extends a
common shape with a `$client` property; the per-event fields below are
the additions on top of that.

All event classes live in `PhpOpcua\Client\Event\`.

## Connection lifecycle (6)

| Event                | Fires when                                          | Key fields                          |
| -------------------- | --------------------------------------------------- | ----------------------------------- |
| `ClientConnecting`   | `connect()` starts                                  | `endpoint`                          |
| `ClientConnected`    | Session is activated                                | `endpoint`, `sessionId`             |
| `ClientDisconnecting`| `disconnect()` starts                               | —                                   |
| `ClientDisconnected` | Disconnect completed (clean or broken)              | `reason: 'clean'\|'broken'`         |
| `ClientReconnecting` | `reconnect()` starts                                | `endpoint`                          |
| `ConnectionFailed`   | `connect()` raised                                  | `endpoint`, `exception`             |

## Secure channel (2)

| Event                  | Fires when                            | Key fields                                  |
| ---------------------- | ------------------------------------- | ------------------------------------------- |
| `SecureChannelOpened`  | OPN exchange completed                | `channelId`, `securityPolicyUri`, `securityMode` |
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
| `MonitoredItemCreated`  | `createMonitoredItems()` returned Good      | `subscriptionId`, `monitoredItemId`, `nodeId` |
| `MonitoredItemModified` | `modifyMonitoredItems()` returned Good      | `subscriptionId`, `monitoredItemId`         |
| `MonitoredItemDeleted`  | `deleteMonitoredItems()` returned Good      | `subscriptionId`, `monitoredItemId`         |

## Publish (3)

| Event                       | Fires when                                  | Key fields                              |
| --------------------------- | ------------------------------------------- | --------------------------------------- |
| `DataChangeReceived`        | A data-change notification was delivered    | `subscriptionId`, `monitoredItemId`, `dataValue` |
| `EventNotificationReceived` | An event notification was delivered         | `subscriptionId`, `monitoredItemId`, `event`     |
| `PublishResponseReceived`   | Any publish response (including keep-alives)| `subscriptionId`, `sequenceNumber`, `moreNotifications` |

## Triggering (1)

| Event                  | Fires when                              | Key fields                          |
| ---------------------- | --------------------------------------- | ----------------------------------- |
| `TriggeringConfigured` | `setTriggering()` returned Good         | `subscriptionId`, `triggeringItemId`, `linksAdded`, `linksRemoved` |

## Alarms (9)

These are auto-deduced from event notification payloads — when an
`EventNotificationReceived` event carries alarm-shaped fields, the
library dispatches one of the specific events below in addition.

| Event                      | Fires when                                  | Key fields                                    |
| -------------------------- | ------------------------------------------- | --------------------------------------------- |
| `AlarmEventReceived`       | Any alarm-shaped event                      | `sourceName`, `message`, `severity`, `eventType` |
| `AlarmActivated`           | `ActiveState` → `Active`                    | `sourceName`, `severity`                      |
| `AlarmDeactivated`         | `ActiveState` → `Inactive`                  | `sourceName`                                  |
| `AlarmAcknowledged`        | `AckedState` → acknowledged                 | `sourceName`, `acknowledger`                  |
| `AlarmConfirmed`           | `ConfirmedState` → confirmed                | `sourceName`                                  |
| `AlarmShelved`             | Shelved/Unshelved state transition          | `sourceName`, `shelved`                       |
| `AlarmSeverityChanged`     | Severity changed                            | `sourceName`, `oldSeverity`, `newSeverity`    |
| `LimitAlarmExceeded`       | LimitAlarmType variants tripped a limit     | `sourceName`, `limitName`, `limitValue`       |
| `OffNormalAlarmTriggered`  | OffNormalAlarmType variants tripped         | `sourceName`, `normalState`                   |

## Type discovery (1)

| Event                  | Fires when                                  | Key fields                |
| ---------------------- | ------------------------------------------- | ------------------------- |
| `DataTypesDiscovered`  | `discoverDataTypes()` completed             | `count`, `nodeIds`        |

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
| "Tell me when it breaks"                 | `ClientDisconnected` (`reason: 'broken'`), `ConnectionFailed` |
| "Count reads"                            | `NodeValueRead`                                 |
| "Count failed writes"                    | `NodeValueWriteFailed`                          |
| "Page on critical alarms"                | `AlarmActivated` filtered by `severity`         |
| "Measure cache effectiveness"            | `CacheHit` and `CacheMiss`                      |
| "Track retry behaviour"                  | `RetryAttempt`, `RetryExhausted`                |
| "Audit certificate trust decisions"      | The five trust-store events                     |

For wiring patterns, see [Events](./events.md). For events you want to
trigger programmatically (in tests), see [Testing ·
MockClient](../testing/mock-client.md).
