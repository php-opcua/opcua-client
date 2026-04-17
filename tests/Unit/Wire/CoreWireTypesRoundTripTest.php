<?php

declare(strict_types=1);

use PhpOpcua\Client\Module\Browse\BrowseModule;
use PhpOpcua\Client\Module\Browse\BrowseResultSet;
use PhpOpcua\Client\Module\History\HistoryModule;
use PhpOpcua\Client\Module\ModuleRegistry;
use PhpOpcua\Client\Module\NodeManagement\AddNodesResult;
use PhpOpcua\Client\Module\NodeManagement\NodeManagementModule;
use PhpOpcua\Client\Module\ReadWrite\CallResult;
use PhpOpcua\Client\Module\ReadWrite\ReadWriteModule;
use PhpOpcua\Client\Module\ServerInfo\BuildInfo;
use PhpOpcua\Client\Module\ServerInfo\ServerInfoModule;
use PhpOpcua\Client\Module\Subscription\MonitoredItemModifyResult;
use PhpOpcua\Client\Module\Subscription\MonitoredItemResult;
use PhpOpcua\Client\Module\Subscription\PublishResult;
use PhpOpcua\Client\Module\Subscription\SetTriggeringResult;
use PhpOpcua\Client\Module\Subscription\SubscriptionModule;
use PhpOpcua\Client\Module\Subscription\SubscriptionResult;
use PhpOpcua\Client\Module\Subscription\TransferResult;
use PhpOpcua\Client\Module\TranslateBrowsePath\BrowsePathResult;
use PhpOpcua\Client\Module\TranslateBrowsePath\BrowsePathTarget;
use PhpOpcua\Client\Module\TranslateBrowsePath\TranslateBrowsePathModule;
use PhpOpcua\Client\Module\TypeDiscovery\TypeDiscoveryModule;
use PhpOpcua\Client\Types\BrowseDirection;
use PhpOpcua\Client\Types\BrowseNode;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\ConnectionState;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\EndpointDescription;
use PhpOpcua\Client\Types\ExtensionObject;
use PhpOpcua\Client\Types\LocalizedText;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\QualifiedName;
use PhpOpcua\Client\Types\ReferenceDescription;
use PhpOpcua\Client\Types\UserTokenPolicy;
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Wire\WireTypeRegistry;

/**
 * End-to-end round-trip validation of the wire infrastructure: build the
 * registry the way the session-manager daemon will (all built-in modules
 * loaded), encode a PHP value, serialize to JSON, decode back, assert equality
 * by field. Covers every WireSerializable DTO and every registered enum.
 */
function buildRegistryWithAllBuiltInModules(): WireTypeRegistry
{
    $modules = new ModuleRegistry();
    $modules->add(new ReadWriteModule());
    $modules->add(new BrowseModule());
    $modules->add(new SubscriptionModule());
    $modules->add(new HistoryModule());
    $modules->add(new NodeManagementModule());
    $modules->add(new TranslateBrowsePathModule());
    $modules->add(new ServerInfoModule());
    $modules->add(new TypeDiscoveryModule());

    return $modules->buildWireTypeRegistry();
}

function roundTrip(WireTypeRegistry $registry, mixed $value): mixed
{
    $encoded = $registry->encode($value);
    $json = json_encode($encoded, JSON_THROW_ON_ERROR);
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    return $registry->decode($decoded);
}

