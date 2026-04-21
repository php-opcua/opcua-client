<?php

declare(strict_types=1);

require_once __DIR__ . '/SecurityTestHelpers.php';

use PhpOpcua\Client\Client;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Protocol\MessageHeader;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Security\SecureChannel;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Transport\TcpTransport;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\ConnectionState;
use PhpOpcua\Client\Types\NodeId;

// ──────────────────────────────────────────────
// Mock transport classes
// ──────────────────────────────────────────────

if (! class_exists('MockTransport')) {
    class MockTransport extends TcpTransport
    {
        private array $responses = [];

        private int $index = 0;

        public array $sent = [];

        public function addResponse(string $data): void
        {
            $this->responses[] = $data;
        }

        public function connect(string $host, int $port, null|float $timeout = null): void
        {
        }

        public function send(string $data): void
        {
            $this->sent[] = $data;
        }

        public function receive(): string
        {
            if ($this->index >= count($this->responses)) {
                throw new ConnectionException('No more mock responses');
            }

            return $this->responses[$this->index++];
        }

        public function close(): void
        {
        }

        public function isConnected(): bool
        {
            return true;
        }
    }
}

if (! class_exists('FailingMockTransport')) {
    class FailingMockTransport extends TcpTransport
    {
        private int $sendCount = 0;

        private int $receiveCount = 0;

        private int $failAfterSends;

        private int $failAfterReceives;

        public function __construct(int $failAfterSends = 0, int $failAfterReceives = 0)
        {
            $this->failAfterSends = $failAfterSends;
            $this->failAfterReceives = $failAfterReceives;
        }

        public function connect(string $host, int $port, null|float $timeout = null): void
        {
        }

        public function send(string $data): void
        {
            $this->sendCount++;
            if ($this->failAfterSends > 0 && $this->sendCount > $this->failAfterSends) {
                throw new ConnectionException('Mock send failure');
            }
        }

        public function receive(): string
        {
            $this->receiveCount++;
            if ($this->failAfterReceives > 0 && $this->receiveCount > $this->failAfterReceives) {
                throw new ConnectionException('Mock receive failure');
            }
            throw new ConnectionException('Mock receive failure');
        }

        public function close(): void
        {
        }

        public function isConnected(): bool
        {
            return true;
        }
    }
}

if (! class_exists('SecureMockTransport')) {
    /**
     * A MockTransport that captures the OPN request to build a valid encrypted response.
     * Also provides CreateSession, ActivateSession, and readMulti responses.
     */
    class SecureMockTransport extends TcpTransport
    {
        private array $responses = [];

        private int $index = 0;

        public array $sent = [];

        private string $clientDer;

        private OpenSSLAsymmetricKey $clientKey;

        private string $serverDer;

        private OpenSSLAsymmetricKey $serverKey;

        private SecurityPolicy $policy;

        public function __construct(
            string $clientDer,
            OpenSSLAsymmetricKey $clientKey,
            string $serverDer,
            OpenSSLAsymmetricKey $serverKey,
            SecurityPolicy $policy,
        ) {
            $this->clientDer = $clientDer;
            $this->clientKey = $clientKey;
            $this->serverDer = $serverDer;
            $this->serverKey = $serverKey;
            $this->policy = $policy;

            // Pre-build the non-OPN responses
            $this->responses[] = buildAckResponse();
            // OPN response will be built dynamically on receive
            $this->responses[] = 'OPN_PLACEHOLDER';
            $this->responses[] = buildCreateSessionResponse();
            $this->responses[] = buildActivateSessionResponse();
            $this->responses[] = buildDiscoverLimitsResponse();
        }

        public function connect(string $host, int $port, null|float $timeout = null): void
        {
        }

        public function send(string $data): void
        {
            $this->sent[] = $data;

            // If we're about to serve the OPN response, build it from the sent OPN request
            if (count($this->sent) === 2) {
                // The 2nd sent message is the OPN request. Parse clientNonce from the SecureChannel.
                // We need to build a valid encrypted OPN response.
                // Create a temporary SecureChannel to process the request and get clientNonce.
                $tempChannel = new SecureChannel(
                    $this->policy,
                    SecurityMode::SignAndEncrypt,
                    $this->serverDer,
                    $this->serverKey,
                    $this->clientDer,
                );
                $tempChannel->createOpenSecureChannelMessage();
                $clientNonce = $tempChannel->getClientNonce();

                // Actually, we can't easily extract clientNonce from encrypted data.
                // Instead, build a response using the helper that encrypts with clientDer (client will decrypt with clientKey).
                $serverNonce = random_bytes(32);
                $this->responses[1] = buildTestOPNResponse(
                    $this->serverDer,
                    $this->serverKey,
                    $this->clientDer,
                    $this->clientKey,
                    random_bytes(32), // We don't know the actual clientNonce, but this is for key derivation
                    $serverNonce,
                    100,
                    200,
                    $this->policy,
                );
            }
        }

        public function receive(): string
        {
            if ($this->index >= count($this->responses)) {
                throw new ConnectionException('No more mock responses');
            }

            return $this->responses[$this->index++];
        }

        public function close(): void
        {
        }

        public function isConnected(): bool
        {
            return true;
        }
    }
}

