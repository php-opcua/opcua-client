<?php

declare(strict_types=1);

use PhpOpcua\Client\Module\FileTransfer\OpenFileMode;
use PhpOpcua\Client\Tests\Integration\Helpers\TestHelper;

/**
 * Integration tests for the File Transfer service set (Part 5 §C).
 *
 * Targets the four FileType fixtures shipped by uanetstandard-test-suite
 * v1.3.0+ on the opcua-no-security service (port 4840):
 *   - ns=1;s=TestServer/Files/ReadOnlyFile  (1024 B, deterministic seed)
 *   - ns=1;s=TestServer/Files/EmptyFile     (0 B)
 *   - ns=1;s=TestServer/Files/LargeFile     (256 KB, bytes 0..255 × 1024)
 *   - ns=1;s=TestServer/Files/WritableFile  (0 B initial, Writable=true)
 */
describe('File Transfer', function () {

    it('Open → Read → Close on ReadOnlyFile returns the seed bytes', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $node = TestHelper::browseToNode($client, ['TestServer', 'Files', 'ReadOnlyFile']);

            $handle = $client->openFile($node, OpenFileMode::Read);
            expect($handle)->toBeGreaterThan(0);

            $bytes = $client->readFile($node, $handle, 1024);

            // The seed is MD5("readonly-seed") × 64 — 1024 bytes total.
            $expected = str_repeat(md5('readonly-seed', true), 64);

            expect(strlen($bytes))->toBe(1024);
            expect($bytes)->toBe($expected);

            $client->closeFile($node, $handle);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('Open(Read) → Read on EmptyFile returns an empty ByteString without error', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $node = TestHelper::browseToNode($client, ['TestServer', 'Files', 'EmptyFile']);

            $handle = $client->openFile($node, OpenFileMode::Read);
            $bytes = $client->readFile($node, $handle, 100);
            $client->closeFile($node, $handle);

            expect($bytes)->toBe('');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('chunked Read drains LargeFile (256 KB → 32 × 8 KB chunks)', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $node = TestHelper::browseToNode($client, ['TestServer', 'Files', 'LargeFile']);

            $handle = $client->openFile($node, OpenFileMode::Read);

            $collected = '';
            $chunkSize = 8192;
            while (true) {
                $chunk = $client->readFile($node, $handle, $chunkSize);
                if ($chunk === '') {
                    break;
                }
                $collected .= $chunk;
            }

            $client->closeFile($node, $handle);

            expect(strlen($collected))->toBe(262144);
            // Seed: bytes 0..255 repeated 1024 times.
            expect(ord($collected[0]))->toBe(0);
            expect(ord($collected[255]))->toBe(255);
            expect(ord($collected[256]))->toBe(0);
            expect(ord($collected[262143]))->toBe(255);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('round-trip Write → Read on WritableFile preserves the payload', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $node = TestHelper::browseToNode($client, ['TestServer', 'Files', 'WritableFile']);

            $payload = 'integration-test-payload-' . bin2hex(random_bytes(8));

            $writeHandle = $client->openFile($node, OpenFileMode::toByte(OpenFileMode::Write, OpenFileMode::EraseExisting));
            $client->writeFile($node, $writeHandle, $payload);
            $client->closeFile($node, $writeHandle);

            $readHandle = $client->openFile($node, OpenFileMode::Read);
            $readBack = $client->readFile($node, $readHandle, strlen($payload) + 16);
            $client->closeFile($node, $readHandle);

            expect($readBack)->toBe($payload);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('GetPosition and SetPosition cooperate with Read', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $node = TestHelper::browseToNode($client, ['TestServer', 'Files', 'LargeFile']);

            $handle = $client->openFile($node, OpenFileMode::Read);

            expect($client->getFilePosition($node, $handle))->toBe(0);

            $client->readFile($node, $handle, 100);
            expect($client->getFilePosition($node, $handle))->toBe(100);

            $client->setFilePosition($node, $handle, 256);
            $slice = $client->readFile($node, $handle, 4);

            // Bytes at position 256..259 in the seed = 0, 1, 2, 3 (cycle restarts).
            expect(ord($slice[0]))->toBe(0);
            expect(ord($slice[1]))->toBe(1);
            expect(ord($slice[2]))->toBe(2);
            expect(ord($slice[3]))->toBe(3);

            $client->closeFile($node, $handle);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('Open with Write on a read-only file returns BadNotWritable', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $node = TestHelper::browseToNode($client, ['TestServer', 'Files', 'ReadOnlyFile']);

            expect(fn () => $client->openFile($node, OpenFileMode::Write))
                ->toThrow(PhpOpcua\Client\Exception\ServiceException::class, 'BadNotWritable');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

});