describe('Core + module DTO round-trip', function () {

    beforeEach(function () {
        $this->registry = buildRegistryWithAllBuiltInModules();
    });

    it('NodeId: numeric', function () {
        $n = NodeId::numeric(2, 85);
        $r = roundTrip($this->registry, $n);
        expect($r)->toBeInstanceOf(NodeId::class);
        expect((string) $r)->toBe('ns=2;i=85');
    });

    it('NodeId: string with slashes', function () {
        $n = NodeId::string(1, 'TestServer/Dynamic/Counter');
        $r = roundTrip($this->registry, $n);
        expect((string) $r)->toBe('ns=1;s=TestServer/Dynamic/Counter');
    });

    it('NodeId: guid', function () {
        $n = NodeId::guid(0, '72962b91-fa75-4ae6-8d28-b404dc7daf63');
        $r = roundTrip($this->registry, $n);
        expect((string) $r)->toBe('g=72962b91-fa75-4ae6-8d28-b404dc7daf63');
    });

    it('NodeId: opaque', function () {
        $n = NodeId::opaque(3, 'deadbeef');
        $r = roundTrip($this->registry, $n);
        expect((string) $r)->toBe('ns=3;b=deadbeef');
    });

    it('QualifiedName', function () {
        $q = new QualifiedName(2, 'Temperature');
        $r = roundTrip($this->registry, $q);
        expect($r->namespaceIndex)->toBe(2);
        expect($r->name)->toBe('Temperature');
    });

    it('LocalizedText with both fields', function () {
        $l = new LocalizedText('en-US', 'Hello');
        $r = roundTrip($this->registry, $l);
        expect($r->locale)->toBe('en-US');
        expect($r->text)->toBe('Hello');
    });

    it('LocalizedText with both nulls', function () {
        $l = new LocalizedText(null, null);
        $r = roundTrip($this->registry, $l);
        expect($r->locale)->toBeNull();
        expect($r->text)->toBeNull();
    });

    it('DataValue with Variant Int32 + timestamps', function () {
        $dt = new DateTimeImmutable('2026-04-17T10:30:00.123456+00:00');
        $dv = new DataValue(new Variant(BuiltinType::Int32, 42), 0, $dt, $dt);
        $r = roundTrip($this->registry, $dv);
        expect($r->getValue())->toBe(42);
        expect($r->statusCode)->toBe(0);
        expect($r->sourceTimestamp->format('Y-m-d\TH:i:s.uP'))->toBe('2026-04-17T10:30:00.123456+00:00');
    });

    it('Variant Double', function () {
        $v = new Variant(BuiltinType::Double, 3.14159);
        $r = roundTrip($this->registry, $v);
        expect($r->type)->toBe(BuiltinType::Double);
        expect($r->value)->toBe(3.14159);
    });

    it('Variant ByteString via base64', function () {
        $bytes = "\x00\x01\x02\xff\xfe";
        $v = new Variant(BuiltinType::ByteString, $bytes);
        $r = roundTrip($this->registry, $v);
        expect($r->type)->toBe(BuiltinType::ByteString);
        expect($r->value)->toBe($bytes);
    });

    it('Variant with dimensions', function () {
        $v = new Variant(BuiltinType::Int32, [[1, 2], [3, 4]], [2, 2]);
        $r = roundTrip($this->registry, $v);
        expect($r->value)->toBe([[1, 2], [3, 4]]);
        expect($r->dimensions)->toBe([2, 2]);
    });

    it('ExtensionObject with raw body', function () {
        $eo = new ExtensionObject(NodeId::numeric(0, 297), 0x01, "\x00\x01\x02", null);
        $r = roundTrip($this->registry, $eo);
        expect((string) $r->typeId)->toBe('i=297');
        expect($r->encoding)->toBe(0x01);
        expect($r->body)->toBe("\x00\x01\x02");
    });

    it('ReferenceDescription', function () {
        $rd = new ReferenceDescription(
            NodeId::numeric(0, 35),
            true,
            NodeId::numeric(0, 85),
            new QualifiedName(0, 'Objects'),
            new LocalizedText('en', 'Objects'),
            NodeClass::Object,
            NodeId::numeric(0, 61),
        );
        $r = roundTrip($this->registry, $rd);
        expect($r->nodeClass)->toBe(NodeClass::Object);
        expect((string) $r->nodeId)->toBe('i=85');
        expect((string) $r->referenceTypeId)->toBe('i=35');
        expect($r->typeDefinition)->not->toBeNull();
    });

    it('BrowseNode with children', function () {
        $rd = new ReferenceDescription(
            NodeId::numeric(0, 35), true, NodeId::numeric(0, 85),
            new QualifiedName(0, 'Objects'), new LocalizedText('en', 'Objects'),
            NodeClass::Object, null,
        );
        $parent = new BrowseNode($rd);
        $child = new BrowseNode(new ReferenceDescription(
            NodeId::numeric(0, 35), true, NodeId::numeric(0, 2253),
            new QualifiedName(0, 'Server'), new LocalizedText('en', 'Server'),
            NodeClass::Object, null,
        ));
        $parent->addChild($child);

        $r = roundTrip($this->registry, $parent);
        expect($r->hasChildren())->toBeTrue();
        expect(count($r->getChildren()))->toBe(1);
        expect((string) $r->getChildren()[0]->reference->nodeId)->toBe('i=2253');
    });

    it('EndpointDescription with userTokens array', function () {
        $ep = new EndpointDescription(
            'opc.tcp://host:4840',
            "\x00\x01cert",
            3,
            'http://opcfoundation.org/UA/SecurityPolicy#None',
            [new UserTokenPolicy('anonymous', 0, null, null, null)],
            'http://opcfoundation.org/UA-Profile/Transport/uatcp-uasc-uabinary',
            0,
        );
        $r = roundTrip($this->registry, $ep);
        expect($r->endpointUrl)->toBe('opc.tcp://host:4840');
        expect($r->serverCertificate)->toBe("\x00\x01cert");
        expect($r->userIdentityTokens)->toHaveCount(1);
        expect($r->userIdentityTokens[0])->toBeInstanceOf(UserTokenPolicy::class);
        expect($r->userIdentityTokens[0]->policyId)->toBe('anonymous');
    });

    it('BrowseDirection / NodeClass / BuiltinType / ConnectionState enums', function () {
        expect(roundTrip($this->registry, BrowseDirection::Both))->toBe(BrowseDirection::Both);
        expect(roundTrip($this->registry, NodeClass::Method))->toBe(NodeClass::Method);
        expect(roundTrip($this->registry, BuiltinType::UInt64))->toBe(BuiltinType::UInt64);
        expect(roundTrip($this->registry, ConnectionState::Connected))->toBe(ConnectionState::Connected);
    });

    it('SubscriptionResult', function () {
        $sr = new SubscriptionResult(42, 500.0, 10, 3);
        $r = roundTrip($this->registry, $sr);
        expect($r->subscriptionId)->toBe(42);
        expect($r->revisedPublishingInterval)->toBe(500.0);
        expect($r->revisedLifetimeCount)->toBe(10);
        expect($r->revisedMaxKeepAliveCount)->toBe(3);
    });

    it('TransferResult', function () {
        $tr = new TransferResult(0, [1, 2, 3]);
        $r = roundTrip($this->registry, $tr);
        expect($r->statusCode)->toBe(0);
        expect($r->availableSequenceNumbers)->toBe([1, 2, 3]);
    });

    it('MonitoredItemResult', function () {
        $mi = new MonitoredItemResult(0, 7, 100.0, 5);
        $r = roundTrip($this->registry, $mi);
        expect($r->monitoredItemId)->toBe(7);
        expect($r->revisedSamplingInterval)->toBe(100.0);
    });

    it('MonitoredItemModifyResult', function () {
        $mi = new MonitoredItemModifyResult(0, 50.0, 3);
        $r = roundTrip($this->registry, $mi);
        expect($r->revisedSamplingInterval)->toBe(50.0);
        expect($r->revisedQueueSize)->toBe(3);
    });

    it('PublishResult', function () {
        $pr = new PublishResult(1, 100, true, [], [99, 100]);
        $r = roundTrip($this->registry, $pr);
        expect($r->subscriptionId)->toBe(1);
        expect($r->moreNotifications)->toBeTrue();
        expect($r->availableSequenceNumbers)->toBe([99, 100]);
    });

    it('SetTriggeringResult', function () {
        $st = new SetTriggeringResult([0, 0], [0x80340000]);
        $r = roundTrip($this->registry, $st);
        expect($r->addResults)->toBe([0, 0]);
        expect($r->removeResults)->toBe([0x80340000]);
    });

    it('CallResult with Variant outputs', function () {
        $cr = new CallResult(0, [0], [new Variant(BuiltinType::Int32, 7), new Variant(BuiltinType::String, 'ok')]);
        $r = roundTrip($this->registry, $cr);
        expect($r->outputArguments)->toHaveCount(2);
        expect($r->outputArguments[0]->value)->toBe(7);
        expect($r->outputArguments[1]->value)->toBe('ok');
    });

    it('BrowsePathResult with targets', function () {
        $bp = new BrowsePathResult(0, [new BrowsePathTarget(NodeId::numeric(2, 100), 0)]);
        $r = roundTrip($this->registry, $bp);
        expect($r->statusCode)->toBe(0);
        expect($r->targets)->toHaveCount(1);
        expect((string) $r->targets[0]->targetId)->toBe('ns=2;i=100');
    });

    it('BrowseResultSet with references + continuationPoint bytes', function () {
        $refs = [new ReferenceDescription(
            NodeId::numeric(0, 35), true, NodeId::numeric(0, 85),
            new QualifiedName(0, 'Objects'), new LocalizedText('en', 'Objects'),
            NodeClass::Object, null,
        )];
        $bs = new BrowseResultSet($refs, "\x01\x02\x03");
        $r = roundTrip($this->registry, $bs);
        expect($r->references)->toHaveCount(1);
        expect($r->continuationPoint)->toBe("\x01\x02\x03");
    });

    it('AddNodesResult', function () {
        $a = new AddNodesResult(0, NodeId::string(2, 'MyNewNode'));
        $r = roundTrip($this->registry, $a);
        expect($r->statusCode)->toBe(0);
        expect((string) $r->addedNodeId)->toBe('ns=2;s=MyNewNode');
    });

    it('BuildInfo with all fields', function () {
        $bi = new BuildInfo('prod', 'mfg', '1.0', '42', new DateTimeImmutable('2026-04-17T00:00:00+00:00'));
        $r = roundTrip($this->registry, $bi);
        expect($r->productName)->toBe('prod');
        expect($r->manufacturerName)->toBe('mfg');
        expect($r->softwareVersion)->toBe('1.0');
        expect($r->buildNumber)->toBe('42');
        expect($r->buildDate->format('Y-m-d'))->toBe('2026-04-17');
    });

    it('BuildInfo with null fields', function () {
        $bi = new BuildInfo(null, null, null, null, null);
        $r = roundTrip($this->registry, $bi);
        expect($r->productName)->toBeNull();
        expect($r->buildDate)->toBeNull();
    });
});