// ──────────────────────────────────────────────
// Reflection helpers
// ──────────────────────────────────────────────

if (! function_exists('setClientProperty')) {
    function setClientProperty(Client $client, string $name, mixed $value): void
    {
        $ref = new ReflectionProperty(Client::class, $name);
        $ref->setValue($client, $value);
    }
}

if (! function_exists('callClientMethod')) {
    function callClientMethod(Client $client, string $name, array $args = []): mixed
    {
        $ref = new ReflectionMethod($client, $name);

        return $ref->invokeArgs($client, $args);
    }
}

// ──────────────────────────────────────────────
// Message builder helpers
// ──────────────────────────────────────────────

if (! function_exists('buildMsgResponse')) {
    function buildMsgResponse(int $typeId, Closure $writeBody): string
    {
        $e = new BinaryEncoder();
        (new MessageHeader('MSG', 'F', 0))->encode($e);
        $e->writeUInt32(1);
        $e->writeUInt32(1);
        $e->writeUInt32(1);
        $e->writeUInt32(1);
        $e->writeNodeId(NodeId::numeric(0, $typeId));
        $e->writeInt64(0);
        $e->writeUInt32(1);
        $e->writeUInt32(0);
        $e->writeByte(0);
        $e->writeInt32(0);
        $e->writeNodeId(NodeId::numeric(0, 0));
        $e->writeByte(0);
        $writeBody($e);
        $d = $e->getBuffer();

        return substr($d, 0, 4) . pack('V', strlen($d)) . substr($d, 8);
    }
}

if (! function_exists('buildErrMsg')) {
    function buildErrMsg(int $code = 0x80010000, string $reason = 'Server error'): string
    {
        $e = new BinaryEncoder();
        (new MessageHeader('ERR', 'F', 0))->encode($e);
        $e->writeUInt32($code);
        $e->writeString($reason);
        $d = $e->getBuffer();

        return substr($d, 0, 4) . pack('V', strlen($d)) . substr($d, 8);
    }
}

if (! function_exists('readResponseMsg')) {
    function readResponseMsg(int $value = 42): string
    {
        return buildMsgResponse(634, function (BinaryEncoder $e) use ($value) {
            $e->writeInt32(1);
            $e->writeByte(0x01);
            $e->writeByte(BuiltinType::Int32->value);
            $e->writeInt32($value);
            $e->writeInt32(0);
        });
    }
}

