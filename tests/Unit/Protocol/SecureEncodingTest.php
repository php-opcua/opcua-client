<?php

declare(strict_types=1);

use PhpOpcua\Client\Module\Browse\BrowseService;
use PhpOpcua\Client\Module\Browse\GetEndpointsService;
use PhpOpcua\Client\Module\History\HistoryReadService;
use PhpOpcua\Client\Module\ReadWrite\CallService;
use PhpOpcua\Client\Module\ReadWrite\ReadService;
use PhpOpcua\Client\Module\ReadWrite\WriteService;
use PhpOpcua\Client\Module\Subscription\MonitoredItemService;
use PhpOpcua\Client\Module\Subscription\PublishService;
use PhpOpcua\Client\Module\Subscription\SubscriptionService;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Security\CertificateManager;
use PhpOpcua\Client\Security\SecureChannel;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\Variant;

/**
 * Creates a SessionService with an active SecureChannel (Sign mode, no encryption needed).
 * The SecureChannel needs symmetric keys to build messages, so we set them up via OPN exchange.
 */
function createSecureSession(): SessionService
{
    $privKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['CN' => 'test'], $privKey);
    $cert = openssl_csr_sign($csr, null, $privKey, 365);
    openssl_x509_export($cert, $certPem);

    $cm = new CertificateManager();
    $tmpFile = tempnam(sys_get_temp_dir(), 'opcua_se_cert_');
    file_put_contents($tmpFile, $certPem);
    $clientDer = $cm->loadCertificatePem($tmpFile);
    unlink($tmpFile);

    // Use same cert for both client and server (self-signed test)
    $sc = new SecureChannel(
        SecurityPolicy::Basic256Sha256,
        SecurityMode::Sign,
        $clientDer,
        $privKey,
        $clientDer,
    );

    // Generate OPN to get clientNonce
    $sc->createOpenSecureChannelMessage();

    // Build and process an OPN response to derive symmetric keys
    $serverNonce = random_bytes(32);
    $response = buildEncryptedOPNResponse(
        $clientDer,
        $privKey,   // server cert/key (same as client for test)
        $clientDer,
        $privKey,   // client cert/key
        $sc->getClientNonce(),
        $serverNonce,
        500,
        600,
        SecurityPolicy::Basic256Sha256,
    );
    $sc->processOpenSecureChannelResponse($response);

    return new SessionService(0, 0, $sc);
}

describe('Secure encoding: SubscriptionService', function () {

    it('encodes CreateSubscription via secure channel', function () {
        $session = createSecureSession();
        $service = new SubscriptionService($session);

        $bytes = $service->encodeCreateSubscriptionRequest(1, NodeId::numeric(0, 0));
        // Sign mode: MSG header is present, body is plaintext + HMAC signature
        expect(substr($bytes, 0, 3))->toBe('MSG');
        expect(strlen($bytes))->toBeGreaterThan(50);
    });

    it('encodes ModifySubscription via secure channel', function () {
        $session = createSecureSession();
        $service = new SubscriptionService($session);

        $bytes = $service->encodeModifySubscriptionRequest(1, NodeId::numeric(0, 0), 42);
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });

    it('encodes DeleteSubscriptions via secure channel', function () {
        $session = createSecureSession();
        $service = new SubscriptionService($session);

        $bytes = $service->encodeDeleteSubscriptionsRequest(1, NodeId::numeric(0, 0), [10, 20]);
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });

    it('encodes SetPublishingMode via secure channel', function () {
        $session = createSecureSession();
        $service = new SubscriptionService($session);

        $bytes = $service->encodeSetPublishingModeRequest(1, NodeId::numeric(0, 0), true, [42]);
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });
});

describe('Secure encoding: ReadService', function () {

    it('encodes ReadMulti via secure channel', function () {
        $session = createSecureSession();
        $service = new ReadService($session);

        $bytes = $service->encodeReadMultiRequest(1, [
            ['nodeId' => NodeId::numeric(1, 100)],
        ], NodeId::numeric(0, 0));
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });
});

describe('Secure encoding: BrowseService', function () {

    it('encodes Browse via secure channel', function () {
        $session = createSecureSession();
        $service = new BrowseService($session);

        $bytes = $service->encodeBrowseRequest(1, NodeId::numeric(0, 85), NodeId::numeric(0, 0));
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });

    it('encodes BrowseNext via secure channel', function () {
        $session = createSecureSession();
        $service = new BrowseService($session);

        $bytes = $service->encodeBrowseNextRequest(1, 'cont-point', NodeId::numeric(0, 0));
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });
});

