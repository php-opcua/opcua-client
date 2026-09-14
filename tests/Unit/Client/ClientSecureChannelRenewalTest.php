<?php

declare(strict_types=1);

require_once __DIR__ . '/ClientTraitsCoverageTest.php';

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Event\SecureChannelRenewed;
use PhpOpcua\Client\Protocol\MessageHeader;
use PhpOpcua\Client\Protocol\SecureChannelRequest;
use PhpOpcua\Client\Tests\Unit\Helpers\InMemoryEventDispatcher;
use PhpOpcua\Client\Types\BuiltinType;

function readResponseWithInt(int $value): string
{
    return buildMsgResponse(634, function (BinaryEncoder $e) use ($value) {
        $e->writeInt32(1);
        $e->writeByte(0x01);
        $e->writeByte(BuiltinType::Int32->value);
        $e->writeInt32($value);
        $e->writeInt32(0);
    });
}

describe('SecureChannelRequest', function () {

    it('encodes the request type and sequence number of a renewal', function () {
        $bytes = (new SecureChannelRequest())->encode(7, SecureChannelRequest::REQUEST_TYPE_RENEW, 42);

        $decoder = new BinaryDecoder($bytes);
        expect(MessageHeader::decode($decoder)->getMessageType())->toBe('OPN');
        expect($decoder->readUInt32())->toBe(7);
        $decoder->readString();
        $decoder->readByteString();
        $decoder->readByteString();
        expect($decoder->readUInt32())->toBe(42);
        $decoder->readUInt32();
        $decoder->readNodeId();
        $decoder->readNodeId();
        $decoder->readDateTime();
        $decoder->readUInt32();
        $decoder->readUInt32();
        $decoder->readString();
        $decoder->readUInt32();
        $decoder->readNodeId();
        $decoder->readByte();
        expect($decoder->readUInt32())->toBe(0);
        expect($decoder->readUInt32())->toBe(SecureChannelRequest::REQUEST_TYPE_RENEW);
    });

    it('issues a first token by default', function () {
        $bytes = (new SecureChannelRequest())->encode();

        expect(substr($bytes, -20, 4))->toBe(pack('V', SecureChannelRequest::REQUEST_TYPE_ISSUE));
    });
});

describe('Secure channel token renewal', function () {

    it('renews the token once the renewal time has passed and uses the new token', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $mock = new MockTransport();
        $mock->addResponse(buildOpnResponse(1, 99));
        $mock->addResponse(readResponseWithInt(5));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'eventDispatcher', $dispatcher);
        setClientProperty($client, 'secureChannelRenewAt', microtime(true) - 1);

        expect($client->read('i=2258')->getValue())->toBe(5);

        expect($mock->sent)->toHaveCount(2);
        expect(substr($mock->sent[0], 0, 3))->toBe('OPN');
        expect(substr($mock->sent[1], 0, 3))->toBe('MSG');
        expect(unpack('V', $mock->sent[1], 12)[1])->toBe(99);

        $events = $dispatcher->getEventsOfType(SecureChannelRenewed::class);
        expect($events)->toHaveCount(1);
        expect($events[0]->tokenId)->toBe(99);
        expect($events[0]->revisedLifetime)->toBe(3600000);

        $renewAt = (fn () => $this->secureChannelRenewAt)->call($client);
        expect($renewAt)->toBeGreaterThan(microtime(true) + 2600);
    });

    it('does not renew before the renewal time', function () {
        $mock = new MockTransport();
        $mock->addResponse(readResponseWithInt(5));

        $client = setupConnectedClient($mock);
        setClientProperty($client, 'secureChannelRenewAt', microtime(true) + 60);

        expect($client->read('i=2258')->getValue())->toBe(5);
        expect($mock->sent)->toHaveCount(1);
        expect(substr($mock->sent[0], 0, 3))->toBe('MSG');
    });
});
