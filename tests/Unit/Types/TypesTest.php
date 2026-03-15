<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\EndpointDescription;
use Gianfriaur\OpcuaPhpClient\Types\LocalizedText;
use Gianfriaur\OpcuaPhpClient\Types\NodeClass;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\QualifiedName;
use Gianfriaur\OpcuaPhpClient\Types\ReferenceDescription;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;
use Gianfriaur\OpcuaPhpClient\Types\UserTokenPolicy;
use Gianfriaur\OpcuaPhpClient\Types\Variant;

describe('StatusCode', function () {

    it('identifies Good status codes', function () {
        expect(StatusCode::isGood(StatusCode::Good))->toBeTrue();
        expect(StatusCode::isBad(StatusCode::Good))->toBeFalse();
        expect(StatusCode::isUncertain(StatusCode::Good))->toBeFalse();
    });

    it('identifies Bad status codes', function () {
        expect(StatusCode::isBad(StatusCode::BadNodeIdUnknown))->toBeTrue();
        expect(StatusCode::isGood(StatusCode::BadNodeIdUnknown))->toBeFalse();
        expect(StatusCode::isUncertain(StatusCode::BadNodeIdUnknown))->toBeFalse();

        expect(StatusCode::isBad(StatusCode::BadInternalError))->toBeTrue();
        expect(StatusCode::isBad(StatusCode::BadNotWritable))->toBeTrue();
        expect(StatusCode::isBad(StatusCode::BadTypeMismatch))->toBeTrue();
        expect(StatusCode::isBad(StatusCode::BadUserAccessDenied))->toBeTrue();
    });

    it('identifies Uncertain status codes', function () {
        expect(StatusCode::isUncertain(StatusCode::UncertainNoCommunicationLastUsableValue))->toBeTrue();
        expect(StatusCode::isGood(StatusCode::UncertainNoCommunicationLastUsableValue))->toBeFalse();
        expect(StatusCode::isBad(StatusCode::UncertainNoCommunicationLastUsableValue))->toBeFalse();
    });

    it('returns correct names for known codes', function () {
        expect(StatusCode::getName(StatusCode::Good))->toBe('Good');
        expect(StatusCode::getName(StatusCode::BadNodeIdUnknown))->toBe('BadNodeIdUnknown');
        expect(StatusCode::getName(StatusCode::BadNotWritable))->toBe('BadNotWritable');
        expect(StatusCode::getName(StatusCode::BadInternalError))->toBe('BadInternalError');
        expect(StatusCode::getName(StatusCode::UncertainNoCommunicationLastUsableValue))->toBe('UncertainNoCommunicationLastUsableValue');
    });

    it('returns hex string for unknown codes', function () {
        expect(StatusCode::getName(0x80FF0000))->toBe('0x80FF0000');
        expect(StatusCode::getName(0x12345678))->toBe('0x12345678');
    });
});

describe('QualifiedName', function () {

    it('returns name and namespace index', function () {
        $qn = new QualifiedName(2, 'TestNode');
        expect($qn->getNamespaceIndex())->toBe(2);
        expect($qn->getName())->toBe('TestNode');
    });

    it('toString returns name only for namespace 0', function () {
        $qn = new QualifiedName(0, 'Server');
        expect((string) $qn)->toBe('Server');
    });

    it('toString returns ns:name for non-zero namespace', function () {
        $qn = new QualifiedName(3, 'Variable');
        expect((string) $qn)->toBe('3:Variable');
    });
});

describe('DataValue', function () {

    it('returns null value when no variant', function () {
        $dv = new DataValue();
        expect($dv->getValue())->toBeNull();
        expect($dv->getVariant())->toBeNull();
        expect($dv->getStatusCode())->toBe(0);
        expect($dv->getSourceTimestamp())->toBeNull();
        expect($dv->getServerTimestamp())->toBeNull();
    });

    it('returns value from variant', function () {
        $variant = new Variant(BuiltinType::Int32, 42);
        $dv = new DataValue($variant);
        expect($dv->getValue())->toBe(42);
        expect($dv->getVariant())->toBe($variant);
    });

    it('encoding mask reflects set fields', function () {
        // Nothing set
        $dv = new DataValue();
        expect($dv->getEncodingMask())->toBe(0);

        // Only value
        $dv = new DataValue(new Variant(BuiltinType::Boolean, true));
        expect($dv->getEncodingMask())->toBe(0x01);

        // Value + bad status code
        $dv = new DataValue(new Variant(BuiltinType::Boolean, true), StatusCode::BadNotWritable);
        expect($dv->getEncodingMask())->toBe(0x03);

        // Value + source timestamp
        $dv = new DataValue(new Variant(BuiltinType::Int32, 1), 0, new DateTimeImmutable());
        expect($dv->getEncodingMask())->toBe(0x05);

        // Value + server timestamp
        $dv = new DataValue(new Variant(BuiltinType::Int32, 1), 0, null, new DateTimeImmutable());
        expect($dv->getEncodingMask())->toBe(0x09);

        // All fields
        $dv = new DataValue(
            new Variant(BuiltinType::Double, 3.14),
            StatusCode::BadInternalError,
            new DateTimeImmutable(),
            new DateTimeImmutable(),
        );
        expect($dv->getEncodingMask())->toBe(0x0F);
    });

    it('returns timestamps', function () {
        $src = new DateTimeImmutable('2024-01-01 00:00:00');
        $srv = new DateTimeImmutable('2024-01-01 00:00:01');
        $dv = new DataValue(null, 0, $src, $srv);
        expect($dv->getSourceTimestamp())->toBe($src);
        expect($dv->getServerTimestamp())->toBe($srv);
    });
});

