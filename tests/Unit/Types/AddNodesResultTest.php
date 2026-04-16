<?php

declare(strict_types=1);

use PhpOpcua\Client\Types\AddNodesResult;
use PhpOpcua\Client\Types\NodeId;

describe('AddNodesResult', function () {

    it('stores statusCode and addedNodeId', function () {
        $nodeId = NodeId::numeric(2, 1001);
        $result = new AddNodesResult(0, $nodeId);

        expect($result->statusCode)->toBe(0);
        expect($result->addedNodeId)->toBe($nodeId);
    });

    it('stores a bad status code', function () {
        $nodeId = NodeId::numeric(0, 0);
        $result = new AddNodesResult(0x80340000, $nodeId);

        expect($result->statusCode)->toBe(0x80340000);
    });

    it('is readonly', function () {
        $result = new AddNodesResult(0, NodeId::numeric(0, 0));
        $ref = new ReflectionClass($result);

        expect($ref->isReadOnly())->toBeTrue();
    });
});
