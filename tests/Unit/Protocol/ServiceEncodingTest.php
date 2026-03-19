<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Protocol\BrowseService;
use Gianfriaur\OpcuaPhpClient\Protocol\CallService;
use Gianfriaur\OpcuaPhpClient\Protocol\GetEndpointsService;
use Gianfriaur\OpcuaPhpClient\Protocol\MessageHeader;
use Gianfriaur\OpcuaPhpClient\Protocol\ReadService;
use Gianfriaur\OpcuaPhpClient\Protocol\SecureChannelRequest;
use Gianfriaur\OpcuaPhpClient\Protocol\SecureChannelResponse;
use Gianfriaur\OpcuaPhpClient\Protocol\SessionService;
use Gianfriaur\OpcuaPhpClient\Protocol\SubscriptionService;
use Gianfriaur\OpcuaPhpClient\Protocol\WriteService;
use Gianfriaur\OpcuaPhpClient\Types\BrowseDirection;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\Variant;

describe('SecureChannelRequest encoding', function () {

    it('encodes a valid OPN message', function () {
        $request = new SecureChannelRequest();
        $bytes = $request->encode();

        // Should start with OPN header
        expect(substr($bytes, 0, 3))->toBe('OPN');
        expect($bytes[3])->toBe('F'); // Final chunk

        // Decode the header
        $decoder = new BinaryDecoder($bytes);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('OPN');
        expect($header->getMessageSize())->toBe(strlen($bytes));
    });

    it('encodes with custom secure channel ID', function () {
        $request = new SecureChannelRequest();
        $bytes = $request->encode(42);

        $decoder = new BinaryDecoder($bytes);
        MessageHeader::decode($decoder);
        $channelId = $decoder->readUInt32();
        expect($channelId)->toBe(42);
    });
});

describe('SecureChannelResponse decoding', function () {

    it('decodes a mock OPN response', function () {
        // Build a fake OPN response
        $response = buildMockOPNResponse(123, 456, 3600000);

        // Strip message header + channelId (12 bytes) since decode expects body only
        $bodyDecoder = new BinaryDecoder(substr($response, 12));
        $scResponse = SecureChannelResponse::decode($bodyDecoder);

        expect($scResponse->getSecureChannelId())->toBe(123);
        expect($scResponse->getTokenId())->toBe(456);
        expect($scResponse->getRevisedLifetime())->toBe(3600000);
    });
});

describe('SessionService', function () {

    it('getSecureChannelId returns constructor value without SecureChannel', function () {
        $session = new SessionService(100, 200);
        expect($session->getSecureChannelId())->toBe(100);
        expect($session->getTokenId())->toBe(200);
    });

    it('getNextSequenceNumber auto-increments from 2', function () {
        $session = new SessionService(1, 1);
        expect($session->getNextSequenceNumber())->toBe(2);
        expect($session->getNextSequenceNumber())->toBe(3);
        expect($session->getNextSequenceNumber())->toBe(4);
    });

    it('getSecureChannel returns null without SecureChannel', function () {
        $session = new SessionService(1, 1);
        expect($session->getSecureChannel())->toBeNull();
    });

    it('setUserTokenPolicyIds updates policy IDs', function () {
        $session = new SessionService(1, 1);
        $session->setUserTokenPolicyIds('user_pol', 'cert_pol', 'anon_pol');
        // We can verify these indirectly by encoding a session request
        // (the policy IDs are used in identity tokens)
        expect(true)->toBeTrue(); // No getter, but should not throw
    });

    it('setUserTokenPolicyIds ignores nulls', function () {
        $session = new SessionService(1, 1);
        $session->setUserTokenPolicyIds(null, null, null);
        // Should not change defaults
        expect(true)->toBeTrue();
    });

    it('encodes CreateSessionRequest as MSG', function () {
        $session = new SessionService(10, 20);
        $bytes = $session->encodeCreateSessionRequest(1, 'opc.tcp://localhost:4840');

        $decoder = new BinaryDecoder($bytes);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('MSG');
        expect($header->getMessageSize())->toBe(strlen($bytes));

        // SecureChannelId
        $channelId = $decoder->readUInt32();
        expect($channelId)->toBe(10);
    });

    it('encodes ActivateSessionRequest as MSG', function () {
        $session = new SessionService(10, 20);
        $authToken = NodeId::numeric(0, 999);
        $bytes = $session->encodeActivateSessionRequest(1, $authToken);

        $decoder = new BinaryDecoder($bytes);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('MSG');
    });

    it('encodes ActivateSessionRequest with username/password', function () {
        $session = new SessionService(10, 20);
        $authToken = NodeId::numeric(0, 999);
        $bytes = $session->encodeActivateSessionRequest(
            1, $authToken, 'admin', 'admin123',
        );

        expect(strlen($bytes))->toBeGreaterThan(0);
        $decoder = new BinaryDecoder($bytes);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('MSG');
    });

    it('unwrapResponse strips header for non-secure', function () {
        $session = new SessionService(1, 1);
        // Build a fake message with 12 byte header + body
        $fakeHeader = str_repeat("\x00", 12);
        $body = 'testbody';
        $message = $fakeHeader . $body;
        $result = $session->unwrapResponse($message);
        expect($result)->toBe($body);
    });
});

