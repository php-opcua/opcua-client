<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Encoding;

use DateTimeImmutable;
use Gianfriaur\OpcuaPhpClient\Exception\EncodingException;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\LocalizedText;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\QualifiedName;
use Gianfriaur\OpcuaPhpClient\Types\Variant;

/**
 * OPC UA binary protocol serializer. Writes typed values to a byte buffer.
 */
class BinaryEncoder
{
    private string $buffer = '';

    /**
     * Return the encoded byte buffer.
     */
    public function getBuffer(): string
    {
        return $this->buffer;
    }

    /**
     * Return the current buffer size in bytes.
     */
    public function getSize(): int
    {
        return strlen($this->buffer);
    }

    /**
     * Reset the buffer to empty.
     */
    public function reset(): void
    {
        $this->buffer = '';
    }

    /**
     * @param bool $value
     */
    public function writeBoolean(bool $value): void
    {
        $this->buffer .= pack('C', $value ? 1 : 0);
    }

    /**
     * @param int $value
     */
    public function writeByte(int $value): void
    {
        $this->buffer .= pack('C', $value);
    }

    /**
     * @param int $value
     */
    public function writeSByte(int $value): void
    {
        $this->buffer .= pack('c', $value);
    }

    /**
     * @param int $value
     */
    public function writeUInt16(int $value): void
    {
        $this->buffer .= pack('v', $value);
    }

    /**
     * @param int $value
     */
    public function writeInt16(int $value): void
    {
        $this->buffer .= pack('v', $value & 0xFFFF);
    }

    /**
     * @param int $value
     */
    public function writeUInt32(int $value): void
    {
        $this->buffer .= pack('V', $value);
    }

    /**
     * @param int $value
     */
    public function writeInt32(int $value): void
    {
        $this->buffer .= pack('V', $value & 0xFFFFFFFF);
    }

    /**
     * @param int $value
     */
    public function writeInt64(int $value): void
    {
        $this->buffer .= pack('P', $value);
    }

    /**
     * @param int $value
     */
    public function writeUInt64(int $value): void
    {
        $this->buffer .= pack('P', $value);
    }

    /**
     * @param float $value
     */
    public function writeFloat(float $value): void
    {
        $this->buffer .= pack('g', $value);
    }

    /**
     * @param float $value
     */
    public function writeDouble(float $value): void
    {
        $this->buffer .= pack('e', $value);
    }

    /**
     * @param ?string $value
     */
    public function writeString(?string $value): void
    {
        if ($value === null) {
            $this->writeInt32(-1);
            return;
        }
        $this->writeInt32(strlen($value));
        $this->buffer .= $value;
    }

    /**
     * @param ?string $value
     */
    public function writeByteString(?string $value): void
    {
        $this->writeString($value);
    }

    /**
     * @param ?DateTimeImmutable $value
     */
    public function writeDateTime(?DateTimeImmutable $value): void
    {
        if ($value === null) {
            $this->writeInt64(0);
            return;
        }

        $unixTimestamp = (float)$value->format('U.u');
        $epochOffset = 11644473600;
        $opcuaTimestamp = (int)(($unixTimestamp + $epochOffset) * 10_000_000);
        $this->writeInt64($opcuaTimestamp);
    }

    /**
     * @param string $guid
     */
    public function writeGuid(string $guid): void
    {
        $parts = explode('-', $guid);
        if (count($parts) !== 5) {
            throw new EncodingException("Invalid GUID format: {$guid}");
        }

        $this->writeUInt32((int)hexdec($parts[0]));
        $this->writeUInt16((int)hexdec($parts[1]));
        $this->writeUInt16((int)hexdec($parts[2]));
        $this->writeRawBytes(hex2bin($parts[3] . $parts[4]));
    }

    /**
     * @param NodeId $nodeId
     */
    public function writeNodeId(NodeId $nodeId): void
    {
        $encodingByte = $nodeId->getEncodingByte();
        $identifier = $nodeId->getIdentifier();

        switch ($encodingByte) {
            case 0x00:
                $this->writeByte(0x00);
                $this->writeByte((int)$identifier);
                break;
            case 0x01:
                $this->writeByte(0x01);
                $this->writeByte($nodeId->getNamespaceIndex());
                $this->writeUInt16((int)$identifier);
                break;
            case 0x02:
                $this->writeByte(0x02);
                $this->writeUInt16($nodeId->getNamespaceIndex());
                $this->writeUInt32((int)$identifier);
                break;
            case 0x03:
                $this->writeByte(0x03);
                $this->writeUInt16($nodeId->getNamespaceIndex());
                $this->writeString((string)$identifier);
                break;
            case 0x04:
                $this->writeByte(0x04);
                $this->writeUInt16($nodeId->getNamespaceIndex());
                $this->writeGuid((string)$identifier);
                break;
            case 0x05:
                $this->writeByte(0x05);
                $this->writeUInt16($nodeId->getNamespaceIndex());
                $this->writeByteString(hex2bin((string)$identifier));
                break;
        }
    }

