---
eyebrow: 'Docs · Reference'
lede:    'Every method on OpcUaClientInterface, grouped by concern. Use the topical operations pages to learn each call; this page is the alphabetical surface.'

see_also:
  - { href: './builder-api.md',   meta: '6 min' }
  - { href: './exceptions.md',    meta: '7 min' }
  - { href: '../operations/reading-attributes.md', meta: '7 min' }

prev: { label: 'Integration tests', href: '../testing/integration.md' }
next: { label: 'Builder API',       href: './builder-api.md' }
---

# Client API

`PhpOpcua\Client\OpcUaClientInterface` — the public contract every
`Client` (real or mock) implements. Methods are grouped here by
concern; each links to the page that explains it in depth.

<!-- @divider eyebrow="Lifecycle" -->
Methods that observe or transition the connection state.
<!-- @enddivider -->

<!-- @method name="reconnect(): void" returns="void" visibility="public" -->
<!-- @method name="disconnect(): void" returns="void" visibility="public" -->
<!-- @method name="isConnected(): bool" returns="bool" visibility="public" -->
<!-- @method name="getConnectionState(): ConnectionState" returns="ConnectionState" visibility="public" -->

See [Connection · Opening and closing](../connection/opening-and-closing.md).

<!-- @divider eyebrow="Introspection" -->
Discover the methods and modules the client exposes at runtime.
<!-- @enddivider -->

<!-- @method name="hasMethod(string \$name): bool" returns="bool" visibility="public" -->
<!-- @method name="hasModule(string \$moduleClass): bool" returns="bool" visibility="public" -->
<!-- @method name="getRegisteredMethods(): string[]" returns="string[]" visibility="public" -->
<!-- @method name="getLoadedModules(): class-string[]" returns="class-string[]" visibility="public" -->

Used by `ManagedClient` (in `opcua-session-manager`) and by callers
that branch on optional service-set availability. See [Recipes ·
Detecting server capabilities](../recipes/detecting-server-capabilities.md).

<!-- @divider eyebrow="Reading" -->
Single and multi-node attribute reads.
<!-- @enddivider -->

<!-- @method name="read(NodeId|string \$nodeId, int \$attributeId = AttributeId::Value, bool \$refresh = false): DataValue" returns="DataValue" visibility="public" -->
<!-- @method name="readMulti(?array \$readItems = null): array|ReadMultiBuilder" returns="DataValue[] or ReadMultiBuilder" visibility="public" -->

See [Operations · Reading attributes](../operations/reading-attributes.md).

<!-- @divider eyebrow="Writing" -->
Single and multi-node value writes.
<!-- @enddivider -->

<!-- @method name="write(NodeId|string \$nodeId, mixed \$value, ?BuiltinType \$type = null): int" returns="int (StatusCode)" visibility="public" -->
<!-- @method name="writeMulti(?array \$writeItems = null): array|WriteMultiBuilder" returns="int[] or WriteMultiBuilder" visibility="public" -->

See [Operations · Writing values](../operations/writing-values.md).

<!-- @divider eyebrow="Browsing" -->
Address-space navigation.
<!-- @enddivider -->

<!-- @method name="browse(NodeId|string \$nodeId, BrowseDirection \$direction = BrowseDirection::Forward, bool \$includeSubtypes = true, int \$nodeClassMask = 0, bool \$useCache = true): ReferenceDescription[]" returns="ReferenceDescription[]" visibility="public" -->
<!-- @method name="browseAll(NodeId|string \$nodeId, BrowseDirection \$direction = BrowseDirection::Forward, bool \$includeSubtypes = true, int \$nodeClassMask = 0, bool \$useCache = true): ReferenceDescription[]" returns="ReferenceDescription[]" visibility="public" -->
<!-- @method name="browseRecursive(NodeId|string \$nodeId, ?int \$maxDepth = null, int \$nodeClassMask = 0): BrowseNode" returns="BrowseNode" visibility="public" -->
<!-- @method name="browseWithContinuation(NodeId|string \$nodeId, ...): BrowseResultSet" returns="BrowseResultSet" visibility="public" -->
<!-- @method name="browseNext(string \$continuationPoint): BrowseResultSet" returns="BrowseResultSet" visibility="public" -->

