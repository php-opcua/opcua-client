<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\OpcUaException;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use Psr\Log\AbstractLogger;

$logger = new class (__DIR__ . '/opcua-debug.log') extends AbstractLogger {
    /** @var resource */
    private $file;

    public function __construct(string $logPath)
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

            if (in_array($key, ['session_id', 'token', 'authToken'], true) && $value !== null && $value !== '') {
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

try {
    $builder = ClientBuilder::create(logger: $logger)
        ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
        ->setSecurityMode(SecurityMode::SignAndEncrypt)
        // Client certificate: if omitted, the library auto-generates an
        // ephemeral self-signed RSA-2048 certificate in memory (changes on
        // every Client instance). For production, uncomment and provide your
        // own certificate, private key, and optional CA chain:
        // ->setClientCertificate(
        //     __DIR__ . '/certs/client.pem',
        //     __DIR__ . '/certs/client.key',
        //     __DIR__ . '/certs/ca.pem',
        // )
        ->setUserCredentials('admin', 'admin123');
    //  ->setBatchSize(0); // Disable batching, skipping discoverServerOperationLimits feature
    //  ->autoAccept(true, force:true); // Client forces auto accept server certificate even if not trusted

    $client = $builder->connect('opc.tcp://localhost:4841/UA/TestServer');

    $status = $client->read('i=2259');
    $logger->info('ServerStatus read', [
        'value' => $status->getValue(),
        'statusCode' => $status->statusCode,
    ]);


    // WARNING: the block below logs the server build info (vendor, software
    // version, build number) -- this is a server fingerprint and may help an
    // attacker identify CVEs affecting your deployment. Uncomment only if you
    // intend to share the output in accordance with SECURITY.md.
    //$info = $client->getServerBuildInfo();
    //$logger->info('Server build info', [
    //    'productName'      => $info->productName,
    //    'manufacturerName' => $info->manufacturerName,
    //    'softwareVersion'  => $info->softwareVersion,
    //    'buildNumber'      => $info->buildNumber,
    //    'buildDate'        => $info->buildDate?->format(DateTimeInterface::ATOM),
    //]);

    $client->disconnect();
} catch (Throwable $e) {
    $label = match (true) {
        $e instanceof ConnectionException => 'OPC UA connection failed',
        $e instanceof OpcUaException => 'OPC UA exception',
        default => 'Unhandled exception',
    };

    $logger->error($label, [
        'exception' => $e::class,
        'message' => $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);

    throw $e;
}
