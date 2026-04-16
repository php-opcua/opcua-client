<?php

declare(strict_types=1);

require_once __DIR__ . '/../Client/ClientTraitsCoverageTest.php';

use PhpOpcua\Client\Event\ServerCertificateAutoAccepted;
use PhpOpcua\Client\Event\ServerCertificateRejected;
use PhpOpcua\Client\Event\ServerCertificateTrusted;
use PhpOpcua\Client\Exception\UntrustedCertificateException;
use PhpOpcua\Client\Security\CertificateManager;
use PhpOpcua\Client\Tests\Unit\Helpers\InMemoryEventDispatcher;
use PhpOpcua\Client\TrustStore\FileTrustStore;
use PhpOpcua\Client\TrustStore\TrustPolicy;

function createTestCertDer(): string
{
    return (new CertificateManager())->generateSelfSignedCertificate()['certDer'];
}

function createTestTrustStore(): FileTrustStore
{
    return new FileTrustStore(sys_get_temp_dir() . '/opcua-trust-trait-test-' . uniqid());
}

function cleanupStore(FileTrustStore $store): void
{
    foreach ([$store->getTrustedDir(), $store->getRejectedDir()] as $dir) {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.der') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
    @rmdir(dirname($store->getTrustedDir()));
}

describe('ManagesTrustStoreTrait on Client', function () {

    it('has null trust store and policy by default', function () {
        $client = createClientWithoutConnect();
        expect($client->getTrustStore())->toBeNull();
        expect($client->getTrustPolicy())->toBeNull();
    });

    it('setTrustStore is fluent on builder', function () {
        $builder = new PhpOpcua\Client\ClientBuilder();
        $store = createTestTrustStore();
        $result = $builder->setTrustStore($store);
        expect($result)->toBe($builder);
        expect($builder->getTrustStore())->toBe($store);
        cleanupStore($store);
    });

    it('setTrustPolicy is fluent on builder', function () {
        $builder = new PhpOpcua\Client\ClientBuilder();
        $result = $builder->setTrustPolicy(TrustPolicy::Fingerprint);
        expect($result)->toBe($builder);
        expect($builder->getTrustPolicy())->toBe(TrustPolicy::Fingerprint);
    });

    it('setTrustPolicy(null) disables validation', function () {
        $builder = new PhpOpcua\Client\ClientBuilder();
        $builder->setTrustPolicy(TrustPolicy::Fingerprint);
        $builder->setTrustPolicy(null);
        expect($builder->getTrustPolicy())->toBeNull();
    });

    it('autoAccept is fluent on builder', function () {
        $builder = new PhpOpcua\Client\ClientBuilder();
        $result = $builder->autoAccept(true);
        expect($result)->toBe($builder);
    });

    it('validateServerCertificate does nothing when trust store is null', function () {
        $client = createClientWithoutConnect();
        $method = new ReflectionMethod($client, 'validateServerCertificate');
        $method->invoke($client);
        expect(true)->toBeTrue();
    });

    it('validateServerCertificate does nothing when trust policy is null', function () {
        $client = createClientWithoutConnect();
        $store = createTestTrustStore();
        setClientProperty($client, 'trustStore', $store);
        $method = new ReflectionMethod($client, 'validateServerCertificate');
        $method->invoke($client);
        expect(true)->toBeTrue();
        cleanupStore($store);
    });

    it('validates trusted cert and dispatches ServerCertificateTrusted event', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $store = createTestTrustStore();
        $cert = createTestCertDer();
        $store->trust($cert);

        $client = createClientWithoutConnect();
        setClientProperty($client, 'trustStore', $store);
        setClientProperty($client, 'trustPolicy', TrustPolicy::Fingerprint);
        setClientProperty($client, 'eventDispatcher', $dispatcher);

        $ref = new ReflectionProperty($client, 'serverCertDer');
        $ref->setValue($client, $cert);

        $method = new ReflectionMethod($client, 'validateServerCertificate');
        $method->invoke($client);

        expect($dispatcher->hasEvent(ServerCertificateTrusted::class))->toBeTrue();
        cleanupStore($store);
    });

    it('throws UntrustedCertificateException for untrusted cert', function () {
        $store = createTestTrustStore();
        $cert = createTestCertDer();

        $client = createClientWithoutConnect();
        setClientProperty($client, 'trustStore', $store);
        setClientProperty($client, 'trustPolicy', TrustPolicy::Fingerprint);

        $ref = new ReflectionProperty($client, 'serverCertDer');
        $ref->setValue($client, $cert);

        $method = new ReflectionMethod($client, 'validateServerCertificate');

        expect(fn () => $method->invoke($client))
            ->toThrow(UntrustedCertificateException::class);

        cleanupStore($store);
    });

    it('dispatches ServerCertificateRejected and rejects cert on untrusted', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $store = createTestTrustStore();
        $cert = createTestCertDer();

        $client = createClientWithoutConnect();
        setClientProperty($client, 'trustStore', $store);
        setClientProperty($client, 'trustPolicy', TrustPolicy::Fingerprint);
        setClientProperty($client, 'eventDispatcher', $dispatcher);

        $ref = new ReflectionProperty($client, 'serverCertDer');
        $ref->setValue($client, $cert);

        $method = new ReflectionMethod($client, 'validateServerCertificate');

        try {
            $method->invoke($client);
        } catch (UntrustedCertificateException) {
        }

        expect($dispatcher->hasEvent(ServerCertificateRejected::class))->toBeTrue();

        $fingerprint = sha1($cert);
        $rejectedPath = $store->getRejectedDir() . DIRECTORY_SEPARATOR . $fingerprint . '.der';
        expect(file_exists($rejectedPath))->toBeTrue();

        cleanupStore($store);
    });

    it('auto-accepts new cert when autoAccept enabled and no certs trusted', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $store = createTestTrustStore();
        $cert = createTestCertDer();

        $client = createClientWithoutConnect();
        setClientProperty($client, 'trustStore', $store);
        setClientProperty($client, 'trustPolicy', TrustPolicy::Fingerprint);
        setClientProperty($client, 'eventDispatcher', $dispatcher);
        setClientProperty($client, 'autoAcceptEnabled', true);

        $ref = new ReflectionProperty($client, 'serverCertDer');
        $ref->setValue($client, $cert);

        $method = new ReflectionMethod($client, 'validateServerCertificate');
        $method->invoke($client);

        expect($store->isTrusted($cert))->toBeTrue();
        expect($dispatcher->hasEvent(ServerCertificateAutoAccepted::class))->toBeTrue();

        cleanupStore($store);
    });

    it('force auto-accept updates changed cert', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $store = createTestTrustStore();
        $oldCert = createTestCertDer();
        $newCert = createTestCertDer();
        $store->trust($oldCert);

        $client = createClientWithoutConnect();
        setClientProperty($client, 'trustStore', $store);
        setClientProperty($client, 'trustPolicy', TrustPolicy::Fingerprint);
        setClientProperty($client, 'eventDispatcher', $dispatcher);
        setClientProperty($client, 'autoAcceptEnabled', true);
        setClientProperty($client, 'autoAcceptForce', true);

        $ref = new ReflectionProperty($client, 'serverCertDer');
        $ref->setValue($client, $newCert);

        $method = new ReflectionMethod($client, 'validateServerCertificate');
        $method->invoke($client);

        expect($store->isTrusted($newCert))->toBeTrue();
        expect($dispatcher->hasEvent(ServerCertificateAutoAccepted::class))->toBeTrue();

        cleanupStore($store);
    });

    it('rejects changed cert when autoAccept without force', function () {
        $store = createTestTrustStore();
        $oldCert = createTestCertDer();
        $newCert = createTestCertDer();
        $store->trust($oldCert);

        $client = createClientWithoutConnect();
        setClientProperty($client, 'trustStore', $store);
        setClientProperty($client, 'trustPolicy', TrustPolicy::Fingerprint);
        setClientProperty($client, 'autoAcceptEnabled', true);

        $ref = new ReflectionProperty($client, 'serverCertDer');
        $ref->setValue($client, $newCert);

        $method = new ReflectionMethod($client, 'validateServerCertificate');

        expect(fn () => $method->invoke($client))
            ->toThrow(UntrustedCertificateException::class);

        cleanupStore($store);
    });

    it('UntrustedCertificateException carries fingerprint and certDer', function () {
        $cert = createTestCertDer();
        $fingerprint = 'aa:bb:cc';
        $ex = new UntrustedCertificateException($fingerprint, $cert, 'Not trusted');

        expect($ex->fingerprint)->toBe('aa:bb:cc');
        expect($ex->certDer)->toBe($cert);
        expect($ex->getMessage())->toBe('Not trusted');
    });

});

