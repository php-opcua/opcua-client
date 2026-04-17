<?php

declare(strict_types=1);

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Module\Browse\BrowseService;
use PhpOpcua\Client\Module\Browse\GetEndpointsService;
use PhpOpcua\Client\Module\ReadWrite\CallService;
use PhpOpcua\Client\Module\ReadWrite\ReadService;
use PhpOpcua\Client\Module\ReadWrite\WriteService;
use PhpOpcua\Client\Module\Subscription\SubscriptionService;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\NodeId;

/**
 * Writes a minimal OPC UA ResponseHeader into the encoder.
 */
function writeResponseHeader(BinaryEncoder $encoder, int $statusCode = 0): void
{
    $encoder->writeInt64(0);                       // Timestamp
    $encoder->writeUInt32(1);                      // RequestHandle
    $encoder->writeUInt32($statusCode);            // StatusCode
    $encoder->writeByte(0);                        // DiagnosticInfo (empty)
    $encoder->writeInt32(0);                       // StringTable (empty)
    $encoder->writeNodeId(NodeId::numeric(0, 0));  // AdditionalHeader TypeId
    $encoder->writeByte(0);                        // AdditionalHeader encoding
}

/**
 * Writes the security header + sequence header that decode methods expect.
 */
function writeMessagePrefix(BinaryEncoder $encoder): void
{
    $encoder->writeUInt32(1);  // TokenId
    $encoder->writeUInt32(1);  // SequenceNumber
    $encoder->writeUInt32(1);  // RequestId
}

describe('ReadService decoding', function () {

    it('decodes a single-value ReadResponse', function () {
        $session = new SessionService(1, 1);
        $service = new ReadService($session);

        $encoder = new BinaryEncoder();
        writeMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 634)); // ReadResponse TypeId
        writeResponseHeader($encoder);
        // Results array: 1 DataValue
        $encoder->writeInt32(1);
        // DataValue: value only (mask 0x01)
        $encoder->writeByte(0x01);
        $encoder->writeByte(BuiltinType::Int32->value); // Variant type
        $encoder->writeInt32(42);
        // DiagnosticInfos: empty
        $encoder->writeInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $service->decodeReadResponse($decoder);
        expect($result->getValue())->toBe(42);
    });

    it('decodes a multi-value ReadResponse', function () {
        $session = new SessionService(1, 1);
        $service = new ReadService($session);

        $encoder = new BinaryEncoder();
        writeMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 634));
        writeResponseHeader($encoder);
        // Results array: 2 DataValues
        $encoder->writeInt32(2);
        // DataValue 1
        $encoder->writeByte(0x01);
        $encoder->writeByte(BuiltinType::Double->value);
        $encoder->writeDouble(3.14);
        // DataValue 2
        $encoder->writeByte(0x01);
        $encoder->writeByte(BuiltinType::String->value);
        $encoder->writeString('hello');
        // DiagnosticInfos: empty
        $encoder->writeInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $results = $service->decodeReadMultiResponse($decoder);
        expect($results)->toHaveCount(2);
        expect($results[0]->getValue())->toBe(3.14);
        expect($results[1]->getValue())->toBe('hello');
    });

    it('decodes empty ReadResponse returning empty DataValue', function () {
        $session = new SessionService(1, 1);
        $service = new ReadService($session);

        $encoder = new BinaryEncoder();
        writeMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 634));
        writeResponseHeader($encoder);
        $encoder->writeInt32(0); // 0 results
        $encoder->writeInt32(0); // 0 diagnostics

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $service->decodeReadResponse($decoder);
        expect($result->getValue())->toBeNull();
    });
});

describe('WriteService decoding', function () {

    it('decodes a WriteResponse with status codes', function () {
        $session = new SessionService(1, 1);
        $service = new WriteService($session);

        $encoder = new BinaryEncoder();
        writeMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 676)); // WriteResponse TypeId
        writeResponseHeader($encoder);
        // Results: 2 status codes
        $encoder->writeInt32(2);
        $encoder->writeUInt32(0);          // Good
        $encoder->writeUInt32(0x80730000); // BadNotWritable
        // DiagnosticInfos: empty
        $encoder->writeInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $results = $service->decodeWriteResponse($decoder);
        expect($results)->toHaveCount(2);
        expect($results[0])->toBe(0);
        expect($results[1])->toBe(0x80730000);
    });
});

