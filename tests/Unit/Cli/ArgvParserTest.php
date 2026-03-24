<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Cli\ArgvParser;

describe('ArgvParser', function () {

    it('parses a command with no arguments', function () {
        $parser = new ArgvParser();
        $result = $parser->parse(['opcua-cli', 'browse']);
        expect($result['command'])->toBe('browse');
        expect($result['arguments'])->toBe([]);
        expect($result['options'])->toBe([]);
    });

    it('parses command with positional arguments', function () {
        $parser = new ArgvParser();
        $result = $parser->parse(['opcua-cli', 'browse', 'opc.tcp://localhost:4840', '/Objects']);
        expect($result['command'])->toBe('browse');
        expect($result['arguments'])->toBe(['opc.tcp://localhost:4840', '/Objects']);
    });

    it('parses long option with = separator', function () {
        $parser = new ArgvParser();
        $result = $parser->parse(['opcua-cli', 'read', 'opc.tcp://localhost', 'i=2259', '--attribute=DisplayName']);
        expect($result['options']['attribute'])->toBe('DisplayName');
    });

    it('parses long option with space separator', function () {
        $parser = new ArgvParser();
        $result = $parser->parse(['opcua-cli', 'read', 'opc.tcp://localhost', 'i=2259', '--attribute', 'DisplayName']);
        expect($result['options']['attribute'])->toBe('DisplayName');
    });

    it('parses boolean flags', function () {
        $parser = new ArgvParser();
        $result = $parser->parse(['opcua-cli', 'browse', 'opc.tcp://localhost', '--json', '--recursive']);
        expect($result['options']['json'])->toBeTrue();
        expect($result['options']['recursive'])->toBeTrue();
    });

    it('parses short option aliases', function () {
        $parser = new ArgvParser();
        $result = $parser->parse(['opcua-cli', 'browse', 'opc.tcp://localhost', '-j']);
        expect($result['options']['json'])->toBeTrue();
    });

    it('parses short option with value', function () {
        $parser = new ArgvParser();
        $result = $parser->parse(['opcua-cli', 'read', 'opc.tcp://localhost', '-u', 'admin', '-p', 'secret']);
        expect($result['options']['username'])->toBe('admin');
        expect($result['options']['password'])->toBe('secret');
    });

    it('parses security options', function () {
        $parser = new ArgvParser();
        $result = $parser->parse([
            'opcua-cli', 'read', 'opc.tcp://localhost', 'i=2259',
            '--security-policy=Basic256Sha256',
            '--security-mode=SignAndEncrypt',
            '--cert', '/path/to/cert.pem',
            '--key', '/path/to/key.pem',
            '--ca', '/path/to/ca.pem',
        ]);
        expect($result['options']['security-policy'])->toBe('Basic256Sha256');
        expect($result['options']['security-mode'])->toBe('SignAndEncrypt');
        expect($result['options']['cert'])->toBe('/path/to/cert.pem');
        expect($result['options']['key'])->toBe('/path/to/key.pem');
        expect($result['options']['ca'])->toBe('/path/to/ca.pem');
    });

    it('parses timeout option', function () {
        $parser = new ArgvParser();
        $result = $parser->parse(['opcua-cli', 'read', 'opc.tcp://localhost', '-t', '10']);
        expect($result['options']['timeout'])->toBe('10');
    });

    it('parses depth option', function () {
        $parser = new ArgvParser();
        $result = $parser->parse(['opcua-cli', 'browse', 'opc.tcp://localhost', '--recursive', '--depth=5']);
        expect($result['options']['recursive'])->toBeTrue();
        expect($result['options']['depth'])->toBe('5');
    });

    it('parses interval option', function () {
        $parser = new ArgvParser();
        $result = $parser->parse(['opcua-cli', 'watch', 'opc.tcp://localhost', 'i=1001', '--interval=250']);
        expect($result['options']['interval'])->toBe('250');
    });

    it('parses debug flags', function () {
        $parser = new ArgvParser();
        $result = $parser->parse(['opcua-cli', 'read', 'opc.tcp://localhost', 'i=2259', '--debug']);
        expect($result['options']['debug'])->toBeTrue();
    });

    it('parses debug-file option', function () {
        $parser = new ArgvParser();
        $result = $parser->parse(['opcua-cli', 'read', 'opc.tcp://localhost', 'i=2259', '--debug-file=/tmp/opcua.log']);
        expect($result['options']['debug-file'])->toBe('/tmp/opcua.log');
    });

    it('parses debug-stderr flag', function () {
        $parser = new ArgvParser();
        $result = $parser->parse(['opcua-cli', 'read', 'opc.tcp://localhost', 'i=2259', '--debug-stderr']);
        expect($result['options']['debug-stderr'])->toBeTrue();
    });

    it('parses help flag', function () {
        $parser = new ArgvParser();
        $result = $parser->parse(['opcua-cli', '--help']);
        expect($result['command'])->toBeNull();
        expect($result['options']['help'])->toBeTrue();
    });

    it('parses help short flag', function () {
        $parser = new ArgvParser();
        $result = $parser->parse(['opcua-cli', '-h']);
        expect($result['options']['help'])->toBeTrue();
    });

    it('parses version flag', function () {
        $parser = new ArgvParser();
        $result = $parser->parse(['opcua-cli', '--version']);
        expect($result['options']['version'])->toBeTrue();
    });

    it('returns null command when no arguments', function () {
        $parser = new ArgvParser();
        $result = $parser->parse(['opcua-cli']);
        expect($result['command'])->toBeNull();
        expect($result['arguments'])->toBe([]);
    });

    it('handles mixed options and arguments', function () {
        $parser = new ArgvParser();
        $result = $parser->parse([
            'opcua-cli', 'browse', 'opc.tcp://localhost:4840', '/Objects',
            '--json', '--recursive', '--depth=3', '-u', 'admin',
        ]);
        expect($result['command'])->toBe('browse');
        expect($result['arguments'])->toBe(['opc.tcp://localhost:4840', '/Objects']);
        expect($result['options']['json'])->toBeTrue();
        expect($result['options']['recursive'])->toBeTrue();
        expect($result['options']['depth'])->toBe('3');
        expect($result['options']['username'])->toBe('admin');
    });

});