if (! function_exists('browseResponseMsg')) {
    function browseResponseMsg(): string
    {
        return buildMsgResponse(530, function (BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeUInt32(0);
            $e->writeByteString(null);
            $e->writeInt32(1);
            $e->writeNodeId(NodeId::numeric(0, 35));
            $e->writeBoolean(true);
            $e->writeExpandedNodeId(NodeId::numeric(0, 2253));
            $e->writeUInt16(0);
            $e->writeString('Server');
            $e->writeByte(0x02);
            $e->writeString('Server');
            $e->writeUInt32(1);
            $e->writeExpandedNodeId(NodeId::numeric(0, 2004));
            $e->writeInt32(0);
        });
    }
}

if (! function_exists('browseResponseWithContinuationMsg')) {
    function browseResponseWithContinuationMsg(): string
    {
        return buildMsgResponse(530, function (BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeUInt32(0);
            $e->writeByteString('cont-point');
            $e->writeInt32(1);
            $e->writeNodeId(NodeId::numeric(0, 35));
            $e->writeBoolean(true);
            $e->writeExpandedNodeId(NodeId::numeric(0, 2253));
            $e->writeUInt16(0);
            $e->writeString('Server');
            $e->writeByte(0x02);
            $e->writeString('Server');
            $e->writeUInt32(1);
            $e->writeExpandedNodeId(NodeId::numeric(0, 2004));
            $e->writeInt32(0);
        });
    }
}

if (! function_exists('browseNextResponseMsg')) {
    function browseNextResponseMsg(): string
    {
        return buildMsgResponse(536, function (BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeUInt32(0);
            $e->writeByteString(null);
            $e->writeInt32(1);
            $e->writeNodeId(NodeId::numeric(0, 35));
            $e->writeBoolean(true);
            $e->writeExpandedNodeId(NodeId::numeric(0, 2254));
            $e->writeUInt16(0);
            $e->writeString('ServerArray');
            $e->writeByte(0x02);
            $e->writeString('ServerArray');
            $e->writeUInt32(2);
            $e->writeExpandedNodeId(NodeId::numeric(0, 68));
            $e->writeInt32(0);
        });
    }
}

// ──────────────────────────────────────────────
// Connection / handshake response builders
// ──────────────────────────────────────────────

if (! function_exists('buildAckResponse')) {
    function buildAckResponse(): string
    {
        $e = new BinaryEncoder();
        $e->writeRawBytes('ACKF');
        $e->writeUInt32(28);
        $e->writeUInt32(0);
        $e->writeUInt32(65535);
        $e->writeUInt32(65535);
        $e->writeUInt32(0);
        $e->writeUInt32(0);

        return $e->getBuffer();
    }
}

if (! function_exists('buildOpnResponse')) {
    function buildOpnResponse(int $channelId = 1, int $tokenId = 1): string
    {
        $e = new BinaryEncoder();
        $header = new MessageHeader('OPN', 'F', 0);
        $header->encode($e);
        $e->writeUInt32($channelId);
        // Security header
        $e->writeString(SecurityPolicy::None->value);
        $e->writeByteString(null);
        $e->writeByteString(null);
        // Sequence header
        $e->writeUInt32(1);
        $e->writeUInt32(1);
        // TypeId
        $e->writeNodeId(NodeId::numeric(0, 449));
        // ResponseHeader
        $e->writeInt64(0);
        $e->writeUInt32(1);
        $e->writeUInt32(0);
        $e->writeByte(0);
        $e->writeInt32(0);
        $e->writeNodeId(NodeId::numeric(0, 0));
        $e->writeByte(0);
        // OPN fields
        $e->writeUInt32(0);
        $e->writeUInt32($channelId);
        $e->writeUInt32($tokenId);
        $e->writeInt64(0);
        $e->writeUInt32(3600000);
        $e->writeByteString(null);

        $d = $e->getBuffer();

        return substr($d, 0, 4) . pack('V', strlen($d)) . substr($d, 8);
    }
}

// ──────────────────────────────────────────────
// Session response builders
// ──────────────────────────────────────────────

