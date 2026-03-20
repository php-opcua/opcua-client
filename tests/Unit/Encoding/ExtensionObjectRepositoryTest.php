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

describe('ExtensionObjectRepository', function () {

    it('has no codecs by default', function () {
        $repo = new ExtensionObjectRepository();
        expect($repo->has(NodeId::numeric(0, 100)))->toBeFalse();
        expect($repo->get(NodeId::numeric(0, 100)))->toBeNull();
    });

    it('registers a codec by class name', function () {
        $repo = new ExtensionObjectRepository();
        $repo->register(NodeId::numeric(0, 100), TestPointCodec::class);
        expect($repo->has(NodeId::numeric(0, 100)))->toBeTrue();
        expect($repo->get(NodeId::numeric(0, 100)))->toBeInstanceOf(TestPointCodec::class);
    });

    it('registers a codec by instance', function () {
        $repo = new ExtensionObjectRepository();
        $codec = new TestPointCodec();
        $repo->register(NodeId::numeric(0, 200), $codec);
        expect($repo->get(NodeId::numeric(0, 200)))->toBe($codec);
    });

    it('unregisters a codec', function () {
        $repo = new ExtensionObjectRepository();
        $repo->register(NodeId::numeric(0, 100), TestPointCodec::class);
        expect($repo->has(NodeId::numeric(0, 100)))->toBeTrue();

        $repo->unregister(NodeId::numeric(0, 100));
        expect($repo->has(NodeId::numeric(0, 100)))->toBeFalse();
    });

    it('clear removes all codecs', function () {
        $repo = new ExtensionObjectRepository();
        $repo->register(NodeId::numeric(0, 100), TestPointCodec::class);
        $repo->register(NodeId::numeric(0, 200), TestPointCodec::class);

        $repo->clear();
        expect($repo->has(NodeId::numeric(0, 100)))->toBeFalse();
        expect($repo->has(NodeId::numeric(0, 200)))->toBeFalse();
    });

    it('different typeIds are independent', function () {
        $repo = new ExtensionObjectRepository();
        $repo->register(NodeId::numeric(0, 100), TestPointCodec::class);
        expect($repo->has(NodeId::numeric(0, 100)))->toBeTrue();
        expect($repo->has(NodeId::numeric(0, 200)))->toBeFalse();
    });

    it('supports string NodeIds', function () {
        $repo = new ExtensionObjectRepository();
        $repo->register(NodeId::string(2, 'MyType'), TestPointCodec::class);
        expect($repo->has(NodeId::string(2, 'MyType')))->toBeTrue();
        expect($repo->has(NodeId::string(2, 'OtherType')))->toBeFalse();
    });

    it('Client exposes its repository via getExtensionObjectRepository', function () {
        $repo = new ExtensionObjectRepository();
        $repo->register(NodeId::numeric(0, 100), TestPointCodec::class);

        $client = new \Gianfriaur\OpcuaPhpClient\Client($repo);
        expect($client->getExtensionObjectRepository())->toBe($repo);
        expect($client->getExtensionObjectRepository()->has(NodeId::numeric(0, 100)))->toBeTrue();
    });

    it('Client creates empty repository when none provided', function () {
        $client = new \Gianfriaur\OpcuaPhpClient\Client();
        $repo = $client->getExtensionObjectRepository();
        expect($repo)->toBeInstanceOf(ExtensionObjectRepository::class);
        expect($repo->has(NodeId::numeric(0, 100)))->toBeFalse();
    });

    it('codecs registered via getExtensionObjectRepository are used by the client', function () {
        $client = new \Gianfriaur\OpcuaPhpClient\Client();
        $client->getExtensionObjectRepository()->register(NodeId::numeric(0, 100), TestPointCodec::class);

        expect($client->getExtensionObjectRepository()->has(NodeId::numeric(0, 100)))->toBeTrue();
    });

    it('two repositories are isolated from each other', function () {
        $repo1 = new ExtensionObjectRepository();
        $repo2 = new ExtensionObjectRepository();

        $repo1->register(NodeId::numeric(0, 100), TestPointCodec::class);

        expect($repo1->has(NodeId::numeric(0, 100)))->toBeTrue();
        expect($repo2->has(NodeId::numeric(0, 100)))->toBeFalse();
    });
});

describe('ExtensionObject decoding with codec', function () {

    it('decodes ExtensionObject using registered codec', function () {
        $repo = new ExtensionObjectRepository();
        $typeId = NodeId::numeric(2, 500);
        $repo->register($typeId, TestPointCodec::class);

        $bodyEncoder = new BinaryEncoder();
        $bodyEncoder->writeDouble(1.5);
        $bodyEncoder->writeDouble(2.5);
        $bodyBytes = $bodyEncoder->getBuffer();

        $encoder = new BinaryEncoder();
        $encoder->writeNodeId($typeId);
        $encoder->writeByte(0x01);
        $encoder->writeInt32(strlen($bodyBytes));
        $encoder->writeRawBytes($bodyBytes);

        $decoder = new BinaryDecoder($encoder->getBuffer(), $repo);
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
        $repo = new ExtensionObjectRepository();
        $typeId = NodeId::numeric(2, 500);
        $repo->register($typeId, TestPointCodec::class);

        $encoder = new BinaryEncoder();
        $encoder->writeNodeId($typeId);
        $encoder->writeByte(0x02);
        $encoder->writeString('<Point><X>1</X></Point>');

        $decoder = new BinaryDecoder($encoder->getBuffer(), $repo);
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

    it('returns raw array when decoder has no repository', function () {
        $typeId = NodeId::numeric(2, 500);

        $bodyEncoder = new BinaryEncoder();
        $bodyEncoder->writeDouble(1.5);
        $bodyEncoder->writeDouble(2.5);
        $bodyBytes = $bodyEncoder->getBuffer();

        $encoder = new BinaryEncoder();
        $encoder->writeNodeId($typeId);
        $encoder->writeByte(0x01);
        $encoder->writeByteString($bodyBytes);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readExtensionObject();

        expect($result)->toBeArray();
        expect($result)->toHaveKeys(['typeId', 'encoding', 'body']);
    });
});
