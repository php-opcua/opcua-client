<?php

declare(strict_types=1);

require_once __DIR__ . '/ClientTraitsCoverageTest.php';

use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Encoding\DynamicCodec;
use Gianfriaur\OpcuaPhpClient\Protocol\MessageHeader;
use Gianfriaur\OpcuaPhpClient\Protocol\SessionService;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\ConnectionState;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\StructureDefinition;

function tdBrowseResponse(array $refs): string
{
    return buildMsgResponse(530, function (BinaryEncoder $e) use ($refs) {
        $e->writeInt32(1);
        $e->writeUInt32(0);
        $e->writeByteString(null);
        $e->writeInt32(count($refs));
        foreach ($refs as $ref) {
            $e->writeNodeId(NodeId::numeric(0, $ref['refType'] ?? 45));
            $e->writeBoolean(true);
            $e->writeExpandedNodeId($ref['nodeId']);
            $e->writeUInt16($ref['nodeId']->namespaceIndex);
            $e->writeString($ref['browseName']);
            $e->writeByte(0x02);
            $e->writeString($ref['displayName'] ?? $ref['browseName']);
            $e->writeUInt32($ref['nodeClass'] ?? 64);
            $e->writeExpandedNodeId($ref['typeDef'] ?? NodeId::numeric(0, 0));
        }
        $e->writeInt32(0);
    });
}

function tdEmptyBrowse(): string
{
    return tdBrowseResponse([]);
}

function tdReadDefResponse(NodeId $encodingId, array $fields): string
{
    $bodyEncoder = new BinaryEncoder();
    $bodyEncoder->writeNodeId($encodingId);
    $bodyEncoder->writeNodeId(NodeId::numeric(0, 22));
    $bodyEncoder->writeUInt32(StructureDefinition::STRUCTURE);
    $bodyEncoder->writeInt32(count($fields));
    foreach ($fields as $field) {
        $bodyEncoder->writeString($field['name']);
        $bodyEncoder->writeLocalizedText(new \Gianfriaur\OpcuaPhpClient\Types\LocalizedText(null, null));
        $bodyEncoder->writeNodeId($field['dataType']);
        $bodyEncoder->writeInt32($field['valueRank'] ?? -1);
        $bodyEncoder->writeInt32(0);
        $bodyEncoder->writeUInt32(0);
        $bodyEncoder->writeBoolean($field['isOptional'] ?? false);
    }
    $body = $bodyEncoder->getBuffer();

    return buildMsgResponse(634, function (BinaryEncoder $e) use ($body) {
        $e->writeInt32(1);
        $e->writeByte(0x01);
        $e->writeByte(BuiltinType::ExtensionObject->value);
        $e->writeNodeId(NodeId::numeric(0, 122));
        $e->writeByte(0x01);
        $e->writeInt32(strlen($body));
        $e->writeRawBytes($body);
        $e->writeInt32(0);
    });
}

function tdBadReadResponse(): string
{
    return buildMsgResponse(634, function (BinaryEncoder $e) {
        $e->writeInt32(1);
        $e->writeByte(0x02);
        $e->writeUInt32(0x80350000);
        $e->writeInt32(0);
    });
}

