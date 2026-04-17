<?php

declare(strict_types=1);

use PhpOpcua\Client\Module\NodeManagement\AddNodesResult;
use PhpOpcua\Client\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\QualifiedName;
use PhpOpcua\Client\Types\StatusCode;

describe('NodeManagement Services', function () {

    it('adds a Variable node, reads it, and deletes it', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $parentNodeId = NodeId::numeric(0, 85); // Objects folder
            $newNodeId = NodeId::string(2, 'PhpOpcuaTest_AddVariable_' . uniqid());

            // Add a Variable node
            $results = $client->addNodes([
                [
                    'parentNodeId' => $parentNodeId,
                    'referenceTypeId' => NodeId::numeric(0, 35), // Organizes
                    'requestedNewNodeId' => $newNodeId,
                    'browseName' => new QualifiedName(2, 'TestVariable'),
                    'nodeClass' => NodeClass::Variable,
                    'typeDefinition' => NodeId::numeric(0, 63), // BaseDataVariableType
                    'dataType' => NodeId::numeric(0, 6), // Int32
                    'accessLevel' => 3, // CurrentRead | CurrentWrite
                ],
            ]);

            expect($results)->toHaveCount(1);
            expect($results[0])->toBeInstanceOf(AddNodesResult::class);

            if (StatusCode::isGood($results[0]->statusCode)) {
                $addedId = $results[0]->addedNodeId;

                // Write and read back to verify the node exists
                $writeStatus = $client->write($addedId, 42, PhpOpcua\Client\Types\BuiltinType::Int32);
                expect(StatusCode::isGood($writeStatus))->toBeTrue();

                $dv = $client->read($addedId);
                expect($dv->getValue())->toBe(42);

                // Delete the node
                $deleteResults = $client->deleteNodes([
                    ['nodeId' => $addedId, 'deleteTargetReferences' => true],
                ]);

                expect($deleteResults)->toHaveCount(1);
                expect(StatusCode::isGood($deleteResults[0]))->toBeTrue();
            } else {
                // Server may not support NodeManagement — BadServiceUnsupported is acceptable
                expect($results[0]->statusCode)->toBeIn([
                    StatusCode::BadServiceUnsupported,
                    0x80140000, // BadNotSupported
                    0x80480000, // BadParentNodeIdInvalid
                ]);
            }
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration')->skip('NodeManagement module disabled by default (see ROADMAP.md): UA .NET Standard returns a top-level ServiceFault for this service set, which the client does not yet decode.');

    it('adds an Object node under Objects folder', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $newNodeId = NodeId::string(2, 'PhpOpcuaTest_AddObject_' . uniqid());

            $results = $client->addNodes([
                [
                    'parentNodeId' => 'i=85',
                    'referenceTypeId' => 'i=35', // Organizes
                    'requestedNewNodeId' => $newNodeId,
                    'browseName' => new QualifiedName(2, 'TestObject'),
                    'nodeClass' => NodeClass::Object,
                    'typeDefinition' => 'i=58', // BaseObjectType
                ],
            ]);

            expect($results)->toHaveCount(1);

            if (StatusCode::isGood($results[0]->statusCode)) {
                // Browse to verify the node is visible
                $refs = $client->browse($results[0]->addedNodeId);
                expect($refs)->toBeArray();

                // Cleanup
                $client->deleteNodes([['nodeId' => $results[0]->addedNodeId]]);
            }
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration')->skip('NodeManagement module disabled by default (see ROADMAP.md): UA .NET Standard returns a top-level ServiceFault for this service set, which the client does not yet decode.');

    it('adds multiple nodes in a single request', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $uid = uniqid();
            $results = $client->addNodes([
                [
                    'parentNodeId' => 'i=85',
                    'referenceTypeId' => 'i=35',
                    'requestedNewNodeId' => NodeId::string(2, "PhpOpcuaTest_Multi1_{$uid}"),
                    'browseName' => new QualifiedName(2, 'Multi1'),
                    'nodeClass' => NodeClass::Object,
                    'typeDefinition' => 'i=58',
                ],
                [
                    'parentNodeId' => 'i=85',
                    'referenceTypeId' => 'i=35',
                    'requestedNewNodeId' => NodeId::string(2, "PhpOpcuaTest_Multi2_{$uid}"),
                    'browseName' => new QualifiedName(2, 'Multi2'),
                    'nodeClass' => NodeClass::Object,
                    'typeDefinition' => 'i=58',
                ],
            ]);

            expect($results)->toHaveCount(2);

            // Cleanup any successfully added nodes
            $toDelete = [];
            foreach ($results as $r) {
                if (StatusCode::isGood($r->statusCode)) {
                    $toDelete[] = ['nodeId' => $r->addedNodeId];
                }
            }
            if (count($toDelete) > 0) {
                $client->deleteNodes($toDelete);
            }
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration')->skip('NodeManagement module disabled by default (see ROADMAP.md): UA .NET Standard returns a top-level ServiceFault for this service set, which the client does not yet decode.');

    it('deleteNodes returns error for non-existent node', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $results = $client->deleteNodes([
                ['nodeId' => NodeId::string(2, 'PhpOpcuaTest_NonExistent_' . uniqid())],
            ]);

            expect($results)->toHaveCount(1);
            // Should be BadNodeIdUnknown or similar
            expect(StatusCode::isBad($results[0]))->toBeTrue();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration')->skip('NodeManagement module disabled by default (see ROADMAP.md): UA .NET Standard returns a top-level ServiceFault for this service set, which the client does not yet decode.');

    it('adds and deletes a reference between existing nodes', function () {
        $client = null;
        $addedNodes = [];
        try {
            $client = TestHelper::connectNoSecurity();

            $uid = uniqid();
            $nodeA = NodeId::string(2, "PhpOpcuaTest_RefA_{$uid}");
            $nodeB = NodeId::string(2, "PhpOpcuaTest_RefB_{$uid}");

            // Create two nodes first
            $addResults = $client->addNodes([
                [
                    'parentNodeId' => 'i=85',
                    'referenceTypeId' => 'i=35',
                    'requestedNewNodeId' => $nodeA,
                    'browseName' => new QualifiedName(2, 'RefNodeA'),
                    'nodeClass' => NodeClass::Object,
                    'typeDefinition' => 'i=58',
                ],
                [
                    'parentNodeId' => 'i=85',
                    'referenceTypeId' => 'i=35',
                    'requestedNewNodeId' => $nodeB,
                    'browseName' => new QualifiedName(2, 'RefNodeB'),
                    'nodeClass' => NodeClass::Object,
                    'typeDefinition' => 'i=58',
                ],
            ]);

            foreach ($addResults as $r) {
                if (StatusCode::isGood($r->statusCode)) {
                    $addedNodes[] = $r->addedNodeId;
                }
            }

            if (count($addedNodes) === 2) {
                // Add a reference from A to B
                $refResults = $client->addReferences([
                    [
                        'sourceNodeId' => $addedNodes[0],
                        'referenceTypeId' => NodeId::numeric(0, 35), // Organizes
                        'isForward' => true,
                        'targetNodeId' => $addedNodes[1],
                        'targetNodeClass' => NodeClass::Object,
                    ],
                ]);

                expect($refResults)->toHaveCount(1);

                if (StatusCode::isGood($refResults[0])) {
                    // Verify via browse
                    $refs = $client->browse($addedNodes[0]);
                    $found = false;
                    foreach ($refs as $ref) {
                        if ((string) $ref->getNodeId() === (string) $addedNodes[1]) {
                            $found = true;
                            break;
                        }
                    }
                    expect($found)->toBeTrue();

                    // Delete the reference
                    $delRefResults = $client->deleteReferences([
                        [
                            'sourceNodeId' => $addedNodes[0],
                            'referenceTypeId' => NodeId::numeric(0, 35),
                            'isForward' => true,
                            'targetNodeId' => $addedNodes[1],
                            'deleteBidirectional' => true,
                        ],
                    ]);

                    expect($delRefResults)->toHaveCount(1);
                    expect(StatusCode::isGood($delRefResults[0]))->toBeTrue();
                }
            }
        } finally {
            // Cleanup created nodes
            if ($client !== null && count($addedNodes) > 0) {
                try {
                    $client->deleteNodes(array_map(fn ($n) => ['nodeId' => $n], $addedNodes));
                } catch (Throwable) {
                }
            }
            TestHelper::safeDisconnect($client);
        }
    })->group('integration')->skip('NodeManagement module disabled by default (see ROADMAP.md): UA .NET Standard returns a top-level ServiceFault for this service set, which the client does not yet decode.');

    it('uses string NodeIds for convenience', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $newNodeId = 'ns=2;s=PhpOpcuaTest_StringId_' . uniqid();

            $results = $client->addNodes([
                [
                    'parentNodeId' => 'i=85',
                    'referenceTypeId' => 'i=35',
                    'requestedNewNodeId' => $newNodeId,
                    'browseName' => new QualifiedName(2, 'StringIdNode'),
                    'nodeClass' => NodeClass::Object,
                    'typeDefinition' => 'i=58',
                ],
            ]);

            expect($results)->toHaveCount(1);

            if (StatusCode::isGood($results[0]->statusCode)) {
                $client->deleteNodes([['nodeId' => $results[0]->addedNodeId]]);
            }
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration')->skip('NodeManagement module disabled by default (see ROADMAP.md): UA .NET Standard returns a top-level ServiceFault for this service set, which the client does not yet decode.');

})->group('integration');
