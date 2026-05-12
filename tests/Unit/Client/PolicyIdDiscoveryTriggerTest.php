<?php

declare(strict_types=1);

require_once __DIR__ . '/../Helpers/ClientTestHelpers.php';
require_once __DIR__ . '/ClientDiscoveryCoverageTest.php';

use PhpOpcua\Client\Security\SecurityPolicy;

describe('discovery trigger covers all identity-token policyIds', function () {

    it('triggers discovery when usernamePolicyId is null even if anonymousPolicyId is populated', function () {
        [$host, $port, $pid] = discRunServer([
            discAck(),
            discOpnResponse(),
            discEndpointsResponse([
                [
                    'url' => 'opc.tcp://localhost:4840',
                    'cert' => null,
                    'securityMode' => 1,
                    'securityPolicy' => SecurityPolicy::None->value,
                    'tokens' => [
                        ['id' => 'anonymous', 'type' => 0],
                        ['id' => 'server-specific-username', 'type' => 1],
                    ],
                ],
            ]),
        ]);

        $client = createClientWithoutConnect();
        setClientProperty($client, 'transport', new MockTransport());
        setClientProperty($client, 'anonymousPolicyId', 'anonymous');
        setClientProperty($client, 'usernamePolicyId', null);
        setClientProperty($client, 'certificatePolicyId', null);

        try {
            callClientMethod($client, 'performConnect', ["opc.tcp://$host:$port"]);
        } catch (Throwable) {
            // Discovery completes against the forked TCP server. The regular connect
            // flow then runs against the no-op MockTransport and fails on receive —
            // expected and irrelevant to what this test asserts.
        }

        pcntl_waitpid($pid, $status);

        $ref = new ReflectionProperty($client, 'usernamePolicyId');
        expect($ref->getValue($client))->toBe('server-specific-username');
    });

    it('triggers discovery when certificatePolicyId is null even if anonymousPolicyId is populated', function () {
        [$host, $port, $pid] = discRunServer([
            discAck(),
            discOpnResponse(),
            discEndpointsResponse([
                [
                    'url' => 'opc.tcp://localhost:4840',
                    'cert' => null,
                    'securityMode' => 1,
                    'securityPolicy' => SecurityPolicy::None->value,
                    'tokens' => [
                        ['id' => 'anonymous', 'type' => 0],
                        ['id' => 'server-specific-certificate', 'type' => 2],
                    ],
                ],
            ]),
        ]);

        $client = createClientWithoutConnect();
        setClientProperty($client, 'transport', new MockTransport());
        setClientProperty($client, 'anonymousPolicyId', 'anonymous');
        setClientProperty($client, 'usernamePolicyId', null);
        setClientProperty($client, 'certificatePolicyId', null);

        try {
            callClientMethod($client, 'performConnect', ["opc.tcp://$host:$port"]);
        } catch (Throwable) {
        }

        pcntl_waitpid($pid, $status);

        $ref = new ReflectionProperty($client, 'certificatePolicyId');
        expect($ref->getValue($client))->toBe('server-specific-certificate');
    });

    it('uses the policyId advertised by the server, not the hardcoded default, for UserName auth', function () {
        $advertisedPolicyId = 'open62541-username-policy-none#Basic256Sha256';

        [$host, $port, $pid] = discRunServer([
            discAck(),
            discOpnResponse(),
            discEndpointsResponse([
                [
                    'url' => 'opc.tcp://localhost:4840',
                    'cert' => null,
                    'securityMode' => 1,
                    'securityPolicy' => SecurityPolicy::None->value,
                    'tokens' => [
                        ['id' => $advertisedPolicyId, 'type' => 1],
                    ],
                ],
            ]),
        ]);

        $client = createClientWithoutConnect();
        setClientProperty($client, 'transport', new MockTransport());
        setClientProperty($client, 'anonymousPolicyId', 'anonymous');
        setClientProperty($client, 'usernamePolicyId', null);
        setClientProperty($client, 'certificatePolicyId', null);

        try {
            callClientMethod($client, 'performConnect', ["opc.tcp://$host:$port"]);
        } catch (Throwable) {
        }

        pcntl_waitpid($pid, $status);

        $ref = new ReflectionProperty($client, 'usernamePolicyId');
        expect($ref->getValue($client))->toBe($advertisedPolicyId);
        expect($ref->getValue($client))->not->toBe('username');
    });
})
    ->skipOnWindows();
