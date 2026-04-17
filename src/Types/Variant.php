<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Types;

use PhpOpcua\Client\Exception\EncodingException;
use PhpOpcua\Client\Wire\WireSerializable;

/**
 * Represents an OPC UA Variant, a union type that can hold any built-in data type value.
 */
readonly class Variant implements WireSerializable
{
    /**
     * @param BuiltinType $type
     * @param mixed $value
     * @param null|int[] $dimensions
     */
    public function __construct(
        public BuiltinType $type,
        public mixed $value,
        public ?array $dimensions = null,
    ) {
    }

    /**
     * @deprecated Access the public property directly instead. Use ->type instead.
     * @return BuiltinType
     * @see Variant::$type
     */
    public function getType(): BuiltinType
    {
        return $this->type;
    }

    /**
     * @deprecated Access the public property directly instead. Use ->value instead.
     * @return mixed
     * @see Variant::$value
     */
    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * @deprecated Access the public property directly instead. Use ->dimensions instead.
     * @return int[]|null
     * @see Variant::$dimensions
     */
    public function getDimensions(): ?array
    {
        return $this->dimensions;
    }

    /**
     * Checks whether this Variant holds a multi-dimensional array value.
     *
     * @return bool
     */
    public function isMultiDimensional(): bool
    {
        return $this->dimensions !== null && count($this->dimensions) > 1;
    }

    /**
     * @return array{type: BuiltinType, value: mixed, dims: null|int[], bytesB64: ?string}
     */
    public function jsonSerialize(): array
    {
        $value = $this->value;
        $bytesB64 = null;

        if ($this->type === BuiltinType::ByteString && is_string($value)) {
            $bytesB64 = base64_encode($value);
            $value = null;
        }

        return [
            'type' => $this->type,
            'value' => $value,
            'dims' => $this->dimensions,
            'bytesB64' => $bytesB64,
        ];
    }

    /**
     * @param array{type?: mixed, value?: mixed, dims?: null|int[], bytesB64?: ?string} $data
     * @return static
     * @throws EncodingException
     */
    public static function fromWireArray(array $data): static
    {
        $type = $data['type'] ?? null;
        if (! $type instanceof BuiltinType) {
            throw new EncodingException('Variant wire payload: "type" must be a decoded BuiltinType instance.');
        }

        $value = $data['value'] ?? null;
        if ($type === BuiltinType::ByteString && isset($data['bytesB64']) && is_string($data['bytesB64'])) {
            $decoded = base64_decode($data['bytesB64'], true);
            if ($decoded === false) {
                throw new EncodingException('Variant wire payload: "bytesB64" is not valid base64.');
            }
            $value = $decoded;
        }

        return new self($type, $value, $data['dims'] ?? null);
    }

    /**
     * @return string
     */
    public static function wireTypeId(): string
    {
        return 'Variant';
    }
}
