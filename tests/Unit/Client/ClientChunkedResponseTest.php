<?php

declare(strict_types=1);

require_once __DIR__ . '/ClientTraitsCoverageTest.php';

use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Exception\ProtocolException;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Protocol\MessageHeader;
use PhpOpcua\Client\Types\BuiltinType;

if (! class_exists('ScriptedChunkTransport')) {
    /**
     * Answers each receive() with a closure fed the request ID of the last request sent,
     * so a test can produce chunks, stale responses and aborts for the real ID.
     */
    class ScriptedChunkTransport extends MockTransport
    {
        /** @var list<Closure(int): string> */
        private array $script = [];

        public bool $external = false;

        public function then(Closure $step): self
        {
            $this->script[] = $step;

            return $this;
        }

        public function receive(): string
        {
            if ($this->script === []) {
                throw new PhpOpcua\Client\Exception\ConnectionException('No more scripted responses');
            }
            $last = $this->sent[array_key_last($this->sent)];

            return (array_shift($this->script))(unpack('V', $last, 20)[1]);
        }

        public function isSecureChannelExternal(): bool
        {
            return $this->external;
        }
    }
}

/**
 * Splits a plain MSG message into chunks of at most $bodyPerChunk body bytes, each carrying the
 * message's channel, token, sequence and request IDs, with the given request ID.
 *
 * @return list<string>
 */
function chunkMessage(string $message, int $requestId, int $bodyPerChunk, string $lastChunkType = 'F'): array
{
    $prefix = substr($message, 8, 12);
    $parts = str_split(substr($message, 24), $bodyPerChunk);
    $chunks = [];
    foreach ($parts as $i => $part) {
        $type = $i === array_key_last($parts) ? $lastChunkType : 'C';
        $chunks[] = messageChunk($type, $prefix . pack('V', $requestId) . $part);
    }

    return $chunks;
}

function messageChunk(string $chunkType, string $afterHeader): string
{
    $encoder = new BinaryEncoder();
    (new MessageHeader('MSG', $chunkType, MessageHeader::HEADER_SIZE + strlen($afterHeader)))->encode($encoder);
    $encoder->writeRawBytes($afterHeader);

    return $encoder->getBuffer();
}

function readResponseWithString(string $value): string
{
    return buildMsgResponse(634, function (BinaryEncoder $e) use ($value) {
        $e->writeInt32(1);
        $e->writeByte(0x01);
        $e->writeByte(BuiltinType::String->value);
        $e->writeString($value);
        $e->writeInt32(0);
    });
}

describe('Chunked responses', function () {

    it('assembles a response split across several chunks', function () {
        $value = str_repeat('0123456789', 2000);
        $transport = new ScriptedChunkTransport();
        $chunks = null;
        $transport->then(function (int $requestId) use (&$chunks, $value) {
            $chunks = chunkMessage(readResponseWithString($value), $requestId, 6000);

            return array_shift($chunks);
        });
        foreach (range(1, 3) as $ignored) {
            $transport->then(function () use (&$chunks) {
                return array_shift($chunks);
            });
        }

        $client = setupConnectedClient($transport);

        expect($client->read('i=2258')->getValue())->toBe($value);
    });

    it('discards a response to an abandoned request and returns the matching one', function () {
        $transport = (new ScriptedChunkTransport())
            ->then(fn (int $requestId) => chunkMessage(readResponseWithString('stale'), $requestId - 1, 100000)[0])
            ->then(fn (int $requestId) => chunkMessage(readResponseWithString('fresh'), $requestId, 100000)[0]);

        $client = setupConnectedClient($transport);

        expect($client->read('i=2258')->getValue())->toBe('fresh');
    });

    it('discards every chunk of a stale chunked response', function () {
        $stale = null;
        $staleChunkCount = count(chunkMessage(readResponseWithString(str_repeat('s', 300)), 0, 100));
        $transport = (new ScriptedChunkTransport())
            ->then(function (int $requestId) use (&$stale) {
                $stale = chunkMessage(readResponseWithString(str_repeat('s', 300)), $requestId - 1, 100);

                return array_shift($stale);
            });
        foreach (range(2, $staleChunkCount) as $ignored) {
            $transport->then(function () use (&$stale) {
                return array_shift($stale);
            });
        }
        $transport->then(fn (int $requestId) => chunkMessage(readResponseWithString('fresh'), $requestId, 100000)[0]);
        expect($staleChunkCount)->toBeGreaterThan(2);

        $client = setupConnectedClient($transport);

        expect($client->read('i=2258')->getValue())->toBe('fresh');
    });

    it('raises the error of an abort chunk', function () {
        $transport = (new ScriptedChunkTransport())
            ->then(fn (int $requestId) => chunkMessage(readResponseWithString(str_repeat('x', 200)), $requestId, 100)[0])
            ->then(function (int $requestId) {
                $abort = new BinaryEncoder();
                $abort->writeUInt32(1);
                $abort->writeUInt32(1);
                $abort->writeUInt32(1);
                $abort->writeUInt32($requestId);
                $abort->writeUInt32(0x80080000);
                $abort->writeString('Response too large');

                return messageChunk('A', $abort->getBuffer());
            });

        $client = setupConnectedClient($transport);

        try {
            $client->read('i=2258');
            $caught = null;
        } catch (ServiceException $e) {
            $caught = $e;
        }

        expect($caught)->toBeInstanceOf(ServiceException::class);
        expect($caught->getStatusCode())->toBe(0x80080000);
        expect($caught->getMessage())->toContain('Response too large');
    });

    it('rejects a chunk that belongs to a different request', function () {
        $transport = (new ScriptedChunkTransport())
            ->then(fn (int $requestId) => chunkMessage(readResponseWithString(str_repeat('x', 200)), $requestId, 100)[0])
            ->then(fn (int $requestId) => chunkMessage(readResponseWithString(str_repeat('x', 200)), $requestId + 5, 100)[1]);

        $client = setupConnectedClient($transport);

        expect(fn () => $client->read('i=2258'))->toThrow(ProtocolException::class);
    });

    it('raises an ERR message received between chunks', function () {
        $transport = (new ScriptedChunkTransport())
            ->then(fn (int $requestId) => chunkMessage(readResponseWithString(str_repeat('x', 200)), $requestId, 100)[0])
            ->then(fn () => buildErrMsg(0x80020000, 'Internal failure'));

        $client = setupConnectedClient($transport);

        expect(fn () => $client->read('i=2258'))->toThrow(ServiceException::class, 'Internal failure');
    });

    it('does not match request IDs on transports that own the secure channel', function () {
        $transport = (new ScriptedChunkTransport())
            ->then(fn () => chunkMessage(readResponseWithString('https'), 1, 100000)[0]);
        $transport->external = true;

        $client = setupConnectedClient($transport);

        expect($client->read('i=2258')->getValue())->toBe('https');
    });
});