describe('Secure encoding: CallService', function () {

    it('encodes Call via secure channel', function () {
        $session = createSecureSession();
        $service = new CallService($session);

        $bytes = $service->encodeCallRequest(
            1,
            NodeId::numeric(0, 2253),
            NodeId::numeric(0, 11492),
            [new Variant(BuiltinType::String, 'test')],
            NodeId::numeric(0, 0),
        );
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });
});

describe('Secure encoding: MonitoredItemService', function () {

    it('encodes CreateMonitoredItems via secure channel', function () {
        $session = createSecureSession();
        $service = new MonitoredItemService($session);

        $bytes = $service->encodeCreateMonitoredItemsRequest(
            1,
            NodeId::numeric(0, 0),
            42,
            [['nodeId' => NodeId::numeric(1, 100)]],
        );
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });

    it('encodes CreateEventMonitoredItem via secure channel', function () {
        $session = createSecureSession();
        $service = new MonitoredItemService($session);

        $bytes = $service->encodeCreateEventMonitoredItemRequest(
            1,
            NodeId::numeric(0, 0),
            42,
            NodeId::numeric(0, 2253),
            ['EventId', 'EventType'],
            5,
        );
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });

    it('encodes DeleteMonitoredItems via secure channel', function () {
        $session = createSecureSession();
        $service = new MonitoredItemService($session);

        $bytes = $service->encodeDeleteMonitoredItemsRequest(1, NodeId::numeric(0, 0), 42, [1, 2]);
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });
});

describe('Secure encoding: HistoryReadService', function () {

    it('encodes HistoryReadRaw via secure channel', function () {
        $session = createSecureSession();
        $service = new HistoryReadService($session);

        $bytes = $service->encodeHistoryReadRawRequest(
            1,
            NodeId::numeric(0, 0),
            NodeId::numeric(1, 100),
            new DateTimeImmutable('2024-01-01'),
            new DateTimeImmutable('2024-01-02'),
            100,
            true,
        );
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });

    it('encodes HistoryReadProcessed via secure channel', function () {
        $session = createSecureSession();
        $service = new HistoryReadService($session);

        $bytes = $service->encodeHistoryReadProcessedRequest(
            1,
            NodeId::numeric(0, 0),
            NodeId::numeric(1, 100),
            new DateTimeImmutable('2024-01-01'),
            new DateTimeImmutable('2024-01-02'),
            500.0,
            NodeId::numeric(0, 2341),
        );
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });

    it('encodes HistoryReadAtTime via secure channel', function () {
        $session = createSecureSession();
        $service = new HistoryReadService($session);

        $bytes = $service->encodeHistoryReadAtTimeRequest(
            1,
            NodeId::numeric(0, 0),
            NodeId::numeric(1, 100),
            [new DateTimeImmutable('2024-01-01 12:00:00')],
        );
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });
});

describe('Secure encoding: PublishService', function () {

    it('encodes Publish via secure channel', function () {
        $session = createSecureSession();
        $service = new PublishService($session);

        $bytes = $service->encodePublishRequest(1, NodeId::numeric(0, 0));
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });

    it('encodes Publish with acks via secure channel', function () {
        $session = createSecureSession();
        $service = new PublishService($session);

        $bytes = $service->encodePublishRequest(1, NodeId::numeric(0, 0), [
            ['subscriptionId' => 42, 'sequenceNumber' => 1],
        ]);
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });
});

describe('Secure encoding: WriteService', function () {

    it('encodes Write via secure channel', function () {
        $session = createSecureSession();
        $service = new WriteService($session);

        $bytes = $service->encodeWriteRequest(
            1,
            NodeId::numeric(1, 100),
            new DataValue(new Variant(BuiltinType::Int32, 42)),
            NodeId::numeric(0, 0),
        );
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });
});

describe('Secure encoding: GetEndpointsService', function () {

    it('encodes GetEndpoints via secure channel', function () {
        $session = createSecureSession();
        $service = new GetEndpointsService($session);

        $bytes = $service->encodeGetEndpointsRequest(1, 'opc.tcp://localhost:4840', NodeId::numeric(0, 0));
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });
});

describe('Secure encoding: SessionService', function () {

    it('encodes CreateSession via secure channel', function () {
        $session = createSecureSession();

        $bytes = $session->encodeCreateSessionRequest(1, 'opc.tcp://localhost:4840');
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });
});
