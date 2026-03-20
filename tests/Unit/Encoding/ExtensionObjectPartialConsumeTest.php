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

    it('skips unconsumed bytes when codec reads less than bodyLength', function () {
        $repo = new ExtensionObjectRepository();
        $typeId = NodeId::numeric(2, 9999);
        $repo->register($typeId, PartialConsumeCodec::class);

        $encoder = new BinaryEncoder();
        $encoder->writeNodeId($typeId);
        $encoder->writeByte(0x01);

        $bodyEncoder = new BinaryEncoder();
        $bodyEncoder->writeDouble(1.5);
        $bodyEncoder->writeDouble(2.5);
        $bodyEncoder->writeDouble(3.5);
        $body = $bodyEncoder->getBuffer();

        $encoder->writeInt32(strlen($body));
        $encoder->writeRawBytes($body);
        $encoder->writeInt32(12345);

        $decoder = new BinaryDecoder($encoder->getBuffer(), $repo);
        $result = $decoder->readExtensionObject();

        expect($result)->toBe(['x' => 1.5]);

        $sentinel = $decoder->readInt32();
        expect($sentinel)->toBe(12345);
    });
});
