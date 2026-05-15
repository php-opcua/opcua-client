<?php

declare(strict_types=1);

/**
 * OPC UA Connection Diagnostic Tool
 * ----------------------------------
 *
 * Runs a sequence of connection scenarios against an OPC UA server and produces
 * a structured report. Designed to isolate the root cause of connection issues
 * such as BadIdentityTokenInvalid by comparing what the server advertises in
 * GetEndpoints against what the client actually uses.
 *
 * Usage:
 *   php scripts/diagnose.php
 *
 * Examples:
 *
 *   Linux / macOS (bash, zsh):
 *     OPCUA_DISCOVERY_URL='opc.tcp://192.168.1.10:4840' \
 *     OPCUA_SESSION_URL='opc.tcp://192.168.1.10:4840' \
 *     OPCUA_USERNAME='admin' \
 *     OPCUA_PASSWORD='admin123' \
 *     php scripts/diagnose.php
 *
 *   Windows (PowerShell):
 *     $env:OPCUA_DISCOVERY_URL='opc.tcp://192.168.1.10:4840'
 *     $env:OPCUA_SESSION_URL='opc.tcp://192.168.1.10:4840'
 *     $env:OPCUA_USERNAME='admin'
 *     $env:OPCUA_PASSWORD='admin123'
 *     php scripts\diagnose.php
 *
 *   Windows (cmd.exe):
 *     set OPCUA_DISCOVERY_URL=opc.tcp://192.168.1.10:4840
 *     set OPCUA_SESSION_URL=opc.tcp://192.168.1.10:4840
 *     set OPCUA_USERNAME=admin
 *     set OPCUA_PASSWORD=admin123
 *     php scripts\diagnose.php
 *
 * Configuration is read from constants below, with optional environment overrides:
 *   OPCUA_DISCOVERY_URL  — endpoint used for unsecured GetEndpoints
 *   OPCUA_SESSION_URL    — endpoint used for the connection scenarios
 *   OPCUA_USERNAME       — username for UserName/Password scenarios
 *   OPCUA_PASSWORD       — password for UserName/Password scenarios
 *   OPCUA_LOG_FILE       — path to the structured log file
 *
 * Exit codes:
 *   0  — all scenarios passed, no diagnosis flagged
 *   1  — discovery failed (cannot even enumerate endpoints)
 *   2  — one or more connection scenarios failed
 *   3  — diagnosis identified a UserName policyId mismatch
 *   4  — invalid configuration
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\OpcUaException;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Types\EndpointDescription;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

//Configuration

const DEFAULT_DISCOVERY_URL = 'opc.tcp://YOUR_PLC_IP:4840';
const DEFAULT_SESSION_URL = 'opc.tcp://YOUR_PLC_IP:4840';
const DEFAULT_USERNAME = 'admin';
const DEFAULT_PASSWORD = 'admin123';
const DEFAULT_LOG_FILE = __DIR__ . '/opcua-debug.log';

const CLIENT_HARDCODED_USERNAME_POLICY_ID = 'username';
const CLIENT_HARDCODED_ANONYMOUS_POLICY_ID = 'anonymous';

const STATUS_BAD_IDENTITY_TOKEN_INVALID = 0x80200000;
const TOKEN_TYPE_ANONYMOUS = 0;
const TOKEN_TYPE_USERNAME = 1;
const TOKEN_TYPE_CERTIFICATE = 2;
const TOKEN_TYPE_ISSUED = 3;

const EXIT_OK = 0;
const EXIT_DISCOVERY_FAILED = 1;
const EXIT_SCENARIO_FAILED = 2;
const EXIT_DIAGNOSIS_MISMATCH = 3;
const EXIT_INVALID_CONFIG = 4;

$discoveryUrl = getenv('OPCUA_DISCOVERY_URL') ?: DEFAULT_DISCOVERY_URL;
$sessionUrl = getenv('OPCUA_SESSION_URL') ?: DEFAULT_SESSION_URL;
$username = getenv('OPCUA_USERNAME') ?: DEFAULT_USERNAME;
$password = getenv('OPCUA_PASSWORD') ?: DEFAULT_PASSWORD;
$logFile = getenv('OPCUA_LOG_FILE') ?: DEFAULT_LOG_FILE;

if (str_contains($discoveryUrl, 'YOUR_PLC_IP') || str_contains($sessionUrl, 'YOUR_PLC_IP')) {
    fwrite(STDERR, "ERROR: configure endpoint URLs (constants or OPCUA_*_URL env vars)\n");
    exit(EXIT_INVALID_CONFIG);
}


/**
 * Masks URLs (any scheme://host[:port][/path]), bare host:port pairs, and absolute
 * filesystem paths that would leak OS username or workspace layout in a string.
 */