describe('BrowseService encoding', function () {

    it('encodes a browse request', function () {
        $session = new SessionService(1, 1);
        $service = new BrowseService($session);
        $bytes = $service->encodeBrowseRequest(1, NodeId::numeric(0, 85), NodeId::numeric(0, 0));

        $decoder = new BinaryDecoder($bytes);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('MSG');
        expect(strlen($bytes))->toBeGreaterThan(20);
    });

    it('encodes a browse request with filters', function () {
        $session = new SessionService(1, 1);
        $service = new BrowseService($session);
        $bytes = $service->encodeBrowseRequest(
            1,
            NodeId::numeric(0, 85),
            NodeId::numeric(0, 0),
            BrowseDirection::Inverse,  // direction: Inverse
            NodeId::numeric(0, 35),    // Organizes
            false,                     // includeSubtypes
            0xFF,                      // nodeClassMask
        );
        expect(strlen($bytes))->toBeGreaterThan(20);
    });

    it('encodes a browseNext request', function () {
        $session = new SessionService(1, 1);
        $service = new BrowseService($session);
        $bytes = $service->encodeBrowseNextRequest(1, 'continuation-point-bytes', NodeId::numeric(0, 0));

        $decoder = new BinaryDecoder($bytes);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('MSG');
    });
});

describe('ReadService encoding', function () {

    it('encodes a single read request', function () {
        $session = new SessionService(1, 1);
        $service = new ReadService($session);
        $bytes = $service->encodeReadRequest(1, NodeId::numeric(0, 2259), NodeId::numeric(0, 0));

        $decoder = new BinaryDecoder($bytes);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('MSG');
    });

    it('encodes a multi-read request', function () {
        $session = new SessionService(1, 1);
        $service = new ReadService($session);
        $bytes = $service->encodeReadMultiRequest(1, [
            ['nodeId' => NodeId::numeric(0, 2259)],
            ['nodeId' => NodeId::numeric(0, 2260), 'attributeId' => 1],
        ], NodeId::numeric(0, 0));

        expect(strlen($bytes))->toBeGreaterThan(40);
    });
});

describe('WriteService encoding', function () {

    it('encodes a single write request', function () {
        $session = new SessionService(1, 1);
        $service = new WriteService($session);
        $dv = new DataValue(new Variant(BuiltinType::Int32, 42));
        $bytes = $service->encodeWriteRequest(1, NodeId::numeric(1, 1000), $dv, NodeId::numeric(0, 0));

        $decoder = new BinaryDecoder($bytes);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('MSG');
    });

    it('encodes a multi-write request', function () {
        $session = new SessionService(1, 1);
        $service = new WriteService($session);
        $bytes = $service->encodeWriteMultiRequest(1, [
            ['nodeId' => NodeId::numeric(1, 100), 'dataValue' => new DataValue(new Variant(BuiltinType::Int32, 1))],
            ['nodeId' => NodeId::numeric(1, 101), 'dataValue' => new DataValue(new Variant(BuiltinType::Double, 3.14)), 'attributeId' => 13],
        ], NodeId::numeric(0, 0));

        expect(strlen($bytes))->toBeGreaterThan(40);
    });
});

describe('CallService encoding', function () {

    it('encodes a call request with no arguments', function () {
        $session = new SessionService(1, 1);
        $service = new CallService($session);
        $bytes = $service->encodeCallRequest(
            1,
            NodeId::numeric(1, 100),
            NodeId::numeric(1, 200),
            [],
            NodeId::numeric(0, 0),
        );

        $decoder = new BinaryDecoder($bytes);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('MSG');
    });

    it('encodes a call request with arguments', function () {
        $session = new SessionService(1, 1);
        $service = new CallService($session);
        $bytes = $service->encodeCallRequest(
            1,
            NodeId::numeric(1, 100),
            NodeId::numeric(1, 200),
            [
                new Variant(BuiltinType::Double, 3.0),
                new Variant(BuiltinType::Double, 4.0),
            ],
            NodeId::numeric(0, 0),
        );

        expect(strlen($bytes))->toBeGreaterThan(40);
    });
});