describe('CallService decoding', function () {

    it('decodes a CallResponse with output arguments', function () {
        $session = new SessionService(1, 1);
        $service = new CallService($session);

        $encoder = new BinaryEncoder();
        writeMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 715)); // CallResponse TypeId
        writeResponseHeader($encoder);
        // Results array: 1 CallMethodResult
        $encoder->writeInt32(1);
        $encoder->writeUInt32(0); // StatusCode (Good)
        // InputArgumentResults: 2 status codes
        $encoder->writeInt32(2);
        $encoder->writeUInt32(0);
        $encoder->writeUInt32(0);
        // InputArgumentDiagnosticInfos: empty
        $encoder->writeInt32(0);
        // OutputArguments: 1 variant
        $encoder->writeInt32(1);
        $encoder->writeByte(BuiltinType::Double->value);
        $encoder->writeDouble(5.0);
        // DiagnosticInfos: empty
        $encoder->writeInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $service->decodeCallResponse($decoder);
        expect($result->statusCode)->toBe(0);
        expect($result->inputArgumentResults)->toHaveCount(2);
        expect($result->outputArguments)->toHaveCount(1);
        expect($result->outputArguments[0]->getValue())->toBe(5.0);
    });

    it('decodes a CallResponse with no results', function () {
        $session = new SessionService(1, 1);
        $service = new CallService($session);

        $encoder = new BinaryEncoder();
        writeMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 715));
        writeResponseHeader($encoder);
        $encoder->writeInt32(0); // no results
        $encoder->writeInt32(0); // no diagnostics

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $service->decodeCallResponse($decoder);
        expect($result->statusCode)->toBe(0);
        expect($result->outputArguments)->toBe([]);
    });
});

describe('BrowseService decoding', function () {

    it('decodes a BrowseResponse with references', function () {
        $session = new SessionService(1, 1);
        $service = new BrowseService($session);

        $encoder = new BinaryEncoder();
        writeMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 530)); // BrowseResponse TypeId
        writeResponseHeader($encoder);
        // Results array: 1 BrowseResult
        $encoder->writeInt32(1);
        $encoder->writeUInt32(0);             // StatusCode
        $encoder->writeByteString(null);       // ContinuationPoint
        // References: 1
        $encoder->writeInt32(1);
        // ReferenceDescription
        $encoder->writeNodeId(NodeId::numeric(0, 35));  // ReferenceTypeId
        $encoder->writeBoolean(true);                    // IsForward
        $encoder->writeExpandedNodeId(NodeId::numeric(0, 2253)); // TargetNodeId
        $encoder->writeUInt16(0);                        // BrowseName ns
        $encoder->writeString('Server');                 // BrowseName name
        $encoder->writeByte(0x02);                       // DisplayName mask (text only)
        $encoder->writeString('Server');                 // DisplayName text
        $encoder->writeUInt32(1);                        // NodeClass (Object)
        $encoder->writeExpandedNodeId(NodeId::numeric(0, 2004)); // TypeDefinition
        // DiagnosticInfos: empty
        $encoder->writeInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $service->decodeBrowseResponseWithContinuation($decoder);
        expect($result->references)->toHaveCount(1);
        expect($result->continuationPoint)->toBeNull();
        expect($result->references[0]->getBrowseName()->getName())->toBe('Server');
    });

    it('decodes a BrowseResponse with continuation point', function () {
        $session = new SessionService(1, 1);
        $service = new BrowseService($session);

        $encoder = new BinaryEncoder();
        writeMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 530));
        writeResponseHeader($encoder);
        $encoder->writeInt32(1);
        $encoder->writeUInt32(0);
        $encoder->writeByteString('continuation-data');
        $encoder->writeInt32(0); // 0 references
        // DiagnosticInfos: empty
        $encoder->writeInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $service->decodeBrowseResponseWithContinuation($decoder);
        expect($result->references)->toBe([]);
        expect($result->continuationPoint)->toBe('continuation-data');
    });

    it('decodeBrowseResponse returns flat array', function () {
        $session = new SessionService(1, 1);
        $service = new BrowseService($session);

        $encoder = new BinaryEncoder();
        writeMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 530));
        writeResponseHeader($encoder);
        $encoder->writeInt32(1);
        $encoder->writeUInt32(0);
        $encoder->writeByteString(null);
        $encoder->writeInt32(0); // 0 references
        $encoder->writeInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $service->decodeBrowseResponse($decoder);
        expect($result)->toBeArray();
        expect($result)->toBe([]);
    });

    it('decodes a BrowseNextResponse', function () {
        $session = new SessionService(1, 1);
        $service = new BrowseService($session);

        $encoder = new BinaryEncoder();
        writeMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 536)); // BrowseNextResponse
        writeResponseHeader($encoder);
        $encoder->writeInt32(1);
        $encoder->writeUInt32(0);
        $encoder->writeByteString(null);
        $encoder->writeInt32(0);
        $encoder->writeInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $service->decodeBrowseNextResponse($decoder);
        expect($result->references)->toBe([]);
        expect($result->continuationPoint)->toBeNull();
    });
});

