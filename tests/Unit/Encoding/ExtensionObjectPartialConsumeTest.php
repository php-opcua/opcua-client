<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Encoding\ExtensionObjectCodec;
use Gianfriaur\OpcuaPhpClient\Repository\ExtensionObjectRepository;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

class PartialConsumeCodec implements ExtensionObjectCodec
{
    public function decode(BinaryDecoder $decoder): array
    {
        return ['x' => $decoder->readDouble()];
    }

    public function encode(BinaryEncoder $encoder, mixed $value): void
    {
        $encoder->writeDouble($value['x']);
    }
}

describe('ExtensionObject codec partial body consumption', function () {

    afterEach(function () {
        ExtensionObjectRepository::clear();
    });

    it('skips unconsumed bytes when codec reads less than bodyLength', function () {
        $typeId = NodeId::numeric(2, 9999);
        ExtensionObjectRepository::register($typeId, PartialConsumeCodec::class);

        $encoder = new BinaryEncoder();
        $encoder->writeNodeId($typeId);
        $encoder->writeByte(0x01);

        // Body: 3 doubles (24 bytes), codec only reads 1 (8 bytes)
        $bodyEncoder = new BinaryEncoder();
        $bodyEncoder->writeDouble(1.5);
        $bodyEncoder->writeDouble(2.5);
        $bodyEncoder->writeDouble(3.5);
        $body = $bodyEncoder->getBuffer();

        $encoder->writeInt32(strlen($body));
        $encoder->writeRawBytes($body);
        $encoder->writeInt32(12345);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readExtensionObject();

        expect($result)->toBe(['x' => 1.5]);

        $sentinel = $decoder->readInt32();
        expect($sentinel)->toBe(12345);
    });
});
