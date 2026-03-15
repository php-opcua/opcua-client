<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Types;

use DateTimeImmutable;

class DataValue
{
    /**
     * @param ?Variant $value
     * @param int $statusCode
     * @param ?DateTimeImmutable $sourceTimestamp
     * @param ?DateTimeImmutable $serverTimestamp
     */
    public function __construct(
        private readonly ?Variant $value = null,
        private readonly int $statusCode = 0,
        private readonly ?DateTimeImmutable $sourceTimestamp = null,
        private readonly ?DateTimeImmutable $serverTimestamp = null,
    ) {
    }

    public function getValue(): mixed
    {
        return $this->value?->getValue();
    }

    public function getVariant(): ?Variant
    {
        return $this->value;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getSourceTimestamp(): ?DateTimeImmutable
    {
        return $this->sourceTimestamp;
    }

    public function getServerTimestamp(): ?DateTimeImmutable
    {
        return $this->serverTimestamp;
    }

    public function getEncodingMask(): int
    {
        $mask = 0;
        if ($this->value !== null) {
            $mask |= 0x01;
        }
        if ($this->statusCode !== 0) {
            $mask |= 0x02;
        }
        if ($this->sourceTimestamp !== null) {
            $mask |= 0x04;
        }
        if ($this->serverTimestamp !== null) {
            $mask |= 0x08;
        }

        return $mask;
    }
}