describe('SubscriptionService decoding', function () {

    it('decodes CreateSubscriptionResponse', function () {
        $session = new SessionService(1, 1);
        $service = new SubscriptionService($session);

        $encoder = new BinaryEncoder();
        writeMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 790)); // CreateSubscriptionResponse
        writeResponseHeader($encoder);
        $encoder->writeUInt32(42);        // subscriptionId
        $encoder->writeDouble(500.0);     // revisedPublishingInterval
        $encoder->writeUInt32(2400);      // revisedLifetimeCount
        $encoder->writeUInt32(10);        // revisedMaxKeepAliveCount

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $service->decodeCreateSubscriptionResponse($decoder);
        expect($result->subscriptionId)->toBe(42);
        expect($result->revisedPublishingInterval)->toBe(500.0);
        expect($result->revisedLifetimeCount)->toBe(2400);
        expect($result->revisedMaxKeepAliveCount)->toBe(10);
    });

    it('decodes ModifySubscriptionResponse', function () {
        $session = new SessionService(1, 1);
        $service = new SubscriptionService($session);

        $encoder = new BinaryEncoder();
        writeMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 796)); // ModifySubscriptionResponse
        writeResponseHeader($encoder);
        $encoder->writeDouble(1000.0);
        $encoder->writeUInt32(4800);
        $encoder->writeUInt32(20);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $service->decodeModifySubscriptionResponse($decoder);
        expect($result['revisedPublishingInterval'])->toBe(1000.0);
        expect($result['revisedLifetimeCount'])->toBe(4800);
        expect($result['revisedMaxKeepAliveCount'])->toBe(20);
    });

    it('decodes DeleteSubscriptionsResponse', function () {
        $session = new SessionService(1, 1);
        $service = new SubscriptionService($session);

        $encoder = new BinaryEncoder();
        writeMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 850));
        writeResponseHeader($encoder);
        $encoder->writeInt32(2);
        $encoder->writeUInt32(0);          // Good
        $encoder->writeUInt32(0x80330000); // some error
        $encoder->writeInt32(0);           // no diagnostics

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $service->decodeDeleteSubscriptionsResponse($decoder);
        expect($result)->toHaveCount(2);
        expect($result[0])->toBe(0);
        expect($result[1])->toBe(0x80330000);
    });

    it('decodes SetPublishingModeResponse', function () {
        $session = new SessionService(1, 1);
        $service = new SubscriptionService($session);

        $encoder = new BinaryEncoder();
        writeMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 802));
        writeResponseHeader($encoder);
        $encoder->writeInt32(1);
        $encoder->writeUInt32(0); // Good
        $encoder->writeInt32(0);  // no diagnostics

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $service->decodeSetPublishingModeResponse($decoder);
        expect($result)->toHaveCount(1);
        expect($result[0])->toBe(0);
    });
});