describe('ManagesTypeDiscoveryTrait via MockTransport', function () {

    it('discovers a custom structured type', function () {
        $mock = new MockTransport();

        // browseRecursive from Structure(i=22): browseAll → finds custom type
        $mock->addResponse(tdBrowseResponse([
            ['nodeId' => NodeId::numeric(2, 3000), 'browseName' => 'PointXYZ', 'nodeClass' => 64],
        ]));
        // browseAll for subtypes of PointXYZ → empty
        $mock->addResponse(tdEmptyBrowse());
        // browse PointXYZ for HasEncoding → Default Binary
        $mock->addResponse(tdBrowseResponse([
            ['nodeId' => NodeId::numeric(2, 3010), 'browseName' => 'Default Binary', 'nodeClass' => 1],
        ]));
        // read DataTypeDefinition
        $mock->addResponse(tdReadDefResponse(
            NodeId::numeric(2, 3010),
            [
                ['name' => 'x', 'dataType' => NodeId::numeric(0, 11)],
                ['name' => 'y', 'dataType' => NodeId::numeric(0, 11)],
                ['name' => 'z', 'dataType' => NodeId::numeric(0, 11)],
            ],
        ));

        $client = setupConnectedClient($mock);
        $count = $client->discoverDataTypes();

        expect($count)->toBe(1);
        expect($client->getExtensionObjectRepository()->has(NodeId::numeric(2, 3010)))->toBeTrue();

        $codec = $client->getExtensionObjectRepository()->get(NodeId::numeric(2, 3010));
        expect($codec)->toBeInstanceOf(DynamicCodec::class);
        expect($codec->getDefinition()->fields)->toHaveCount(3);
    });

    it('skips namespace 0 types', function () {
        $mock = new MockTransport();

        $mock->addResponse(tdBrowseResponse([
            ['nodeId' => NodeId::numeric(0, 296), 'browseName' => 'Argument', 'nodeClass' => 64],
        ]));
        $mock->addResponse(tdEmptyBrowse());

        $client = setupConnectedClient($mock);
        $count = $client->discoverDataTypes();

        expect($count)->toBe(0);
    });

    it('filters by namespace index', function () {
        $mock = new MockTransport();

        $mock->addResponse(tdBrowseResponse([
            ['nodeId' => NodeId::numeric(2, 3000), 'browseName' => 'TypeA', 'nodeClass' => 64],
            ['nodeId' => NodeId::numeric(3, 4000), 'browseName' => 'TypeB', 'nodeClass' => 64],
        ]));
        // subtypes of TypeA → empty
        $mock->addResponse(tdEmptyBrowse());
        // subtypes of TypeB → empty
        $mock->addResponse(tdEmptyBrowse());
        // browse TypeB for HasEncoding
        $mock->addResponse(tdBrowseResponse([
            ['nodeId' => NodeId::numeric(3, 4010), 'browseName' => 'Default Binary', 'nodeClass' => 1],
        ]));
        // read DataTypeDefinition for TypeB
        $mock->addResponse(tdReadDefResponse(
            NodeId::numeric(3, 4010),
            [['name' => 'val', 'dataType' => NodeId::numeric(0, 6)]],
        ));

        $client = setupConnectedClient($mock);
        $count = $client->discoverDataTypes(namespaceIndex: 3);

        expect($count)->toBe(1);
        expect($client->getExtensionObjectRepository()->has(NodeId::numeric(3, 4010)))->toBeTrue();
    });

    it('returns 0 when no types found', function () {
        $mock = new MockTransport();

        $mock->addResponse(tdEmptyBrowse());

        $client = setupConnectedClient($mock);
        $count = $client->discoverDataTypes();

        expect($count)->toBe(0);
    });

    it('does not overwrite already registered codecs', function () {
        $mock = new MockTransport();

        $mock->addResponse(tdBrowseResponse([
            ['nodeId' => NodeId::numeric(2, 3000), 'browseName' => 'PointXYZ', 'nodeClass' => 64],
        ]));
        $mock->addResponse(tdEmptyBrowse());
        $mock->addResponse(tdBrowseResponse([
            ['nodeId' => NodeId::numeric(2, 3010), 'browseName' => 'Default Binary', 'nodeClass' => 1],
        ]));

        $client = setupConnectedClient($mock);
        $client->getExtensionObjectRepository()->register(
            NodeId::numeric(2, 3010),
            new class implements \Gianfriaur\OpcuaPhpClient\Encoding\ExtensionObjectCodec {
                public function decode(\Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder $d): array { return ['custom' => true]; }
                public function encode(\Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder $e, mixed $v): void {}
            },
        );

        $count = $client->discoverDataTypes();

        expect($count)->toBe(0);
    });

    it('skips enum definitions (TypeId i=123)', function () {
        $mock = new MockTransport();

        $mock->addResponse(tdBrowseResponse([
            ['nodeId' => NodeId::numeric(2, 5000), 'browseName' => 'MyEnum', 'nodeClass' => 64],
        ]));
        $mock->addResponse(tdEmptyBrowse());
        $mock->addResponse(tdBrowseResponse([
            ['nodeId' => NodeId::numeric(2, 5010), 'browseName' => 'Default Binary', 'nodeClass' => 1],
        ]));

        $enumBody = new BinaryEncoder();
        $enumBody->writeInt32(2);
        $enumBody->writeInt64(0);
        $enumBody->writeLocalizedText(new \Gianfriaur\OpcuaPhpClient\Types\LocalizedText(null, 'Off'));
        $enumBody->writeLocalizedText(new \Gianfriaur\OpcuaPhpClient\Types\LocalizedText(null, null));
        $enumBody->writeInt64(1);
        $enumBody->writeLocalizedText(new \Gianfriaur\OpcuaPhpClient\Types\LocalizedText(null, 'On'));
        $enumBody->writeLocalizedText(new \Gianfriaur\OpcuaPhpClient\Types\LocalizedText(null, null));
        $body = $enumBody->getBuffer();

        $mock->addResponse(buildMsgResponse(634, function (BinaryEncoder $e) use ($body) {
            $e->writeInt32(1);
            $e->writeByte(0x01);
            $e->writeByte(BuiltinType::ExtensionObject->value);
            $e->writeNodeId(NodeId::numeric(0, 123));
            $e->writeByte(0x01);
            $e->writeInt32(strlen($body));
            $e->writeRawBytes($body);
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        $count = $client->discoverDataTypes();

        expect($count)->toBe(0);
    });

    it('skips types without HasEncoding reference', function () {
        $mock = new MockTransport();

        $mock->addResponse(tdBrowseResponse([
            ['nodeId' => NodeId::numeric(2, 3000), 'browseName' => 'TypeNoBinary', 'nodeClass' => 64],
        ]));
        $mock->addResponse(tdEmptyBrowse());
        $mock->addResponse(tdEmptyBrowse());

        $client = setupConnectedClient($mock);
        $count = $client->discoverDataTypes();

        expect($count)->toBe(0);
    });

    it('skips types where read DataTypeDefinition returns bad status', function () {
        $mock = new MockTransport();

        $mock->addResponse(tdBrowseResponse([
            ['nodeId' => NodeId::numeric(2, 3000), 'browseName' => 'BadType', 'nodeClass' => 64],
        ]));
        $mock->addResponse(tdEmptyBrowse());
        $mock->addResponse(tdBrowseResponse([
            ['nodeId' => NodeId::numeric(2, 3010), 'browseName' => 'Default Binary', 'nodeClass' => 1],
        ]));
        $mock->addResponse(tdBadReadResponse());

        $client = setupConnectedClient($mock);
        $count = $client->discoverDataTypes();

        expect($count)->toBe(0);
    });

    it('handles errors gracefully during discovery', function () {
        $mock = new MockTransport();

        $mock->addResponse(tdBrowseResponse([
            ['nodeId' => NodeId::numeric(2, 3000), 'browseName' => 'CrashType', 'nodeClass' => 64],
        ]));
        $mock->addResponse(tdEmptyBrowse());
        $mock->addResponse(tdBrowseResponse([
            ['nodeId' => NodeId::numeric(2, 3010), 'browseName' => 'Default Binary', 'nodeClass' => 1],
        ]));

        $mock->addResponse(buildMsgResponse(634, function (BinaryEncoder $e) {
            $e->writeInt32(1);
            $e->writeByte(0x01);
            $e->writeByte(BuiltinType::String->value);
            $e->writeString('not an extension object');
            $e->writeInt32(0);
        }));

        $client = setupConnectedClient($mock);
        $count = $client->discoverDataTypes();

        expect($count)->toBe(0);
    });
});
