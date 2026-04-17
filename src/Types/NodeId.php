<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Types;

use PhpOpcua\Client\Exception\InvalidNodeIdException;
use PhpOpcua\Client\Wire\WireSerializable;

/**
 * Represents an OPC UA NodeId, uniquely identifying a node within a server address space.
 */
readonly class NodeId implements WireSerializable
{
    public const TYPE_NUMERIC = 'numeric';

    public const TYPE_STRING = 'string';

    public const TYPE_GUID = 'guid';

    public const TYPE_OPAQUE = 'opaque';

    /**
     * @param int $namespaceIndex
     * @param int|string $identifier
     * @param string $type
     */
    public function __construct(
        public int $namespaceIndex,
        public int|string $identifier,
        public string $type = self::TYPE_NUMERIC,
    ) {
    }

    /**
     * Creates a numeric NodeId.
     *
     * @param int $namespaceIndex
     * @param int $identifier
     * @return self
     */
    public static function numeric(int $namespaceIndex, int $identifier): self
    {
        return new self($namespaceIndex, $identifier, self::TYPE_NUMERIC);
    }

    /**
     * Creates a string NodeId.
     *
     * @param int $namespaceIndex
     * @param string $identifier
     * @return self
     */
    public static function string(int $namespaceIndex, string $identifier): self
    {
        return new self($namespaceIndex, $identifier, self::TYPE_STRING);
    }

    /**
     * Creates a GUID NodeId.
     *
     * @param int $namespaceIndex
     * @param string $guidString
     * @return self
     */
    public static function guid(int $namespaceIndex, string $guidString): self
    {
        return new self($namespaceIndex, $guidString, self::TYPE_GUID);
    }

    /**
     * Creates an opaque (ByteString) NodeId.
     *
     * @param int $namespaceIndex
     * @param string $hexIdentifier
     * @return self
     */
    public static function opaque(int $namespaceIndex, string $hexIdentifier): self
    {
        return new self($namespaceIndex, $hexIdentifier, self::TYPE_OPAQUE);
    }

    /**
     * @deprecated Access the public property directly instead. Use ->namespaceIndex instead.
     * @return int
     * @see NodeId::$namespaceIndex
     */
    public function getNamespaceIndex(): int
    {
        return $this->namespaceIndex;
    }

    /**
     * @deprecated Access the public property directly instead. Use ->identifier instead.
     * @return int|string
     * @see NodeId::$identifier
     */
    public function getIdentifier(): int|string
    {
        return $this->identifier;
    }

    /**
     * @deprecated Access the public property directly instead. Use ->type instead.
     * @return string
     * @see NodeId::$type
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Checks whether this NodeId has a numeric identifier type.
     *
     * @return bool
     */
    public function isNumeric(): bool
    {
        return $this->type === self::TYPE_NUMERIC;
    }

    /**
     * Checks whether this NodeId has a string identifier type.
     *
     * @return bool
     */
    public function isString(): bool
    {
        return $this->type === self::TYPE_STRING;
    }

    /**
     * Checks whether this NodeId has a GUID identifier type.
     *
     * @return bool
     */
    public function isGuid(): bool
    {
        return $this->type === self::TYPE_GUID;
    }

    /**
     * Checks whether this NodeId has an opaque identifier type.
     *
     * @return bool
     */
    public function isOpaque(): bool
    {
        return $this->type === self::TYPE_OPAQUE;
    }

    /**
     * Parses a NodeId from its OPC UA string representation (e.g. "ns=2;i=10" or "s=MyNode").
     *
     * @param string $nodeIdString
     * @return self
     * @throws InvalidNodeIdException If the string format is invalid or the type identifier is unknown.
     */
    public static function parse(string $nodeIdString): self
    {
        $namespace = 0;
        $remaining = $nodeIdString;

        if (str_starts_with($remaining, 'ns=')) {
            $semiPos = strpos($remaining, ';');
            if ($semiPos === false) {
                throw new InvalidNodeIdException("Invalid NodeId format: {$nodeIdString}");
            }
            $namespace = (int) substr($remaining, 3, $semiPos - 3);
            $remaining = substr($remaining, $semiPos + 1);
        }

        $eqPos = strpos($remaining, '=');
        if ($eqPos === false) {
            throw new InvalidNodeIdException("Invalid NodeId format: {$nodeIdString}");
        }

        $typeChar = substr($remaining, 0, $eqPos);
        $value = substr($remaining, $eqPos + 1);

        return match ($typeChar) {
            'i' => self::numeric($namespace, (int) $value),
            's' => self::string($namespace, $value),
            'g' => self::guid($namespace, $value),
            'b' => self::opaque($namespace, $value),
            default => throw new InvalidNodeIdException("Unknown NodeId type identifier: {$typeChar}"),
        };
    }

    /**
     * Returns the OPC UA string representation of this NodeId (e.g. "ns=2;i=10").
     *
     * @return string
     */
    public function toString(): string
    {
        $typeChar = match ($this->type) {
            self::TYPE_NUMERIC => 'i',
            self::TYPE_STRING => 's',
            self::TYPE_GUID => 'g',
            self::TYPE_OPAQUE => 'b',
        };

        $prefix = $this->namespaceIndex > 0 ? "ns={$this->namespaceIndex};" : '';

        return "{$prefix}{$typeChar}={$this->identifier}";
    }

    /**
     * Returns the OPC UA string representation of this NodeId.
     *
     * @return string
     * @see NodeId::toString()
     */
    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * @return array{v: string}
     */
    public function jsonSerialize(): array
    {
        return ['v' => $this->toString()];
    }

    /**
     * @param array{v: string} $data
     * @return static
     * @throws InvalidNodeIdException If the wire payload is malformed.
     */
    public static function fromWireArray(array $data): static
    {
        if (! isset($data['v']) || ! is_string($data['v'])) {
            throw new InvalidNodeIdException('NodeId wire payload: missing or non-string "v" field.');
        }

        return self::parse($data['v']);
    }

    /**
     * @return string
     */
    public static function wireTypeId(): string
    {
        return 'NodeId';
    }

    /**
     * Returns the binary encoding byte for this NodeId based on its type and value range.
     *
     * @return int
     */
    public function getEncodingByte(): int
    {
        if ($this->isNumeric()) {
            if ($this->namespaceIndex === 0 && $this->identifier >= 0 && $this->identifier <= 255) {
                return 0x00;
            }
            if ($this->namespaceIndex >= 0 && $this->namespaceIndex <= 255
                && $this->identifier >= 0 && $this->identifier <= 65535) {
                return 0x01;
            }

            return 0x02;
        }

        if ($this->isGuid()) {
            return 0x04;
        }

        if ($this->isOpaque()) {
            return 0x05;
        }

        return 0x03;
    }
}