    /**
     * @param NodeId $nodeId
     */
    public function writeExpandedNodeId(NodeId $nodeId): void
    {
        $this->writeNodeId($nodeId);
    }

    /**
     * @param QualifiedName $name
     */
    public function writeQualifiedName(QualifiedName $name): void
    {
        $this->writeUInt16($name->getNamespaceIndex());
        $this->writeString($name->getName());
    }

    /**
     * @param LocalizedText $text
     */
    public function writeLocalizedText(LocalizedText $text): void
    {
        $mask = $text->getEncodingMask();
        $this->writeByte($mask);
        if ($mask & 0x01) {
            $this->writeString($text->getLocale());
        }
        if ($mask & 0x02) {
            $this->writeString($text->getText());
        }
    }

    /**
     * @param Variant $variant
     */
    public function writeVariant(Variant $variant): void
    {
        $type = $variant->getType();
        $value = $variant->getValue();

        if (is_array($value)) {
            $dimensions = $variant->getDimensions();
            $encodingByte = $type->value | 0x80;
            if ($dimensions !== null) {
                $encodingByte |= 0x40;
            }
            $this->writeByte($encodingByte);
            $this->writeInt32(count($value));
            foreach ($value as $item) {
                $this->writeVariantValue($type, $item);
            }
            if ($dimensions !== null) {
                $this->writeInt32(count($dimensions));
                foreach ($dimensions as $dim) {
                    $this->writeInt32($dim);
                }
            }
        } else {
            $this->writeByte($type->value);
            $this->writeVariantValue($type, $value);
        }
    }

    /**
     * @param BuiltinType $type
     * @param mixed $value
     */
    public function writeVariantValue(BuiltinType $type, mixed $value): void
    {
        match ($type) {
            BuiltinType::Boolean => $this->writeBoolean((bool)$value),
            BuiltinType::SByte => $this->writeSByte((int)$value),
            BuiltinType::Byte => $this->writeByte((int)$value),
            BuiltinType::Int16 => $this->writeInt16((int)$value),
            BuiltinType::UInt16 => $this->writeUInt16((int)$value),
            BuiltinType::Int32 => $this->writeInt32((int)$value),
            BuiltinType::UInt32 => $this->writeUInt32((int)$value),
            BuiltinType::Int64 => $this->writeInt64((int)$value),
            BuiltinType::UInt64 => $this->writeUInt64((int)$value),
            BuiltinType::Float => $this->writeFloat((float)$value),
            BuiltinType::Double => $this->writeDouble((float)$value),
            BuiltinType::String => $this->writeString($value),
            BuiltinType::DateTime => $this->writeDateTime($value),
            BuiltinType::Guid => $this->writeGuid((string)$value),
            BuiltinType::ByteString => $this->writeByteString($value),
            BuiltinType::XmlElement => $this->writeString($value),
            BuiltinType::NodeId => $this->writeNodeId($value),
            BuiltinType::ExpandedNodeId => $this->writeExpandedNodeId($value),
            BuiltinType::StatusCode => $this->writeUInt32((int)$value),
            BuiltinType::QualifiedName => $this->writeQualifiedName($value),
            BuiltinType::LocalizedText => $this->writeLocalizedText($value),
            BuiltinType::ExtensionObject => $this->writeExtensionObject($value),
            BuiltinType::DataValue => $this->writeDataValue($value),
            BuiltinType::Variant => $this->writeVariant($value),
            BuiltinType::DiagnosticInfo => throw new EncodingException('DiagnosticInfo encoding not supported'),
        };
    }

    /**
     * @param array $value
     */
    public function writeExtensionObject(array $value): void
    {
        $this->writeNodeId($value['typeId']);
        $this->writeByte($value['encoding']);
        if ($value['encoding'] === 0x01) {
            $this->writeByteString($value['body']);
        } elseif ($value['encoding'] === 0x02) {
            $this->writeString($value['body']);
        }
    }

    /**
     * @param DataValue $dv
     */
    public function writeDataValue(DataValue $dv): void
    {
        $mask = $dv->getEncodingMask();
        $this->writeByte($mask);

        if ($mask & 0x01) {
            $this->writeVariant($dv->getVariant());
        }
        if ($mask & 0x02) {
            $this->writeUInt32($dv->getStatusCode());
        }
        if ($mask & 0x04) {
            $this->writeDateTime($dv->getSourceTimestamp());
        }
        if ($mask & 0x08) {
            $this->writeDateTime($dv->getServerTimestamp());
        }
    }

    /**
     * @param string $bytes
     */
    public function writeRawBytes(string $bytes): void
    {
        $this->buffer .= $bytes;
    }
}