if (! function_exists('buildCreateSessionResponse')) {
    function buildCreateSessionResponse(): string
    {
        return buildMsgResponse(464, function (BinaryEncoder $e) {
            $e->writeNodeId(NodeId::numeric(0, 1)); // sessionId
            $e->writeNodeId(NodeId::numeric(0, 2)); // authToken
            $e->writeDouble(120000.0);
            $e->writeByteString('server-nonce');
            $e->writeByteString(null); // serverCert
            $e->writeInt32(0); // endpoints
            $e->writeInt32(0); // certs
            $e->writeString(null);
            $e->writeByteString(null);
            $e->writeUInt32(0); // maxRequestSize
        });
    }
}

if (! function_exists('buildActivateSessionResponse')) {
    function buildActivateSessionResponse(): string
    {
        return buildMsgResponse(470, function (BinaryEncoder $e) {
            $e->writeByteString(null);
            $e->writeInt32(0);
            $e->writeInt32(0);
        });
    }
}

if (! function_exists('buildDiscoverLimitsResponse')) {
    function buildDiscoverLimitsResponse(): string
    {
        return buildMsgResponse(634, function (BinaryEncoder $e) {
            $e->writeInt32(2);
            $e->writeByte(0x01);
            $e->writeByte(BuiltinType::UInt32->value);
            $e->writeUInt32(0); // MaxNodesPerRead = 0 (unknown)
            $e->writeByte(0x01);
            $e->writeByte(BuiltinType::UInt32->value);
            $e->writeUInt32(0); // MaxNodesPerWrite = 0 (unknown)
            $e->writeInt32(0);
        });
    }
}

if (! function_exists('buildCreateSessionResponseWithCert')) {
    function buildCreateSessionResponseWithCert(string $serverCertDer): string
    {
        return buildMsgResponse(464, function (BinaryEncoder $e) use ($serverCertDer) {
            $e->writeNodeId(NodeId::numeric(0, 1));
            $e->writeNodeId(NodeId::numeric(0, 2));
            $e->writeDouble(120000.0);
            $e->writeByteString('server-nonce');
            $e->writeByteString($serverCertDer);
            $e->writeInt32(0);
            $e->writeInt32(0);
            $e->writeString(null);
            $e->writeByteString(null);
            $e->writeUInt32(0);
        });
    }
}

if (! function_exists('buildCreateSessionResponseWithEccAdditionalHeader')) {
    function buildCreateSessionResponseWithEccAdditionalHeader(): string
    {
        // Build a CreateSession response where the response header contains
        // an AdditionalHeader with ECDHKey
        $e = new BinaryEncoder();
        (new MessageHeader('MSG', 'F', 0))->encode($e);
        $e->writeUInt32(1); // channelId
        // Security header (token)
        $e->writeUInt32(1);
        // Sequence header
        $e->writeUInt32(1);
        $e->writeUInt32(1);
        // TypeId: CreateSessionResponse (464)
        $e->writeNodeId(NodeId::numeric(0, 464));
        // ResponseHeader
        $e->writeInt64(0);
        $e->writeUInt32(1);
        $e->writeUInt32(0); // statusCode
        $e->writeByte(0);   // diagInfo
        $e->writeInt32(0);  // stringTable

        // AdditionalHeader with ECDHKey
        $e->writeNodeId(NodeId::numeric(0, 17537));
        $e->writeByte(0x01);

        $additionalBody = new BinaryEncoder();
        $additionalBody->writeInt32(1);
        $additionalBody->writeUInt16(0);
        $additionalBody->writeString('ECDHKey');
        $additionalBody->writeByte(22); // ExtensionObject variant
        $additionalBody->writeNodeId(NodeId::numeric(0, 17546));
        $additionalBody->writeByte(0x01);
        $extBody = new BinaryEncoder();
        $extBody->writeByteString('fake-ecc-public-key-data');
        $extBody->writeByteString('fake-signature-data');
        $extBodyBytes = $extBody->getBuffer();
        $additionalBody->writeInt32(strlen($extBodyBytes));
        $additionalBody->writeRawBytes($extBodyBytes);
        $additionalBodyBytes = $additionalBody->getBuffer();
        $e->writeInt32(strlen($additionalBodyBytes));
        $e->writeRawBytes($additionalBodyBytes);

        // CreateSession body
        $e->writeNodeId(NodeId::numeric(0, 1));  // sessionId
        $e->writeNodeId(NodeId::numeric(0, 2));  // authToken
        $e->writeDouble(120000.0);
        $e->writeByteString('server-nonce');
        $e->writeByteString(null);
        $e->writeInt32(0); // endpoints
        $e->writeInt32(0); // certs
        $e->writeString(null);
        $e->writeByteString(null);
        $e->writeUInt32(0);

        $d = $e->getBuffer();

        return substr($d, 0, 4) . pack('V', strlen($d)) . substr($d, 8);
    }
}

