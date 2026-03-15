<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Types;

class Variant
{
    /**
     * @param BuiltinType $type
     * @param mixed $value
     */
    public function __construct(
        private readonly BuiltinType $type,
        private readonly mixed $value,
    ) {
    }

    public function getType(): BuiltinType
    {
        return $this->type;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }
}
