<?php

declare(strict_types=1);

require_once __DIR__ . '/ClientTraitsCoverageTest.php';

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Module\History\HistoryReadService;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\NodeId;

/**
 * @param list<int> $values
 */
function historyPageResponse(array $values, ?string $continuationPoint): string
{
    return buildMsgResponse(667, function (BinaryEncoder $e) use ($values, $continuationPoint) {
        $e->writeInt32(1);
        $e->writeUInt32(0);
        $e->writeByteString($continuationPoint);
        $e->writeNodeId(NodeId::numeric(0, 658));
        $e->writeByte(0x01);
        $body = new BinaryEncoder();
        $body->writeInt32(count($values));
        foreach ($values as $value) {
            $body->writeByte(0x01);
            $body->writeByte(BuiltinType::Int32->value);
            $body->writeInt32($value);
        }
        $e->writeInt32(strlen($body->getBuffer()));
        $e->writeRawBytes($body->getBuffer());
        $e->writeInt32(0);
    });
}

function releaseFlagAndContinuationPoint(string $request, string $continuationPoint): bool
{
    return str_contains($request, pack('V', 2) . "\x01" . pack('V', 1))
        && str_contains($request, pack('V', strlen($continuationPoint)) . $continuationPoint);
}

describe('HistoryReadService continuation points', function () {

    it('encodes the continuation point and the release flag', function () {
        $service = new HistoryReadService(new SessionService(1, 1));

        $request = $service->encodeHistoryReadRawRequest(1, NodeId::numeric(0, 0), NodeId::numeric(2, 1), null, null, 0, false, 'cp-1', true);
        $plain = $service->encodeHistoryReadRawRequest(1, NodeId::numeric(0, 0), NodeId::numeric(2, 1));

        expect(releaseFlagAndContinuationPoint($request, 'cp-1'))->toBeTrue();
        expect(str_contains($plain, pack('V', 2) . "\x00" . pack('V', 1)))->toBeTrue();
        expect(str_ends_with($plain, pack('l', -1)))->toBeTrue();
    });

    it('decodes the continuation point of a HistoryRead result', function () {
        $service = new HistoryReadService(new SessionService(1, 1));
        $response = historyPageResponse([1, 2], 'next-page');

        $page = $service->decodeHistoryReadResponseWithContinuation(new BinaryDecoder(substr($response, 12)));

        expect($page['continuationPoint'])->toBe('next-page');
        expect(array_map(fn ($dv) => $dv->getValue(), $page['values']))->toBe([1, 2]);
    });
});

describe('History reads across continuation points', function () {

    it('follows every continuation point and returns all values', function () {
        $mock = new MockTransport();
        $mock->addResponse(historyPageResponse([1, 2], 'cp-a'));
        $mock->addResponse(historyPageResponse([3, 4], 'cp-b'));
        $mock->addResponse(historyPageResponse([5], null));

        $client = setupConnectedClient($mock);
        $values = $client->historyReadRaw('ns=2;s=Counter');

        expect(array_map(fn ($dv) => $dv->getValue(), $values))->toBe([1, 2, 3, 4, 5]);
        expect($mock->sent)->toHaveCount(3);
        expect(str_contains($mock->sent[1], pack('V', 4) . 'cp-a'))->toBeTrue();
        expect(str_contains($mock->sent[2], pack('V', 4) . 'cp-b'))->toBeTrue();
    });

    it('stops at numValuesPerNode and releases the pending continuation point', function () {
        $mock = new MockTransport();
        $mock->addResponse(historyPageResponse([1, 2], 'cp-a'));
        $mock->addResponse(historyPageResponse([3, 4], 'cp-b'));
        $mock->addResponse(historyPageResponse([], null));

        $client = setupConnectedClient($mock);
        $values = $client->historyReadRaw('ns=2;s=Counter', numValuesPerNode: 3);

        expect(array_map(fn ($dv) => $dv->getValue(), $values))->toBe([1, 2, 3]);
        expect($mock->sent)->toHaveCount(3);
        expect(releaseFlagAndContinuationPoint($mock->sent[2], 'cp-b'))->toBeTrue();
    });

    it('follows continuation points for processed and at-time reads', function () {
        $mock = new MockTransport();
        $mock->addResponse(historyPageResponse([1], 'cp-a'));
        $mock->addResponse(historyPageResponse([2], null));
        $mock->addResponse(historyPageResponse([3], 'cp-b'));
        $mock->addResponse(historyPageResponse([4], null));

        $client = setupConnectedClient($mock);
        $processed = $client->historyReadProcessed('ns=2;s=Counter', new DateTimeImmutable('-1 hour'), new DateTimeImmutable(), 60000.0, NodeId::numeric(0, 2342));
        $atTime = $client->historyReadAtTime('ns=2;s=Counter', [new DateTimeImmutable()]);

        expect(array_map(fn ($dv) => $dv->getValue(), $processed))->toBe([1, 2]);
        expect(array_map(fn ($dv) => $dv->getValue(), $atTime))->toBe([3, 4]);
    });
});