See [Operations · Browsing](../operations/browsing.md).

<!-- @divider eyebrow="Path resolution" -->
Translate browse paths to NodeIds.
<!-- @enddivider -->

<!-- @method name="translateBrowsePaths(?array \$browsePaths = null): array|BrowsePathsBuilder" returns="BrowsePathResult[] or BrowsePathsBuilder" visibility="public" -->
<!-- @method name="resolveNodeId(string \$path, NodeId|string|null \$startingNodeId = null, bool \$useCache = true): NodeId" returns="NodeId" visibility="public" -->

See [Operations · Resolving paths](../operations/resolving-paths.md).

<!-- @divider eyebrow="Method calls" -->
Invoke server-side procedures.
<!-- @enddivider -->

<!-- @method name="call(NodeId|string \$objectId, NodeId|string \$methodId, array \$inputArguments = []): CallResult" returns="CallResult" visibility="public" -->

See [Operations · Calling methods](../operations/calling-methods.md).

<!-- @divider eyebrow="Subscriptions" -->
Server-pushed notifications via the publish loop.
<!-- @enddivider -->

<!-- @method name="createSubscription(float \$publishingInterval = 1000.0, int \$lifetimeCount = 2400, int \$maxKeepAliveCount = 10, int \$maxNotificationsPerPublish = 0, int \$priority = 0): SubscriptionResult" returns="SubscriptionResult" visibility="public" -->
<!-- @method name="deleteSubscription(int \$subscriptionId): int" returns="int (StatusCode)" visibility="public" -->
<!-- @method name="publish(array \$acknowledgements = []): PublishResult" returns="PublishResult" visibility="public" -->
<!-- @method name="transferSubscriptions(array \$subscriptionIds, bool \$sendInitialValues = false): TransferResult[]" returns="TransferResult[]" visibility="public" -->
<!-- @method name="republish(int \$subscriptionId, int \$retransmitSequenceNumber): array" returns="array" visibility="public" -->

See [Operations · Subscriptions](../operations/subscriptions.md).

<!-- @divider eyebrow="Monitored items" -->
Per-attribute and per-event observation.
<!-- @enddivider -->

<!-- @method name="createMonitoredItems(int \$subscriptionId, ?array \$itemsToCreate = null): MonitoredItemResult[]|MonitoredItemsBuilder" returns="MonitoredItemResult[] or MonitoredItemsBuilder" visibility="public" -->
<!-- @method name="createEventMonitoredItem(int \$subscriptionId, NodeId|string \$objectId, array \$eventFilter): MonitoredItemResult" returns="MonitoredItemResult" visibility="public" -->
<!-- @method name="modifyMonitoredItems(int \$subscriptionId, array \$itemsToModify): MonitoredItemModifyResult[]" returns="MonitoredItemModifyResult[]" visibility="public" -->
<!-- @method name="deleteMonitoredItems(int \$subscriptionId, array \$monitoredItemIds): int[]" returns="int[]" visibility="public" -->
<!-- @method name="setTriggering(int \$subscriptionId, int \$triggeringItemId, array \$linksToAdd = [], array \$linksToRemove = []): SetTriggeringResult" returns="SetTriggeringResult" visibility="public" -->

See [Operations · Monitored items](../operations/monitored-items.md).

<!-- @divider eyebrow="History" -->
Read stored historical data. Optional service set.
<!-- @enddivider -->

<!-- @method name="historyReadRaw(NodeId|string \$nodeId, DateTimeInterface \$startTime, DateTimeInterface \$endTime, int \$numValuesPerNode = 0, bool \$returnBounds = false): DataValue[]" returns="DataValue[]" visibility="public" -->
<!-- @method name="historyReadProcessed(NodeId|string \$nodeId, DateTimeInterface \$startTime, DateTimeInterface \$endTime, double \$processingInterval, NodeId|string \$aggregateType): DataValue[]" returns="DataValue[]" visibility="public" -->
<!-- @method name="historyReadAtTime(NodeId|string \$nodeId, array \$timestamps, bool \$useSimpleBounds = false): DataValue[]" returns="DataValue[]" visibility="public" -->

