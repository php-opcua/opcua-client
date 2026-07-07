<?php

declare(strict_types=1);

require_once __DIR__ . '/../Helpers/ClientTestHelpers.php';

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Encoding\ExtensionObjectCodec;
use PhpOpcua\Client\Exception\EncodingException;
use PhpOpcua\Client\Exception\SecurityException;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Exception\UntrustedCertificateException;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Repository\ExtensionObjectRepository;
use PhpOpcua\Client\Security\CertificateManager;
use PhpOpcua\Client\Security\MessageSecurity;
use PhpOpcua\Client\Security\SecureChannel;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\TrustStore\FileTrustStore;
use PhpOpcua\Client\Types\EndpointDescription;
use PhpOpcua\Client\Types\NodeId;

function shfMakeSecureChannel(array $client, array $server): SecureChannel
{
    $channel = new SecureChannel(
        SecurityPolicy::Basic256Sha256,
        SecurityMode::SignAndEncrypt,
        $client['certDer'],
        $client['privateKey'],
        $server['certDer'],
    );

    return $channel;
}

function shfVerifyServerSignature(SecureChannel $channel, ?string $clientNonce, ?string $serverCertDer, ?string $signature): void
{
    $session = new SessionService(1, 1, $channel);

    $ref = new ReflectionClass($session);
    if ($clientNonce !== null) {
        $nonceProp = $ref->getProperty('lastClientNonce');
        $nonceProp->setValue($session, $clientNonce);
    }

    $method = $ref->getMethod('verifyServerSignature');
    $method->invoke($session, $serverCertDer, $signature);
}

describe('SessionService::verifyServerSignature (FIX 1)', function () {

    it('accepts a valid server signature over clientCert || clientNonce', function () {
        $cm = new CertificateManager();
        $client = $cm->generateSelfSignedCertificate('urn:test:client');
        $server = $cm->generateSelfSignedCertificate('urn:test:server');
        $channel = shfMakeSecureChannel($client, $server);

        $clientNonce = random_bytes(32);
        $messageSecurity = new MessageSecurity($cm);
        $signature = $messageSecurity->asymmetricSign(
            $client['certDer'] . $clientNonce,
            $server['privateKey'],
            SecurityPolicy::Basic256Sha256,
        );

        shfVerifyServerSignature($channel, $clientNonce, $server['certDer'], $signature);
        expect(true)->toBeTrue();
    });

    it('rejects a signature over the wrong nonce', function () {
        $cm = new CertificateManager();
        $client = $cm->generateSelfSignedCertificate('urn:test:client');
        $server = $cm->generateSelfSignedCertificate('urn:test:server');
        $channel = shfMakeSecureChannel($client, $server);

        $messageSecurity = new MessageSecurity($cm);
        $signature = $messageSecurity->asymmetricSign(
            $client['certDer'] . random_bytes(32),
            $server['privateKey'],
            SecurityPolicy::Basic256Sha256,
        );

        shfVerifyServerSignature($channel, random_bytes(32), $server['certDer'], $signature);
    })->throws(ServiceException::class, 'server signature verification failed');

    it('rejects a signature produced by a different key (MITM)', function () {
        $cm = new CertificateManager();
        $client = $cm->generateSelfSignedCertificate('urn:test:client');
        $server = $cm->generateSelfSignedCertificate('urn:test:server');
        $attacker = $cm->generateSelfSignedCertificate('urn:test:attacker');
        $channel = shfMakeSecureChannel($client, $server);

        $clientNonce = random_bytes(32);
        $messageSecurity = new MessageSecurity($cm);
        $signature = $messageSecurity->asymmetricSign(
            $client['certDer'] . $clientNonce,
            $attacker['privateKey'],
            SecurityPolicy::Basic256Sha256,
        );

        shfVerifyServerSignature($channel, $clientNonce, $server['certDer'], $signature);
    })->throws(ServiceException::class, 'server signature verification failed');

    it('rejects a missing server signature when security is active', function () {
        $cm = new CertificateManager();
        $client = $cm->generateSelfSignedCertificate('urn:test:client');
        $server = $cm->generateSelfSignedCertificate('urn:test:server');
        $channel = shfMakeSecureChannel($client, $server);

        shfVerifyServerSignature($channel, random_bytes(32), $server['certDer'], null);
    })->throws(ServiceException::class, 'missing the server signature');

    it('rejects an empty server signature when security is active', function () {
        $cm = new CertificateManager();
        $client = $cm->generateSelfSignedCertificate('urn:test:client');
        $server = $cm->generateSelfSignedCertificate('urn:test:server');
        $channel = shfMakeSecureChannel($client, $server);

        shfVerifyServerSignature($channel, random_bytes(32), $server['certDer'], '');
    })->throws(ServiceException::class, 'missing the server signature');

    it('rejects a missing server certificate when security is active', function () {
        $cm = new CertificateManager();
        $client = $cm->generateSelfSignedCertificate('urn:test:client');
        $server = $cm->generateSelfSignedCertificate('urn:test:server');
        $channel = shfMakeSecureChannel($client, $server);

        shfVerifyServerSignature($channel, random_bytes(32), null, 'some-signature');
    })->throws(ServiceException::class, 'missing the server signature');

    it('fails closed when the client nonce is unavailable', function () {
        $cm = new CertificateManager();
        $client = $cm->generateSelfSignedCertificate('urn:test:client');
        $server = $cm->generateSelfSignedCertificate('urn:test:server');
        $channel = shfMakeSecureChannel($client, $server);

        shfVerifyServerSignature($channel, null, $server['certDer'], 'some-signature');
    })->throws(ServiceException::class, 'client certificate or nonce unavailable');

    it('fails closed on ECDH ephemeral key when the server certificate is unavailable', function () {
        $cm = new CertificateManager();
        $client = $cm->generateSelfSignedCertificate('urn:test:client');
        $channel = new SecureChannel(
            SecurityPolicy::Basic256Sha256,
            SecurityMode::SignAndEncrypt,
            $client['certDer'],
            $client['privateKey'],
            null,
        );
        $session = new SessionService(1, 1, $channel);

        $ref = new ReflectionClass($session);
        $method = $ref->getMethod('verifyEccEphemeralKeySignature');
        $method->invoke($session, 'ephemeral-public-key', 'signature');
    })->throws(ServiceException::class, 'server certificate unavailable');
});

