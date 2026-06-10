<?php

declare(strict_types=1);

use PhpOpcua\Client\Types\AttributeId;

describe('AttributeId', function () {

    it('matches the attribute ids defined in OPC 10000-6 Annex A.1', function () {
        expect(AttributeId::NodeId)->toBe(1);
        expect(AttributeId::NodeClass)->toBe(2);
        expect(AttributeId::BrowseName)->toBe(3);
        expect(AttributeId::DisplayName)->toBe(4);
        expect(AttributeId::Description)->toBe(5);
        expect(AttributeId::WriteMask)->toBe(6);
        expect(AttributeId::UserWriteMask)->toBe(7);
        expect(AttributeId::IsAbstract)->toBe(8);
        expect(AttributeId::Symmetric)->toBe(9);
        expect(AttributeId::InverseName)->toBe(10);
        expect(AttributeId::ContainsNoLoops)->toBe(11);
        expect(AttributeId::EventNotifier)->toBe(12);
        expect(AttributeId::Value)->toBe(13);
        expect(AttributeId::DataType)->toBe(14);
        expect(AttributeId::ValueRank)->toBe(15);
        expect(AttributeId::ArrayDimensions)->toBe(16);
        expect(AttributeId::AccessLevel)->toBe(17);
        expect(AttributeId::UserAccessLevel)->toBe(18);
        expect(AttributeId::MinimumSamplingInterval)->toBe(19);
        expect(AttributeId::Historizing)->toBe(20);
        expect(AttributeId::Executable)->toBe(21);
        expect(AttributeId::UserExecutable)->toBe(22);
        expect(AttributeId::DataTypeDefinition)->toBe(23);
    });
});