// ──────────────────────────────────────────────
// Subscription / monitored item response builders
// ──────────────────────────────────────────────

if (! function_exists('createMonitoredItemsResponseMsg')) {
    function createMonitoredItemsResponseMsg(int $count = 1): string
    {
        return buildMsgResponse(754, function (BinaryEncoder $e) use ($count) {
            $e->writeInt32($count);
            for ($i = 0; $i < $count; $i++) {
                $e->writeUInt32(0);       // StatusCode
                $e->writeUInt32(100 + $i); // MonitoredItemId
                $e->writeDouble(500.0);   // RevisedSamplingInterval
                $e->writeUInt32(1);       // RevisedQueueSize
                $e->writeNodeId(NodeId::numeric(0, 0));
                $e->writeByte(0x00);      // FilterResult
            }
            $e->writeInt32(0); // DiagnosticInfos
        });
    }
}

if (! function_exists('deleteMonitoredItemsResponseMsg')) {
    function deleteMonitoredItemsResponseMsg(int $count = 1): string
    {
        return buildMsgResponse(784, function (BinaryEncoder $e) use ($count) {
            $e->writeInt32($count);
            for ($i = 0; $i < $count; $i++) {
                $e->writeUInt32(0);
            }
            $e->writeInt32(0);
        });
    }
}

if (! function_exists('modifyMonitoredItemsResponseMsg')) {
    function modifyMonitoredItemsResponseMsg(int $count = 1): string
    {
        return buildMsgResponse(769, function (BinaryEncoder $e) use ($count) {
            $e->writeInt32($count);
            for ($i = 0; $i < $count; $i++) {
                $e->writeUInt32(0);       // StatusCode
                $e->writeDouble(250.0);   // RevisedSamplingInterval
                $e->writeUInt32(5);       // RevisedQueueSize
                $e->writeNodeId(NodeId::numeric(0, 0));
                $e->writeByte(0x00);
            }
            $e->writeInt32(0);
        });
    }
}

if (! function_exists('setTriggeringResponseMsg')) {
    function setTriggeringResponseMsg(int $addCount = 1, int $removeCount = 0): string
    {
        return buildMsgResponse(778, function (BinaryEncoder $e) use ($addCount, $removeCount) {
            $e->writeInt32($addCount);
            for ($i = 0; $i < $addCount; $i++) {
                $e->writeUInt32(0);
            }
            $e->writeInt32(0); // add diagnostics
            if ($removeCount > 0) {
                $e->writeInt32($removeCount);
                for ($i = 0; $i < $removeCount; $i++) {
                    $e->writeUInt32(0);
                }
                $e->writeInt32(0); // remove diagnostics
            }
        });
    }
}

if (! function_exists('deleteSubscriptionsResponseMsg')) {
    function deleteSubscriptionsResponseMsg(): string
    {
        return buildMsgResponse(850, function (BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeUInt32(0);
            $e->writeInt32(0);
        });
    }
}

