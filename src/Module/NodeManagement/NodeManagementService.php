<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\NodeManagement;

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Protocol\AbstractProtocolService;
use PhpOpcua\Client\Protocol\ServiceTypeId;
use PhpOpcua\Client\Types\ExtensionObject;
use PhpOpcua\Client\Types\LocalizedText;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\NodeId;

/**
 * OPC UA NodeManagement Service Set: AddNodes, DeleteNodes, AddReferences, DeleteReferences.
 */
class NodeManagementService extends AbstractProtocolService
{
    /**
     * Binary encoding NodeIds for OPC UA attribute structures (namespace 0).
     */
    private const OBJECT_ATTRIBUTES_ENCODING = 354;

    private const VARIABLE_ATTRIBUTES_ENCODING = 357;

    private const METHOD_ATTRIBUTES_ENCODING = 360;

    private const OBJECT_TYPE_ATTRIBUTES_ENCODING = 363;

    private const VARIABLE_TYPE_ATTRIBUTES_ENCODING = 366;

    private const REFERENCE_TYPE_ATTRIBUTES_ENCODING = 369;

    private const DATA_TYPE_ATTRIBUTES_ENCODING = 372;

    private const VIEW_ATTRIBUTES_ENCODING = 375;

    /**
     * @param int $requestId
     * @param AddNodeItem[] $nodesToAdd
     * @param NodeId $authToken
     * @return string
     */
    public function encodeAddNodesRequest(int $requestId, array $nodesToAdd, NodeId $authToken): string
    {
        $body = new BinaryEncoder();
        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::ADD_NODES_REQUEST));
        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeInt32(count($nodesToAdd));
        foreach ($nodesToAdd as $item) {
            $body->writeExpandedNodeId($item->parentNodeId);
            $body->writeNodeId($item->referenceTypeId);
            $body->writeExpandedNodeId($item->requestedNewNodeId);
            $body->writeQualifiedName($item->browseName);
            $body->writeUInt32($item->nodeClass->value);

            $this->writeNodeAttributes($body, $item);

            $body->writeExpandedNodeId($item->typeDefinition);
        }

        return $this->encodeRequestAuto($requestId, $body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return AddNodesResult[]
     */
    public function decodeAddNodesResponse(BinaryDecoder $decoder): array
    {
        $this->readResponseMetadata($decoder);

        $count = $decoder->readInt32();
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $statusCode = $decoder->readUInt32();
            $addedNodeId = $decoder->readExpandedNodeId();
            $results[] = new AddNodesResult($statusCode, $addedNodeId);
        }

        $decoder->skipDiagnosticInfoArray();

        return $results;
    }

    /**
     * @param int $requestId
     * @param array<array{nodeId: NodeId, deleteTargetReferences?: bool}> $nodesToDelete
     * @param NodeId $authToken
     * @return string
     */
    public function encodeDeleteNodesRequest(int $requestId, array $nodesToDelete, NodeId $authToken): string
    {
        $body = new BinaryEncoder();
        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::DELETE_NODES_REQUEST));
        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeInt32(count($nodesToDelete));
        foreach ($nodesToDelete as $item) {
            $body->writeNodeId($item['nodeId']);
            $body->writeBoolean($item['deleteTargetReferences'] ?? true);
        }

        return $this->encodeRequestAuto($requestId, $body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return int[]
     */
    public function decodeDeleteNodesResponse(BinaryDecoder $decoder): array
    {
        $this->readResponseMetadata($decoder);

        $count = $decoder->readInt32();
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $results[] = $decoder->readUInt32();
        }

        $decoder->skipDiagnosticInfoArray();

        return $results;
    }

    /**
     * @param int $requestId
     * @param array<array{
     *     sourceNodeId: NodeId,
     *     referenceTypeId: NodeId,
     *     isForward: bool,
     *     targetNodeId: NodeId,
     *     targetNodeClass: NodeClass,
     *     targetServerUri?: ?string,
     * }> $referencesToAdd
     * @param NodeId $authToken
     * @return string
     */
    public function encodeAddReferencesRequest(int $requestId, array $referencesToAdd, NodeId $authToken): string
    {
        $body = new BinaryEncoder();
        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::ADD_REFERENCES_REQUEST));
        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeInt32(count($referencesToAdd));
        foreach ($referencesToAdd as $item) {
            $body->writeNodeId($item['sourceNodeId']);
            $body->writeNodeId($item['referenceTypeId']);
            $body->writeBoolean($item['isForward']);
            $body->writeString($item['targetServerUri'] ?? null);
            $body->writeExpandedNodeId($item['targetNodeId']);
            $body->writeUInt32($item['targetNodeClass']->value);
        }

        return $this->encodeRequestAuto($requestId, $body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return int[]
     */
    public function decodeAddReferencesResponse(BinaryDecoder $decoder): array
    {
        return $this->decodeDeleteNodesResponse($decoder);
    }

    /**
     * @param int $requestId
     * @param array<array{
     *     sourceNodeId: NodeId,
     *     referenceTypeId: NodeId,
     *     isForward: bool,
     *     targetNodeId: NodeId,
     *     deleteBidirectional?: bool,
     * }> $referencesToDelete
     * @param NodeId $authToken
     * @return string
     */
    public function encodeDeleteReferencesRequest(int $requestId, array $referencesToDelete, NodeId $authToken): string
    {
        $body = new BinaryEncoder();
        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::DELETE_REFERENCES_REQUEST));
        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeInt32(count($referencesToDelete));
        foreach ($referencesToDelete as $item) {
            $body->writeNodeId($item['sourceNodeId']);
            $body->writeNodeId($item['referenceTypeId']);
            $body->writeBoolean($item['isForward']);
            $body->writeExpandedNodeId($item['targetNodeId']);
            $body->writeBoolean($item['deleteBidirectional'] ?? true);
        }

        return $this->encodeRequestAuto($requestId, $body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return int[]
     */
    public function decodeDeleteReferencesResponse(BinaryDecoder $decoder): array
    {
        return $this->decodeDeleteNodesResponse($decoder);
    }

    /**
     * Encode the node attributes as an ExtensionObject based on the node class.
     *
     * @param BinaryEncoder $body
     * @param AddNodeItem $item
     */
    private function writeNodeAttributes(BinaryEncoder $body, AddNodeItem $item): void
    {
        $attrBody = new BinaryEncoder();
        $nodeClass = $item->nodeClass;

        match ($nodeClass) {
            NodeClass::Object => $this->writeObjectAttributes($attrBody, $item),
            NodeClass::Variable => $this->writeVariableAttributes($attrBody, $item),
            NodeClass::Method => $this->writeMethodAttributes($attrBody, $item),
            NodeClass::ObjectType => $this->writeObjectTypeAttributes($attrBody, $item),
            NodeClass::VariableType => $this->writeVariableTypeAttributes($attrBody, $item),
            NodeClass::ReferenceType => $this->writeReferenceTypeAttributes($attrBody, $item),
            NodeClass::DataType => $this->writeDataTypeAttributes($attrBody, $item),
            NodeClass::View => $this->writeViewAttributes($attrBody, $item),
            default => $this->writeObjectAttributes($attrBody, $item),
        };

        $encodingId = match ($nodeClass) {
            NodeClass::Object => self::OBJECT_ATTRIBUTES_ENCODING,
            NodeClass::Variable => self::VARIABLE_ATTRIBUTES_ENCODING,
            NodeClass::Method => self::METHOD_ATTRIBUTES_ENCODING,
            NodeClass::ObjectType => self::OBJECT_TYPE_ATTRIBUTES_ENCODING,
            NodeClass::VariableType => self::VARIABLE_TYPE_ATTRIBUTES_ENCODING,
            NodeClass::ReferenceType => self::REFERENCE_TYPE_ATTRIBUTES_ENCODING,
            NodeClass::DataType => self::DATA_TYPE_ATTRIBUTES_ENCODING,
            NodeClass::View => self::VIEW_ATTRIBUTES_ENCODING,
            default => self::OBJECT_ATTRIBUTES_ENCODING,
        };

        $extObj = new ExtensionObject(
            NodeId::numeric(0, $encodingId),
            0x01,
            $attrBody->getBuffer(),
        );
        $body->writeExtensionObject($extObj);
    }

    /**
     * @param BinaryEncoder $e
     * @param AddNodeItem $item
     */
    private function writeCommonAttributes(BinaryEncoder $e, AddNodeItem $item, int $specifiedAttributes): void
    {
        $e->writeUInt32($specifiedAttributes);
        $e->writeLocalizedText(new LocalizedText(null, $item->displayName ?? $item->browseName->name));
        $e->writeLocalizedText(new LocalizedText(null, $item->description));
        $e->writeUInt32($item->writeMask);
        $e->writeUInt32($item->userWriteMask);
    }

    /**
     * @param BinaryEncoder $e
     * @param AddNodeItem $item
     */
    private function writeObjectAttributes(BinaryEncoder $e, AddNodeItem $item): void
    {
        $this->writeCommonAttributes($e, $item, 0x1F);
        $e->writeByte($item->eventNotifier);
    }

    /**
     * @param BinaryEncoder $e
     * @param AddNodeItem $item
     */
    private function writeVariableAttributes(BinaryEncoder $e, AddNodeItem $item): void
    {
        $this->writeCommonAttributes($e, $item, 0x0FFF);

        if ($item->value !== null) {
            $e->writeVariant($item->value);
        } else {
            $e->writeByte(0);
        }

        $e->writeNodeId($item->dataType ?? NodeId::numeric(0, 24));

        $e->writeInt32($item->valueRank);

        $e->writeInt32(count($item->arrayDimensions));
        foreach ($item->arrayDimensions as $dim) {
            $e->writeUInt32($dim);
        }

        $e->writeByte($item->accessLevel);
        $e->writeByte($item->userAccessLevel);

        $e->writeDouble($item->minimumSamplingInterval);

        $e->writeBoolean($item->historizing);
    }

    /**
     * @param BinaryEncoder $e
     * @param AddNodeItem $item
     */
    private function writeMethodAttributes(BinaryEncoder $e, AddNodeItem $item): void
    {
        $this->writeCommonAttributes($e, $item, 0x3F);
        $e->writeBoolean($item->executable);
        $e->writeBoolean($item->userExecutable);
    }

    /**
     * @param BinaryEncoder $e
     * @param AddNodeItem $item
     */
    private function writeObjectTypeAttributes(BinaryEncoder $e, AddNodeItem $item): void
    {
        $this->writeCommonAttributes($e, $item, 0x1F);
        $e->writeBoolean($item->isAbstract);
    }

    /**
     * @param BinaryEncoder $e
     * @param AddNodeItem $item
     */
    private function writeVariableTypeAttributes(BinaryEncoder $e, AddNodeItem $item): void
    {
        $this->writeCommonAttributes($e, $item, 0x07FF);

        if ($item->value !== null) {
            $e->writeVariant($item->value);
        } else {
            $e->writeByte(0);
        }

        $e->writeNodeId($item->dataType ?? NodeId::numeric(0, 24));
        $e->writeInt32($item->valueRank);

        $e->writeInt32(count($item->arrayDimensions));
        foreach ($item->arrayDimensions as $dim) {
            $e->writeUInt32($dim);
        }

        $e->writeBoolean($item->isAbstract);
    }

    /**
     * @param BinaryEncoder $e
     * @param AddNodeItem $item
     */
    private function writeReferenceTypeAttributes(BinaryEncoder $e, AddNodeItem $item): void
    {
        $this->writeCommonAttributes($e, $item, 0x7F);
        $e->writeBoolean($item->isAbstract);
        $e->writeBoolean($item->symmetric);
        $e->writeLocalizedText(new LocalizedText(null, $item->inverseName));
    }

    /**
     * @param BinaryEncoder $e
     * @param AddNodeItem $item
     */
    private function writeDataTypeAttributes(BinaryEncoder $e, AddNodeItem $item): void
    {
        $this->writeCommonAttributes($e, $item, 0x1F);
        $e->writeBoolean($item->isAbstract);
    }

    /**
     * @param BinaryEncoder $e
     * @param AddNodeItem $item
     */
    private function writeViewAttributes(BinaryEncoder $e, AddNodeItem $item): void
    {
        $this->writeCommonAttributes($e, $item, 0x3F);
        $e->writeBoolean($item->containsNoLoops);
        $e->writeByte($item->eventNotifier);
    }
}
