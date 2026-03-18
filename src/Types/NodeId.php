<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Types;

use Gianfriaur\OpcuaPhpClient\Exception\InvalidNodeIdException;

class NodeId
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
        private readonly int $namespaceIndex,
        private readonly int|string $identifier,
        private readonly string $type = self::TYPE_NUMERIC,
    ) {
    }

    /**
     * @param int $namespaceIndex
     * @param int $identifier
     */
    public static function numeric(int $namespaceIndex, int $identifier): self
    {
        return new self($namespaceIndex, $identifier, self::TYPE_NUMERIC);
    }

    /**
     * @param int $namespaceIndex
     * @param string $identifier
     */
    public static function string(int $namespaceIndex, string $identifier): self
    {
        return new self($namespaceIndex, $identifier, self::TYPE_STRING);
    }

    /**
     * @param int $namespaceIndex
     * @param string $guidString
     */
    public static function guid(int $namespaceIndex, string $guidString): self
    {
        return new self($namespaceIndex, $guidString, self::TYPE_GUID);
    }

    /**
     * @param int $namespaceIndex
     * @param string $hexIdentifier
     */
    public static function opaque(int $namespaceIndex, string $hexIdentifier): self
    {
        return new self($namespaceIndex, $hexIdentifier, self::TYPE_OPAQUE);
    }

    public function getNamespaceIndex(): int
    {
        return $this->namespaceIndex;
    }

    public function getIdentifier(): int|string
    {
        return $this->identifier;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isNumeric(): bool
    {
        return $this->type === self::TYPE_NUMERIC;
    }

    public function isString(): bool
    {
        return $this->type === self::TYPE_STRING;
    }

    public function isGuid(): bool
    {
        return $this->type === self::TYPE_GUID;
    }

    public function isOpaque(): bool
    {
        return $this->type === self::TYPE_OPAQUE;
    }

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

    public function __toString(): string
    {
        return $this->toString();
    }

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
