<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Exception\ConfigurationException;
use Gianfriaur\OpcuaPhpClient\Exception\ConnectionException;
use Gianfriaur\OpcuaPhpClient\Exception\EncodingException;
use Gianfriaur\OpcuaPhpClient\Exception\OpcUaException;
use Gianfriaur\OpcuaPhpClient\Exception\ProtocolException;
use Gianfriaur\OpcuaPhpClient\Exception\SecurityException;
use Gianfriaur\OpcuaPhpClient\Exception\ServiceException;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

describe('Exception hierarchy', function () {

    it('all exceptions extend OpcUaException', function () {
        expect(new ConnectionException('test'))->toBeInstanceOf(OpcUaException::class);
        expect(new ProtocolException('test'))->toBeInstanceOf(OpcUaException::class);
        expect(new SecurityException('test'))->toBeInstanceOf(OpcUaException::class);
        expect(new ServiceException('test'))->toBeInstanceOf(OpcUaException::class);
        expect(new EncodingException('test'))->toBeInstanceOf(OpcUaException::class);
        expect(new ConfigurationException('test'))->toBeInstanceOf(OpcUaException::class);
    });

    it('all exceptions extend RuntimeException', function () {
        expect(new ConnectionException('test'))->toBeInstanceOf(RuntimeException::class);
        expect(new ProtocolException('test'))->toBeInstanceOf(RuntimeException::class);
        expect(new SecurityException('test'))->toBeInstanceOf(RuntimeException::class);
        expect(new ServiceException('test'))->toBeInstanceOf(RuntimeException::class);
        expect(new EncodingException('test'))->toBeInstanceOf(RuntimeException::class);
        expect(new ConfigurationException('test'))->toBeInstanceOf(RuntimeException::class);
    });

    it('ServiceException carries a status code', function () {
        $ex = new ServiceException('ActivateSession failed', 0x80070000);
        expect($ex->getStatusCode())->toBe(0x80070000);
        expect($ex->getMessage())->toBe('ActivateSession failed');
    });

    it('ServiceException defaults status code to zero', function () {
        $ex = new ServiceException('generic error');
        expect($ex->getStatusCode())->toBe(0);
    });

    it('catch OpcUaException catches all specific exceptions', function () {
        $exceptions = [
            new ConnectionException('conn'),
            new ProtocolException('proto'),
            new SecurityException('sec'),
            new ServiceException('svc'),
            new EncodingException('enc'),
            new ConfigurationException('cfg'),
        ];

        foreach ($exceptions as $ex) {
            $caught = false;
            try {
                throw $ex;
            } catch (OpcUaException) {
                $caught = true;
            }
            expect($caught)->toBeTrue();
        }
    });
});

describe('Exception thrown in correct context', function () {

    it('throws ConnectionException when calling browse without connecting', function () {
        $client = new Client();
        expect(fn() => $client->browse(NodeId::numeric(0, 85)))
            ->toThrow(ConnectionException::class);
    });

    it('throws ConfigurationException for invalid endpoint URL', function () {
        $client = new Client();
        expect(fn() => $client->connect('not-a-valid-url'))
            ->toThrow(ConfigurationException::class);
    });

    it('throws ConnectionException for unreachable host', function () {
        $client = new Client();
        expect(fn() => $client->connect('opc.tcp://192.0.2.1:4840/UA/TestServer'))
            ->toThrow(ConnectionException::class);
    });

    it('throws EncodingException on buffer underflow', function () {
        $decoder = new BinaryDecoder("\x01\x02");
        expect(fn() => $decoder->readUInt32())
            ->toThrow(EncodingException::class);
    });

    it('throws EncodingException on invalid GUID format', function () {
        $encoder = new BinaryEncoder();
        expect(fn() => $encoder->writeGuid('not-a-guid'))
            ->toThrow(EncodingException::class);
    });

    it('throws EncodingException on unknown NodeId encoding', function () {
        // Build a buffer with an invalid NodeId encoding byte (0xFF)
        $decoder = new BinaryDecoder("\xFF");
        expect(fn() => $decoder->readNodeId())
            ->toThrow(EncodingException::class);
    });
});
