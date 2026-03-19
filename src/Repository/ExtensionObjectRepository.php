<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Repository;

use Gianfriaur\OpcuaPhpClient\Encoding\ExtensionObjectCodec;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

class ExtensionObjectRepository
{
    /** @var array<string, ExtensionObjectCodec> */
    private static array $codecs = [];

    /**
     * @param NodeId $typeId
     * @param class-string<ExtensionObjectCodec>|ExtensionObjectCodec $codec
     */
    public static function register(NodeId $typeId, string|ExtensionObjectCodec $codec): void
    {
        if (is_string($codec)) {
            $codec = new $codec();
        }

        self::$codecs[self::key($typeId)] = $codec;
    }

    /**
     * @param NodeId $typeId
     * @return void
     */
    public static function unregister(NodeId $typeId): void
    {
        unset(self::$codecs[self::key($typeId)]);
    }

    /**
     * @param NodeId $typeId
     * @return ExtensionObjectCodec|null
     */
    public static function get(NodeId $typeId): ?ExtensionObjectCodec
    {
        return self::$codecs[self::key($typeId)] ?? null;
    }

    /**
     * @param NodeId $typeId
     * @return bool
     */
    public static function has(NodeId $typeId): bool
    {
        return isset(self::$codecs[self::key($typeId)]);
    }

    /**
     * @return void
     */
    public static function clear(): void
    {
        self::$codecs = [];
    }

    /**
     * @param NodeId $nodeId
     * @return string
     */
    private static function key(NodeId $nodeId): string
    {
        return $nodeId->__toString();
    }
}
