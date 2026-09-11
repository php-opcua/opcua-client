<?php

declare(strict_types=1);

use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\DataValueLimit;
use PhpOpcua\Client\Types\StatusCode;
use PhpOpcua\Client\Types\Variant;

describe('StatusCode DataValue InfoBits', function () {

    it('exposes the InfoBits masks', function () {
        expect(StatusCode::Overflow)->toBe(0x00000080);
        expect(StatusCode::LimitLow)->toBe(0x00000100);
        expect(StatusCode::LimitHigh)->toBe(0x00000200);
        expect(StatusCode::LimitConstant)->toBe(0x00000300);
    });

    it('recognises the DataValue InfoType', function () {
        expect(StatusCode::hasDataValueInfoBits(0x00000400))->toBeTrue();
        expect(StatusCode::hasDataValueInfoBits(StatusCode::Good))->toBeFalse();
    });

    it('rejects the reserved InfoType that shares the DataValue bit', function () {
        expect(StatusCode::hasDataValueInfoBits(0x00000C00))->toBeFalse();
        expect(StatusCode::isOverflow(0x00000C80))->toBeFalse();
    });

    it('reports overflow only under the DataValue InfoType', function () {
        expect(StatusCode::isOverflow(0x00000480))->toBeTrue();
        expect(StatusCode::isOverflow(0x00000080))->toBeFalse();
        expect(StatusCode::isOverflow(0x00000400))->toBeFalse();
    });

    it('reads overflow regardless of severity', function () {
        expect(StatusCode::isOverflow(0x40000480))->toBeTrue();
        expect(StatusCode::isOverflow(0x80000480))->toBeTrue();
    });

    it('keeps an overflowed Good value classified as Good', function () {
        expect(StatusCode::isGood(0x00000480))->toBeTrue();
    });

    it('decodes every LimitBits value', function (int $code, DataValueLimit $expected) {
        expect(StatusCode::limit($code))->toBe($expected);
    })->with([
        'none' => [0x00000400, DataValueLimit::None],
        'low' => [0x00000500, DataValueLimit::Low],
        'high' => [0x00000600, DataValueLimit::High],
        'constant' => [0x00000700, DataValueLimit::Constant],
    ]);

    it('ignores limit bits without the DataValue InfoType', function () {
        expect(StatusCode::limit(0x00000300))->toBe(DataValueLimit::None);
    });

    it('reads back the bits written by withDataValueInfoBits()', function () {
        $code = StatusCode::withDataValueInfoBits(StatusCode::Good, StatusCode::Overflow | StatusCode::LimitHigh);

        expect(StatusCode::isOverflow($code))->toBeTrue();
        expect(StatusCode::limit($code))->toBe(DataValueLimit::High);
    });

});

describe('DataValue InfoBits', function () {

    it('delegates to StatusCode', function () {
        $dataValue = new DataValue(new Variant(BuiltinType::Double, 1.5), 0x00000680);

        expect($dataValue->hasInfoBits())->toBeTrue();
        expect($dataValue->isOverflow())->toBeTrue();
        expect($dataValue->limit())->toBe(DataValueLimit::High);
    });

    it('reports nothing for a plain Good value', function () {
        $dataValue = DataValue::ofInt32(42);

        expect($dataValue->hasInfoBits())->toBeFalse();
        expect($dataValue->isOverflow())->toBeFalse();
        expect($dataValue->limit())->toBe(DataValueLimit::None);
    });

});
