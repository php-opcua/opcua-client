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
            $client = TestHelper::connectForNodeManagement();

            $parentNodeId = NodeId::numeric(0, 85); // Objects folder
            $newNodeId = NodeId::string(1, 'PhpOpcuaTest_AddVariable_' . uniqid());

            $results = $client->addNodes([
                [
                    'parentNodeId' => $parentNodeId,
                    'referenceTypeId' => NodeId::numeric(0, 35), // Organizes
                    'requestedNewNodeId' => $newNodeId,
                    'browseName' => new QualifiedName(1, 'TestVariable'),
                    'nodeClass' => NodeClass::Variable,
                    'typeDefinition' => NodeId::numeric(0, 63), // BaseDataVariableType
                    'dataType' => NodeId::numeric(0, 6), // Int32
                    'accessLevel' => 3, // CurrentRead | CurrentWrite
                ],
            ]);

            expect($results)->toHaveCount(1);
            expect($results[0])->toBeInstanceOf(AddNodesResult::class);
            expect(StatusCode::isGood($results[0]->statusCode))->toBeTrue();

            $addedId = $results[0]->addedNodeId;

            $writeStatus = $client->write($addedId, 42, PhpOpcua\Client\Types\BuiltinType::Int32);
            expect(StatusCode::isGood($writeStatus))->toBeTrue();

            $dv = $client->read($addedId);
            expect($dv->getValue())->toBe(42);

            $deleteResults = $client->deleteNodes([
                ['nodeId' => $addedId, 'deleteTargetReferences' => true],
            ]);

            expect($deleteResults)->toHaveCount(1);
            expect(StatusCode::isGood($deleteResults[0]))->toBeTrue();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('adds an Object node under Objects folder', function () {
        $client = null;
        try {
            $client = TestHelper::connectForNodeManagement();

            $newNodeId = NodeId::string(1, 'PhpOpcuaTest_AddObject_' . uniqid());

            $results = $client->addNodes([
                [
                    'parentNodeId' => 'i=85',
                    'referenceTypeId' => 'i=35',
                    'requestedNewNodeId' => $newNodeId,
                    'browseName' => new QualifiedName(1, 'TestObject'),
                    'nodeClass' => NodeClass::Object,
                    'typeDefinition' => 'i=58',
                ],
            ]);

            expect($results)->toHaveCount(1);
            expect(StatusCode::isGood($results[0]->statusCode))->toBeTrue();

            $refs = $client->browse($results[0]->addedNodeId);
            expect($refs)->toBeArray();

            $client->deleteNodes([['nodeId' => $results[0]->addedNodeId]]);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('adds multiple nodes in a single request', function () {
        $client = null;
        try {
            $client = TestHelper::connectForNodeManagement();

            $uid = uniqid();
            $results = $client->addNodes([
                [
                    'parentNodeId' => 'i=85',
                    'referenceTypeId' => 'i=35',
                    'requestedNewNodeId' => NodeId::string(1, "PhpOpcuaTest_Multi1_{$uid}"),
                    'browseName' => new QualifiedName(1, 'Multi1'),
                    'nodeClass' => NodeClass::Object,
                    'typeDefinition' => 'i=58',
                ],
                [
                    'parentNodeId' => 'i=85',
                    'referenceTypeId' => 'i=35',
                    'requestedNewNodeId' => NodeId::string(1, "PhpOpcuaTest_Multi2_{$uid}"),
                    'browseName' => new QualifiedName(1, 'Multi2'),
                    'nodeClass' => NodeClass::Object,
                    'typeDefinition' => 'i=58',
                ],
            ]);

            expect($results)->toHaveCount(2);
            expect(StatusCode::isGood($results[0]->statusCode))->toBeTrue();
            expect(StatusCode::isGood($results[1]->statusCode))->toBeTrue();

            $client->deleteNodes(array_map(
                fn ($r) => ['nodeId' => $r->addedNodeId],
                $results,
            ));
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('deleteNodes returns error for non-existent node', function () {
        $client = null;
        try {
            $client = TestHelper::connectForNodeManagement();

            $results = $client->deleteNodes([
                ['nodeId' => NodeId::string(1, 'PhpOpcuaTest_NonExistent_' . uniqid())],
            ]);

            expect($results)->toHaveCount(1);
            expect(StatusCode::isBad($results[0]))->toBeTrue();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('adds and deletes a reference between existing nodes', function () {
        $client = null;
        $addedNodes = [];
        try {
            $client = TestHelper::connectForNodeManagement();

            $uid = uniqid();
            $nodeA = NodeId::string(1, "PhpOpcuaTest_RefA_{$uid}");
            $nodeB = NodeId::string(1, "PhpOpcuaTest_RefB_{$uid}");

            $addResults = $client->addNodes([
                [
                    'parentNodeId' => 'i=85',
                    'referenceTypeId' => 'i=35',
                    'requestedNewNodeId' => $nodeA,
                    'browseName' => new QualifiedName(1, 'RefNodeA'),
                    'nodeClass' => NodeClass::Object,
                    'typeDefinition' => 'i=58',
                ],
                [
                    'parentNodeId' => 'i=85',
                    'referenceTypeId' => 'i=35',
                    'requestedNewNodeId' => $nodeB,
                    'browseName' => new QualifiedName(1, 'RefNodeB'),
                    'nodeClass' => NodeClass::Object,
                    'typeDefinition' => 'i=58',
                ],
            ]);

            foreach ($addResults as $r) {
                expect(StatusCode::isGood($r->statusCode))->toBeTrue();
                $addedNodes[] = $r->addedNodeId;
            }

            $refResults = $client->addReferences([
                [
                    'sourceNodeId' => $addedNodes[0],
                    'referenceTypeId' => NodeId::numeric(0, 35),
                    'isForward' => true,
                    'targetNodeId' => $addedNodes[1],
                    'targetNodeClass' => NodeClass::Object,
                ],
            ]);

            expect($refResults)->toHaveCount(1);
            expect(StatusCode::isGood($refResults[0]))->toBeTrue();

            $refs = $client->browse($addedNodes[0]);
            $found = false;
            foreach ($refs as $ref) {
                if ((string) $ref->getNodeId() === (string) $addedNodes[1]) {
                    $found = true;
                    break;
                }
            }
            expect($found)->toBeTrue();

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
        } finally {
            if ($client !== null && count($addedNodes) > 0) {
                try {
                    $client->deleteNodes(array_map(fn ($n) => ['nodeId' => $n], $addedNodes));
                } catch (Throwable) {
                }
            }
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('uses string NodeIds for convenience', function () {
        $client = null;
        try {
            $client = TestHelper::connectForNodeManagement();

            $newNodeId = 'ns=1;s=PhpOpcuaTest_StringId_' . uniqid();

            $results = $client->addNodes([
                [
                    'parentNodeId' => 'i=85',
                    'referenceTypeId' => 'i=35',
                    'requestedNewNodeId' => $newNodeId,
                    'browseName' => new QualifiedName(1, 'StringIdNode'),
                    'nodeClass' => NodeClass::Object,
                    'typeDefinition' => 'i=58',
                ],
            ]);

            expect($results)->toHaveCount(1);
            expect(StatusCode::isGood($results[0]->statusCode))->toBeTrue();

            $client->deleteNodes([['nodeId' => $results[0]->addedNodeId]]);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');
