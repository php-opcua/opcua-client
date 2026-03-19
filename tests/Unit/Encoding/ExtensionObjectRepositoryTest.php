<?php /** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Encoding\ExtensionObjectCodec;
use Gianfriaur\OpcuaPhpClient\Repository\ExtensionObjectRepository;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

class TestPointCodec implements ExtensionObjectCodec
{
    public function decode(BinaryDecoder $decoder): object|array
    {
        return [
            'x' => $decoder->readDouble(),
            'y' => $decoder->readDouble(),
        ];
    }

    public function encode(BinaryEncoder $encoder, mixed $value): void
    {
        $encoder->writeDouble($value['x']);
        $encoder->writeDouble($value['y']);
    }
}

beforeEach(function () {
    ExtensionObjectRepository::clear();
});

describe('ExtensionObjectRepository', function () {

    it('has no codecs by default', function () {
        expect(ExtensionObjectRepository::has(NodeId::numeric(0, 100)))->toBeFalse();
        expect(ExtensionObjectRepository::get(NodeId::numeric(0, 100)))->toBeNull();
    });

    it('registers a codec by class name', function () {
        ExtensionObjectRepository::register(NodeId::numeric(0, 100), TestPointCodec::class);
        expect(ExtensionObjectRepository::has(NodeId::numeric(0, 100)))->toBeTrue();
        expect(ExtensionObjectRepository::get(NodeId::numeric(0, 100)))->toBeInstanceOf(TestPointCodec::class);
    });

    it('registers a codec by instance', function () {
        $codec = new TestPointCodec();
        ExtensionObjectRepository::register(NodeId::numeric(0, 200), $codec);
        expect(ExtensionObjectRepository::get(NodeId::numeric(0, 200)))->toBe($codec);
    });

    it('unregisters a codec', function () {
        ExtensionObjectRepository::register(NodeId::numeric(0, 100), TestPointCodec::class);
        expect(ExtensionObjectRepository::has(NodeId::numeric(0, 100)))->toBeTrue();

        ExtensionObjectRepository::unregister(NodeId::numeric(0, 100));
        expect(ExtensionObjectRepository::has(NodeId::numeric(0, 100)))->toBeFalse();
    });

    it('clear removes all codecs', function () {
        ExtensionObjectRepository::register(NodeId::numeric(0, 100), TestPointCodec::class);
        ExtensionObjectRepository::register(NodeId::numeric(0, 200), TestPointCodec::class);

        ExtensionObjectRepository::clear();
        expect(ExtensionObjectRepository::has(NodeId::numeric(0, 100)))->toBeFalse();
        expect(ExtensionObjectRepository::has(NodeId::numeric(0, 200)))->toBeFalse();
    });

    it('different typeIds are independent', function () {
        ExtensionObjectRepository::register(NodeId::numeric(0, 100), TestPointCodec::class);
        expect(ExtensionObjectRepository::has(NodeId::numeric(0, 100)))->toBeTrue();
        expect(ExtensionObjectRepository::has(NodeId::numeric(0, 200)))->toBeFalse();
    });

    it('supports string NodeIds', function () {
        ExtensionObjectRepository::register(NodeId::string(2, 'MyType'), TestPointCodec::class);
        expect(ExtensionObjectRepository::has(NodeId::string(2, 'MyType')))->toBeTrue();
        expect(ExtensionObjectRepository::has(NodeId::string(2, 'OtherType')))->toBeFalse();
    });
});

describe('ExtensionObject decoding with codec', function () {

    it('decodes ExtensionObject using registered codec', function () {
        $typeId = NodeId::numeric(2, 500);
        ExtensionObjectRepository::register($typeId, TestPointCodec::class);

        $bodyEncoder = new BinaryEncoder();
        $bodyEncoder->writeDouble(1.5);
        $bodyEncoder->writeDouble(2.5);
        $bodyBytes = $bodyEncoder->getBuffer();

        $encoder = new BinaryEncoder();
        $encoder->writeNodeId($typeId);
        $encoder->writeByte(0x01);
        $encoder->writeInt32(strlen($bodyBytes));
        $encoder->writeRawBytes($bodyBytes);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readExtensionObject();

        expect($result)->toBeArray();
        expect($result['x'])->toBe(1.5);
        expect($result['y'])->toBe(2.5);
    });

    it('returns raw array when no codec is registered', function () {
        $typeId = NodeId::numeric(2, 999);

        $encoder = new BinaryEncoder();
        $encoder->writeNodeId($typeId);
        $encoder->writeByte(0x01);
        $encoder->writeByteString('some-binary-data');

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readExtensionObject();

        expect($result)->toBeArray();
        expect($result)->toHaveKeys(['typeId', 'encoding', 'body']);
        expect($result['encoding'])->toBe(0x01);
        expect($result['body'])->toBe('some-binary-data');
    });

    it('returns raw array for XML encoding even with codec registered', function () {
        $typeId = NodeId::numeric(2, 500);
        ExtensionObjectRepository::register($typeId, TestPointCodec::class);

        $encoder = new BinaryEncoder();
        $encoder->writeNodeId($typeId);
        $encoder->writeByte(0x02);
        $encoder->writeString('<Point><X>1</X></Point>');

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readExtensionObject();

        expect($result)->toBeArray();
        expect($result)->toHaveKeys(['typeId', 'encoding', 'body']);
        expect($result['encoding'])->toBe(0x02);
    });

    it('returns raw array for no-body encoding', function () {
        $typeId = NodeId::numeric(2, 500);

        $encoder = new BinaryEncoder();
        $encoder->writeNodeId($typeId);
        $encoder->writeByte(0x00);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readExtensionObject();

        expect($result)->toBeArray();
        expect($result['body'])->toBeNull();
    });

    it('codec encode/decode round-trips', function () {
        $codec = new TestPointCodec();

        $encoder = new BinaryEncoder();
        $codec->encode($encoder, ['x' => 3.14, 'y' => 2.71]);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $codec->decode($decoder);

        expect($result['x'])->toBe(3.14);
        expect($result['y'])->toBe(2.71);
    });
});