// ──────────────────────────────────────────────
// Client factory helpers
// ──────────────────────────────────────────────

if (! function_exists('createClientWithoutConnect')) {
    function createClientWithoutConnect(): Client
    {
        $ref = new ReflectionClass(Client::class);
        $client = $ref->newInstanceWithoutConstructor();

        setClientProperty($client, 'connectionState', ConnectionState::Disconnected);
        setClientProperty($client, 'securityPolicy', SecurityPolicy::None);
        setClientProperty($client, 'securityMode', SecurityMode::None);
        setClientProperty($client, 'clientCertPath', null);
        setClientProperty($client, 'clientKeyPath', null);
        setClientProperty($client, 'caCertPath', null);
        setClientProperty($client, 'username', null);
        setClientProperty($client, 'password', null);
        setClientProperty($client, 'userCertPath', null);
        setClientProperty($client, 'userKeyPath', null);
        setClientProperty($client, 'logger', new Psr\Log\NullLogger());
        setClientProperty($client, 'eventDispatcher', new PhpOpcua\Client\Event\NullEventDispatcher());
        setClientProperty($client, 'trustStore', null);
        setClientProperty($client, 'trustPolicy', null);
        setClientProperty($client, 'autoAcceptEnabled', false);
        setClientProperty($client, 'autoAcceptForce', false);
        setClientProperty($client, 'cache', null);
        setClientProperty($client, 'cacheInitialized', false);
        $codecRegistry = new PhpOpcua\Client\Wire\WireTypeRegistry();
        PhpOpcua\Client\Wire\CoreWireTypes::registerForCache($codecRegistry);
        setClientProperty($client, 'cacheCodec', new PhpOpcua\Client\Cache\WireCacheCodec($codecRegistry));
        setClientProperty($client, 'timeout', 5.0);
        setClientProperty($client, 'autoRetry', null);
        setClientProperty($client, 'batchSize', null);
        setClientProperty($client, 'serverMaxNodesPerRead', null);
        setClientProperty($client, 'serverMaxNodesPerWrite', null);
        setClientProperty($client, 'defaultBrowseMaxDepth', 10);
        setClientProperty($client, 'autoDetectWriteType', true);
        setClientProperty($client, 'readMetadataCache', false);
        setClientProperty($client, 'extensionObjectRepository', new PhpOpcua\Client\Repository\ExtensionObjectRepository());
        setClientProperty($client, 'enumMappings', []);
        setClientProperty($client, 'transport', new TcpTransport());
        setClientProperty($client, 'session', null);
        $moduleRegistry = new PhpOpcua\Client\Module\ModuleRegistry();
        $moduleRegistry->add(new PhpOpcua\Client\Module\ReadWrite\ReadWriteModule());
        $moduleRegistry->add(new PhpOpcua\Client\Module\Browse\BrowseModule());
        $moduleRegistry->add(new PhpOpcua\Client\Module\Subscription\SubscriptionModule());
        $moduleRegistry->add(new PhpOpcua\Client\Module\History\HistoryModule());
        $moduleRegistry->add(new PhpOpcua\Client\Module\NodeManagement\NodeManagementModule());
        $moduleRegistry->add(new PhpOpcua\Client\Module\TranslateBrowsePath\TranslateBrowsePathModule());
        $moduleRegistry->add(new PhpOpcua\Client\Module\ServerInfo\ServerInfoModule());
        $moduleRegistry->add(new PhpOpcua\Client\Module\TypeDiscovery\TypeDiscoveryModule());
        setClientProperty($client, 'moduleRegistry', $moduleRegistry);
        setClientProperty($client, 'methodHandlers', []);
        setClientProperty($client, 'methodOwners', []);
        setClientProperty($client, 'currentModuleClass', '');
        setClientProperty($client, 'authenticationToken', null);
        setClientProperty($client, 'secureChannelId', 0);
        setClientProperty($client, 'requestId', 10);
        setClientProperty($client, 'serverCertDer', null);
        setClientProperty($client, 'secureChannel', null);
        setClientProperty($client, 'serverNonce', null);
        setClientProperty($client, 'eccServerEphemeralKey', null);
        setClientProperty($client, 'usernamePolicyId', null);
        setClientProperty($client, 'certificatePolicyId', null);
        setClientProperty($client, 'anonymousPolicyId', null);
        setClientProperty($client, 'lastEndpointUrl', null);

        return $client;
    }
}