describe('GetEndpointsService decoding', function () {

    it('decodes a GetEndpointsResponse', function () {
        $session = new SessionService(1, 1);
        $service = new GetEndpointsService($session);

        $encoder = new BinaryEncoder();
        writeMessagePrefix($encoder);
        $encoder->writeNodeId(NodeId::numeric(0, 431)); // GetEndpointsResponse
        writeResponseHeader($encoder);
        // Endpoints array: 1
        $encoder->writeInt32(1);
        // EndpointDescription
        $encoder->writeString('opc.tcp://localhost:4840'); // EndpointUrl
        // ApplicationDescription
        $encoder->writeString('urn:test:app');             // ApplicationUri
        $encoder->writeString(null);                       // ProductUri
        $encoder->writeByte(0x02);                         // ApplicationName mask
        $encoder->writeString('Test App');                 // ApplicationName text
        $encoder->writeUInt32(0);                          // ApplicationType
        $encoder->writeString(null);                       // GatewayServerUri
        $encoder->writeString(null);                       // DiscoveryProfileUri
        $encoder->writeInt32(0);                           // DiscoveryUrls count
        $encoder->writeByteString(null);                   // ServerCertificate
        $encoder->writeUInt32(1);                          // SecurityMode (None=1)
        $encoder->writeString('http://opcfoundation.org/UA/SecurityPolicy#None');
        // UserIdentityTokens: 1
        $encoder->writeInt32(1);
        $encoder->writeString('anonymous');                // PolicyId
        $encoder->writeUInt32(0);                          // TokenType (Anonymous)
        $encoder->writeString(null);                       // IssuedTokenType
        $encoder->writeString(null);                       // IssuerEndpointUrl
        $encoder->writeString(null);                       // SecurityPolicyUri
        $encoder->writeString('http://opcfoundation.org/UA-Profile/Transport/uatcp-uasc-uabinary');
        $encoder->writeByte(0);                            // SecurityLevel

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $service->decodeGetEndpointsResponse($decoder);
        expect($result)->toHaveCount(1);
        expect($result[0]->getEndpointUrl())->toBe('opc.tcp://localhost:4840');
        expect($result[0]->getSecurityMode())->toBe(1);
        expect($result[0]->getUserIdentityTokens())->toHaveCount(1);
        expect($result[0]->getUserIdentityTokens()[0]->getPolicyId())->toBe('anonymous');
    });
});

describe('SessionService decoding', function () {

    it('decodes CreateSessionResponse', function () {
        $session = new SessionService(1, 1);

        $encoder = new BinaryEncoder();
        // Security header + Sequence header
        $encoder->writeUInt32(1); // TokenId
        $encoder->writeUInt32(1); // SequenceNumber
        $encoder->writeUInt32(1); // RequestId
        // TypeId
        $encoder->writeNodeId(NodeId::numeric(0, 464));
        // ResponseHeader
        writeResponseHeader($encoder);
        // CreateSessionResponse fields
        $encoder->writeNodeId(NodeId::numeric(0, 100)); // SessionId
        $encoder->writeNodeId(NodeId::numeric(0, 200)); // AuthenticationToken
        $encoder->writeDouble(120000.0);                 // RevisedSessionTimeout
        $encoder->writeByteString('server-nonce');       // ServerNonce
        $encoder->writeByteString('server-cert');        // ServerCertificate
        // ServerEndpoints: 0
        $encoder->writeInt32(0);
        // ServerSoftwareCertificates: 0
        $encoder->writeInt32(0);
        // ServerSignature
        $encoder->writeString(null);      // Algorithm
        $encoder->writeByteString(null);  // Signature
        // MaxRequestMessageSize
        $encoder->writeUInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $result = $session->decodeCreateSessionResponse($decoder);
        expect($result['sessionId']->getIdentifier())->toBe(100);
        expect($result['authenticationToken']->getIdentifier())->toBe(200);
        expect($result['serverNonce'])->toBe('server-nonce');
        expect($result['serverCertificate'])->toBe('server-cert');
    });

    it('decodes ActivateSessionResponse', function () {
        $session = new SessionService(1, 1);

        $encoder = new BinaryEncoder();
        $encoder->writeUInt32(1);
        $encoder->writeUInt32(1);
        $encoder->writeUInt32(1);
        $encoder->writeNodeId(NodeId::numeric(0, 470)); // ActivateSessionResponse
        writeResponseHeader($encoder, 0);
        // ServerNonce
        $encoder->writeByteString('new-nonce');
        // Results: 0 status codes
        $encoder->writeInt32(0);
        // DiagnosticInfos: 0
        $encoder->writeInt32(0);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        // Should not throw
        $session->decodeActivateSessionResponse($decoder);
        expect(true)->toBeTrue();
    });

    it('throws ServiceException on failed ActivateSession', function () {
        $session = new SessionService(1, 1);

        $encoder = new BinaryEncoder();
        $encoder->writeUInt32(1);
        $encoder->writeUInt32(1);
        $encoder->writeUInt32(1);
        $encoder->writeNodeId(NodeId::numeric(0, 397)); // ServiceFault
        writeResponseHeader($encoder, 0x80070000); // BadIdentityTokenRejected

        $decoder = new BinaryDecoder($encoder->getBuffer());
        expect(fn () => $session->decodeActivateSessionResponse($decoder))
            ->toThrow(ServiceException::class);
    });
});
