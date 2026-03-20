<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Repository;

use Gianfriaur\OpcuaPhpClient\Encoding\ExtensionObjectCodec;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

class ExtensionObjectRepository
{
    /** @var array<string, ExtensionObjectCodec> */
    private array $codecs = [];

    /**
     * @param NodeId $typeId
     * @param class-string<ExtensionObjectCodec>|ExtensionObjectCodec $codec
     */
    public function register(NodeId $typeId, string|ExtensionObjectCodec $codec): void
    {
        if (is_string($codec)) {
            $codec = new $codec();
        }

        $this->codecs[$this->key($typeId)] = $codec;
    }

    /**
     * @param NodeId $typeId
     * @return void
     */
    public function unregister(NodeId $typeId): void
    {
        unset($this->codecs[$this->key($typeId)]);
    }

    /**
     * @param NodeId $typeId
     * @return ExtensionObjectCodec|null
     */
    public function get(NodeId $typeId): ?ExtensionObjectCodec
    {
        return $this->codecs[$this->key($typeId)] ?? null;
    }

    /**
     * @param NodeId $typeId
     * @return bool
     */
    public function has(NodeId $typeId): bool
    {
        return isset($this->codecs[$this->key($typeId)]);
    }

    /**
     * @return void
     */
    public function clear(): void
    {
        $this->codecs = [];
    }

    /**
     * @param NodeId $nodeId
     * @return string
     */
    private function key(NodeId $nodeId): string
    {
        return $nodeId->__toString();
    }
}
