<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Types;

class BrowseNode
{
    /** @var BrowseNode[] */
    private array $children = [];

    public function __construct(
        private readonly ReferenceDescription $reference,
    )
    {
    }

    public function getReference(): ReferenceDescription
    {
        return $this->reference;
    }

    public function getNodeId(): NodeId
    {
        return $this->reference->getNodeId();
    }

    public function getDisplayName(): LocalizedText
    {
        return $this->reference->getDisplayName();
    }

    public function getBrowseName(): QualifiedName
    {
        return $this->reference->getBrowseName();
    }

    public function getNodeClass(): NodeClass
    {
        return $this->reference->getNodeClass();
    }

    /**
     * @return BrowseNode[]
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    public function addChild(BrowseNode $child): void
    {
        $this->children[] = $child;
    }

    public function hasChildren(): bool
    {
        return count($this->children) > 0;
    }
}
