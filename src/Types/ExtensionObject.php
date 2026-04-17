<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Types;

use PhpOpcua\Client\Exception\EncodingException;
use PhpOpcua\Client\Wire\WireSerializable;

/**
 * Represents an OPC UA ExtensionObject containing a typed binary or XML payload.
 *
 * When a codec is registered for the type, the decoded value is available via {@see $value}
 * and {@see isDecoded()} returns true. When no codec is registered, the raw body is available
 * via {@see $body} and {@see isRaw()} returns true.
 *
 * @see \PhpOpcua\Client\Encoding\BinaryDecoder::readExtensionObject()
 * @see \PhpOpcua\Client\Encoding\ExtensionObjectCodec
 */
readonly class ExtensionObject implements WireSerializable
{
    /**
     * @param NodeId $typeId The encoding NodeId identifying the ExtensionObject type.
     * @param int $encoding The encoding format (0x01 = binary, 0x02 = XML, 0x00 = no body).
     * @param ?string $body The raw body bytes (binary or XML string). Null when decoded via codec.
     * @param mixed $value The decoded value from a registered codec. Null when raw (no codec).
     */
    public function __construct(
        public NodeId $typeId,
        public int $encoding,
        public ?string $body = null,
        public mixed $value = null,
    ) {
    }

    /**
     * Whether this ExtensionObject has been decoded by a registered codec.
     *
     * @return bool
     */
    public function isDecoded(): bool
    {
        return $this->value !== null;
    }

    /**
     * Whether this ExtensionObject contains raw (undecoded) data.
     *
     * @return bool
     */
    public function isRaw(): bool
    {
        return $this->value === null;
    }

    /**
     * @return array{typeId: NodeId, encoding: int, bodyB64: ?string, value: mixed}
     */
    public function jsonSerialize(): array
    {
        return [
            'typeId' => $this->typeId,
            'encoding' => $this->encoding,
            'bodyB64' => $this->body !== null ? base64_encode($this->body) : null,
            'value' => $this->value,
        ];
    }

    /**
     * @param array{typeId?: mixed, encoding?: int, bodyB64?: ?string, value?: mixed} $data
     * @return static
     * @throws EncodingException
     */
    public static function fromWireArray(array $data): static
    {
        $typeId = $data['typeId'] ?? null;
        if (! $typeId instanceof NodeId) {
            throw new EncodingException('ExtensionObject wire payload: "typeId" must be a decoded NodeId instance.');
        }

        $body = null;
        if (isset($data['bodyB64']) && is_string($data['bodyB64'])) {
            $decoded = base64_decode($data['bodyB64'], true);
            if ($decoded === false) {
                throw new EncodingException('ExtensionObject wire payload: "bodyB64" is not valid base64.');
            }
            $body = $decoded;
        }

        return new self($typeId, $data['encoding'] ?? 0, $body, $data['value'] ?? null);
    }

    /**
     * @return string
     */
    public static function wireTypeId(): string
    {
        return 'ExtensionObject';
    }
}
