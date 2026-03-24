<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Cli\CommandRunner;
use Gianfriaur\OpcuaPhpClient\Cli\Output\ConsoleOutput;
use Psr\Log\NullLogger;

describe('CommandRunner', function () {

    it('creates a client with default settings', function () {
        $runner = new CommandRunner();
        $output = new ConsoleOutput(fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+'), STDERR);
        $client = $runner->createClient([], $output);
        expect($client)->toBeInstanceOf(Gianfriaur\OpcuaPhpClient\Client::class);
        expect($client->getTimeout())->toBe(5.0);
    });

    it('creates a client with custom timeout', function () {
        $runner = new CommandRunner();
        $output = new ConsoleOutput(fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+'), STDERR);
        $client = $runner->createClient(['timeout' => '10'], $output);
        expect($client->getTimeout())->toBe(10.0);
    });

    it('creates a client with username/password', function () {
        $runner = new CommandRunner();
        $output = new ConsoleOutput(fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+'), STDERR);
        $client = $runner->createClient(['username' => 'admin', 'password' => 'secret'], $output);
        expect($client)->toBeInstanceOf(Gianfriaur\OpcuaPhpClient\Client::class);
    });

    it('creates a client with debug logger', function () {
        $runner = new CommandRunner();
        $output = new ConsoleOutput(fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+'), STDERR);
        $client = $runner->createClient(['debug' => true], $output);
        expect($client->getLogger())->not->toBeInstanceOf(NullLogger::class);
    });

    it('creates a client with debug-stderr logger', function () {
        $runner = new CommandRunner();
        $output = new ConsoleOutput(fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+'), STDERR);
        $client = $runner->createClient(['debug-stderr' => true], $output);
        expect($client->getLogger())->not->toBeInstanceOf(NullLogger::class);
    });

    it('creates a client with debug-file logger', function () {
        $tmpFile = tempnam(sys_get_temp_dir(), 'opcua-test-');
        $runner = new CommandRunner();
        $output = new ConsoleOutput(fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+'), STDERR);
        $client = $runner->createClient(['debug-file' => $tmpFile], $output);
        expect($client->getLogger())->not->toBeInstanceOf(NullLogger::class);
        unlink($tmpFile);
    });

    it('creates a client with NullLogger by default', function () {
        $runner = new CommandRunner();
        $output = new ConsoleOutput(fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+'), STDERR);
        $client = $runner->createClient([], $output);
        expect($client->getLogger())->toBeInstanceOf(NullLogger::class);
    });

    it('creates a client with security-policy option', function () {
        $runner = new CommandRunner();
        $output = new ConsoleOutput(fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+'), STDERR);
        $client = $runner->createClient(['security-policy' => 'Basic256Sha256'], $output);
        expect($client)->toBeInstanceOf(Gianfriaur\OpcuaPhpClient\Client::class);
    });

    it('creates a client with security-mode option', function () {
        $runner = new CommandRunner();
        $output = new ConsoleOutput(fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+'), STDERR);
        $client = $runner->createClient(['security-mode' => 'SignAndEncrypt'], $output);
        expect($client)->toBeInstanceOf(Gianfriaur\OpcuaPhpClient\Client::class);
    });

    it('creates a client with security-mode Sign', function () {
        $runner = new CommandRunner();
        $output = new ConsoleOutput(fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+'), STDERR);
        $client = $runner->createClient(['security-mode' => 'Sign'], $output);
        expect($client)->toBeInstanceOf(Gianfriaur\OpcuaPhpClient\Client::class);
    });

    it('creates a client with security-mode None', function () {
        $runner = new CommandRunner();
        $output = new ConsoleOutput(fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+'), STDERR);
        $client = $runner->createClient(['security-mode' => 'None'], $output);
        expect($client)->toBeInstanceOf(Gianfriaur\OpcuaPhpClient\Client::class);
    });

    it('creates a client with numeric security-mode', function () {
        $runner = new CommandRunner();
        $output = new ConsoleOutput(fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+'), STDERR);
        $client = $runner->createClient(['security-mode' => '3'], $output);
        expect($client)->toBeInstanceOf(Gianfriaur\OpcuaPhpClient\Client::class);
    });

    it('creates a client with unknown security-mode falls back to None', function () {
        $runner = new CommandRunner();
        $output = new ConsoleOutput(fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+'), STDERR);
        $client = $runner->createClient(['security-mode' => 'Unknown'], $output);
        expect($client)->toBeInstanceOf(Gianfriaur\OpcuaPhpClient\Client::class);
    });

});
