<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Types;

use PhpOpcua\Client\Wire\WireSerializable;

/**
 * Represents an OPC UA LocalizedText, containing a locale identifier and a text string.
 */
readonly class LocalizedText implements WireSerializable
{
    /**
     * @param ?string $locale
     * @param ?string $text
     */
    public function __construct(
        public ?string $locale,
        public ?string $text,
    ) {
    }

    /**
     * @deprecated Access the public property directly instead. Use ->locale instead.
     * @return ?string
     * @see LocalizedText::$locale
     */
    public function getLocale(): ?string
    {
        return $this->locale;
    }

    /**
     * @deprecated Access the public property directly instead. Use ->text instead.
     * @return ?string
     * @see LocalizedText::$text
     */
    public function getText(): ?string
    {
        return $this->text;
    }

    /**
     * Returns the binary encoding mask indicating which optional fields are present.
     *
     * @return int
     */
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

    /**
     * Returns the text content, or an empty string if text is null.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->text ?? '';
    }

    /**
     * @return array{locale: ?string, text: ?string}
     */
    public function jsonSerialize(): array
    {
        return ['locale' => $this->locale, 'text' => $this->text];
    }

    /**
     * @param array{locale?: ?string, text?: ?string} $data
     * @return static
     */
    public static function fromWireArray(array $data): static
    {
        return new self($data['locale'] ?? null, $data['text'] ?? null);
    }

    /**
     * @return string
     */
    public static function wireTypeId(): string
    {
        return 'LocalizedText';
    }
}
