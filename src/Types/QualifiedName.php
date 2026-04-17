<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Types;

use PhpOpcua\Client\Exception\EncodingException;
use PhpOpcua\Client\Wire\WireSerializable;

/**
 * Represents an OPC UA QualifiedName, consisting of a namespace index and a name string.
 */
readonly class QualifiedName implements WireSerializable
{
    /**
     * @param int $namespaceIndex
     * @param string $name
     */
    public function __construct(
        public int $namespaceIndex,
        public string $name,
    ) {
    }

    /**
     * @deprecated Access the public property directly instead. Use ->namespaceIndex instead.
     * @return int
     * @see QualifiedName::$namespaceIndex
     */
    public function getNamespaceIndex(): int
    {
        return $this->namespaceIndex;
    }

    /**
     * @deprecated Access the public property directly instead. Use ->name instead.
     * @return string
     * @see QualifiedName::$name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Returns the string representation in the format "namespaceIndex:name" (or just "name" for namespace 0).
     *
     * @return string
     */
    public function __toString(): string
    {
        if ($this->namespaceIndex === 0) {
            return $this->name;
        }

        return $this->namespaceIndex . ':' . $this->name;
    }

    /**
     * @return array{ns: int, n: string}
     */
    public function jsonSerialize(): array
    {
        return ['ns' => $this->namespaceIndex, 'n' => $this->name];
    }

    /**
     * @param array{ns: int, n: string} $data
     * @return static
     * @throws EncodingException
     */
    public static function fromWireArray(array $data): static
    {
        if (! isset($data['ns'], $data['n']) || ! is_int($data['ns']) || ! is_string($data['n'])) {
            throw new EncodingException('QualifiedName wire payload: missing or invalid "ns" (int) / "n" (string) fields.');
        }

        return new self($data['ns'], $data['n']);
    }

    /**
     * @return string
     */
    public static function wireTypeId(): string
    {
        return 'QualifiedName';
    }
}