function shfValidateAppUri(string $certDer, ?string $expectedUri, bool $verify = true): void
{
    $client = createClientWithoutConnect();
    setClientProperty($client, 'serverCertDer', $certDer);
    setClientProperty($client, 'expectedServerApplicationUri', $expectedUri);
    setClientProperty($client, 'verifyApplicationUri', $verify);

    $ref = new ReflectionClass($client);
    $method = $ref->getMethod('validateServerApplicationUri');
    $method->invoke($client);
}

describe('Client::validateServerApplicationUri (FIX 2)', function () {

    it('accepts a certificate whose SAN URI matches the endpoint', function () {
        $cm = new CertificateManager();
        $server = $cm->generateSelfSignedCertificate('urn:test:server');

        shfValidateAppUri($server['certDer'], 'urn:test:server');
        expect(true)->toBeTrue();
    });

    it('rejects a certificate whose SAN URI does not match the endpoint', function () {
        $cm = new CertificateManager();
        $server = $cm->generateSelfSignedCertificate('urn:test:server-B');

        shfValidateAppUri($server['certDer'], 'urn:test:server-A');
    })->throws(UntrustedCertificateException::class, 'does not match endpoint');

    it('skips the check when disabled via verifyApplicationUri(false)', function () {
        $cm = new CertificateManager();
        $server = $cm->generateSelfSignedCertificate('urn:test:server-B');

        shfValidateAppUri($server['certDer'], 'urn:test:server-A', verify: false);
        expect(true)->toBeTrue();
    });

    it('skips the check when the endpoint declared no ApplicationUri', function () {
        $cm = new CertificateManager();
        $server = $cm->generateSelfSignedCertificate('urn:test:server');

        shfValidateAppUri($server['certDer'], null);
        expect(true)->toBeTrue();
    });
});

describe('EndpointDescription applicationUri (FIX 2)', function () {

    it('round-trips applicationUri through the wire format', function () {
        $ep = new EndpointDescription('url', null, 1, 'policy', [], 'transport', 0, 'urn:test:server');

        $restored = EndpointDescription::fromWireArray($ep->jsonSerialize());
        expect($restored->applicationUri)->toBe('urn:test:server');
    });

    it('defaults applicationUri to null', function () {
        $ep = new EndpointDescription('url', null, 1, 'policy', [], 'transport', 0);
        expect($ep->applicationUri)->toBeNull();
    });
});

describe('FileTrustStore SHA-256 content check (M1)', function () {

    it('does not trust a certificate when the stored file content differs', function () {
        $dir = sys_get_temp_dir() . '/opcua-trust-sha256-' . uniqid();
        $store = new FileTrustStore($dir);

        try {
            $cm = new CertificateManager();
            $cert = $cm->generateSelfSignedCertificate('urn:test:m1')['certDer'];

            $store->trust($cert);
            expect($store->isTrusted($cert))->toBeTrue();

            $fingerprint = strtolower(sha1($cert));
            file_put_contents($store->getTrustedDir() . DIRECTORY_SEPARATOR . $fingerprint . '.der', 'not-the-same-der');

            expect($store->isTrusted($cert))->toBeFalse();
        } finally {
            array_map('unlink', glob($store->getTrustedDir() . '/*') ?: []);
            array_map('unlink', glob($store->getRejectedDir() . '/*') ?: []);
            @rmdir($store->getTrustedDir());
            @rmdir($store->getRejectedDir());
            @rmdir($dir);
        }
    });
});

