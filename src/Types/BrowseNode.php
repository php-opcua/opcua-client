<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Types;

use PhpOpcua\Client\Exception\EncodingException;
use PhpOpcua\Client\Wire\WireSerializable;

/**
 * Represents a node in a hierarchical browse tree, wrapping a ReferenceDescription with child nodes.
 */
class BrowseNode implements WireSerializable
{
    /** @var BrowseNode[] */
    private array $children = [];

    /**
     * @param ReferenceDescription $reference
     */
    public function __construct(
        public readonly ReferenceDescription $reference,
    ) {
    }

    /**
     * @deprecated Access the public property directly instead. Use ->reference instead.
     * @return ReferenceDescription
     * @see BrowseNode::$reference
     */
    public function getReference(): ReferenceDescription
    {
        return $this->reference;
    }

    /**
     * @deprecated Access the public property directly instead. Use ->reference->nodeId instead.
     * @return NodeId
     * @see ReferenceDescription::$nodeId
     */
    public function getNodeId(): NodeId
    {
        return $this->reference->getNodeId();
    }

    /**
     * @deprecated Access the public property directly instead. Use ->reference->displayName instead.
     * @return LocalizedText
     * @see ReferenceDescription::$displayName
     */
    public function getDisplayName(): LocalizedText
    {
        return $this->reference->getDisplayName();
    }

    /**
     * @deprecated Access the public property directly instead. Use ->reference->browseName instead.
     * @return QualifiedName
     * @see ReferenceDescription::$browseName
     */
    public function getBrowseName(): QualifiedName
    {
        return $this->reference->getBrowseName();
    }

    /**
     * @deprecated Access the public property directly instead. Use ->reference->nodeClass instead.
     * @return NodeClass
     * @see ReferenceDescription::$nodeClass
     */
    public function getNodeClass(): NodeClass
    {
        return $this->reference->getNodeClass();
    }

    /**
     * Returns the child nodes of this browse node.
     *
     * @return BrowseNode[]
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    /**
     * Adds a child node to this browse node.
     *
     * @param BrowseNode $child
     * @return void
     */
    public function addChild(self $child): void
    {
        $this->children[] = $child;
    }

    /**
     * Checks whether this browse node has any children.
     *
     * @return bool
     */
    public function hasChildren(): bool
    {
        return count($this->children) > 0;
    }

    /**
     * @return array{reference: ReferenceDescription, children: BrowseNode[]}
     */
    public function jsonSerialize(): array
    {
        return [
            'reference' => $this->reference,
            'children' => $this->children,
        ];
    }

    /**
     * @param array{reference?: mixed, children?: array} $data
     * @return static
     * @throws EncodingException
     */
    public static function fromWireArray(array $data): static
    {
        if (! isset($data['reference']) || ! $data['reference'] instanceof ReferenceDescription) {
            throw new EncodingException('BrowseNode wire payload: "reference" must be a decoded ReferenceDescription instance.');
        }

        $node = new self($data['reference']);
        foreach ($data['children'] ?? [] as $child) {
            if (! $child instanceof self) {
                throw new EncodingException('BrowseNode wire payload: "children" must contain decoded BrowseNode instances.');
            }
            $node->addChild($child);
        }

        return $node;
    }

    /**
     * @return string
     */
    public static function wireTypeId(): string
    {
        return 'BrowseNode';
    }
}
