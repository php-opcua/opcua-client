<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\Variant;

describe('BinaryDecoder skip()', function () {

    it('skips the requested number of bytes', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeUInt32(111);
        $encoder->writeUInt32(222);
        $encoder->writeUInt32(333);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $decoder->skip(4); // skip first UInt32
        expect($decoder->readUInt32())->toBe(222);
    });

    it('throws on skip beyond buffer', function () {
        $decoder = new BinaryDecoder("\x01\x02");
        expect(fn() => $decoder->skip(10))
            ->toThrow(\Gianfriaur\OpcuaPhpClient\Exception\EncodingException::class);
    });
});

describe('BinaryDecoder readDiagnosticInfo()', function () {

    it('reads empty diagnostic info (mask 0)', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeByte(0x00); // empty mask

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $info = $decoder->readDiagnosticInfo();
        expect($info)->toBe([]);
    });

    it('reads diagnostic info with all fields', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeByte(0x1F); // all except innerDiag
        $encoder->writeInt32(10);           // symbolicId
        $encoder->writeInt32(20);           // namespaceUri
        $encoder->writeInt32(30);           // locale
        $encoder->writeString('extra info'); // additionalInfo
        $encoder->writeUInt32(0x80010000);  // innerStatusCode

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $info = $decoder->readDiagnosticInfo();
        expect($info['symbolicId'])->toBe(10);
        expect($info['namespaceUri'])->toBe(20);
        expect($info['locale'])->toBe(30);
        expect($info['additionalInfo'])->toBe('extra info');
        expect($info['innerStatusCode'])->toBe(0x80010000);
    });

    it('reads diagnostic info with inner diagnostic info', function () {
        $encoder = new BinaryEncoder();
        // Outer: has symbolicId + innerDiagnosticInfo
        $encoder->writeByte(0x21);
        $encoder->writeInt32(42); // symbolicId
        // Inner: has additionalInfo only
        $encoder->writeByte(0x08);
        $encoder->writeString('inner details');

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $info = $decoder->readDiagnosticInfo();
        expect($info['symbolicId'])->toBe(42);
        expect($info['innerDiagnosticInfo'])->toBeArray();
        expect($info['innerDiagnosticInfo']['additionalInfo'])->toBe('inner details');
    });
});

describe('BinaryDecoder readExpandedNodeId() with flags', function () {

    it('reads ExpandedNodeId with namespace URI', function () {
        $encoder = new BinaryEncoder();
        // Encoding: 0x80 (hasNamespaceUri) | 0x00 (TwoByte NodeId)
        $encoder->writeByte(0x80);
        $encoder->writeByte(42); // NodeId identifier
        $encoder->writeString('urn:test:namespace'); // namespace URI

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readExpandedNodeId();
        expect($result->getIdentifier())->toBe(42);
    });

    it('reads ExpandedNodeId with server index', function () {
        $encoder = new BinaryEncoder();
        // Encoding: 0x40 (hasServerIndex) | 0x00 (TwoByte NodeId)
        $encoder->writeByte(0x40);
        $encoder->writeByte(99); // NodeId identifier
        $encoder->writeUInt32(5); // server index

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readExpandedNodeId();
        expect($result->getIdentifier())->toBe(99);
    });

    it('reads ExpandedNodeId with both namespace URI and server index', function () {
        $encoder = new BinaryEncoder();
        // Encoding: 0xC0 (hasNamespaceUri + hasServerIndex) | 0x00 (TwoByte)
        $encoder->writeByte(0xC0);
        $encoder->writeByte(7);
        $encoder->writeString('urn:test:ns');
        $encoder->writeUInt32(3);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readExpandedNodeId();
        expect($result->getIdentifier())->toBe(7);
    });
});

describe('ExtensionObject with XML body', function () {

    it('round-trips ExtensionObject with XML encoding', function () {
        $ext = [
            'typeId' => NodeId::numeric(0, 200),
            'encoding' => 0x02,
            'body' => '<root>test</root>',
        ];
        $encoder = new BinaryEncoder();
        $encoder->writeExtensionObject($ext);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readExtensionObject();
        expect($result['encoding'])->toBe(0x02);
        expect($result['body'])->toBe('<root>test</root>');
    });
});

describe('Variant containing complex types', function () {

    it('round-trips Variant containing ExtensionObject', function () {
        // Build the raw bytes manually to test decoder path
        $encoder = new BinaryEncoder();
        // Variant encoding byte: ExtensionObject type = 22
        $encoder->writeByte(BuiltinType::ExtensionObject->value);
        // ExtensionObject: typeId + encoding + body
        $encoder->writeNodeId(NodeId::numeric(0, 100));
        $encoder->writeByte(0x01); // binary body
        $encoder->writeByteString("\xAA\xBB");

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getType())->toBe(BuiltinType::ExtensionObject);
        $value = $result->getValue();
        expect($value['encoding'])->toBe(0x01);
        expect($value['body'])->toBe("\xAA\xBB");
    });

    it('round-trips Variant containing DataValue', function () {
        $dv = new DataValue(new Variant(BuiltinType::Int32, 99));
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::DataValue, $dv));

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getType())->toBe(BuiltinType::DataValue);
        $decoded = $result->getValue();
        expect($decoded)->toBeInstanceOf(DataValue::class);
        expect($decoded->getValue())->toBe(99);
    });

    it('round-trips nested Variant', function () {
        $inner = new Variant(BuiltinType::String, 'nested');
        $encoder = new BinaryEncoder();
        $encoder->writeVariant(new Variant(BuiltinType::Variant, $inner));

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getType())->toBe(BuiltinType::Variant);
        $inner = $result->getValue();
        expect($inner)->toBeInstanceOf(Variant::class);
        expect($inner->getValue())->toBe('nested');
    });
});

