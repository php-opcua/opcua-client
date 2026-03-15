<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Exception\EncodingException;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\LocalizedText;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\QualifiedName;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;
use Gianfriaur\OpcuaPhpClient\Types\Variant;

describe('Variant encoding/decoding', function () {

    it('round-trips scalar Boolean variant', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::Boolean, true));
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getType())->toBe(BuiltinType::Boolean);
        expect($result->getValue())->toBeTrue();
    });

    it('round-trips scalar Int32 variant', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::Int32, -42));
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getType())->toBe(BuiltinType::Int32);
        expect($result->getValue())->toBe(-42);
    });

    it('round-trips scalar Double variant', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::Double, 3.14159));
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getValue())->toBe(3.14159);
    });

    it('round-trips scalar String variant', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::String, 'hello world'));
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getValue())->toBe('hello world');
    });

    it('round-trips scalar Float variant', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::Float, 1.5));
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getValue())->toBeFloat();
    });

    it('round-trips scalar Byte variant', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::Byte, 255));
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getValue())->toBe(255);
    });

    it('round-trips scalar SByte variant', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::SByte, -128));
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getValue())->toBe(-128);
    });

    it('round-trips scalar UInt16 variant', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::UInt16, 65535));
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getValue())->toBe(65535);
    });

    it('round-trips scalar Int16 variant', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::Int16, -1000));
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getValue())->toBe(-1000);
    });

    it('round-trips scalar UInt32 variant', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::UInt32, 100000));
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getValue())->toBe(100000);
    });

    it('round-trips scalar Int64 variant', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::Int64, 123456789012));
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getValue())->toBe(123456789012);
    });

    it('round-trips scalar UInt64 variant', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::UInt64, 999999999));
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getValue())->toBe(999999999);
    });

    it('round-trips scalar DateTime variant', function () {
        $dt = new DateTimeImmutable('2024-06-15 12:30:00');
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::DateTime, $dt));
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getType())->toBe(BuiltinType::DateTime);
        expect($result->getValue())->toBeInstanceOf(DateTimeImmutable::class);
    });

    it('round-trips scalar Guid variant', function () {
        $guid = '12345678-1234-5678-9abc-def012345678';
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::Guid, $guid));
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getType())->toBe(BuiltinType::Guid);
        expect($result->getValue())->toBe($guid);
    });

    it('round-trips scalar ByteString variant', function () {
        $data = "\x00\x01\x02\xFF";
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::ByteString, $data));
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getValue())->toBe($data);
    });

    it('round-trips scalar XmlElement variant', function () {
        $xml = '<root><child>value</child></root>';
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::XmlElement, $xml));
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getValue())->toBe($xml);
    });

    it('round-trips scalar NodeId variant', function () {
        $nodeId = NodeId::numeric(2, 1000);
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::NodeId, $nodeId));
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getType())->toBe(BuiltinType::NodeId);
        $decoded = $result->getValue();
        expect($decoded->getNamespaceIndex())->toBe(2);
        expect($decoded->getIdentifier())->toBe(1000);
    });

    it('round-trips scalar ExpandedNodeId variant', function () {
        $nodeId = NodeId::numeric(0, 50);
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::ExpandedNodeId, $nodeId));
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getType())->toBe(BuiltinType::ExpandedNodeId);
    });

    it('round-trips scalar StatusCode variant', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::StatusCode, StatusCode::BadNodeIdUnknown));
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getValue())->toBe(StatusCode::BadNodeIdUnknown);
    });

    it('round-trips scalar QualifiedName variant', function () {
        $qn = new QualifiedName(1, 'TestName');
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::QualifiedName, $qn));
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getType())->toBe(BuiltinType::QualifiedName);
        $decoded = $result->getValue();
        expect($decoded->getNamespaceIndex())->toBe(1);
        expect($decoded->getName())->toBe('TestName');
    });

    it('round-trips scalar LocalizedText variant', function () {
        $lt = new LocalizedText('en', 'Hello');
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::LocalizedText, $lt));
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getType())->toBe(BuiltinType::LocalizedText);
        $decoded = $result->getValue();
        expect($decoded->getLocale())->toBe('en');
        expect($decoded->getText())->toBe('Hello');
    });

    it('round-trips array Int32 variant', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::Int32, [10, 20, 30]));
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getValue())->toBe([10, 20, 30]);
    });

    it('round-trips array Double variant', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::Double, [1.1, 2.2, 3.3]));
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getValue())->toBe([1.1, 2.2, 3.3]);
    });

    it('round-trips array String variant', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::String, ['a', 'b', 'c']));
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getValue())->toBe(['a', 'b', 'c']);
    });

    it('round-trips array Boolean variant', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::Boolean, [true, false, true]));
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getValue())->toBe([true, false, true]);
    });

    it('throws EncodingException for DiagnosticInfo encoding', function () {
        $encoder = new BinaryEncoder();
        expect(fn() => $encoder->writeVariant(new Variant(BuiltinType::DiagnosticInfo, null)))
            ->toThrow(EncodingException::class);
    });

    it('throws EncodingException for unknown variant type on decode', function () {
        // Type ID 0 is invalid for variant
        $data = pack('C', 0);
        $decoder = new BinaryDecoder($data);
        expect(fn() => $decoder->readVariant())
            ->toThrow(EncodingException::class);
    });
});