if (! function_exists('registerClientModules')) {
    function registerClientModules(Client $client): void
    {
        $ref = new ReflectionProperty(Client::class, 'moduleRegistry');
        $moduleRegistry = $ref->getValue($client);

        foreach ($moduleRegistry->getModuleClasses() as $moduleClass) {
            $module = $moduleRegistry->get($moduleClass);
            $module->setKernel($client);
            $module->setClient($client);
            $client->setCurrentModuleClass($moduleClass);
            $module->register();
        }
    }
}

if (! function_exists('bootClientModules')) {
    function bootClientModules(Client $client, SessionService $session): void
    {
        $ref = new ReflectionProperty(Client::class, 'moduleRegistry');
        $moduleRegistry = $ref->getValue($client);

        foreach ($moduleRegistry->getModuleClasses() as $moduleClass) {
            $moduleRegistry->get($moduleClass)->boot($session);
        }
    }
}

if (! function_exists('setupConnectedClient')) {
    function setupConnectedClient(MockTransport $mock): Client
    {
        $client = createClientWithoutConnect();
        $session = new SessionService(1, 1);

        setClientProperty($client, 'transport', $mock);
        setClientProperty($client, 'connectionState', ConnectionState::Connected);
        setClientProperty($client, 'session', $session);
        setClientProperty($client, 'authenticationToken', NodeId::numeric(0, 2));
        setClientProperty($client, 'secureChannelId', 1);
        setClientProperty($client, 'lastEndpointUrl', 'opc.tcp://mock:4840');
        registerClientModules($client);
        bootClientModules($client, $session);

        return $client;
    }
}

if (! function_exists('makeConnectedClient')) {
    function makeConnectedClient(TcpTransport $transport, ?SecureChannel $sc = null): Client
    {
        $client = createClientWithoutConnect();
        $session = new SessionService(1, 1, $sc);

        setClientProperty($client, 'transport', $transport);
        setClientProperty($client, 'connectionState', ConnectionState::Connected);
        setClientProperty($client, 'session', $session);
        setClientProperty($client, 'authenticationToken', NodeId::numeric(0, 2));
        setClientProperty($client, 'secureChannelId', 1);
        setClientProperty($client, 'lastEndpointUrl', 'opc.tcp://mock:4840');
        if ($sc !== null) {
            setClientProperty($client, 'secureChannel', $sc);
        }
        registerClientModules($client);
        bootClientModules($client, $session);

        return $client;
    }
}

// ──────────────────────────────────────────────
// Temporary file helpers
// ──────────────────────────────────────────────

if (! isset($GLOBALS['_tempFiles'])) {
    $GLOBALS['_tempFiles'] = [];
}

$_tempFiles = &$GLOBALS['_tempFiles'];

if (! function_exists('writeTmpFile')) {
    function writeTmpFile(string $content): string
    {
        global $_tempFiles;
        $path = tempnam(sys_get_temp_dir(), 'opcua_test_');
        file_put_contents($path, $content);
        $_tempFiles[] = $path;

        return $path;
    }
}

if (! function_exists('cleanupTmpFiles')) {
    function cleanupTmpFiles(): void
    {
        global $_tempFiles;
        foreach ($_tempFiles as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        $_tempFiles = [];
    }
}