describe('UserTokenPolicy', function () {

    it('returns all properties', function () {
        $policy = new UserTokenPolicy('anon', 0, null, null, null);
        expect($policy->getPolicyId())->toBe('anon');
        expect($policy->getTokenType())->toBe(0);
        expect($policy->getIssuedTokenType())->toBeNull();
        expect($policy->getIssuerEndpointUrl())->toBeNull();
        expect($policy->getSecurityPolicyUri())->toBeNull();
    });

    it('returns all properties with values', function () {
        $policy = new UserTokenPolicy(
            'username_policy',
            1,
            'urn:token:type',
            'opc.tcp://issuer:4840',
            'http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256',
        );
        expect($policy->getPolicyId())->toBe('username_policy');
        expect($policy->getTokenType())->toBe(1);
        expect($policy->getIssuedTokenType())->toBe('urn:token:type');
        expect($policy->getIssuerEndpointUrl())->toBe('opc.tcp://issuer:4840');
        expect($policy->getSecurityPolicyUri())->toBe('http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256');
    });
});

describe('EndpointDescription', function () {

    it('returns all properties', function () {
        $token = new UserTokenPolicy('anon', 0, null, null, null);
        $ep = new EndpointDescription(
            'opc.tcp://localhost:4840',
            'cert-bytes',
            1,
            'http://opcfoundation.org/UA/SecurityPolicy#None',
            [$token],
            'http://opcfoundation.org/UA-Profile/Transport/uatcp-uasc-uabinary',
            0,
        );
        expect($ep->getEndpointUrl())->toBe('opc.tcp://localhost:4840');
        expect($ep->getServerCertificate())->toBe('cert-bytes');
        expect($ep->getSecurityMode())->toBe(1);
        expect($ep->getSecurityPolicyUri())->toBe('http://opcfoundation.org/UA/SecurityPolicy#None');
        expect($ep->getUserIdentityTokens())->toHaveCount(1);
        expect($ep->getTransportProfileUri())->toBe('http://opcfoundation.org/UA-Profile/Transport/uatcp-uasc-uabinary');
        expect($ep->getSecurityLevel())->toBe(0);
    });

    it('handles null server certificate', function () {
        $ep = new EndpointDescription('url', null, 1, 'policy', [], 'transport', 0);
        expect($ep->getServerCertificate())->toBeNull();
    });
});

describe('ReferenceDescription', function () {

    it('returns all properties', function () {
        $refTypeId = NodeId::numeric(0, 35);
        $nodeId = NodeId::numeric(1, 1000);
        $browseName = new QualifiedName(1, 'TestNode');
        $displayName = new LocalizedText('en', 'Test Node');
        $typeDef = NodeId::numeric(0, 61);

        $ref = new ReferenceDescription(
            $refTypeId,
            true,
            $nodeId,
            $browseName,
            $displayName,
            NodeClass::Variable,
            $typeDef,
        );

        expect($ref->getReferenceTypeId())->toBe($refTypeId);
        expect($ref->isForward())->toBeTrue();
        expect($ref->getNodeId())->toBe($nodeId);
        expect($ref->getBrowseName())->toBe($browseName);
        expect($ref->getDisplayName())->toBe($displayName);
        expect($ref->getNodeClass())->toBe(NodeClass::Variable);
        expect($ref->getTypeDefinition())->toBe($typeDef);
    });

    it('handles null type definition', function () {
        $ref = new ReferenceDescription(
            NodeId::numeric(0, 35),
            false,
            NodeId::numeric(0, 1),
            new QualifiedName(0, 'X'),
            new LocalizedText(null, 'X'),
            NodeClass::Object,
        );
        expect($ref->getTypeDefinition())->toBeNull();
        expect($ref->isForward())->toBeFalse();
    });
});

describe('NodeId', function () {

    it('creates guid NodeId', function () {
        $node = NodeId::guid(1, '12345678-1234-1234-1234-123456789abc');
        expect($node->isGuid())->toBeTrue();
        expect($node->isNumeric())->toBeFalse();
        expect($node->isString())->toBeFalse();
        expect($node->isOpaque())->toBeFalse();
        expect($node->getType())->toBe(NodeId::TYPE_GUID);
        expect($node->getEncodingByte())->toBe(0x04);
    });

    it('creates opaque NodeId', function () {
        $node = NodeId::opaque(2, 'deadbeef');
        expect($node->isOpaque())->toBeTrue();
        expect($node->getType())->toBe(NodeId::TYPE_OPAQUE);
        expect($node->getEncodingByte())->toBe(0x05);
    });

    it('encoding byte for large numeric uses full encoding', function () {
        // ns > 255 forces Numeric encoding
        $node = NodeId::numeric(256, 1);
        expect($node->getEncodingByte())->toBe(0x02);

        // id > 65535 forces Numeric encoding
        $node = NodeId::numeric(0, 70000);
        expect($node->getEncodingByte())->toBe(0x02);
    });
});

describe('Variant', function () {

    it('stores type and value', function () {
        $v = new Variant(BuiltinType::String, 'hello');
        expect($v->getType())->toBe(BuiltinType::String);
        expect($v->getValue())->toBe('hello');
    });

    it('stores array values', function () {
        $v = new Variant(BuiltinType::Int32, [1, 2, 3]);
        expect($v->getValue())->toBe([1, 2, 3]);
    });
});

describe('LocalizedText', function () {

    it('toString returns empty string when text is null', function () {
        $lt = new LocalizedText('en', null);
        expect((string) $lt)->toBe('');
    });

    it('encoding mask with only locale', function () {
        $lt = new LocalizedText('en', null);
        expect($lt->getEncodingMask())->toBe(0x01);
    });

    it('encoding mask with neither', function () {
        $lt = new LocalizedText(null, null);
        expect($lt->getEncodingMask())->toBe(0x00);
    });
});
