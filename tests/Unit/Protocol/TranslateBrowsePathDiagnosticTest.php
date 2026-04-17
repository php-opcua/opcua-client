<?php

declare(strict_types=1);

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Module\TranslateBrowsePath\TranslateBrowsePathService;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\QualifiedName;

function writeTBPResponseHeader(BinaryEncoder $encoder, int $statusCode = 0): void
{
    $encoder->writeUInt32(1);
    $encoder->writeUInt32(1);
    $encoder->writeUInt32(1);
    $encoder->writeNodeId(NodeId::numeric(0, 557));
    $encoder->writeInt64(0);
    $encoder->writeUInt32(1);
    $encoder->writeUInt32($statusCode);
    $encoder->writeByte(0);
    $encoder->writeInt32(0);
    $encoder->writeNodeId(NodeId::numeric(0, 0));
    $encoder->writeByte(0);
}

describe('TranslateBrowsePathService diagnostic info handling', function () {

    it('decodes response with diagnostic infos (all mask bits)', function () {
        $session = new SessionService(1, 1);
        $service = new TranslateBrowsePathService($session);

        $encoder = new BinaryEncoder();
        writeTBPResponseHeader($encoder);

        $encoder->writeInt32(1);
        $encoder->writeUInt32(0);
        $encoder->writeInt32(1);
        $encoder->writeExpandedNodeId(NodeId::numeric(0, 2253));
        $encoder->writeUInt32(0xFFFFFFFF);

        // mask 0x1F: symbolicId + namespaceUri + locale + additionalInfo + innerStatusCode
        $encoder->writeInt32(1);
        $encoder->writeByte(0x1F);
        $encoder->writeInt32(1);
        $encoder->writeInt32(2);
        $encoder->writeInt32(3);
        $encoder->writeString('details');
        $encoder->writeUInt32(0x80010000);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $results = $service->decodeTranslateResponse($decoder);
        expect($results)->toHaveCount(1);
        expect($results[0]->statusCode)->toBe(0);
        expect($results[0]->targets[0]->targetId->getIdentifier())->toBe(2253);
    });

    it('decodes response with nested inner diagnostic info', function () {
        $session = new SessionService(1, 1);
        $service = new TranslateBrowsePathService($session);

        $encoder = new BinaryEncoder();
        writeTBPResponseHeader($encoder);

        $encoder->writeInt32(1);
        $encoder->writeUInt32(0);
        $encoder->writeInt32(0);

        // mask 0x21: symbolicId + innerDiagnosticInfo
        $encoder->writeInt32(1);
        $encoder->writeByte(0x21);
        $encoder->writeInt32(42);
        $encoder->writeByte(0x08);
        $encoder->writeString('inner detail');

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $results = $service->decodeTranslateResponse($decoder);
        expect($results)->toHaveCount(1);
    });

    it('decodes response with multiple diagnostic infos', function () {
        $session = new SessionService(1, 1);
        $service = new TranslateBrowsePathService($session);

        $encoder = new BinaryEncoder();
        writeTBPResponseHeader($encoder);

        $encoder->writeInt32(2);
        $encoder->writeUInt32(0);
        $encoder->writeInt32(1);
        $encoder->writeExpandedNodeId(NodeId::numeric(0, 100));
        $encoder->writeUInt32(0);
        $encoder->writeUInt32(0x80340000);
        $encoder->writeInt32(0);

        $encoder->writeInt32(2);
        $encoder->writeByte(0x02);
        $encoder->writeInt32(5);
        $encoder->writeByte(0x14);
        $encoder->writeInt32(7);
        $encoder->writeUInt32(0x80010000);

        $decoder = new BinaryDecoder($encoder->getBuffer());
        $results = $service->decodeTranslateResponse($decoder);
        expect($results)->toHaveCount(2);
    });

    it('encodes a translate request (non-secure)', function () {
        $session = new SessionService(1, 1);
        $service = new TranslateBrowsePathService($session);

        $bytes = $service->encodeTranslateRequest(
            1,
            [
                [
                    'startingNodeId' => NodeId::numeric(0, 85),
                    'relativePath' => [
                        ['targetName' => new QualifiedName(0, 'Server')],
                    ],
                ],
            ],
            NodeId::numeric(0, 0),
        );

        $decoder = new BinaryDecoder($bytes);
        $header = PhpOpcua\Client\Protocol\MessageHeader::decode($decoder);
        expect($header->getMessageType())->toBe('MSG');
        expect(strlen($bytes))->toBeGreaterThan(40);
    });
});
