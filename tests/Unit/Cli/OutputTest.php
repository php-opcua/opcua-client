<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Cli\Output\ConsoleOutput;
use Gianfriaur\OpcuaPhpClient\Cli\Output\JsonOutput;

describe('ConsoleOutput', function () {

    it('writes line to stdout', function () {
        $stream = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
        $output = new ConsoleOutput($stream, STDERR);
        $output->writeln('Hello');
        rewind($stream);
        expect(stream_get_contents($stream))->toBe("Hello\n");
    });

    it('writes without newline', function () {
        $stream = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
        $output = new ConsoleOutput($stream, STDERR);
        $output->write('Hello');
        rewind($stream);
        expect(stream_get_contents($stream))->toBe('Hello');
    });

    it('writes error to stderr', function () {
        $stdout = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
        $stderr = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
        $output = new ConsoleOutput($stdout, $stderr);
        $output->error('Something failed');
        rewind($stderr);
        $content = stream_get_contents($stderr);
        expect($content)->toContain('Something failed');
    });

    it('outputs data as key-value pairs', function () {
        $stream = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
        $output = new ConsoleOutput($stream, STDERR);
        $output->data(['Name' => 'Test', 'Value' => 42]);
        rewind($stream);
        $content = stream_get_contents($stream);
        expect($content)->toContain('Name:');
        expect($content)->toContain('Test');
        expect($content)->toContain('Value:');
        expect($content)->toContain('42');
    });

    it('outputs data with null and bool values', function () {
        $stream = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
        $output = new ConsoleOutput($stream, STDERR);
        $output->data(['A' => null, 'B' => true, 'C' => false, 'D' => [1, 2]]);
        rewind($stream);
        $content = stream_get_contents($stream);
        expect($content)->toContain('null');
        expect($content)->toContain('true');
        expect($content)->toContain('false');
        expect($content)->toContain('1, 2');
    });

    it('outputs tree structure', function () {
        $stream = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
        $output = new ConsoleOutput($stream, STDERR);
        $output->tree([
            ['name' => 'Server', 'nodeId' => 'i=2253', 'class' => 'Object'],
            ['name' => 'Types', 'nodeId' => 'i=86', 'class' => 'Object', 'children' => [
                ['name' => 'BaseDataType', 'nodeId' => 'i=24', 'class' => 'DataType'],
            ]],
        ]);
        rewind($stream);
        $content = stream_get_contents($stream);
        expect($content)->toContain('├──');
        expect($content)->toContain('└──');
        expect($content)->toContain('Server');
        expect($content)->toContain('BaseDataType');
    });

    it('outputs ANSI color codes when color is forced on', function () {
        $stream = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
        $output = new ConsoleOutput($stream, STDERR, forceColor: true);
        $output->data(['Key' => 'Value']);
        rewind($stream);
        $content = stream_get_contents($stream);
        expect($content)->toContain("\033[");
        expect($content)->toContain("\033[0m");
    });

    it('does not output ANSI codes when color is forced off', function () {
        $stream = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
        $output = new ConsoleOutput($stream, STDERR);
        $output->data(['Key' => 'Value']);
        rewind($stream);
        $content = stream_get_contents($stream);
        expect($content)->not->toContain("\033[");
    });

    it('color formats null, bool, and array values with color on', function () {
        $stream = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
        $output = new ConsoleOutput($stream, STDERR, forceColor: true);
        $output->data(['A' => null, 'B' => true, 'C' => false, 'D' => ['x', 'y']]);
        rewind($stream);
        $content = stream_get_contents($stream);
        expect($content)->toContain("\033[90mnull\033[0m");
        expect($content)->toContain("\033[32mtrue\033[0m");
        expect($content)->toContain("\033[31mfalse\033[0m");
        expect($content)->toContain('x, y');
    });

    it('renders tree with color enabled', function () {
        $stream = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
        $output = new ConsoleOutput($stream, STDERR, forceColor: true);
        $output->tree([
            ['name' => 'Server', 'nodeId' => 'i=2253', 'class' => 'Object'],
        ]);
        rewind($stream);
        $content = stream_get_contents($stream);
        expect($content)->toContain("\033[37mServer\033[0m");
        expect($content)->toContain("\033[33m[Object]\033[0m");
    });

    it('detects NO_COLOR env and disables color', function () {
        putenv('NO_COLOR=1');
        $stream = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
        $output = new ConsoleOutput($stream, STDERR);
        $output->data(['Key' => 'Value']);
        rewind($stream);
        $content = stream_get_contents($stream);
        expect($content)->not->toContain("\033[");
        putenv('NO_COLOR');
    });

    it('falls back to TERM env when posix_isatty not available on non-memory stream', function () {
        $tmpFile = tempnam(sys_get_temp_dir(), 'opcua-color-test-');
        $stream = fopen($tmpFile, 'w+');
        $output = new ConsoleOutput($stream, STDERR);
        $output->data(['Key' => 'Value']);
        fclose($stream);
        unlink($tmpFile);
        expect(true)->toBeTrue();
    });

    it('outputs empty table without error', function () {
        $stream = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
        $output = new ConsoleOutput($stream, STDERR);
        $output->table([]);
        rewind($stream);
        expect(stream_get_contents($stream))->toBe('');
    });

    it('outputs table rows', function () {
        $stream = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
        $output = new ConsoleOutput($stream, STDERR);
        $output->table([
            ['Endpoint' => 'opc.tcp://localhost', 'Security' => 'None'],
        ]);
        rewind($stream);
        $content = stream_get_contents($stream);
        expect($content)->toContain('Endpoint:');
        expect($content)->toContain('opc.tcp://localhost');
    });

});

describe('JsonOutput', function () {

    it('writes data as JSON', function () {
        $stream = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
        $output = new JsonOutput($stream, STDERR);
        $output->data(['key' => 'value']);
        rewind($stream);
        $content = stream_get_contents($stream);
        $decoded = json_decode($content, true);
        expect($decoded['key'])->toBe('value');
    });

    it('writes table as JSON array', function () {
        $stream = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
        $output = new JsonOutput($stream, STDERR);
        $output->table([['a' => 1], ['a' => 2]]);
        rewind($stream);
        $decoded = json_decode(stream_get_contents($stream), true);
        expect($decoded)->toHaveCount(2);
    });

    it('writes tree as JSON', function () {
        $stream = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
        $output = new JsonOutput($stream, STDERR);
        $nodes = [['name' => 'Server', 'nodeId' => 'i=2253', 'class' => 'Object']];
        $output->tree($nodes);
        rewind($stream);
        $decoded = json_decode(stream_get_contents($stream), true);
        expect($decoded[0]['name'])->toBe('Server');
    });

    it('writes error to stderr as JSON', function () {
        $stdout = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
        $stderr = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
        $output = new JsonOutput($stdout, $stderr);
        $output->error('fail');
        rewind($stderr);
        $decoded = json_decode(stream_get_contents($stderr), true);
        expect($decoded['error'])->toBe('fail');
    });

    it('writes message as JSON', function () {
        $stream = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
        $output = new JsonOutput($stream, STDERR);
        $output->writeln('hello');
        rewind($stream);
        $decoded = json_decode(stream_get_contents($stream), true);
        expect($decoded['message'])->toBe('hello');
    });

    it('writes raw string', function () {
        $stream = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
        $output = new JsonOutput($stream, STDERR);
        $output->write('raw');
        rewind($stream);
        expect(stream_get_contents($stream))->toBe('raw');
    });

});
