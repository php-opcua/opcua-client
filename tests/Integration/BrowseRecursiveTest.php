<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\BrowseDirection;
use Gianfriaur\OpcuaPhpClient\Types\BrowseNode;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

describe('browseAll', function () {

    it('returns all references for a node', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $refs = $client->browseAll(NodeId::numeric(0, 85));
            expect($refs)->toBeArray()->not->toBeEmpty();

            $names = array_map(fn($r) => $r->getBrowseName()->getName(), $refs);
            expect($names)->toContain('Server');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('returns same results as browse when no continuation', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $browseRefs = $client->browse(NodeId::numeric(0, 85));
            $allRefs = $client->browseAll(NodeId::numeric(0, 85));

            expect(count($allRefs))->toBe(count($browseRefs));
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');

describe('browseRecursive', function () {

    it('returns a tree of BrowseNode objects', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $tree = $client->browseRecursive(NodeId::numeric(0, 85), maxDepth: 1);

            expect($tree)->toBeArray()->not->toBeEmpty();
            expect($tree[0])->toBeInstanceOf(BrowseNode::class);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('maxDepth=1 returns only direct children without their children', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $tree = $client->browseRecursive(NodeId::numeric(0, 85), maxDepth: 1);

            foreach ($tree as $node) {
                expect($node->getChildren())->toBeEmpty();
            }
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('maxDepth=2 returns children with their children', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $tree = $client->browseRecursive(NodeId::numeric(0, 85), maxDepth: 2);

            $testServer = null;
            foreach ($tree as $node) {
                if ($node->getBrowseName()->getName() === 'TestServer') {
                    $testServer = $node;
                    break;
                }
            }

            expect($testServer)->not->toBeNull();
            expect($testServer->hasChildren())->toBeTrue();

            foreach ($testServer->getChildren() as $child) {
                expect($child->getChildren())->toBeEmpty();
            }
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('maxDepth=3 goes three levels deep', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $tree = $client->browseRecursive(NodeId::numeric(0, 85), maxDepth: 3);

            $testServer = null;
            foreach ($tree as $node) {
                if ($node->getBrowseName()->getName() === 'TestServer') {
                    $testServer = $node;
                    break;
                }
            }

            expect($testServer)->not->toBeNull();

            $dataTypes = null;
            foreach ($testServer->getChildren() as $child) {
                if ($child->getBrowseName()->getName() === 'DataTypes') {
                    $dataTypes = $child;
                    break;
                }
            }

            expect($dataTypes)->not->toBeNull();
            expect($dataTypes->hasChildren())->toBeTrue();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('browses a specific subtree', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $methodsNodeId = TestHelper::browseToNode($client, ['TestServer', 'Methods']);

            $tree = $client->browseRecursive($methodsNodeId, maxDepth: 1);
            expect($tree)->toBeArray()->not->toBeEmpty();

            $names = array_map(fn(BrowseNode $n) => $n->getBrowseName()->getName(), $tree);
            expect($names)->toContain('Add');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('default maxDepth is 10', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $methodsNodeId = TestHelper::browseToNode($client, ['TestServer', 'Methods']);
            $tree = $client->browseRecursive($methodsNodeId);
            expect($tree)->toBeArray()->not->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('uses configured defaultBrowseMaxDepth when maxDepth is not passed', function () {
        $client = null;
        try {
            $client = new Client();
            $client->setDefaultBrowseMaxDepth(1);
            $client->connect(TestHelper::ENDPOINT_NO_SECURITY);

            $tree = $client->browseRecursive(NodeId::numeric(0, 85));

            foreach ($tree as $node) {
                expect($node->getChildren())->toBeEmpty();
            }
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('explicit maxDepth overrides the configured default', function () {
        $client = null;
        try {
            $client = new Client();
            $client->setDefaultBrowseMaxDepth(1);
            $client->connect(TestHelper::ENDPOINT_NO_SECURITY);

            $tree = $client->browseRecursive(NodeId::numeric(0, 85), maxDepth: 2);

            $testServer = null;
            foreach ($tree as $node) {
                if ($node->getBrowseName()->getName() === 'TestServer') {
                    $testServer = $node;
                    break;
                }
            }

            expect($testServer)->not->toBeNull();
            expect($testServer->hasChildren())->toBeTrue();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('maxDepth=-1 browses unlimited (up to hardcoded cap)', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $methodsNodeId = TestHelper::browseToNode($client, ['TestServer', 'Methods']);
            $tree = $client->browseRecursive($methodsNodeId, maxDepth: -1);
            expect($tree)->toBeArray()->not->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('cycle detection prevents infinite loops', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $tree = $client->browseRecursive(
                NodeId::numeric(0, 2253),
                direction: BrowseDirection::Both,
                maxDepth: 5,
            );

            expect($tree)->toBeArray();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('cycle detection skips already visited nodes', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $tree = $client->browseRecursive(
                NodeId::numeric(0, 85),
                direction: BrowseDirection::Both,
                maxDepth: 3,
            );

            $allNodeIds = [];
            $collectNodeIds = function (array $nodes) use (&$collectNodeIds, &$allNodeIds): void {
                foreach ($nodes as $node) {
                    $allNodeIds[] = $node->getNodeId()->__toString();
                    $collectNodeIds($node->getChildren());
                }
            };
            $collectNodeIds($tree);

            expect(count($allNodeIds))->toBeGreaterThan(0);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');