describe('DateTime edge cases', function () {

    it('returns null for negative ticks', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeInt64(-1);
        $decoder = new BinaryDecoder($encoder->getBuffer());
        expect($decoder->readDateTime())->toBeNull();
    });

    it('returns null for zero ticks', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeInt64(0);
        $decoder = new BinaryDecoder($encoder->getBuffer());
        expect($decoder->readDateTime())->toBeNull();
    });

    it('handles DateTime near epoch boundary (negative microseconds path)', function () {
        // A timestamp just before Unix epoch would have negative microseconds
        // OPC UA ticks for 1969-12-31 23:59:59.999999 = (11644473600 - 0.000001) * 10_000_000
        // = 116444735999999990
        $ticks = 116444735999999990;
        $encoder = new BinaryEncoder();
        $encoder->writeInt64($ticks);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readDateTime();
        expect($result)->toBeInstanceOf(DateTimeImmutable::class);
        // Should be very close to Unix epoch
        $ts = (int) $result->format('U');
        expect($ts)->toBeLessThanOrEqual(0);
    });
});

describe('ReferenceDescription round-trip', function () {

    it('round-trips a complete ReferenceDescription via binary', function () {
        $encoder = new BinaryEncoder();

        // ReferenceTypeId
        $encoder->writeNodeId(NodeId::numeric(0, 35));
        // IsForward
        $encoder->writeBoolean(true);
        // TargetNodeId (ExpandedNodeId)
        $encoder->writeExpandedNodeId(NodeId::numeric(1, 1000));
        // BrowseName (QualifiedName)
        $encoder->writeUInt16(1);
        $encoder->writeString('TestVar');
        // DisplayName (LocalizedText)
        $encoder->writeByte(0x02); // has text only
        $encoder->writeString('Test Variable');
        // NodeClass
        $encoder->writeUInt32(2); // Variable
        // TypeDefinition (ExpandedNodeId)
        $encoder->writeExpandedNodeId(NodeId::numeric(0, 62));

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $ref = $decoder->readReferenceDescription();

        expect($ref->getReferenceTypeId()->getIdentifier())->toBe(35);
        expect($ref->isForward())->toBeTrue();
        expect($ref->getNodeId()->getIdentifier())->toBe(1000);
        expect($ref->getBrowseName()->getName())->toBe('TestVar');
        expect((string) $ref->getDisplayName())->toBe('Test Variable');
        expect($ref->getNodeClass())->toBe(\Gianfriaur\OpcuaPhpClient\Types\NodeClass::Variable);
        expect($ref->getTypeDefinition()->getIdentifier())->toBe(62);
    });
});

describe('Multi-dimensional array variant', function () {

    it('decodes array variant with multi-dimensions', function () {
        $encoder = new BinaryEncoder();
        // Encoding byte: Int32 (6) | 0x80 (array) | 0x40 (multi-dim)
        $encoder->writeByte(BuiltinType::Int32->value | 0x80 | 0x40);
        // Array length
        $encoder->writeInt32(4);
        // Values
        $encoder->writeInt32(1);
        $encoder->writeInt32(2);
        $encoder->writeInt32(3);
        $encoder->writeInt32(4);
        // Dimensions
        $encoder->writeInt32(2); // 2 dimensions
        $encoder->writeInt32(2); // dim[0] = 2
        $encoder->writeInt32(2); // dim[1] = 2

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readVariant();
        expect($result->getType())->toBe(BuiltinType::Int32);
        expect($result->getValue())->toBe([1, 2, 3, 4]);
    });
});

describe('DataValue with picoseconds', function () {

    it('decodes DataValue with source and server picoseconds', function () {
        $encoder = new BinaryEncoder();
        // mask: value(0x01) + source timestamp(0x04) + source pico(0x10) + server timestamp(0x08) + server pico(0x20)
        $encoder->writeByte(0x01 | 0x04 | 0x10 | 0x08 | 0x20);
        // Value (Int32 variant)
        $encoder->writeByte(BuiltinType::Int32->value);
        $encoder->writeInt32(42);
        // Source timestamp
        $dt = new DateTimeImmutable('2024-06-15 12:00:00');
        $unixTimestamp = (float) $dt->format('U.u');
        $epochOffset = 11644473600;
        $ticks = (int) (($unixTimestamp + $epochOffset) * 10_000_000);
        $encoder->writeInt64($ticks);
        // Source picoseconds
        $encoder->writeUInt16(500);
        // Server timestamp
        $encoder->writeInt64($ticks);
        // Server picoseconds
        $encoder->writeUInt16(600);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $decoder->readDataValue();
        expect($result->getValue())->toBe(42);
        expect($result->getSourceTimestamp())->toBeInstanceOf(DateTimeImmutable::class);
        expect($result->getServerTimestamp())->toBeInstanceOf(DateTimeImmutable::class);
    });
});
