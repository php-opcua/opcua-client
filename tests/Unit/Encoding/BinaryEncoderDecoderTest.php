<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Exception\EncodingException;
use Gianfriaur\OpcuaPhpClient\Types\LocalizedText;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\QualifiedName;

describe('BinaryEncoder/Decoder round-trip', function () {

    it('encodes and decodes Boolean', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeBoolean(true);
        $encoder->writeBoolean(false);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        expect($decoder->readBoolean())->toBeTrue();
        expect($decoder->readBoolean())->toBeFalse();
    });

    it('encodes and decodes Byte', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeByte(0);
        $encoder->writeByte(255);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        expect($decoder->readByte())->toBe(0);
        expect($decoder->readByte())->toBe(255);
    });

    it('encodes and decodes SByte', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeSByte(-128);
        $encoder->writeSByte(127);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        expect($decoder->readSByte())->toBe(-128);
        expect($decoder->readSByte())->toBe(127);
    });

    it('encodes and decodes UInt16', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeUInt16(0);
        $encoder->writeUInt16(65535);
        $encoder->writeUInt16(1234);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        expect($decoder->readUInt16())->toBe(0);
        expect($decoder->readUInt16())->toBe(65535);
        expect($decoder->readUInt16())->toBe(1234);
    });

    it('encodes and decodes Int16', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeInt16(-32768);
        $encoder->writeInt16(32767);
        $encoder->writeInt16(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        expect($decoder->readInt16())->toBe(-32768);
        expect($decoder->readInt16())->toBe(32767);
        expect($decoder->readInt16())->toBe(0);
    });

    it('encodes and decodes UInt32', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeUInt32(0);
        $encoder->writeUInt32(4294967295);
        $encoder->writeUInt32(42);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        expect($decoder->readUInt32())->toBe(0);
        expect($decoder->readUInt32())->toBe(4294967295);
        expect($decoder->readUInt32())->toBe(42);
    });

    it('encodes and decodes Int32', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeInt32(-1);
        $encoder->writeInt32(0);
        $encoder->writeInt32(2147483647);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        expect($decoder->readInt32())->toBe(-1);
        expect($decoder->readInt32())->toBe(0);
        expect($decoder->readInt32())->toBe(2147483647);
    });

    it('encodes and decodes Int64', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeInt64(0);
        $encoder->writeInt64(-1);
        $encoder->writeInt64(123456789012345);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        expect($decoder->readInt64())->toBe(0);
        expect($decoder->readInt64())->toBe(-1);
        expect($decoder->readInt64())->toBe(123456789012345);
    });

    it('encodes and decodes Float', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeFloat(3.14);
        $encoder->writeFloat(0.0);
        $encoder->writeFloat(-1.5);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        expect($decoder->readFloat())->toBeFloat()->toEqualWithDelta(3.14, 0.001);
        expect($decoder->readFloat())->toBe(0.0);
        expect($decoder->readFloat())->toBe(-1.5);
    });

    it('encodes and decodes Double', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeDouble(3.141592653589793);
        $encoder->writeDouble(0.0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        expect($decoder->readDouble())->toBe(3.141592653589793);
        expect($decoder->readDouble())->toBe(0.0);
    });

    it('encodes and decodes String', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeString('Hello, OPC UA!');
        $encoder->writeString('');
        $encoder->writeString(null);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        expect($decoder->readString())->toBe('Hello, OPC UA!');
        expect($decoder->readString())->toBe('');
        expect($decoder->readString())->toBeNull();
    });

    it('encodes and decodes ByteString', function () {
        $encoder = new BinaryEncoder();
        $bytes = "\x00\x01\x02\xFF";
        $encoder->writeByteString($bytes);
        $encoder->writeByteString(null);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        expect($decoder->readByteString())->toBe($bytes);
        expect($decoder->readByteString())->toBeNull();
    });

    it('encodes and decodes DateTime', function () {
        $encoder = new BinaryEncoder();
        $date = new DateTimeImmutable('2024-01-15T10:30:00Z');
        $encoder->writeDateTime($date);
        $encoder->writeDateTime(null);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $decoded = $decoder->readDateTime();
        expect($decoded)->not->toBeNull();
        expect($decoded->format('Y-m-d H:i:s'))->toBe('2024-01-15 10:30:00');
        expect($decoder->readDateTime())->toBeNull();
    });

    it('encodes and decodes NodeId TwoByte', function () {
        $nodeId = NodeId::numeric(0, 42);

        $encoder = new BinaryEncoder();
        $encoder->writeNodeId($nodeId);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $decoded = $decoder->readNodeId();

        expect($decoded->getNamespaceIndex())->toBe(0);
        expect($decoded->getIdentifier())->toBe(42);
        expect($encoder->getSize())->toBe(2); // TwoByte encoding
    });

    it('encodes and decodes NodeId FourByte', function () {
        $nodeId = NodeId::numeric(1, 1000);

        $encoder = new BinaryEncoder();
        $encoder->writeNodeId($nodeId);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $decoded = $decoder->readNodeId();

        expect($decoded->getNamespaceIndex())->toBe(1);
        expect($decoded->getIdentifier())->toBe(1000);
        expect($encoder->getSize())->toBe(4); // FourByte encoding
    });

    it('encodes and decodes NodeId Numeric (large)', function () {
        $nodeId = NodeId::numeric(256, 100000);

        $encoder = new BinaryEncoder();
        $encoder->writeNodeId($nodeId);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $decoded = $decoder->readNodeId();

        expect($decoded->getNamespaceIndex())->toBe(256);
        expect($decoded->getIdentifier())->toBe(100000);
    });

    it('encodes and decodes NodeId String', function () {
        $nodeId = NodeId::string(2, 'MyVariable');

        $encoder = new BinaryEncoder();
        $encoder->writeNodeId($nodeId);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $decoded = $decoder->readNodeId();

        expect($decoded->getNamespaceIndex())->toBe(2);
        expect($decoded->getIdentifier())->toBe('MyVariable');
        expect($decoded->isString())->toBeTrue();
    });

    it('encodes and decodes QualifiedName', function () {
        $name = new QualifiedName(1, 'Temperature');

        $encoder = new BinaryEncoder();
        $encoder->writeQualifiedName($name);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $decoded = $decoder->readQualifiedName();

        expect($decoded->getNamespaceIndex())->toBe(1);
        expect($decoded->getName())->toBe('Temperature');
    });

    it('encodes and decodes LocalizedText', function () {
        $text = new LocalizedText('en', 'Hello World');

        $encoder = new BinaryEncoder();
        $encoder->writeLocalizedText($text);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $decoded = $decoder->readLocalizedText();

        expect($decoded->getLocale())->toBe('en');
        expect($decoded->getText())->toBe('Hello World');
    });

    it('encodes and decodes LocalizedText with no locale', function () {
        $text = new LocalizedText(null, 'No locale');

        $encoder = new BinaryEncoder();
        $encoder->writeLocalizedText($text);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $decoded = $decoder->readLocalizedText();

        expect($decoded->getLocale())->toBeNull();
        expect($decoded->getText())->toBe('No locale');
    });

    it('throws on buffer underflow', function () {
        $decoder = new BinaryDecoder('');
        $decoder->readByte();
    })->throws(EncodingException::class);
});
