<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Types;

class LocalizedText
{
    /**
     * @param ?string $locale
     * @param ?string $text
     */
    public function __construct(
        private readonly ?string $locale,
        private readonly ?string $text,
    )
    {
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function getEncodingMask(): int
    {
        $mask = 0;
        if ($this->locale !== null) {
            $mask |= 0x01;
        }
        if ($this->text !== null) {
            $mask |= 0x02;
        }

        return $mask;
    }

    public function __toString(): string
    {
        return $this->text ?? '';
    }
}
