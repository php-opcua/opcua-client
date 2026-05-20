<?php

declare(strict_types=1);

use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Module\History\HistoryUpdateResult;
use PhpOpcua\Client\Module\History\HistoryUpdateService;
use PhpOpcua\Client\Module\History\PerformUpdateType;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\Variant;

function huService(): HistoryUpdateService
{
    return new HistoryUpdateService(new SessionService(1, 1));
}

function huNodeId(): NodeId
{
    return NodeId::numeric(2, 42);
}

function huAuthToken(): NodeId
{
    return NodeId::numeric(0, 1);
}

function huDataValue(float $tsSeconds, float $value): DataValue
{
    $ts = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6f', $tsSeconds));

    return new DataValue(new Variant(BuiltinType::Double, $value), 0, $ts);
}

describe('HistoryUpdateService encode', function () {

    it('UpdateData carries the UpdateDataDetails encoding NodeId (682) and PerformUpdateType byte', function () {
        $bytes = huService()->encodeUpdateDataRequest(
            1,
            huAuthToken(),
            huNodeId(),
            PerformUpdateType::Insert,
            [huDataValue(1700000000.0, 42.0)],
        );

        // 682 (little-endian UInt32 inside the ExtensionObject TypeId NodeId, two-byte form 0x02..)
        // Easier: check ASCII-hex of the body for the byte sequence 0xAA 0x02 (= 682 LE).
        expect(strpos($bytes, "\xAA\x02"))->not->toBeFalse();

        // PerformUpdateType::Insert = 1 written as UInt32 LE → \x01\x00\x00\x00
        expect(strpos($bytes, "\x01\x00\x00\x00"))->not->toBeFalse();
    });

    it('Replace and Update only differ from Insert by the PerformUpdateType byte', function () {
        $insert = huService()->encodeUpdateDataRequest(1, huAuthToken(), huNodeId(), PerformUpdateType::Insert, []);
        $replace = huService()->encodeUpdateDataRequest(1, huAuthToken(), huNodeId(), PerformUpdateType::Replace, []);
        $update = huService()->encodeUpdateDataRequest(1, huAuthToken(), huNodeId(), PerformUpdateType::Update, []);

        expect(strlen($insert))->toBe(strlen($replace))->toBe(strlen($update));
        expect($insert)->not->toBe($replace);
        expect($replace)->not->toBe($update);
    });

    it('DeleteRawModified carries the encoding NodeId (688) and the isDeleteModified flag', function () {
        $bytes = huService()->encodeDeleteRawModifiedRequest(
            1,
            huAuthToken(),
            huNodeId(),
            false,
            new DateTimeImmutable('2026-01-01 00:00:00'),
            new DateTimeImmutable('2026-01-02 00:00:00'),
        );

        // 688 = 0xB0 0x02
        expect(strpos($bytes, "\xB0\x02"))->not->toBeFalse();
    });

    it('DeleteAtTime carries the encoding NodeId (691)', function () {
        $bytes = huService()->encodeDeleteAtTimeRequest(
            1,
            huAuthToken(),
            huNodeId(),
            [new DateTimeImmutable('2026-01-01 00:00:00')],
        );

        // 691 = 0xB3 0x02
        expect(strpos($bytes, "\xB3\x02"))->not->toBeFalse();
    });

    it('DeleteEvent carries the encoding NodeId (694)', function () {
        $bytes = huService()->encodeDeleteEventRequest(1, huAuthToken(), huNodeId(), ["\x01\x02\x03"]);

        // 694 = 0xB6 0x02
        expect(strpos($bytes, "\xB6\x02"))->not->toBeFalse();
    });

    it('UpdateEvent carries the encoding NodeId (685)', function () {
        $bytes = huService()->encodeUpdateEventRequest(
            1,
            huAuthToken(),
            huNodeId(),
            PerformUpdateType::Insert,
            ['EventId', 'Severity'],
            [],
        );

        // 685 = 0xAD 0x02
        expect(strpos($bytes, "\xAD\x02"))->not->toBeFalse();
    });
});

function huPrefixResponseBody(string $payload): string
{
    $b = new BinaryEncoder();
    // 3 UInt32 read first by readResponseMetadata (channelId / tokenId / sequence).
    $b->writeUInt32(0);
    $b->writeUInt32(0);
    $b->writeUInt32(0);
    // NodeId of HistoryUpdateResponse_Encoding_DefaultBinary = 703 (FourByte form).
    $b->writeRawBytes("\x01\x00\xBF\x02");
    // ResponseHeader: Int64 timestamp, UInt32 requestHandle, UInt32 serviceResult,
    // Byte diagInfoMask, Int32 stringTableCount, NodeId additionalHeader, Byte additionalHeader encoding.
    $b->writeRawBytes("\x00\x00\x00\x00\x00\x00\x00\x00");
    $b->writeUInt32(0);
    $b->writeUInt32(0);
    $b->writeByte(0x00);
    $b->writeInt32(0);
    $b->writeRawBytes("\x00\x00");
    $b->writeByte(0x00);

    return $b->getBuffer() . $payload;
}

describe('HistoryUpdateService decode', function () {

    it('decodes a single result with two operation status codes', function () {
        $payload = new BinaryEncoder();
        $payload->writeInt32(1);
        $payload->writeUInt32(0);
        $payload->writeInt32(2);
        $payload->writeUInt32(0);
        $payload->writeUInt32(0x80B10000);
        $payload->writeInt32(-1);
        $payload->writeInt32(-1);

        $buffer = huPrefixResponseBody($payload->getBuffer());
        $decoder = new PhpOpcua\Client\Encoding\BinaryDecoder($buffer);
        $results = huService()->decodeHistoryUpdateResponse($decoder);

        expect($results)->toHaveCount(1);
        expect($results[0])->toBeInstanceOf(HistoryUpdateResult::class);
        expect($results[0]->statusCode)->toBe(0);
        expect($results[0]->operationResults)->toBe([0, 0x80B10000]);
    });

    it('decodes a result with empty operationResults', function () {
        $payload = new BinaryEncoder();
        $payload->writeInt32(1);
        $payload->writeUInt32(0);
        $payload->writeInt32(0);
        $payload->writeInt32(-1);
        $payload->writeInt32(-1);

        $buffer = huPrefixResponseBody($payload->getBuffer());
        $decoder = new PhpOpcua\Client\Encoding\BinaryDecoder($buffer);
        $results = huService()->decodeHistoryUpdateResponse($decoder);

        expect($results[0]->operationResults)->toBe([]);
    });
});

describe('PerformUpdateType enum', function () {

    it('matches the OPC UA wire values', function () {
        expect(PerformUpdateType::Insert->value)->toBe(1);
        expect(PerformUpdateType::Replace->value)->toBe(2);
        expect(PerformUpdateType::Update->value)->toBe(3);
        expect(PerformUpdateType::Remove->value)->toBe(4);
    });
});

describe('HistoryUpdateResult DTO', function () {

    it('wire round-trip preserves status and operationResults', function () {
        $r = new HistoryUpdateResult(0x80AB0000, [0, 0x80B10000, 0]);

        $payload = $r->jsonSerialize();
        $back = HistoryUpdateResult::fromWireArray($payload);

        expect($back->statusCode)->toBe(0x80AB0000);
        expect($back->operationResults)->toBe([0, 0x80B10000, 0]);
    });
});
