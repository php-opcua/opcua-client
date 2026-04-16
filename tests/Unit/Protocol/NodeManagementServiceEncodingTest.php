<?php

declare(strict_types=1);

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Protocol\NodeManagementService;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Types\AddNodesResult;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\QualifiedName;

describe('NodeManagementService encoding', function () {

    it('encodes an AddNodes request with Object node class', function () {
        $session = new SessionService(1, 1);
        $service = new NodeManagementService($session);

        $bytes = $service->encodeAddNodesRequest(1, [
            [
                'parentNodeId' => NodeId::numeric(0, 85),
                'referenceTypeId' => NodeId::numeric(0, 35),
                'requestedNewNodeId' => NodeId::numeric(2, 1001),
                'browseName' => new QualifiedName(2, 'TestObject'),
                'nodeClass' => NodeClass::Object,
                'typeDefinition' => NodeId::numeric(0, 58),
            ],
        ], NodeId::numeric(0, 0));

        expect(strlen($bytes))->toBeGreaterThan(40);
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });

    it('encodes an AddNodes request with Variable node class', function () {
        $session = new SessionService(1, 1);
        $service = new NodeManagementService($session);

        $bytes = $service->encodeAddNodesRequest(1, [
            [
                'parentNodeId' => NodeId::numeric(2, 1001),
                'referenceTypeId' => NodeId::numeric(0, 47),
                'requestedNewNodeId' => NodeId::numeric(2, 1002),
                'browseName' => new QualifiedName(2, 'Temperature'),
                'nodeClass' => NodeClass::Variable,
                'typeDefinition' => NodeId::numeric(0, 63),
                'dataType' => NodeId::numeric(0, 11),
                'accessLevel' => 3,
            ],
        ], NodeId::numeric(0, 0));

        expect(strlen($bytes))->toBeGreaterThan(60);
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });

    it('encodes an AddNodes request with Method node class', function () {
        $session = new SessionService(1, 1);
        $service = new NodeManagementService($session);

        $bytes = $service->encodeAddNodesRequest(1, [
            [
                'parentNodeId' => NodeId::numeric(2, 1001),
                'referenceTypeId' => NodeId::numeric(0, 47),
                'requestedNewNodeId' => NodeId::numeric(2, 2001),
                'browseName' => new QualifiedName(2, 'MyMethod'),
                'nodeClass' => NodeClass::Method,
                'typeDefinition' => NodeId::numeric(0, 0),
                'executable' => true,
                'userExecutable' => true,
            ],
        ], NodeId::numeric(0, 0));

        expect(strlen($bytes))->toBeGreaterThan(40);
    });

    it('encodes a DeleteNodes request', function () {
        $session = new SessionService(1, 1);
        $service = new NodeManagementService($session);

        $bytes = $service->encodeDeleteNodesRequest(1, [
            ['nodeId' => NodeId::numeric(2, 1001), 'deleteTargetReferences' => true],
            ['nodeId' => NodeId::numeric(2, 1002)],
        ], NodeId::numeric(0, 0));

        expect(strlen($bytes))->toBeGreaterThan(40);
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });

    it('encodes an AddReferences request', function () {
        $session = new SessionService(1, 1);
        $service = new NodeManagementService($session);

        $bytes = $service->encodeAddReferencesRequest(1, [
            [
                'sourceNodeId' => NodeId::numeric(2, 1001),
                'referenceTypeId' => NodeId::numeric(0, 35),
                'isForward' => true,
                'targetNodeId' => NodeId::numeric(2, 1002),
                'targetNodeClass' => NodeClass::Variable,
            ],
        ], NodeId::numeric(0, 0));

        expect(strlen($bytes))->toBeGreaterThan(40);
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });

    it('encodes a DeleteReferences request', function () {
        $session = new SessionService(1, 1);
        $service = new NodeManagementService($session);

        $bytes = $service->encodeDeleteReferencesRequest(1, [
            [
                'sourceNodeId' => NodeId::numeric(2, 1001),
                'referenceTypeId' => NodeId::numeric(0, 35),
                'isForward' => true,
                'targetNodeId' => NodeId::numeric(2, 1002),
                'deleteBidirectional' => false,
            ],
        ], NodeId::numeric(0, 0));

        expect(strlen($bytes))->toBeGreaterThan(40);
        expect(substr($bytes, 0, 3))->toBe('MSG');
    });

    it('encodes AddNodes with all node classes', function () {
        $session = new SessionService(1, 1);
        $service = new NodeManagementService($session);

        $nodeClasses = [
            NodeClass::Object,
            NodeClass::Variable,
            NodeClass::Method,
            NodeClass::ObjectType,
            NodeClass::VariableType,
            NodeClass::ReferenceType,
            NodeClass::DataType,
            NodeClass::View,
        ];

        foreach ($nodeClasses as $nodeClass) {
            $bytes = $service->encodeAddNodesRequest(1, [
                [
                    'parentNodeId' => NodeId::numeric(0, 85),
                    'referenceTypeId' => NodeId::numeric(0, 35),
                    'requestedNewNodeId' => NodeId::numeric(2, 9999),
                    'browseName' => new QualifiedName(2, 'Test'),
                    'nodeClass' => $nodeClass,
                    'typeDefinition' => NodeId::numeric(0, 0),
                ],
            ], NodeId::numeric(0, 0));

            expect(strlen($bytes))->toBeGreaterThan(40);
        }
    });
});

