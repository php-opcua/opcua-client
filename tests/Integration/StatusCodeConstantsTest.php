<?php

declare(strict_types=1);

use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Module\FileTransfer\OpenFileMode;
use PhpOpcua\Client\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;

/**
 * Every constant here is checked against the status code UA-.NETStandard itself produces:
 * the codes come from the stack's access checks, its aggregate calculators and the
 * FileType method handlers, never from a value the test server hard-codes.
 */
function historyWithBadSamples(PhpOpcua\Client\Client $client, NodeId $nodeId): DateTimeImmutable
{
    $deadline = microtime(true) + 60;
    do {
        $raw = $client->historyReadRaw($nodeId, new DateTimeImmutable('-1 day'), new DateTimeImmutable('+1 minute'));
        if (count($raw) >= 45) {
            return $raw[0]->sourceTimestamp;
        }
        usleep(1_000_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('HistoricalWithBadSamples did not collect 45 samples within 60 seconds');
}

describe('StatusCode constants against a real server', function () {

    it('matches BadNotReadable when reading a write-only variable', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'AccessControl', 'AccessLevels', 'CurrentWrite_Only']);

            expect($client->read($nodeId)->statusCode)->toBe(StatusCode::BadNotReadable);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('matches BadNoData and UncertainDataSubNormal in processed history', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Historical', 'HistoricalWithBadSamples']);
            $firstSample = historyWithBadSamples($client, $nodeId);

            $processed = $client->historyReadProcessed(
                $nodeId,
                $firstSample->modify('-30 seconds'),
                $firstSample->modify('+40 seconds'),
                10000.0,
                NodeId::numeric(0, 2342),
            );
            $baseCodes = array_map(fn (DataValue $dataValue) => $dataValue->statusCode & 0xFFFF0000, $processed);

            expect($baseCodes[0])->toBe(StatusCode::BadNoData);
            expect($baseCodes)->toContain(StatusCode::UncertainDataSubNormal);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('matches BadAggregateNotSupported for an unknown aggregate', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Historical', 'HistoricalWithBadSamples']);

            try {
                $client->historyReadProcessed($nodeId, new DateTimeImmutable('-1 minute'), new DateTimeImmutable(), 10000.0, NodeId::numeric(0, 99999));
                $statusCode = StatusCode::Good;
            } catch (ServiceException $e) {
                $statusCode = $e->getStatusCode();
            }

            expect($statusCode)->toBe(StatusCode::BadAggregateNotSupported);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('matches the deprecated file constants to the codes FileType methods return', function () {
        $client = null;
        $handle = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $file = TestHelper::browseToNode($client, ['TestServer', 'Files', 'WritableFile']);

            $readStatus = function (int $fileHandle) use ($client, $file): int {
                try {
                    $client->readFile($file, $fileHandle, 10);

                    return StatusCode::Good;
                } catch (ServiceException $e) {
                    return $e->getStatusCode();
                }
            };

            expect($readStatus(987654))->toBe(StatusCode::BadFileHandleInvalid)->toBe(StatusCode::BadInvalidArgument);

            $handle = $client->openFile($file, OpenFileMode::Write);
            expect($readStatus($handle))->toBe(StatusCode::BadFileNotOpened)->toBe(StatusCode::BadInvalidState);
        } finally {
            if ($client !== null && $handle !== null) {
                $client->closeFile($file, $handle);
            }
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');
});
