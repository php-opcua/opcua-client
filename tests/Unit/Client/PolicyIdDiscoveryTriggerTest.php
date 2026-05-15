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

    it('picks the UserName policy whose securityPolicyUri matches the SecureChannel policy', function () {
        [$host, $port, $pid] = discRunServer([
            discAck(),
            discOpnResponse(),
            discEndpointsResponse([
                [
                    'url' => 'opc.tcp://localhost:4840',
                    'cert' => null,
                    'securityMode' => 3,
                    'securityPolicy' => SecurityPolicy::Basic256Sha256->value,
                    'tokens' => [
                        ['id' => 'Anonymous', 'type' => 0],
                        ['id' => 'UserName_Basic128Rsa15_Token', 'type' => 1, 'policy' => SecurityPolicy::Basic128Rsa15->value],
                        ['id' => 'UserName_Basic256_Token', 'type' => 1, 'policy' => SecurityPolicy::Basic256->value],
                        ['id' => 'UserName_Basic256Sha256_Token', 'type' => 1, 'policy' => SecurityPolicy::Basic256Sha256->value],
                        ['id' => 'UserName_Aes128Sha256RsaOaep_Token', 'type' => 1, 'policy' => SecurityPolicy::Aes128Sha256RsaOaep->value],
                        ['id' => 'UserName_Aes256Sha256RsaPss_Token', 'type' => 1, 'policy' => SecurityPolicy::Aes256Sha256RsaPss->value],
                    ],
                ],
            ]),
        ]);

        $client = createClientWithoutConnect();
        setClientProperty($client, 'transport', new MockTransport());
        setClientProperty($client, 'securityPolicy', SecurityPolicy::Basic256Sha256);
        setClientProperty($client, 'securityMode', PhpOpcua\Client\Security\SecurityMode::SignAndEncrypt);
        setClientProperty($client, 'anonymousPolicyId', null);
        setClientProperty($client, 'usernamePolicyId', null);
        setClientProperty($client, 'certificatePolicyId', null);

        try {
            callClientMethod($client, 'performConnect', ["opc.tcp://$host:$port"]);
        } catch (Throwable) {
        }

        pcntl_waitpid($pid, $status);

        $usernameRef = new ReflectionProperty($client, 'usernamePolicyId');
        expect($usernameRef->getValue($client))->toBe('UserName_Basic256Sha256_Token');

        $anonRef = new ReflectionProperty($client, 'anonymousPolicyId');
        expect($anonRef->getValue($client))->toBe('Anonymous');
    });

    it('falls back to the strongest supported UserName policy when channel policy has no exact match', function () {
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
                        ['id' => 'UserName_Basic128Rsa15_Token', 'type' => 1, 'policy' => SecurityPolicy::Basic128Rsa15->value],
                        ['id' => 'UserName_Aes256Sha256RsaPss_Token', 'type' => 1, 'policy' => SecurityPolicy::Aes256Sha256RsaPss->value],
                        ['id' => 'UserName_Basic256Sha256_Token', 'type' => 1, 'policy' => SecurityPolicy::Basic256Sha256->value],
                        ['id' => 'UserName_Basic256_Token', 'type' => 1, 'policy' => SecurityPolicy::Basic256->value],
                    ],
                ],
            ]),
        ]);

        $client = createClientWithoutConnect();
        setClientProperty($client, 'transport', new MockTransport());
        setClientProperty($client, 'usernamePolicyId', null);
        setClientProperty($client, 'certificatePolicyId', null);
        setClientProperty($client, 'anonymousPolicyId', null);

        try {
            callClientMethod($client, 'performConnect', ["opc.tcp://$host:$port"]);
        } catch (Throwable) {
        }

        pcntl_waitpid($pid, $status);

        $ref = new ReflectionProperty($client, 'usernamePolicyId');
        expect($ref->getValue($client))->toBe('UserName_Basic256Sha256_Token');
    });

    it('prefers a UserName policy with empty securityPolicyUri (use channel policy) over heuristic fallback', function () {
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
                        ['id' => 'UserName_Basic128Rsa15_Token', 'type' => 1, 'policy' => SecurityPolicy::Basic128Rsa15->value],
                        ['id' => 'UserName_ChannelPolicy', 'type' => 1, 'policy' => null],
                        ['id' => 'UserName_Basic256Sha256_Token', 'type' => 1, 'policy' => SecurityPolicy::Basic256Sha256->value],
                    ],
                ],
            ]),
        ]);

        $client = createClientWithoutConnect();
        setClientProperty($client, 'transport', new MockTransport());
        setClientProperty($client, 'usernamePolicyId', null);
        setClientProperty($client, 'certificatePolicyId', null);
        setClientProperty($client, 'anonymousPolicyId', null);

        try {
            callClientMethod($client, 'performConnect', ["opc.tcp://$host:$port"]);
        } catch (Throwable) {
        }

        pcntl_waitpid($pid, $status);

        $ref = new ReflectionProperty($client, 'usernamePolicyId');
        expect($ref->getValue($client))->toBe('UserName_ChannelPolicy');
    });
})
    ->skipOnWindows();
