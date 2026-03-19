<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Tests\Integration\Helpers;

use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\ReferenceDescription;
use RuntimeException;
use Throwable;

final class TestHelper
{
    // ── Endpoint URLs ──────────────────────────────────────────────────
    public const ENDPOINT_NO_SECURITY = 'opc.tcp://localhost:4840/UA/TestServer';
    public const ENDPOINT_USERPASS = 'opc.tcp://localhost:4841/UA/TestServer';
    public const ENDPOINT_CERTIFICATE = 'opc.tcp://localhost:4842/UA/TestServer';
    public const ENDPOINT_ALL_SECURITY = 'opc.tcp://localhost:4843/UA/TestServer';
    public const ENDPOINT_DISCOVERY = 'opc.tcp://localhost:4844';
    public const ENDPOINT_AUTO_ACCEPT = 'opc.tcp://localhost:4845/UA/TestServer';
    public const ENDPOINT_SIGN_ONLY = 'opc.tcp://localhost:4846/UA/TestServer';
    public const ENDPOINT_LEGACY = 'opc.tcp://localhost:4847/UA/TestServer';

    // ── Certificate paths (overridable via OPCUA_CERTS_DIR env var) ────
    public static function getCertsDir(): string
    {
        return getenv('OPCUA_CERTS_DIR') ?: __DIR__ . '/../../../../opcua-test-server-suite/certs';
    }

    public static function getClientCertPath(): string
    {
        return self::getCertsDir() . '/client/cert.pem';
    }

    public static function getClientKeyPath(): string
    {
        return self::getCertsDir() . '/client/key.pem';
    }

    public static function getCaCertPath(): string
    {
        return self::getCertsDir() . '/ca/ca-cert.pem';
    }

    // ── Users ──────────────────────────────────────────────────────────
    public const USER_ADMIN = ['username' => 'admin', 'password' => 'admin123'];
    public const USER_OPERATOR = ['username' => 'operator', 'password' => 'operator123'];
    public const USER_VIEWER = ['username' => 'viewer', 'password' => 'viewer123'];
    public const USER_TEST = ['username' => 'test', 'password' => 'test'];

    // ── Well-known NodeIds (OPC UA standard) ───────────────────────────
    public const NODE_ROOT = [0, 84];    // Root
    public const NODE_OBJECTS = [0, 85];    // Objects
    public const NODE_SERVER = [0, 2253];  // Server
    public const NODE_SERVER_STATUS = [0, 2256];  // Server.ServerStatus
    public const NODE_SERVER_STATE = [0, 2259];  // Server.ServerStatus.State

    // ── Reference type IDs ─────────────────────────────────────────────
    public const REF_HIERARCHICAL = [0, 33];  // HierarchicalReferences
    public const REF_ORGANIZES = [0, 35];  // Organizes
    public const REF_HAS_COMPONENT = [0, 47]; // HasComponent

    /**
     * Create a client connected to the no-security server (anonymous).
     */
    public static function connectNoSecurity(): Client
    {
        $client = new Client();
        $client->connect(self::ENDPOINT_NO_SECURITY);
        return $client;
    }

    /**
     * Create a client connected with username/password credentials.
     */
    public static function connectWithUserPass(
        string         $endpoint,
        string         $username,
        string         $password,
        SecurityPolicy $policy = SecurityPolicy::None,
        SecurityMode   $mode = SecurityMode::None,
    ): Client
    {
        $client = new Client();

        if ($policy !== SecurityPolicy::None) {
            $client->setSecurityPolicy($policy);
            $client->setSecurityMode($mode);
            $client->setClientCertificate(self::getClientCertPath(), self::getClientKeyPath(), self::getCaCertPath());
        }

        $client->setUserCredentials($username, $password);
        $client->connect($endpoint);
        return $client;
    }

    /**
     * Create a client connected with certificate authentication.
     */
    public static function connectWithCertificate(
        string         $endpoint,
        SecurityPolicy $policy = SecurityPolicy::Basic256Sha256,
        SecurityMode   $mode = SecurityMode::SignAndEncrypt,
    ): Client
    {
        $client = new Client();
        $client->setSecurityPolicy($policy);
        $client->setSecurityMode($mode);
        $client->setClientCertificate(self::getClientCertPath(), self::getClientKeyPath(), self::getCaCertPath());
        $client->setUserCertificate(self::getClientCertPath(), self::getClientKeyPath());
        $client->connect($endpoint);
        return $client;
    }

    /**
     * Browse to a node by following a path of browse names starting from the Objects folder.
     *
     * @param Client $client Connected client
     * @param string[] $path Browse names, e.g. ["TestServer", "DataTypes", "Scalars", "BooleanValue"]
     * @return NodeId          The NodeId of the final node in the path
     */
    public static function browseToNode(Client $client, array $path): NodeId
    {
        $currentNodeId = NodeId::numeric(0, 85); // Objects folder

        foreach ($path as $name) {
            $refs = $client->browse($currentNodeId);
            $found = false;

            foreach ($refs as $ref) {
                $browseName = $ref->getBrowseName()->getName();
                $displayName = (string)$ref->getDisplayName();

                if ($browseName === $name || $displayName === $name) {
                    $currentNodeId = $ref->getNodeId();
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $availableNames = array_map(
                    fn(ReferenceDescription $r) => $r->getBrowseName()->getName(),
                    $refs,
                );
                throw new RuntimeException(
                    "Could not find child node '{$name}' under node. "
                    . "Available: " . implode(', ', $availableNames)
                );
            }
        }

        return $currentNodeId;
    }

    /**
     * Find a reference by browse name among browse results.
     *
     * @param ReferenceDescription[] $refs
     */
    public static function findRefByName(array $refs, string $name): ?ReferenceDescription
    {
        foreach ($refs as $ref) {
            if ($ref->getBrowseName()->getName() === $name || (string)$ref->getDisplayName() === $name) {
                return $ref;
            }
        }
        return null;
    }

    /**
     * Safely disconnect a client, suppressing any exceptions.
     */
    public static function safeDisconnect(?Client $client): void
    {
        if ($client === null) {
            return;
        }
        try {
            $client->disconnect();
        } catch (Throwable) {
            // best effort
        }
    }
}
