<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Types;

use PhpOpcua\Client\Exception\EncodingException;
use PhpOpcua\Client\Wire\WireSerializable;

/**
 * Represents an OPC UA ReferenceDescription returned from a Browse operation.
 */
readonly class ReferenceDescription implements WireSerializable
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
        public NodeId $referenceTypeId,
        public bool $isForward,
        public NodeId $nodeId,
        public QualifiedName $browseName,
        public LocalizedText $displayName,
        public NodeClass $nodeClass,
        public ?NodeId $typeDefinition = null,
    ) {
    }

    /**
     * @deprecated Access the public property directly instead. Use ->referenceTypeId instead.
     * @return NodeId
     * @see ReferenceDescription::$referenceTypeId
     */
    public function getReferenceTypeId(): NodeId
    {
        return $this->referenceTypeId;
    }

    /**
     * @deprecated Access the public property directly instead. Use ->isForward instead.
     * @return bool
     * @see ReferenceDescription::$isForward
     */
    public function isForward(): bool
    {
        return $this->isForward;
    }

    /**
     * @deprecated Access the public property directly instead. Use ->nodeId instead.
     * @return NodeId
     * @see ReferenceDescription::$nodeId
     */
    public function getNodeId(): NodeId
    {
        return $this->nodeId;
    }

    /**
     * @deprecated Access the public property directly instead. Use ->browseName instead.
     * @return QualifiedName
     * @see ReferenceDescription::$browseName
     */
    public function getBrowseName(): QualifiedName
    {
        return $this->browseName;
    }

    /**
     * @deprecated Access the public property directly instead. Use ->displayName instead.
     * @return LocalizedText
     * @see ReferenceDescription::$displayName
     */
    public function getDisplayName(): LocalizedText
    {
        return $this->displayName;
    }

    /**
     * @deprecated Access the public property directly instead. Use ->nodeClass instead.
     * @return NodeClass
     * @see ReferenceDescription::$nodeClass
     */
    public function getNodeClass(): NodeClass
    {
        return $this->nodeClass;
    }

    /**
     * @deprecated Access the public property directly instead. Use ->typeDefinition instead.
     * @return ?NodeId
     * @see ReferenceDescription::$typeDefinition
     */
    public function getTypeDefinition(): ?NodeId
    {
        return $this->typeDefinition;
    }

    /**
     * @return array{refType: NodeId, isForward: bool, nodeId: NodeId, browseName: QualifiedName, displayName: LocalizedText, nodeClass: NodeClass, typeDef: ?NodeId}
     */
    public function jsonSerialize(): array
    {
        return [
            'refType' => $this->referenceTypeId,
            'isForward' => $this->isForward,
            'nodeId' => $this->nodeId,
            'browseName' => $this->browseName,
            'displayName' => $this->displayName,
            'nodeClass' => $this->nodeClass,
            'typeDef' => $this->typeDefinition,
        ];
    }

    /**
     * @param array{refType?: mixed, isForward?: bool, nodeId?: mixed, browseName?: mixed, displayName?: mixed, nodeClass?: mixed, typeDef?: mixed} $data
     * @return static
     * @throws EncodingException
     */
    public static function fromWireArray(array $data): static
    {
        $required = ['refType' => NodeId::class, 'nodeId' => NodeId::class, 'browseName' => QualifiedName::class, 'displayName' => LocalizedText::class, 'nodeClass' => NodeClass::class];
        foreach ($required as $key => $class) {
            if (! isset($data[$key]) || ! $data[$key] instanceof $class) {
                throw new EncodingException("ReferenceDescription wire payload: field \"{$key}\" must be a decoded {$class} instance.");
            }
        }

        return new self(
            $data['refType'],
            $data['isForward'] ?? false,
            $data['nodeId'],
            $data['browseName'],
            $data['displayName'],
            $data['nodeClass'],
            $data['typeDef'] ?? null,
        );
    }

    /**
     * @return string
     */
    public static function wireTypeId(): string
    {
        return 'ReferenceDescription';
    }
}