See [Operations · History reads](../operations/history-reads.md).

<!-- @divider eyebrow="Node management" -->
Mutate the address space. Optional service set.
<!-- @enddivider -->

<!-- @method name="addNodes(array \$nodesToAdd): AddNodesResult[]" returns="AddNodesResult[]" visibility="public" -->
<!-- @method name="deleteNodes(array \$nodesToDelete): int[]" returns="int[]" visibility="public" -->
<!-- @method name="addReferences(array \$referencesToAdd): int[]" returns="int[]" visibility="public" -->
<!-- @method name="deleteReferences(array \$referencesToDelete): int[]" returns="int[]" visibility="public" -->

See [Operations · Managing nodes](../operations/managing-nodes.md).

<!-- @divider eyebrow="Discovery & server info" -->
Endpoint discovery and well-known server-info shortcuts.
<!-- @enddivider -->

<!-- @method name="getEndpoints(string \$endpointUrl, bool \$useCache = true): EndpointDescription[]" returns="EndpointDescription[]" visibility="public" -->
<!-- @method name="discoverDataTypes(?int \$namespaceIndex = null, bool \$useCache = true): int" returns="int" visibility="public" -->
<!-- @method name="getServerProductName(): ?string" returns="?string" visibility="public" -->
<!-- @method name="getServerManufacturerName(): ?string" returns="?string" visibility="public" -->
<!-- @method name="getServerSoftwareVersion(): ?string" returns="?string" visibility="public" -->
<!-- @method name="getServerBuildNumber(): ?string" returns="?string" visibility="public" -->
<!-- @method name="getServerBuildDate(): ?DateTimeImmutable" returns="?DateTimeImmutable" visibility="public" -->
<!-- @method name="getServerBuildInfo(): BuildInfo" returns="BuildInfo" visibility="public" -->

<!-- @divider eyebrow="Cache" -->
Manual control over the PSR-16 cache.
<!-- @enddivider -->

<!-- @method name="getCache(): ?CacheInterface" returns="?CacheInterface" visibility="public" -->
<!-- @method name="invalidateCache(NodeId|string \$nodeId): void" returns="void" visibility="public" -->
<!-- @method name="flushCache(): void" returns="void" visibility="public" -->

See [Observability · Caching](../observability/caching.md).

<!-- @divider eyebrow="Trust store" -->
Per-client view of the configured trust store.
<!-- @enddivider -->

<!-- @method name="getTrustStore(): ?TrustStoreInterface" returns="?TrustStoreInterface" visibility="public" -->
<!-- @method name="getTrustPolicy(): ?TrustPolicy" returns="?TrustPolicy" visibility="public" -->
<!-- @method name="trustCertificate(string \$certDer): void" returns="void" visibility="public" -->
<!-- @method name="untrustCertificate(string \$fingerprint): void" returns="void" visibility="public" -->

See [Security · Trust store](../security/trust-store.md).

<!-- @divider eyebrow="Configuration accessors" -->
Read-only views of the builder values frozen into this client.
<!-- @enddivider -->

<!-- @method name="getLogger(): LoggerInterface" returns="LoggerInterface" visibility="public" -->
<!-- @method name="getEventDispatcher(): EventDispatcherInterface" returns="EventDispatcherInterface" visibility="public" -->
<!-- @method name="getExtensionObjectRepository(): ExtensionObjectRepository" returns="ExtensionObjectRepository" visibility="public" -->
<!-- @method name="getTimeout(): float" returns="float" visibility="public" -->
<!-- @method name="getAutoRetry(): int" returns="int" visibility="public" -->
<!-- @method name="getBatchSize(): ?int" returns="?int" visibility="public" -->
<!-- @method name="getServerMaxNodesPerRead(): ?int" returns="?int" visibility="public" -->
<!-- @method name="getServerMaxNodesPerWrite(): ?int" returns="?int" visibility="public" -->
<!-- @method name="getDefaultBrowseMaxDepth(): int" returns="int" visibility="public" -->

These accessors are useful in middleware-style code that branches on
the active configuration — testing infrastructure that injects a
client and wants to verify the builder set the right defaults, mostly.

For the *write* side of these (the setters), see [Builder
API](./builder-api.md).
