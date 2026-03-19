<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Types;

class QualifiedName
{
    /**
     * @param int $namespaceIndex
     * @param string $name
     */
    public function __construct(
        private readonly int    $namespaceIndex,
        private readonly string $name,
    )
    {
    }

    public function getNamespaceIndex(): int
    {
        return $this->namespaceIndex;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function __toString(): string
    {
        if ($this->namespaceIndex === 0) {
            return $this->name;
        }

        return $this->namespaceIndex . ':' . $this->name;
    }
}