describe('DataValue encoding/decoding', function () {

    it('round-trips DataValue with value only', function () {
        $dv = new DataValue(new Variant(BuiltinType::Int32, 42));
        $encoder = new BinaryEncoder();
        $encoder->writeDataValue($dv);
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readDataValue();
        expect($result->getValue())->toBe(42);
        expect($result->getStatusCode())->toBe(0);
    });

    it('round-trips DataValue with status code', function () {
        $dv = new DataValue(
            new Variant(BuiltinType::Double, 3.14),
            StatusCode::BadNotWritable,
        );
        $encoder = new BinaryEncoder();
        $encoder->writeDataValue($dv);
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readDataValue();
        expect($result->getValue())->toBe(3.14);
        expect($result->getStatusCode())->toBe(StatusCode::BadNotWritable);
    });

    it('round-trips DataValue with timestamps', function () {
        $src = new DateTimeImmutable('2024-01-15 10:30:00');
        $srv = new DateTimeImmutable('2024-01-15 10:30:01');
        $dv = new DataValue(
            new Variant(BuiltinType::String, 'test'),
            0,
            $src,
            $srv,
        );
        $encoder = new BinaryEncoder();
        $encoder->writeDataValue($dv);
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readDataValue();
        expect($result->getValue())->toBe('test');
        expect($result->getSourceTimestamp())->toBeInstanceOf(DateTimeImmutable::class);
        expect($result->getServerTimestamp())->toBeInstanceOf(DateTimeImmutable::class);
    });

    it('round-trips empty DataValue', function () {
        $dv = new DataValue();
        $encoder = new BinaryEncoder();
        $encoder->writeDataValue($dv);
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readDataValue();
        expect($result->getValue())->toBeNull();
        expect($result->getStatusCode())->toBe(0);
    });
});

describe('ExtensionObject encoding', function () {

    it('round-trips ExtensionObject with binary body', function () {
        $ext = [
            'typeId' => NodeId::numeric(0, 100),
            'encoding' => 0x01,
            'body' => "\x01\x02\x03",
        ];
        $encoder = new BinaryEncoder();
        $encoder->writeExtensionObject($ext);
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readExtensionObject();
        expect($result['encoding'])->toBe(0x01);
        expect($result['body'])->toBe("\x01\x02\x03");
    });

    it('round-trips ExtensionObject with no body', function () {
        $ext = [
            'typeId' => NodeId::numeric(0, 0),
            'encoding' => 0x00,
            'body' => null,
        ];
        $encoder = new BinaryEncoder();
        $encoder->writeExtensionObject($ext);
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readExtensionObject();
        expect($result['encoding'])->toBe(0x00);
    });
});

describe('Guid encoding', function () {

    it('round-trips GUID via encoder/decoder', function () {
        $guid = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
        $encoder = new BinaryEncoder();
        $encoder->writeGuid($guid);
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readGuid();
        expect($result)->toBe($guid);
    });
});

describe('NodeId encoding extended', function () {

    it('round-trips Guid NodeId', function () {
        $guid = '12345678-1234-5678-9abc-def012345678';
        $node = NodeId::guid(1, $guid);
        $encoder = new BinaryEncoder();
        $encoder->writeNodeId($node);
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readNodeId();
        expect($result->isGuid())->toBeTrue();
        expect($result->getIdentifier())->toBe($guid);
        expect($result->getNamespaceIndex())->toBe(1);
    });

    it('round-trips Opaque NodeId', function () {
        $node = NodeId::opaque(2, 'deadbeef');
        $encoder = new BinaryEncoder();
        $encoder->writeNodeId($node);
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readNodeId();
        expect($result->isOpaque())->toBeTrue();
        expect($result->getNamespaceIndex())->toBe(2);
    });
});

describe('Encoder misc', function () {

    it('getSize returns buffer length', function () {
        $encoder = new BinaryEncoder();
        expect($encoder->getSize())->toBe(0);
        $encoder->writeBoolean(true);
        expect($encoder->getSize())->toBe(1);
        $encoder->writeUInt32(100);
        expect($encoder->getSize())->toBe(5);
    });

    it('reset clears the buffer', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeUInt32(100);
        expect($encoder->getSize())->toBe(4);
        $encoder->reset();
        expect($encoder->getSize())->toBe(0);
        expect($encoder->getBuffer())->toBe('');
    });

    it('writeDateTime with null writes zero', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeDateTime(null);
        $decoder = new BinaryDecoder($encoder->getBuffer());
        expect($decoder->readInt64())->toBe(0);
    });

    it('writeString with null writes -1 length', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeString(null);
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $len = $decoder->readInt32();
        // -1 encoded as unsigned is 0xFFFFFFFF
        expect($len)->toBe(-1);
    });

    it('decoder getRemainingLength works', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeUInt32(1);
        $encoder->writeUInt32(2);
        $decoder = new BinaryDecoder($encoder->getBuffer());
        expect($decoder->getRemainingLength())->toBe(8);
        $decoder->readUInt32();
        expect($decoder->getRemainingLength())->toBe(4);
    });

    it('ExpandedNodeId round-trip', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeExpandedNodeId(NodeId::numeric(0, 42));
        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readNodeId(); // ExpandedNodeId reads same as NodeId
        expect($result->getIdentifier())->toBe(42);
    });
});
