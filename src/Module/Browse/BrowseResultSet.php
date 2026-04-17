<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Browse;

use PhpOpcua\Client\Types\ReferenceDescription;
use PhpOpcua\Client\Wire\WireSerializable;

/**
 * Holds the result of a browse operation, including references and an optional continuation point.
 */
readonly class BrowseResultSet implements WireSerializable
{
    /**
     * @param ReferenceDescription[] $references
     * @param ?string $continuationPoint
     */
    public function __construct(
        public array $references,
        public ?string $continuationPoint,
    ) {
    }

    /**
     * @return array{refs: ReferenceDescription[], cpB64: ?string}
     */
    public function jsonSerialize(): array
    {
        return [
            'refs' => $this->references,
            'cpB64' => $this->continuationPoint !== null ? base64_encode($this->continuationPoint) : null,
        ];
    }

    /**
     * @param array{refs?: ReferenceDescription[], cpB64?: ?string} $data
     * @return static
     */
    public static function fromWireArray(array $data): static
    {
        $cp = null;
        if (isset($data['cpB64']) && is_string($data['cpB64'])) {
            $cp = base64_decode($data['cpB64'], true) ?: null;
        }

        return new self($data['refs'] ?? [], $cp);
    }

    /**
     * @return string
     */
    public static function wireTypeId(): string
    {
        return 'BrowseResultSet';
    }
}
