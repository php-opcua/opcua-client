<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Encoding;

interface ExtensionObjectCodec
{
    /**
     * @param BinaryDecoder $decoder
     * @return object|array
     */
    public function decode(BinaryDecoder $decoder): object|array;

    /**
     * @param BinaryEncoder $encoder
     * @param mixed $value
     */
    public function encode(BinaryEncoder $encoder, mixed $value): void;
}