describe('Trust Store Event classes', function () {

    it('creates ServerCertificateTrusted', function () {
        $client = PhpOpcua\Client\Testing\MockClient::create();
        $event = new ServerCertificateTrusted($client, 'aa:bb', 'CN=Server');
        expect($event->client)->toBe($client);
        expect($event->fingerprint)->toBe('aa:bb');
        expect($event->subject)->toBe('CN=Server');
    });

    it('creates ServerCertificateRejected', function () {
        $client = PhpOpcua\Client\Testing\MockClient::create();
        $event = new ServerCertificateRejected($client, 'aa:bb', 'Expired', 'CN=Server');
        expect($event->reason)->toBe('Expired');
    });

    it('creates ServerCertificateAutoAccepted', function () {
        $client = PhpOpcua\Client\Testing\MockClient::create();
        $event = new ServerCertificateAutoAccepted($client, 'aa:bb', 'CN=Server');
        expect($event->fingerprint)->toBe('aa:bb');
    });

});

describe('ManagesTrustStoreRuntimeTrait on Client', function () {

    it('trustCertificate does nothing when trust store is null', function () {
        $client = createClientWithoutConnect();
        setClientProperty($client, 'trustStore', null);
        $client->trustCertificate('cert-bytes');
        expect(true)->toBeTrue();
    });

    it('trustCertificate trusts cert and dispatches event', function () {
        $tmpDir = sys_get_temp_dir() . '/opcua-trust-test-' . uniqid();
        $store = new FileTrustStore($tmpDir);
        $events = [];
        $dispatcher = new class($events) implements Psr\EventDispatcher\EventDispatcherInterface {
            public function __construct(private array &$events)
            {
            }

            public function dispatch(object $event): object
            {
                $this->events[] = $event;

                return $event;
            }
        };

        $client = createClientWithoutConnect();
        setClientProperty($client, 'trustStore', $store);
        setClientProperty($client, 'eventDispatcher', $dispatcher);
        $client->trustCertificate('fake-cert-der-bytes');
        expect($events)->not->toBeEmpty();
        expect($events[0])->toBeInstanceOf(PhpOpcua\Client\Event\ServerCertificateManuallyTrusted::class);

        array_map('unlink', glob($tmpDir . '/trusted/*') ?: []);
        @rmdir($tmpDir . DIRECTORY_SEPARATOR . 'trusted');
        @rmdir($tmpDir . DIRECTORY_SEPARATOR . 'rejected');
        @rmdir($tmpDir);
    });

    it('untrustCertificate does nothing when trust store is null', function () {
        $client = createClientWithoutConnect();
        setClientProperty($client, 'trustStore', null);
        $client->untrustCertificate('ab:cd:ef');
        expect(true)->toBeTrue();
    });

    it('untrustCertificate calls untrust and dispatches event', function () {
        $tmpDir = sys_get_temp_dir() . '/opcua-untrust-test-' . uniqid();
        $store = new FileTrustStore($tmpDir);
        $events = [];
        $dispatcher = new class($events) implements Psr\EventDispatcher\EventDispatcherInterface {
            public function __construct(private array &$events)
            {
            }

            public function dispatch(object $event): object
            {
                $this->events[] = $event;

                return $event;
            }
        };

        $client = createClientWithoutConnect();
        setClientProperty($client, 'trustStore', $store);
        setClientProperty($client, 'eventDispatcher', $dispatcher);
        $client->untrustCertificate('ab:cd:ef:01:23');
        expect($events)->not->toBeEmpty();
        expect($events[0])->toBeInstanceOf(PhpOpcua\Client\Event\ServerCertificateRemoved::class);

        @rmdir($tmpDir . DIRECTORY_SEPARATOR . 'trusted');
        @rmdir($tmpDir . DIRECTORY_SEPARATOR . 'rejected');
        @rmdir($tmpDir);
    });
});