function redactSensitive(string $value): string
{
    static $projectRoot = null;

    if ($projectRoot === null) {
        $projectRoot = dirname(__DIR__);
    }

    if ($projectRoot !== '' && $projectRoot !== '/') {
        $value = str_replace($projectRoot, '<project>', $value);
    }

    $value = (string)preg_replace('~/(home|Users)/[^/\s"\'<>]+~', '/$1/<user>', $value);

    $value = (string)preg_replace_callback(
        '~\b([a-z][a-z0-9+\-.]*)://([^/:?#\s"\'<>}\]]+)([^\s"\'<>}\]]*)?~i',
        static fn(array $m): string => $m[1] . '://***' . ($m[3] ?? ''),
        $value,
    );

    return (string)preg_replace_callback(
        '~(?<![:/.\w])(?:(?:[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?\.)+[a-z][a-z0-9\-]*|[a-z][a-z0-9\-]*|\d{1,3}(?:\.\d{1,3}){3}):(\d{1,5})(?!\d)~i',
        static fn(array $m): string => '***:' . $m[1],
        $value,
    );
}

/**
 * Masks the configured username and password verbatim wherever they appear.
 * Used on stack traces and error messages before printing.
 *
 * Username uses a word-boundary regex so a short username like "User" does
 * not corrupt unrelated tokens such as "UserName_Basic256Sha256_Token".
 * Password uses a literal replacement to tolerate special characters.
 */
function redactCredentials(string $value, string $username, string $password): string
{
    if (strlen($password) >= 3) {
        $value = str_replace($password, '<password>', $value);
    }
    if (strlen($username) >= 3) {
        $value = preg_replace('/\b' . preg_quote($username, '/') . '\b/', '<username>', $value) ?? $value;
    }

    return $value;
}

/**
 * Full redaction for report output: credentials first, then URLs/hosts.
 */
function redactForReport(string $value, string $username, string $password): string
{
    return redactSensitive(redactCredentials($value, $username, $password));
}

$logger = new class ($logFile, $username, $password) extends AbstractLogger {
    /** @var resource */
    private $file;

    public function __construct(string $logPath, private readonly string $username, private readonly string $password)
    {
        $handle = fopen($logPath, 'ab');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Cannot open log file: %s', $logPath));
        }

        $this->file = $handle;
    }

    /**
     * @param mixed $level
     * @param string|Stringable $message
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $timestamp = (new DateTimeImmutable())->format('Y-m-d H:i:s.u');
        $redacted = $this->redact($context);
        $interpolated = $this->sanitizeString($this->interpolate((string)$message, $redacted));
        $contextJson = $redacted === [] ? '' : ' ' . json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        $line = sprintf('[%s] opcua.%s: %s%s%s', $timestamp, strtoupper((string)$level), $interpolated, $contextJson, PHP_EOL);

        fwrite(STDOUT, $line);
        fwrite($this->file, $line);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = $this->redact($value);
                continue;
            }

            if (in_array($key, ['session_id', 'token', 'authToken', 'password'], true) && $value !== null && $value !== '') {
                $context[$key] = '***' . substr(md5((string)$value), -5);
                continue;
            }

            if ($key === 'host' && $value !== null && $value !== '') {
                $context[$key] = '***';
                continue;
            }

            if (is_string($value)) {
                $context[$key] = $this->sanitizeString($value);
            }
        }

        return $context;
    }

    private function sanitizeString(string $value): string
    {
        return redactSensitive(redactCredentials($value, $this->username, $this->password));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        $replacements = [];

        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null || $value instanceof Stringable) {
                $replacements['{' . $key . '}'] = (string)($value ?? '');
            }
        }

        return strtr($message, $replacements);
    }
};

/**
 * Immutable result of a single diagnostic step.
 */
final class TestResult
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string  $name,
        public readonly bool    $ok,
        public readonly ?string $exception = null,
        public readonly ?string $error = null,
        public readonly ?int    $statusCode = null,
        public readonly ?string $trace = null,
        public readonly array   $data = [],
    )
    {
    }

    public static function pass(string $name, array $data = []): self
    {
        return new self($name, true, data: $data);
    }

    public static function fail(string $name, Throwable $e, array $data = []): self
    {
        return new self(
            name: $name,
            ok: false,
            exception: $e::class,
            error: $e->getMessage(),
            statusCode: $e instanceof ServiceException ? $e->getStatusCode() : null,
            trace: $e->getTraceAsString(),
            data: $data,
        );
    }
}