function shfSequenceCheck(SecureChannel $channel, int $sequenceNumber): void
{
    $ref = new ReflectionClass($channel);
    $method = $ref->getMethod('validateIncomingSequenceNumber');
    $method->invoke($channel, pack('V', $sequenceNumber) . pack('V', 1));
}

describe('SecureChannel incoming message validation (M2)', function () {

    it('rejects a MSG with a mismatched secure channel id', function () {
        $channel = new SecureChannel(SecurityPolicy::Basic256Sha256, SecurityMode::Sign);
        $ref = new ReflectionClass($channel);
        $ref->getProperty('secureChannelId')->setValue($channel, 42);
        $ref->getProperty('tokenId')->setValue($channel, 7);

        $msg = 'MSGF' . pack('V', 24) . pack('V', 99) . pack('V', 7) . 'rest';
        $channel->processMessage($msg);
    })->throws(SecurityException::class, 'secure channel id mismatch');

    it('rejects a MSG with a mismatched token id', function () {
        $channel = new SecureChannel(SecurityPolicy::Basic256Sha256, SecurityMode::Sign);
        $ref = new ReflectionClass($channel);
        $ref->getProperty('secureChannelId')->setValue($channel, 42);
        $ref->getProperty('tokenId')->setValue($channel, 7);

        $msg = 'MSGF' . pack('V', 24) . pack('V', 42) . pack('V', 8) . 'rest';
        $channel->processMessage($msg);
    })->throws(SecurityException::class, 'token id mismatch');

    it('accepts strictly increasing sequence numbers', function () {
        $channel = new SecureChannel(SecurityPolicy::Basic256Sha256, SecurityMode::Sign);
        shfSequenceCheck($channel, 1);
        shfSequenceCheck($channel, 2);
        shfSequenceCheck($channel, 100);
        expect(true)->toBeTrue();
    });

    it('rejects a replayed (equal) sequence number', function () {
        $channel = new SecureChannel(SecurityPolicy::Basic256Sha256, SecurityMode::Sign);
        shfSequenceCheck($channel, 5);
        shfSequenceCheck($channel, 5);
    })->throws(SecurityException::class, 'sequence number not increasing');

    it('rejects a lower sequence number', function () {
        $channel = new SecureChannel(SecurityPolicy::Basic256Sha256, SecurityMode::Sign);
        shfSequenceCheck($channel, 10);
        shfSequenceCheck($channel, 3);
    })->throws(SecurityException::class, 'sequence number not increasing');

    it('allows the documented wrap-around below 1024', function () {
        $channel = new SecureChannel(SecurityPolicy::Basic256Sha256, SecurityMode::Sign);
        shfSequenceCheck($channel, 0xFFFFFBFF);
        shfSequenceCheck($channel, 5);
        expect(true)->toBeTrue();
    });

    it('rejects a verified plaintext too short to contain a sequence header', function () {
        $channel = new SecureChannel(SecurityPolicy::Basic256Sha256, SecurityMode::Sign);

        $ref = new ReflectionClass($channel);
        $method = $ref->getMethod('validateIncomingSequenceNumber');
        $method->invoke($channel, "\x01\x02\x03");
    })->throws(SecurityException::class, 'too short to contain a sequence header');

    it('restarts the sequence counter on a new channel instance', function () {
        $old = new SecureChannel(SecurityPolicy::Basic256Sha256, SecurityMode::Sign);
        shfSequenceCheck($old, 500000);

        $fresh = new SecureChannel(SecurityPolicy::Basic256Sha256, SecurityMode::Sign);
        shfSequenceCheck($fresh, 1);
        expect(true)->toBeTrue();
    });
});

describe('BinaryDecoder readExtensionObject over-consumption guard (M4)', function () {

    it('throws EncodingException when a codec consumes more than the declared body length', function () {
        $repo = new ExtensionObjectRepository();
        $repo->register(NodeId::numeric(2, 99), new class() implements ExtensionObjectCodec {
            public function decode(BinaryDecoder $decoder): array
            {
                return ['a' => $decoder->readUInt32(), 'b' => $decoder->readUInt32()];
            }

            public function encode(BinaryEncoder $encoder, mixed $value): void
            {
            }
        });

        $encoder = new BinaryEncoder();
        $encoder->writeNodeId(NodeId::numeric(2, 99));
        $encoder->writeByte(0x01);
        $encoder->writeInt32(4);
        $encoder->writeUInt32(0xAABBCCDD);
        $encoder->writeUInt32(0x11223344);

        $decoder = new BinaryDecoder($encoder->getBuffer(), $repo);
        $decoder->readExtensionObject();
    })->throws(EncodingException::class, 'consumed');
});