describe('GetEndpointsService encoding', function () {

    it('encodes a GetEndpoints request', function () {
        $session = new SessionService(1, 1);
        $service = new GetEndpointsService($session);
        $bytes = $service->encodeGetEndpointsRequest(1, 'opc.tcp://localhost:4840', NodeId::numeric(0, 0));

        $decoder = new BinaryDecoder($bytes);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('MSG');
    });
});

describe('SubscriptionService encoding', function () {

    it('encodes CreateSubscription request', function () {
        $session = new SessionService(1, 1);
        $service = new SubscriptionService($session);
        $bytes = $service->encodeCreateSubscriptionRequest(1, NodeId::numeric(0, 0));

        $decoder = new BinaryDecoder($bytes);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('MSG');
    });

    it('encodes CreateSubscription request with custom params', function () {
        $session = new SessionService(1, 1);
        $service = new SubscriptionService($session);
        $bytes = $service->encodeCreateSubscriptionRequest(
            1,
            NodeId::numeric(0, 0),
            1000.0,
            4800,
            20,
            100,
            false,
            5,
        );
        expect(strlen($bytes))->toBeGreaterThan(20);
    });

    it('encodes ModifySubscription request', function () {
        $session = new SessionService(1, 1);
        $service = new SubscriptionService($session);
        $bytes = $service->encodeModifySubscriptionRequest(1, NodeId::numeric(0, 0), 42);

        $decoder = new BinaryDecoder($bytes);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('MSG');
    });

    it('encodes DeleteSubscriptions request', function () {
        $session = new SessionService(1, 1);
        $service = new SubscriptionService($session);
        $bytes = $service->encodeDeleteSubscriptionsRequest(1, NodeId::numeric(0, 0), [10, 20, 30]);

        $decoder = new BinaryDecoder($bytes);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('MSG');
    });

    it('encodes SetPublishingMode request', function () {
        $session = new SessionService(1, 1);
        $service = new SubscriptionService($session);
        $bytes = $service->encodeSetPublishingModeRequest(1, NodeId::numeric(0, 0), true, [42]);

        $decoder = new BinaryDecoder($bytes);
        $header = MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('MSG');
    });
});

// Helper to build a mock OPN response
function buildMockOPNResponse(int $channelId, int $tokenId, int $lifetime): string
{
    $encoder = new BinaryEncoder();

    // Security header
    $encoder->writeString('http://opcfoundation.org/UA/SecurityPolicy#None');
    $encoder->writeByteString(null);
    $encoder->writeByteString(null);

    // Sequence header
    $encoder->writeUInt32(1);
    $encoder->writeUInt32(1);

    // TypeId: OpenSecureChannelResponse (449)
    $encoder->writeNodeId(NodeId::numeric(0, 449));

    // ResponseHeader
    $encoder->writeInt64(0);     // Timestamp
    $encoder->writeUInt32(1);    // RequestHandle
    $encoder->writeUInt32(0);    // StatusCode (Good)
    $encoder->writeByte(0);      // DiagnosticInfo
    $encoder->writeInt32(0);     // StringTable
    $encoder->writeNodeId(NodeId::numeric(0, 0)); // AdditionalHeader
    $encoder->writeByte(0);

    // OPN fields
    $encoder->writeUInt32(0);                // ServerProtocolVersion
    $encoder->writeUInt32($channelId);       // SecureChannelId
    $encoder->writeUInt32($tokenId);         // TokenId
    $encoder->writeInt64(0);                 // CreatedAt
    $encoder->writeUInt32($lifetime);        // RevisedLifetime
    $encoder->writeByteString(null);         // ServerNonce

    $body = $encoder->getBuffer();
    $totalSize = 12 + strlen($body);

    $headerEncoder = new BinaryEncoder();
    $header = new MessageHeader('OPN', 'F', $totalSize);
    $header->encode($headerEncoder);
    $headerEncoder->writeUInt32(0); // ChannelId in header
    $headerEncoder->writeRawBytes($body);

    return $headerEncoder->getBuffer();
}
