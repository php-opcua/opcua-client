<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Types;

class ReferenceDescription
{
    /**
     * @param NodeId $referenceTypeId
     * @param bool $isForward
     * @param NodeId $nodeId
     * @param QualifiedName $browseName
     * @param LocalizedText $displayName
     * @param NodeClass $nodeClass
     * @param ?NodeId $typeDefinition
     */
    public function __construct(
        private readonly NodeId $referenceTypeId,
        private readonly bool $isForward,
        private readonly NodeId $nodeId,
        private readonly QualifiedName $browseName,
        private readonly LocalizedText $displayName,
        private readonly NodeClass $nodeClass,
        private readonly ?NodeId $typeDefinition = null,
    ) {
    }

    public function getReferenceTypeId(): NodeId
    {
        return $this->referenceTypeId;
    }

    public function isForward(): bool
    {
        return $this->isForward;
    }

    public function getNodeId(): NodeId
    {
        return $this->nodeId;
    }

    public function getBrowseName(): QualifiedName
    {
        return $this->browseName;
    }

    public function getDisplayName(): LocalizedText
    {
        return $this->displayName;
    }

    public function getNodeClass(): NodeClass
    {
        return $this->nodeClass;
    }

    public function getTypeDefinition(): ?NodeId
    {
        return $this->typeDefinition;
    }
}
