<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Protocol;

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Types\AddNodesResult;
use PhpOpcua\Client\Types\ExtensionObject;
use PhpOpcua\Client\Types\LocalizedText;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\QualifiedName;

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
     * @param array<array{
     *     parentNodeId: NodeId,
     *     referenceTypeId: NodeId,
     *     requestedNewNodeId: NodeId,
     *     browseName: QualifiedName,
     *     nodeClass: NodeClass,
     *     typeDefinition: NodeId,
     *     displayName?: ?string,
     *     description?: ?string,
     *     writeMask?: int,
     *     userWriteMask?: int,
     *     value?: mixed,
     *     dataType?: ?NodeId,
     *     valueRank?: int,
     *     arrayDimensions?: int[],
     *     accessLevel?: int,
     *     userAccessLevel?: int,
     *     minimumSamplingInterval?: float,
     *     historizing?: bool,
     *     executable?: bool,
     *     userExecutable?: bool,
     *     isAbstract?: bool,
     *     symmetric?: bool,
     *     inverseName?: ?string,
     *     containsNoLoops?: bool,
     *     eventNotifier?: int,
     * }> $nodesToAdd
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
            $body->writeExpandedNodeId($item['parentNodeId']);
            $body->writeNodeId($item['referenceTypeId']);
            $body->writeExpandedNodeId($item['requestedNewNodeId']);
            $body->writeQualifiedName($item['browseName']);
            $body->writeUInt32($item['nodeClass']->value);

            $this->writeNodeAttributes($body, $item);

            $body->writeExpandedNodeId($item['typeDefinition']);
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
     * @param array $item
     */
    private function writeNodeAttributes(BinaryEncoder $body, array $item): void
    {
        $attrBody = new BinaryEncoder();
        $nodeClass = $item['nodeClass'];

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
     * @param array $item
     */
    private function writeCommonAttributes(BinaryEncoder $e, array $item, int $specifiedAttributes): void
    {
        $e->writeUInt32($specifiedAttributes);
        $e->writeLocalizedText(new LocalizedText(null, $item['displayName'] ?? $item['browseName']->name));
        $e->writeLocalizedText(new LocalizedText(null, $item['description'] ?? null));
        $e->writeUInt32($item['writeMask'] ?? 0);
        $e->writeUInt32($item['userWriteMask'] ?? 0);
    }

    /**
     * @param BinaryEncoder $e
     * @param array $item
     */
    private function writeObjectAttributes(BinaryEncoder $e, array $item): void
    {
        // SpecifiedAttributes: DisplayName(1) | Description(2) | WriteMask(4) | UserWriteMask(8) | EventNotifier(16)
        $this->writeCommonAttributes($e, $item, 0x1F);
        $e->writeByte($item['eventNotifier'] ?? 0);
    }

    /**
     * @param BinaryEncoder $e
     * @param array $item
     */
    private function writeVariableAttributes(BinaryEncoder $e, array $item): void
    {
        // SpecifiedAttributes bitmask for Variable:
        // DisplayName(1) | Description(2) | WriteMask(4) | UserWriteMask(8)
        // | Value(16) | DataType(32) | ValueRank(64) | ArrayDimensions(128)
        // | AccessLevel(256) | UserAccessLevel(512) | MinimumSamplingInterval(1024) | Historizing(2048)
        $this->writeCommonAttributes($e, $item, 0x0FFF);

        // Value — write as Variant (empty if not provided)
        if (isset($item['value'])) {
            $e->writeVariant($item['value']);
        } else {
            $e->writeByte(0); // Null variant
        }

        // DataType
        $e->writeNodeId($item['dataType'] ?? NodeId::numeric(0, 24)); // BaseDataType

        // ValueRank
        $e->writeInt32($item['valueRank'] ?? -1); // Scalar

        // ArrayDimensions
        $dims = $item['arrayDimensions'] ?? [];
        $e->writeInt32(count($dims));
        foreach ($dims as $dim) {
            $e->writeUInt32($dim);
        }

        // AccessLevel, UserAccessLevel
        $e->writeByte($item['accessLevel'] ?? 1); // CurrentRead
        $e->writeByte($item['userAccessLevel'] ?? 1);

        // MinimumSamplingInterval
        $e->writeDouble($item['minimumSamplingInterval'] ?? 0.0);

        // Historizing
        $e->writeBoolean($item['historizing'] ?? false);
    }

    /**
     * @param BinaryEncoder $e
     * @param array $item
     */
    private function writeMethodAttributes(BinaryEncoder $e, array $item): void
    {
        // SpecifiedAttributes: DisplayName(1) | Description(2) | WriteMask(4) | UserWriteMask(8) | Executable(16) | UserExecutable(32)
        $this->writeCommonAttributes($e, $item, 0x3F);
        $e->writeBoolean($item['executable'] ?? true);
        $e->writeBoolean($item['userExecutable'] ?? true);
    }

    /**
     * @param BinaryEncoder $e
     * @param array $item
     */
    private function writeObjectTypeAttributes(BinaryEncoder $e, array $item): void
    {
        // SpecifiedAttributes: DisplayName(1) | Description(2) | WriteMask(4) | UserWriteMask(8) | IsAbstract(16)
        $this->writeCommonAttributes($e, $item, 0x1F);
        $e->writeBoolean($item['isAbstract'] ?? false);
    }

    /**
     * @param BinaryEncoder $e
     * @param array $item
     */
    private function writeVariableTypeAttributes(BinaryEncoder $e, array $item): void
    {
        // Same fields as Variable minus access-related + IsAbstract
        $this->writeCommonAttributes($e, $item, 0x07FF);

        if (isset($item['value'])) {
            $e->writeVariant($item['value']);
        } else {
            $e->writeByte(0);
        }

        $e->writeNodeId($item['dataType'] ?? NodeId::numeric(0, 24));
        $e->writeInt32($item['valueRank'] ?? -1);

        $dims = $item['arrayDimensions'] ?? [];
        $e->writeInt32(count($dims));
        foreach ($dims as $dim) {
            $e->writeUInt32($dim);
        }

        $e->writeBoolean($item['isAbstract'] ?? false);
    }

    /**
     * @param BinaryEncoder $e
     * @param array $item
     */
    private function writeReferenceTypeAttributes(BinaryEncoder $e, array $item): void
    {
        // SpecifiedAttributes: DisplayName(1) | Description(2) | WriteMask(4) | UserWriteMask(8) | IsAbstract(16) | Symmetric(32) | InverseName(64)
        $this->writeCommonAttributes($e, $item, 0x7F);
        $e->writeBoolean($item['isAbstract'] ?? false);
        $e->writeBoolean($item['symmetric'] ?? false);
        $e->writeLocalizedText(new LocalizedText(null, $item['inverseName'] ?? null));
    }

    /**
     * @param BinaryEncoder $e
     * @param array $item
     */
    private function writeDataTypeAttributes(BinaryEncoder $e, array $item): void
    {
        // SpecifiedAttributes: DisplayName(1) | Description(2) | WriteMask(4) | UserWriteMask(8) | IsAbstract(16)
        $this->writeCommonAttributes($e, $item, 0x1F);
        $e->writeBoolean($item['isAbstract'] ?? false);
    }

    /**
     * @param BinaryEncoder $e
     * @param array $item
     */
    private function writeViewAttributes(BinaryEncoder $e, array $item): void
    {
        // SpecifiedAttributes: DisplayName(1) | Description(2) | WriteMask(4) | UserWriteMask(8) | ContainsNoLoops(16) | EventNotifier(32)
        $this->writeCommonAttributes($e, $item, 0x3F);
        $e->writeBoolean($item['containsNoLoops'] ?? false);
        $e->writeByte($item['eventNotifier'] ?? 0);
    }
}
