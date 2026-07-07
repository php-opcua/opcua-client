<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Encoding;

/**
 * Interface for encoding and decoding OPC UA ExtensionObject payloads.
 */
interface ExtensionObjectCodec
{
    /**
     * Decodes an extension object body from a binary stream.
     *
     * @param BinaryDecoder $decoder
     * @return object|array<string, mixed>
     */
    public function decode(BinaryDecoder $decoder): object|array;

    /**
     * Encodes an extension object value into a binary stream.
     *
     * @param BinaryEncoder $encoder
     * @param mixed $value
     * @return void
     */
    public function encode(BinaryEncoder $encoder, mixed $value): void;
}