describe('NodeManagementService decoding', function () {

    it('decodes an AddNodes response', function () {
        $session = new SessionService(1, 1);
        $service = new NodeManagementService($session);

        $e = new BinaryEncoder();
        // TokenId, SequenceNumber, RequestId
        $e->writeUInt32(1);
        $e->writeUInt32(2);
        $e->writeUInt32(1);
        // TypeNodeId (response)
        $e->writeNodeId(NodeId::numeric(0, 489));
        // ResponseHeader
        $e->writeInt64(0);    // timestamp
        $e->writeUInt32(1);   // requestHandle
        $e->writeUInt32(0);   // serviceResult
        $e->writeByte(0);     // serviceDiagnostics
        $e->writeInt32(0);    // stringTable
        $e->writeNodeId(NodeId::numeric(0, 0)); // additionalHeader
        $e->writeByte(0);     // additionalHeader encoding

        // Results array
        $e->writeInt32(2);
        // Result 1: Good + assigned NodeId
        $e->writeUInt32(0);
        $e->writeExpandedNodeId(NodeId::numeric(2, 1001));
        // Result 2: Bad + null NodeId
        $e->writeUInt32(0x80340000);
        $e->writeExpandedNodeId(NodeId::numeric(0, 0));

        // DiagnosticInfos
        $e->writeInt32(0);

        $decoder = new BinaryDecoder($e->getBuffer());
        $results = $service->decodeAddNodesResponse($decoder);

        expect($results)->toHaveCount(2);
        expect($results[0])->toBeInstanceOf(AddNodesResult::class);
        expect($results[0]->statusCode)->toBe(0);
        expect((string) $results[0]->addedNodeId)->toBe('ns=2;i=1001');
        expect($results[1]->statusCode)->toBe(0x80340000);
    });

    it('decodes a DeleteNodes response', function () {
        $session = new SessionService(1, 1);
        $service = new NodeManagementService($session);

        $e = new BinaryEncoder();
        $e->writeUInt32(1);
        $e->writeUInt32(2);
        $e->writeUInt32(1);
        $e->writeNodeId(NodeId::numeric(0, 501));
        $e->writeInt64(0);
        $e->writeUInt32(1);
        $e->writeUInt32(0);
        $e->writeByte(0);
        $e->writeInt32(0);
        $e->writeNodeId(NodeId::numeric(0, 0));
        $e->writeByte(0);

        $e->writeInt32(2);
        $e->writeUInt32(0);
        $e->writeUInt32(0x80340000);
        $e->writeInt32(0);

        $decoder = new BinaryDecoder($e->getBuffer());
        $results = $service->decodeDeleteNodesResponse($decoder);

        expect($results)->toHaveCount(2);
        expect($results[0])->toBe(0);
        expect($results[1])->toBe(0x80340000);
    });

    it('decodes an AddReferences response', function () {
        $session = new SessionService(1, 1);
        $service = new NodeManagementService($session);

        $e = new BinaryEncoder();
        $e->writeUInt32(1);
        $e->writeUInt32(2);
        $e->writeUInt32(1);
        $e->writeNodeId(NodeId::numeric(0, 495));
        $e->writeInt64(0);
        $e->writeUInt32(1);
        $e->writeUInt32(0);
        $e->writeByte(0);
        $e->writeInt32(0);
        $e->writeNodeId(NodeId::numeric(0, 0));
        $e->writeByte(0);

        $e->writeInt32(1);
        $e->writeUInt32(0);
        $e->writeInt32(0);

        $decoder = new BinaryDecoder($e->getBuffer());
        $results = $service->decodeAddReferencesResponse($decoder);

        expect($results)->toHaveCount(1);
        expect($results[0])->toBe(0);
    });

    it('decodes a DeleteReferences response', function () {
        $session = new SessionService(1, 1);
        $service = new NodeManagementService($session);

        $e = new BinaryEncoder();
        $e->writeUInt32(1);
        $e->writeUInt32(2);
        $e->writeUInt32(1);
        $e->writeNodeId(NodeId::numeric(0, 507));
        $e->writeInt64(0);
        $e->writeUInt32(1);
        $e->writeUInt32(0);
        $e->writeByte(0);
        $e->writeInt32(0);
        $e->writeNodeId(NodeId::numeric(0, 0));
        $e->writeByte(0);

        $e->writeInt32(1);
        $e->writeUInt32(0);
        $e->writeInt32(0);

        $decoder = new BinaryDecoder($e->getBuffer());
        $results = $service->decodeDeleteReferencesResponse($decoder);

        expect($results)->toHaveCount(1);
        expect($results[0])->toBe(0);
    });
});
