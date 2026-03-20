<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Encoding;

use DateTimeImmutable;
use Gianfriaur\OpcuaPhpClient\Exception\EncodingException;
use Gianfriaur\OpcuaPhpClient\Repository\ExtensionObjectRepository;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;

use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\LocalizedText;
use Gianfriaur\OpcuaPhpClient\Types\NodeClass;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\QualifiedName;
use Gianfriaur\OpcuaPhpClient\Types\ReferenceDescription;
use Gianfriaur\OpcuaPhpClient\Types\Variant;

class BinaryDecoder
{
    private int $offset = 0;

    /**
     * @param string $buffer
     * @param ?ExtensionObjectRepository $extensionObjectRepository
     */
    public function __construct(
        private readonly string                     $buffer,
        private readonly ?ExtensionObjectRepository $extensionObjectRepository = null,
    )
    {
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function getRemainingLength(): int
    {
        return strlen($this->buffer) - $this->offset;
    }

    /**
     * @param int $bytes
     */
    private function ensureAvailable(int $bytes): void
    {
        if ($this->offset + $bytes > strlen($this->buffer)) {
            throw new EncodingException("Buffer underflow: need {$bytes} bytes, have {$this->getRemainingLength()}");
        }
    }

    public function readBoolean(): bool
    {
        return $this->readByte() !== 0;
    }

    public function readByte(): int
    {
        $this->ensureAvailable(1);
        $value = ord($this->buffer[$this->offset]);
        $this->offset++;

        return $value;
    }

    public function readSByte(): int
    {
        $this->ensureAvailable(1);
        $data = unpack('c', $this->buffer, $this->offset);
        $this->offset++;

        return $data[1];
    }

    public function readUInt16(): int
    {
        $this->ensureAvailable(2);
        $data = unpack('v', $this->buffer, $this->offset);
        $this->offset += 2;

        return $data[1];
    }

    public function readInt16(): int
    {
        $value = $this->readUInt16();
        if ($value >= 0x8000) {
            $value -= 0x10000;
        }

        return $value;
    }

    public function readUInt32(): int
    {
        $this->ensureAvailable(4);
        $data = unpack('V', $this->buffer, $this->offset);
        $this->offset += 4;

        return $data[1];
    }

    public function readInt32(): int
    {
        $this->ensureAvailable(4);
        $data = unpack('V', $this->buffer, $this->offset);
        $this->offset += 4;
        $value = $data[1];
        if ($value >= 0x80000000) {
            $value -= 0x100000000;
        }

        return $value;
    }

    public function readInt64(): int
    {
        $this->ensureAvailable(8);
        $data = unpack('P', $this->buffer, $this->offset);
        $this->offset += 8;

        return $data[1];
    }

    public function readUInt64(): int
    {
        return $this->readInt64();
    }

    public function readFloat(): float
    {
        $this->ensureAvailable(4);
        $data = unpack('g', $this->buffer, $this->offset);
        $this->offset += 4;

        return $data[1];
    }

    public function readDouble(): float
    {
        $this->ensureAvailable(8);
        $data = unpack('e', $this->buffer, $this->offset);
        $this->offset += 8;

        return $data[1];
    }

    public function readString(): ?string
    {
        $length = $this->readInt32();
        if ($length < 0) {
            return null;
        }
        $this->ensureAvailable($length);
        $value = substr($this->buffer, $this->offset, $length);
        $this->offset += $length;

        return $value;
    }

    public function readByteString(): ?string
    {
        return $this->readString();
    }

    public function readDateTime(): ?DateTimeImmutable
    {
        $ticks = $this->readInt64();
        if ($ticks === 0 || $ticks < 0) {
            return null;
        }

        $epochOffset = 11644473600;
        $unixMicroseconds = (int)($ticks / 10) - ($epochOffset * 1_000_000);
        $seconds = intdiv($unixMicroseconds, 1_000_000);
        $microseconds = $unixMicroseconds % 1_000_000;
        if ($microseconds < 0) {
            $seconds--;
            $microseconds += 1_000_000;
        }

        return DateTimeImmutable::createFromFormat(
            'U u',
            sprintf('%d %06d', $seconds, $microseconds),
        ) ?: null;
    }

    public function readGuid(): string
    {
        $data1 = $this->readUInt32();
        $data2 = $this->readUInt16();
        $data3 = $this->readUInt16();
        $data4 = $this->readRawBytes(8);

        return sprintf(
            '%08x-%04x-%04x-%s-%s',
            $data1,
            $data2,
            $data3,
            bin2hex(substr($data4, 0, 2)),
            bin2hex(substr($data4, 2, 6)),
        );
    }

    public function readNodeId(): NodeId
    {
        $encodingByte = $this->readByte();

        return $this->readNodeIdByEncoding($encodingByte & 0x0F);
    }

    public function readExpandedNodeId(): NodeId
    {
        $encodingByte = $this->readByte();
        $hasNamespaceUri = ($encodingByte & 0x80) !== 0;
        $hasServerIndex = ($encodingByte & 0x40) !== 0;

        $nodeId = $this->readNodeIdByEncoding($encodingByte & 0x0F);

        if ($hasNamespaceUri) {
            $this->readString();
        }
        if ($hasServerIndex) {
            $this->readUInt32();
        }

        return $nodeId;
    }

    /**
     * @param int $encoding
     * @return NodeId
     */
    private function readNodeIdByEncoding(int $encoding): NodeId
    {
        return match ($encoding) {
            0x00 => NodeId::numeric(0, $this->readByte()),
            0x01 => NodeId::numeric($this->readByte(), $this->readUInt16()),
            0x02 => NodeId::numeric($this->readUInt16(), $this->readUInt32()),
            0x03 => NodeId::string($this->readUInt16(), $this->readString() ?? ''),
            0x04 => NodeId::guid($this->readUInt16(), $this->readGuid()),
            0x05 => NodeId::opaque($this->readUInt16(), bin2hex($this->readByteString() ?? '')),
            default => throw new EncodingException("Unknown NodeId encoding: {$encoding}"),
        };
    }

    public function readQualifiedName(): QualifiedName
    {
        $namespaceIndex = $this->readUInt16();
        $name = $this->readString() ?? '';

        return new QualifiedName($namespaceIndex, $name);
    }

    public function readLocalizedText(): LocalizedText
    {
        $mask = $this->readByte();
        $locale = ($mask & 0x01) ? $this->readString() : null;
        $text = ($mask & 0x02) ? $this->readString() : null;

        return new LocalizedText($locale, $text);
    }

    public function readVariant(): Variant
    {
        $encodingByte = $this->readByte();
        $typeId = $encodingByte & 0x3F;
        $isArray = ($encodingByte & 0x80) !== 0;
        $hasMultiDimensions = ($encodingByte & 0x40) !== 0;

        $type = BuiltinType::tryFrom($typeId);
        if ($type === null) {
            throw new EncodingException("Unknown variant type: {$typeId}");
        }

        if ($isArray) {
            $arrayLength = $this->readInt32();
            $values = [];
            for ($i = 0; $i < $arrayLength; $i++) {
                $values[] = $this->readVariantValue($type);
            }

            $dimensions = null;
            if ($hasMultiDimensions) {
                $dimCount = $this->readInt32();
                $dimensions = [];
                for ($i = 0; $i < $dimCount; $i++) {
                    $dimensions[] = $this->readInt32();
                }
            }

            return new Variant($type, $values, $dimensions);
        }

        $value = $this->readVariantValue($type);

        return new Variant($type, $value);
    }

    /**
     * @param BuiltinType $type
     */
    /**
     * Read a single value of the given BuiltinType from the buffer.
     *
     * @param BuiltinType $type The type to read.
     * @return mixed The decoded value.
     */
    public function readVariantValue(BuiltinType $type): mixed
    {
        return match ($type) {
            BuiltinType::Boolean => $this->readBoolean(),
            BuiltinType::SByte => $this->readSByte(),
            BuiltinType::Byte => $this->readByte(),
            BuiltinType::Int16 => $this->readInt16(),
            BuiltinType::UInt16 => $this->readUInt16(),
            BuiltinType::Int32 => $this->readInt32(),
            BuiltinType::UInt32 => $this->readUInt32(),
            BuiltinType::Int64 => $this->readInt64(),
            BuiltinType::UInt64 => $this->readUInt64(),
            BuiltinType::Float => $this->readFloat(),
            BuiltinType::Double => $this->readDouble(),
            BuiltinType::String => $this->readString(),
            BuiltinType::DateTime => $this->readDateTime(),
            BuiltinType::Guid => $this->readGuid(),
            BuiltinType::ByteString => $this->readByteString(),
            BuiltinType::XmlElement => $this->readString(),
            BuiltinType::NodeId => $this->readNodeId(),
            BuiltinType::ExpandedNodeId => $this->readExpandedNodeId(),
            BuiltinType::StatusCode => $this->readUInt32(),
            BuiltinType::QualifiedName => $this->readQualifiedName(),
            BuiltinType::LocalizedText => $this->readLocalizedText(),
            BuiltinType::ExtensionObject => $this->readExtensionObject(),
            BuiltinType::DataValue => $this->readDataValue(),
            BuiltinType::Variant => $this->readVariant(),
            BuiltinType::DiagnosticInfo => $this->readDiagnosticInfo(),
        };
    }

    public function readExtensionObject(): array|object
    {
        $typeId = $this->readNodeId();
        $encoding = $this->readByte();

        if ($encoding === 0x01) {
            $codec = $this->extensionObjectRepository?->get($typeId);
            if ($codec !== null) {
                $bodyLength = $this->readInt32();
                $bodyStart = $this->offset;
                $decoded = $codec->decode($this);
                $consumed = $this->offset - $bodyStart;
                if ($consumed < $bodyLength) {
                    $this->skip($bodyLength - $consumed);
                }
                return $decoded;
            }

            $body = $this->readByteString();
        } elseif ($encoding === 0x02) {
            $body = $this->readString();
        } else {
            $body = null;
        }

        return [
            'typeId' => $typeId,
            'encoding' => $encoding,
            'body' => $body,
        ];
    }

    public function readDiagnosticInfo(): array
    {
        $mask = $this->readByte();
        $info = [];

        if ($mask & 0x01) {
            $info['symbolicId'] = $this->readInt32();
        }
        if ($mask & 0x02) {
            $info['namespaceUri'] = $this->readInt32();
        }
        if ($mask & 0x04) {
            $info['locale'] = $this->readInt32();
        }
        if ($mask & 0x08) {
            $info['additionalInfo'] = $this->readString();
        }
        if ($mask & 0x10) {
            $info['innerStatusCode'] = $this->readUInt32();
        }
        if ($mask & 0x20) {
            $info['innerDiagnosticInfo'] = $this->readDiagnosticInfo();
        }

        return $info;
    }

    public function readDataValue(): DataValue
    {
        $mask = $this->readByte();
        $value = ($mask & 0x01) ? $this->readVariant() : null;
        $statusCode = ($mask & 0x02) ? $this->readUInt32() : 0;
        $sourceTimestamp = ($mask & 0x04) ? $this->readDateTime() : null;
        if ($mask & 0x10) {
            $this->readUInt16();
        }
        $serverTimestamp = ($mask & 0x08) ? $this->readDateTime() : null;
        if ($mask & 0x20) {
            $this->readUInt16();
        }

        return new DataValue($value, $statusCode, $sourceTimestamp, $serverTimestamp);
    }

    public function readReferenceDescription(): ReferenceDescription
    {
        $referenceTypeId = $this->readNodeId();
        $isForward = $this->readBoolean();
        $nodeId = $this->readExpandedNodeId();
        $browseName = $this->readQualifiedName();
        $displayName = $this->readLocalizedText();
        $nodeClassValue = $this->readUInt32();
        $nodeClass = NodeClass::tryFrom($nodeClassValue) ?? NodeClass::Unspecified;
        $typeDefinition = $this->readExpandedNodeId();

        return new ReferenceDescription(
            $referenceTypeId,
            $isForward,
            $nodeId,
            $browseName,
            $displayName,
            $nodeClass,
            $typeDefinition,
        );
    }

    /**
     * @param int $length
     * @return string
     */
    public function readRawBytes(int $length): string
    {
        $this->ensureAvailable($length);
        $value = substr($this->buffer, $this->offset, $length);
        $this->offset += $length;

        return $value;
    }

    /**
     * @param int $bytes
     */
    public function skip(int $bytes): void
    {
        $this->ensureAvailable($bytes);
        $this->offset += $bytes;
    }
}