/**
 * Configuration for a single connection scenario.
 */
final readonly class ScenarioConfig
{
    public function __construct(
        public string         $name,
        public SecurityPolicy $policy,
        public SecurityMode   $mode,
        public bool           $useCredentials,
    )
    {
    }

    public function describe(): string
    {
        $auth = $this->useCredentials ? 'UserName/Password' : 'Anonymous';

        return sprintf('%s + %s + %s', $this->policy->name, $this->mode->name, $auth);
    }
}

/**
 * Maps OPC UA token type integers to human-readable names.
 */
function tokenTypeName(int $tokenType): string
{
    return match ($tokenType) {
        TOKEN_TYPE_ANONYMOUS => 'Anonymous',
        TOKEN_TYPE_USERNAME => 'UserName',
        TOKEN_TYPE_CERTIFICATE => 'Certificate',
        TOKEN_TYPE_ISSUED => 'IssuedToken',
        default => "Unknown({$tokenType})",
    };
}

/**
 * Maps OPC UA security mode integers to human-readable names.
 */
function securityModeName(int $mode): string
{
    return match ($mode) {
        1 => 'None',
        2 => 'Sign',
        3 => 'SignAndEncrypt',
        default => "Unknown({$mode})",
    };
}

/**
 * @return array{result: TestResult, endpoints: EndpointDescription[]}
 */
