<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Types\BrowseNode;
use Gianfriaur\OpcuaPhpClient\Types\LocalizedText;
use Gianfriaur\OpcuaPhpClient\Types\NodeClass;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\QualifiedName;
use Gianfriaur\OpcuaPhpClient\Types\ReferenceDescription;

function makeRef(int $id, string $name, NodeClass $class = NodeClass::Object): ReferenceDescription
{
    return new ReferenceDescription(
        referenceTypeId: NodeId::numeric(0, 35),
        isForward: true,
        nodeId: NodeId::numeric(0, $id),
        browseName: new QualifiedName(0, $name),
        displayName: new LocalizedText(null, $name),
        nodeClass: $class,
    );
}

describe('BrowseNode', function () {

    it('wraps a ReferenceDescription', function () {
        $ref = makeRef(85, 'Objects');
        $node = new BrowseNode($ref);

        expect($node->getReference())->toBe($ref);
        expect($node->getNodeId()->getIdentifier())->toBe(85);
        expect((string) $node->getDisplayName())->toBe('Objects');
        expect($node->getBrowseName()->getName())->toBe('Objects');
        expect($node->getNodeClass())->toBe(NodeClass::Object);
    });

    it('starts with no children', function () {
        $node = new BrowseNode(makeRef(85, 'Objects'));
        expect($node->getChildren())->toBeEmpty();
        expect($node->hasChildren())->toBeFalse();
    });

    it('can add children', function () {
        $parent = new BrowseNode(makeRef(85, 'Objects'));
        $child1 = new BrowseNode(makeRef(2253, 'Server'));
        $child2 = new BrowseNode(makeRef(86, 'Types'));

        $parent->addChild($child1);
        $parent->addChild($child2);

        expect($parent->getChildren())->toHaveCount(2);
        expect($parent->hasChildren())->toBeTrue();
        expect($parent->getChildren()[0]->getBrowseName()->getName())->toBe('Server');
        expect($parent->getChildren()[1]->getBrowseName()->getName())->toBe('Types');
    });

    it('supports nested children', function () {
        $root = new BrowseNode(makeRef(85, 'Objects'));
        $child = new BrowseNode(makeRef(2253, 'Server'));
        $grandchild = new BrowseNode(makeRef(2256, 'ServerStatus'));

        $child->addChild($grandchild);
        $root->addChild($child);

        expect($root->getChildren()[0]->getChildren()[0]->getBrowseName()->getName())->toBe('ServerStatus');
    });
});
