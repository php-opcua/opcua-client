<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Types;

readonly class Variant
{
    /**
     * @param BuiltinType $type
     * @param mixed $value
     * @param null|int[] $dimensions
     */
    public function __construct(
        private BuiltinType $type,
        private mixed       $value,
        private ?array      $dimensions = null,
    )
    {
    }

    public function getType(): BuiltinType
    {
        return $this->type;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * @return int[]|null
     */
    public function getDimensions(): ?array
    {
        return $this->dimensions;
    }

    public function isMultiDimensional(): bool
    {
        return $this->dimensions !== null && count($this->dimensions) > 1;
    }
}
