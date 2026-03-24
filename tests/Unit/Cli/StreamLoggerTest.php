<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Cli\StreamLogger;

describe('StreamLogger', function () {

    it('writes log messages to stream', function () {
        $stream = fopen('php://memory', 'w+');
        $logger = new StreamLogger($stream);
        $logger->info('Connected to {endpoint}', ['endpoint' => 'opc.tcp://localhost']);
        rewind($stream);
        $content = stream_get_contents($stream);
        expect($content)->toContain('[info]');
        expect($content)->toContain('Connected to opc.tcp://localhost');
    });

    it('includes timestamp', function () {
        $stream = fopen('php://memory', 'w+');
        $logger = new StreamLogger($stream);
        $logger->debug('test');
        rewind($stream);
        $content = stream_get_contents($stream);
        expect($content)->toMatch('/\[\d{2}:\d{2}:\d{2}\.\d+\]/');
    });

    it('handles context with non-string values', function () {
        $stream = fopen('php://memory', 'w+');
        $logger = new StreamLogger($stream);
        $logger->warning('Value is {val}', ['val' => 42, 'obj' => new stdClass()]);
        rewind($stream);
        $content = stream_get_contents($stream);
        expect($content)->toContain('Value is 42');
    });

});