function runEndpointEnumeration(LoggerInterface $logger, string $discoveryUrl): array
{
    $logger->info('=== TEST: endpoint enumeration ===');

    try {
        $client = ClientBuilder::create(logger: $logger)
            ->setSecurityPolicy(SecurityPolicy::None)
            ->setSecurityMode(SecurityMode::None)
            ->connect($discoveryUrl);

        $endpoints = $client->getEndpoints($discoveryUrl);

        $logger->info('Server returned {count} endpoint(s)', ['count' => count($endpoints)]);

        $summary = [];

        foreach ($endpoints as $i => $ep) {
            $logger->info('Endpoint #{i}', [
                'i' => $i,
                'endpointUrl' => $ep->endpointUrl,
                'securityPolicy' => $ep->securityPolicyUri,
                'securityMode' => securityModeName($ep->securityMode),
                'tokenCount' => count($ep->userIdentityTokens),
            ]);

            $tokens = [];

            foreach ($ep->userIdentityTokens as $j => $tp) {
                $logger->info('  UserTokenPolicy #{i}.{j}', [
                    'i' => $i,
                    'j' => $j,
                    'tokenType' => tokenTypeName($tp->tokenType),
                    'policyId' => $tp->policyId,
                    'securityPolicyUri' => $tp->securityPolicyUri,
                ]);

                $tokens[] = [
                    'tokenType' => $tp->tokenType,
                    'policyId' => $tp->policyId,
                    'securityPolicyUri' => $tp->securityPolicyUri,
                ];
            }

            $summary[] = [
                'endpointUrl' => $ep->endpointUrl,
                'securityPolicy' => $ep->securityPolicyUri,
                'securityMode' => $ep->securityMode,
                'userIdentityTokens' => $tokens,
            ];
        }

        $client->disconnect();

        return [
            'result' => TestResult::pass('endpoint-enumeration', ['endpoints' => $summary]),
            'endpoints' => $endpoints,
        ];
    } catch (Throwable $e) {
        $logger->error('Endpoint enumeration failed', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        return [
            'result' => TestResult::fail('endpoint-enumeration', $e),
            'endpoints' => [],
        ];
    }
}

function runConnectionScenario(
    LoggerInterface $logger,
    ScenarioConfig  $scenario,
    string          $sessionUrl,
    string          $username,
    string          $password,
): TestResult
{
    $logger->info('=== TEST: {desc} ===', ['desc' => $scenario->describe()]);

    try {
        $builder = ClientBuilder::create(logger: $logger)
            ->setSecurityPolicy($scenario->policy)
            ->setSecurityMode($scenario->mode);

        if ($scenario->useCredentials) {
            $builder = $builder->setUserCredentials($username, $password);
        }

        $client = $builder->connect($sessionUrl);

        $serverState = $client->read('i=2259')->getValue();
        $logger->info('ServerState read OK ({value})', ['value' => $serverState]);

        $client->disconnect();

        return TestResult::pass($scenario->name, ['serverState' => $serverState]);
    } catch (Throwable $e) {
        $logger->error('Scenario "{desc}" failed', [
            'desc' => $scenario->describe(),
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        return TestResult::fail($scenario->name, $e);
    }
}

/**
 * @param EndpointDescription[] $endpoints
 * @return array{verdict: string, advertised: string[], defaultIsAdvertised: bool, message: string}
 */
function diagnoseUserNamePolicyIdMismatch(array $endpoints, TestResult $secureUserPassResult): array
{
    $advertised = [];

    foreach ($endpoints as $ep) {
        if ($ep->securityPolicyUri !== 'http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256') {
            continue;
        }
        if (securityModeName($ep->securityMode) !== 'SignAndEncrypt') {
            continue;
        }
        foreach ($ep->userIdentityTokens as $tp) {
            if ($tp->tokenType === TOKEN_TYPE_USERNAME && $tp->policyId !== null) {
                $advertised[$tp->policyId] = true;
            }
        }
    }

    $advertisedList = array_keys($advertised);
    $defaultIsAdvertised = isset($advertised[CLIENT_HARDCODED_USERNAME_POLICY_ID]);

    if ($endpoints === []) {
        return [
            'verdict' => 'inconclusive',
            'advertised' => [],
            'defaultIsAdvertised' => false,
            'message' => 'Discovery returned no endpoints; cannot diagnose.',
        ];
    }

    if ($advertisedList === []) {
        return [
            'verdict' => 'no-username-endpoint',
            'advertised' => [],
            'defaultIsAdvertised' => false,
            'message' => 'No endpoint advertises Basic256Sha256 + SignAndEncrypt + UserName. '
                . 'The server may not support this combination — check Siemens TIA OPC UA configuration.',
        ];
    }

    if ($secureUserPassResult->ok) {
        return [
            'verdict' => 'ok',
            'advertised' => $advertisedList,
            'defaultIsAdvertised' => $defaultIsAdvertised,
            'message' => 'Secured UserName/Password connection succeeded — no mismatch in this run.',
        ];
    }

    if (!$defaultIsAdvertised && $secureUserPassResult->statusCode === STATUS_BAD_IDENTITY_TOKEN_INVALID) {
        return [
            'verdict' => 'mismatch-confirmed',
            'advertised' => $advertisedList,
            'defaultIsAdvertised' => false,
            'message' => sprintf(
                "policyId MISMATCH confirmed.\n"
                . "      Server accepts: %s\n"
                . "      Client uses default: '%s'\n"
                . '      This is the username sibling of the anonymous policyId bug fixed in v4.3.0.',
                implode(', ', array_map(static fn(string $id): string => "'{$id}'", $advertisedList)),
                CLIENT_HARDCODED_USERNAME_POLICY_ID,
            ),
        ];
    }

    if (!$defaultIsAdvertised) {
        return [
            'verdict' => 'mismatch-suspected',
            'advertised' => $advertisedList,
            'defaultIsAdvertised' => false,
            'message' => 'Default policyId not advertised, but failure does not match BadIdentityTokenInvalid. '
                . 'The mismatch may still be a factor — inspect ActivateSession in the DEBUG log.',
        ];
    }

    return [
        'verdict' => 'other-root-cause',
        'advertised' => $advertisedList,
        'defaultIsAdvertised' => true,
        'message' => 'Default policyId is advertised by the server. The failure has a different root cause '
            . '(certificate chain, password encoding, signature, or trust). Inspect ActivateSession in the DEBUG log.',
    ];
}

$logger->info('Diagnostic run starting', [
    'discoveryUrl' => $discoveryUrl,
    'sessionUrl' => $sessionUrl,
]);

$scenarios = [
    new ScenarioConfig('unsecured-anonymous', SecurityPolicy::None, SecurityMode::None, false),
    new ScenarioConfig('unsecured-userpass', SecurityPolicy::None, SecurityMode::None, true),
    new ScenarioConfig('secure-anonymous', SecurityPolicy::Basic256Sha256, SecurityMode::SignAndEncrypt, false),
    new ScenarioConfig('secure-userpass', SecurityPolicy::Basic256Sha256, SecurityMode::SignAndEncrypt, true),
];

$enumeration = runEndpointEnumeration($logger, $discoveryUrl);
$discoveryResult = $enumeration['result'];
$endpoints = $enumeration['endpoints'];

$scenarioResults = [];

foreach ($scenarios as $scenario) {
    $scenarioResults[$scenario->name] = runConnectionScenario($logger, $scenario, $sessionUrl, $username, $password);
}

$diagnosis = diagnoseUserNamePolicyIdMismatch($endpoints, $scenarioResults['secure-userpass']);

$line = str_repeat('=', 78);

echo PHP_EOL . $line . PHP_EOL;
echo '  DIAGNOSTIC REPORT' . PHP_EOL;
echo $line . PHP_EOL . PHP_EOL;

echo 'Discovery endpoint : ' . redactSensitive($discoveryUrl) . PHP_EOL;
echo 'Session endpoint   : ' . redactSensitive($sessionUrl) . PHP_EOL . PHP_EOL;

echo '-- Endpoint enumeration --' . PHP_EOL;

if ($discoveryResult->ok) {
    $epCount = count($discoveryResult->data['endpoints'] ?? []);
    echo sprintf('  status     : OK (%d endpoint%s)', $epCount, $epCount === 1 ? '' : 's') . PHP_EOL;

    foreach ($discoveryResult->data['endpoints'] as $i => $ep) {
        echo sprintf(
            '  #%d  %s  |  %s  |  %s' . PHP_EOL,
            $i,
            redactSensitive($ep['endpointUrl']),
            $ep['securityPolicy'],
            securityModeName($ep['securityMode']),
        );

        foreach ($ep['userIdentityTokens'] as $tok) {
            echo sprintf(
                "       %-12s policyId=%s" . PHP_EOL,
                tokenTypeName($tok['tokenType']),
                $tok['policyId'] === null ? '(null)' : "'{$tok['policyId']}'",
            );
        }
    }
} else {
    echo '  status     : FAILED' . PHP_EOL;
    echo '  exception  : ' . ($discoveryResult->exception ?? 'unknown') . PHP_EOL;
    echo '  error      : ' . redactForReport($discoveryResult->error ?? 'unknown', $username, $password) . PHP_EOL;

    if (!empty($discoveryResult->trace)) {
        echo '  trace      :' . PHP_EOL;
        foreach (explode("\n", $discoveryResult->trace) as $traceLine) {
            echo '    ' . redactForReport($traceLine, $username, $password) . PHP_EOL;
        }
    }
}

echo PHP_EOL . '-- Connection scenarios --' . PHP_EOL;

foreach ($scenarios as $scenario) {
    $res = $scenarioResults[$scenario->name];

    if ($res->ok) {
        echo sprintf('  [PASS] %s', $scenario->describe()) . PHP_EOL;
    } else {
        $statusFragment = $res->statusCode !== null
            ? sprintf(' (0x%08X)', $res->statusCode)
            : '';
        echo sprintf('  [FAIL] %s%s', $scenario->describe(), $statusFragment) . PHP_EOL;
        echo '         exception : ' . ($res->exception ?? 'unknown') . PHP_EOL;
        echo '         error     : ' . redactForReport($res->error ?? 'unknown', $username, $password) . PHP_EOL;

        if (!empty($res->trace)) {
            echo '         trace     :' . PHP_EOL;
            foreach (explode("\n", $res->trace) as $traceLine) {
                echo '           ' . redactForReport($traceLine, $username, $password) . PHP_EOL;
            }
        }
    }
}

echo PHP_EOL . '-- Diagnosis: UserName policyId --' . PHP_EOL;
echo '  verdict    : ' . $diagnosis['verdict'] . PHP_EOL;

if ($diagnosis['advertised'] !== []) {
    echo '  advertised : ' . implode(', ', array_map(
            static fn(string $id): string => "'{$id}'",
            $diagnosis['advertised'],
        )) . PHP_EOL;
}

echo '  client def : ' . "'" . CLIENT_HARDCODED_USERNAME_POLICY_ID . "'" . PHP_EOL;
echo '  message    : ' . PHP_EOL;

foreach (explode("\n", $diagnosis['message']) as $msgLine) {
    echo '      ' . $msgLine . PHP_EOL;
}

echo PHP_EOL . $line . PHP_EOL;

if (!$discoveryResult->ok) {
    exit(EXIT_DISCOVERY_FAILED);
}

if ($diagnosis['verdict'] === 'mismatch-confirmed') {
    exit(EXIT_DIAGNOSIS_MISMATCH);
}

foreach ($scenarioResults as $res) {
    if (!$res->ok) {
        exit(EXIT_SCENARIO_FAILED);
    }
}

exit(EXIT_OK);
