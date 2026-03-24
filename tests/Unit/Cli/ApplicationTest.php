<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Cli\Application;

describe('Application', function () {

    it('returns 0 for --version', function () {
        $app = new Application();
        $code = $app->run(['opcua-cli', '--version']);
        expect($code)->toBe(0);
    });

    it('returns 0 for --help', function () {
        $app = new Application();
        $code = $app->run(['opcua-cli', '--help']);
        expect($code)->toBe(0);
    });

    it('returns 0 when no command given', function () {
        $app = new Application();
        $code = $app->run(['opcua-cli']);
        expect($code)->toBe(0);
    });

    it('returns 0 for command-specific help', function () {
        $app = new Application();
        $code = $app->run(['opcua-cli', 'browse', '--help']);
        expect($code)->toBe(0);
    });

    it('returns 0 for -v short flag', function () {
        $app = new Application();
        $code = $app->run(['opcua-cli', '-v']);
        expect($code)->toBe(0);
    });

    it('returns 0 for -h short flag', function () {
        $app = new Application();
        $code = $app->run(['opcua-cli', '-h']);
        expect($code)->toBe(0);
    });

    it('returns 1 for unknown command', function () {
        $app = new Application();
        $code = $app->run(['opcua-cli', 'nonexistent']);
        expect($code)->toBe(1);
    });

    it('returns 1 when --debug and --json used together', function () {
        $app = new Application();
        $code = $app->run(['opcua-cli', '--debug', '--json', 'browse', 'opc.tcp://localhost']);
        expect($code)->toBe(1);
    });

    it('returns 1 when endpoint missing for browse', function () {
        $app = new Application();
        $code = $app->run(['opcua-cli', 'browse']);
        expect($code)->toBe(1);
    });

    it('returns 1 for connection to unreachable server', function () {
        $app = new Application();
        $code = $app->run(['opcua-cli', 'read', 'opc.tcp://192.0.2.1:9999', 'i=2259', '-t', '1']);
        expect($code)->toBe(1);
    });

    it('returns 1 for endpoints help', function () {
        $app = new Application();
        $code = $app->run(['opcua-cli', 'endpoints', '--help']);
        expect($code)->toBe(0);
    });

    it('returns 0 for watch help', function () {
        $app = new Application();
        $code = $app->run(['opcua-cli', 'watch', '--help']);
        expect($code)->toBe(0);
    });

    it('returns 0 for read help', function () {
        $app = new Application();
        $code = $app->run(['opcua-cli', 'read', '--help']);
        expect($code)->toBe(0);
    });

});
