<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Cli\Commands\BrowseCommand;
use Gianfriaur\OpcuaPhpClient\Cli\Commands\EndpointsCommand;
use Gianfriaur\OpcuaPhpClient\Cli\Commands\ReadCommand;
use Gianfriaur\OpcuaPhpClient\Cli\Commands\WatchCommand;
use Gianfriaur\OpcuaPhpClient\Cli\Commands\WriteCommand;
use Gianfriaur\OpcuaPhpClient\Cli\Output\ConsoleOutput;
use Gianfriaur\OpcuaPhpClient\Cli\Output\JsonOutput;
use Gianfriaur\OpcuaPhpClient\Testing\MockClient;
use Gianfriaur\OpcuaPhpClient\Types\BrowseNode;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\EndpointDescription;
use Gianfriaur\OpcuaPhpClient\Types\LocalizedText;
use Gianfriaur\OpcuaPhpClient\Types\NodeClass;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\QualifiedName;
use Gianfriaur\OpcuaPhpClient\Types\ReferenceDescription;
use Gianfriaur\OpcuaPhpClient\Types\UserTokenPolicy;

function createMockRef(string $name, int $ns, int $id, NodeClass $class = NodeClass::Object): ReferenceDescription
{
    return new ReferenceDescription(
        referenceTypeId: NodeId::numeric(0, 35),
        isForward: true,
        nodeId: NodeId::numeric($ns, $id),
        browseName: new QualifiedName($ns, $name),
        displayName: new LocalizedText(null, $name),
        nodeClass: $class,
        typeDefinition: null,
    );
}

function createOutputStream(): array
{
    $stdoutPath = tempnam(sys_get_temp_dir(), 'opcua-test-stdout-');
    $stderrPath = tempnam(sys_get_temp_dir(), 'opcua-test-stderr-');
    $stdout = fopen($stdoutPath, 'w+');
    $stderr = fopen($stderrPath, 'w+');

    return [$stdout, $stderr];
}

function getStreamContent($stream): string
{
    rewind($stream);

    return stream_get_contents($stream);
}

describe('EndpointsCommand', function () {

    it('returns name and description', function () {
        $cmd = new EndpointsCommand();
        expect($cmd->getName())->toBe('endpoints');
        expect($cmd->getDescription())->toBeString();
        expect($cmd->getUsage())->toContain('endpoints');
        expect($cmd->requiresConnection())->toBeTrue();
    });

    it('returns 1 when no arguments', function () {
        $cmd = new EndpointsCommand();
        $client = MockClient::create();
        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $code = $cmd->execute($client, [], [], $output);
        expect($code)->toBe(1);
    });

    it('returns 0 with endpoint having Sign mode and unknown token type', function () {
        $cmd = new EndpointsCommand();
        $client = MockClient::create()
            ->onGetEndpoints(fn () => [
                new EndpointDescription(
                    'opc.tcp://localhost:4840',
                    null,
                    2,
                    'http://opcfoundation.org/UA/SecurityPolicy#Basic256',
                    [new UserTokenPolicy('custom', 99, null, null, null)],
                    '',
                    0,
                ),
            ]);

        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $code = $cmd->execute($client, ['opc.tcp://localhost:4840'], [], $output);
        expect($code)->toBe(0);
        $content = getStreamContent($stdout);
        expect($content)->toContain('Sign');
        expect($content)->toContain('Unknown(99)');
    });

    it('returns 0 with populated endpoints', function () {
        $cmd = new EndpointsCommand();
        $client = MockClient::create()
            ->onGetEndpoints(fn () => [
                new EndpointDescription(
                    'opc.tcp://localhost:4840',
                    null,
                    1,
                    'http://opcfoundation.org/UA/SecurityPolicy#None',
                    [new UserTokenPolicy('anon', 0, null, null, null)],
                    'http://opcfoundation.org/UA-Profile/Transport/uatcp-uasc-uabinary',
                    0,
                ),
                new EndpointDescription(
                    'opc.tcp://localhost:4840',
                    null,
                    3,
                    'http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256',
                    [
                        new UserTokenPolicy('anon', 0, null, null, null),
                        new UserTokenPolicy('user', 1, null, null, null),
                        new UserTokenPolicy('cert', 2, null, null, null),
                    ],
                    'http://opcfoundation.org/UA-Profile/Transport/uatcp-uasc-uabinary',
                    1,
                ),
            ]);

        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $code = $cmd->execute($client, ['opc.tcp://localhost:4840'], [], $output);
        expect($code)->toBe(0);
        $content = getStreamContent($stdout);
        expect($content)->toContain('None');
        expect($content)->toContain('Basic256Sha256');
        expect($content)->toContain('Anonymous');
        expect($content)->toContain('UserName');
        expect($content)->toContain('Certificate');
    });

    it('outputs endpoints as JSON', function () {
        $cmd = new EndpointsCommand();
        $client = MockClient::create()
            ->onGetEndpoints(fn () => [
                new EndpointDescription(
                    'opc.tcp://localhost:4840',
                    null,
                    1,
                    'http://opcfoundation.org/UA/SecurityPolicy#None',
                    [new UserTokenPolicy('anon', 0, null, null, null)],
                    '',
                    0,
                ),
            ]);

        [$stdout, $stderr] = createOutputStream();
        $output = new JsonOutput($stdout, $stderr);
        $code = $cmd->execute($client, ['opc.tcp://localhost:4840'], ['json' => true], $output);
        expect($code)->toBe(0);
        $decoded = json_decode(getStreamContent($stdout), true);
        expect($decoded)->toBeArray();
        expect($decoded[0]['Endpoint'])->toBe('opc.tcp://localhost:4840');
    });

    it('returns 0 with empty endpoints', function () {
        $cmd = new EndpointsCommand();
        $client = MockClient::create();
        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $code = $cmd->execute($client, ['opc.tcp://localhost'], [], $output);
        expect($code)->toBe(0);
        expect(getStreamContent($stdout))->toContain('No endpoints found');
    });

});

describe('ReadCommand', function () {

    it('returns name and description', function () {
        $cmd = new ReadCommand();
        expect($cmd->getName())->toBe('read');
        expect($cmd->getDescription())->toBeString();
        expect($cmd->getUsage())->toContain('read');
        expect($cmd->requiresConnection())->toBeTrue();
    });

    it('returns 1 when insufficient arguments', function () {
        $cmd = new ReadCommand();
        $client = MockClient::create();
        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $code = $cmd->execute($client, ['opc.tcp://localhost'], [], $output);
        expect($code)->toBe(1);
    });

    it('reads a value and outputs data', function () {
        $cmd = new ReadCommand();
        $client = MockClient::create()
            ->onRead('i=2259', fn () => DataValue::ofInt32(0));

        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $code = $cmd->execute($client, ['opc.tcp://localhost', 'i=2259'], [], $output);
        expect($code)->toBe(0);
        $content = getStreamContent($stdout);
        expect($content)->toContain('i=2259');
        expect($content)->toContain('Value');
        expect($content)->toContain('0');
    });

    it('reads with custom attribute', function () {
        $cmd = new ReadCommand();
        $client = MockClient::create()
            ->onRead('i=2259', fn () => DataValue::ofString('ServerStatus'));

        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $code = $cmd->execute($client, ['opc.tcp://localhost', 'i=2259'], ['attribute' => 'DisplayName'], $output);
        expect($code)->toBe(0);
        expect(getStreamContent($stdout))->toContain('DisplayName');
    });

    it('handles null value', function () {
        $cmd = new ReadCommand();
        $client = MockClient::create();

        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $code = $cmd->execute($client, ['opc.tcp://localhost', 'i=99999'], [], $output);
        expect($code)->toBe(0);
        expect(getStreamContent($stdout))->toContain('null');
    });

    it('outputs JSON format', function () {
        $cmd = new ReadCommand();
        $client = MockClient::create()
            ->onRead('i=2259', fn () => DataValue::ofDouble(3.14));

        [$stdout, $stderr] = createOutputStream();
        $output = new JsonOutput($stdout, $stderr);
        $code = $cmd->execute($client, ['opc.tcp://localhost', 'i=2259'], ['json' => true], $output);
        expect($code)->toBe(0);
        $decoded = json_decode(getStreamContent($stdout), true);
        expect($decoded)->toBeArray();
        expect($decoded['Value'])->toBe('3.14');
    });

    it('formats boolean values', function () {
        $cmd = new ReadCommand();
        $client = MockClient::create()
            ->onRead('i=1', fn () => DataValue::ofBoolean(true));

        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $cmd->execute($client, ['opc.tcp://localhost', 'i=1'], [], $output);
        expect(getStreamContent($stdout))->toContain('true');
    });

    it('formats DateTime values', function () {
        $cmd = new ReadCommand();
        $now = new DateTimeImmutable('2026-03-24T12:00:00+00:00');
        $client = MockClient::create()
            ->onRead('i=1', fn () => DataValue::ofDateTime($now));

        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $cmd->execute($client, ['opc.tcp://localhost', 'i=1'], [], $output);
        expect(getStreamContent($stdout))->toContain('2026-03-24');
    });

});

describe('BrowseCommand', function () {

    it('returns name and description', function () {
        $cmd = new BrowseCommand();
        expect($cmd->getName())->toBe('browse');
        expect($cmd->getDescription())->toBeString();
        expect($cmd->getUsage())->toContain('browse');
        expect($cmd->requiresConnection())->toBeTrue();
    });

    it('returns 1 when no arguments', function () {
        $cmd = new BrowseCommand();
        $client = MockClient::create();
        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $code = $cmd->execute($client, [], [], $output);
        expect($code)->toBe(1);
    });

    it('browses flat and outputs tree', function () {
        $cmd = new BrowseCommand();
        $client = MockClient::create()
            ->onBrowse('i=85', fn () => [
                createMockRef('Server', 0, 2253),
                createMockRef('MyPLC', 2, 1000),
            ]);

        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $code = $cmd->execute($client, ['opc.tcp://localhost', 'i=85'], [], $output);
        expect($code)->toBe(0);
        $content = getStreamContent($stdout);
        expect($content)->toContain('Server');
        expect($content)->toContain('MyPLC');
        expect($content)->toContain('├──');
    });

    it('browses with path resolution', function () {
        $cmd = new BrowseCommand();
        $client = MockClient::create()
            ->onResolveNodeId('/Objects', fn () => NodeId::numeric(0, 85))
            ->onBrowse('i=85', fn () => [
                createMockRef('Server', 0, 2253),
            ]);

        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $code = $cmd->execute($client, ['opc.tcp://localhost', '/Objects'], [], $output);
        expect($code)->toBe(0);
        expect(getStreamContent($stdout))->toContain('Server');
    });

    it('shows message when no children found', function () {
        $cmd = new BrowseCommand();
        $client = MockClient::create()
            ->onBrowse('i=85', fn () => []);

        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $code = $cmd->execute($client, ['opc.tcp://localhost', 'i=85'], [], $output);
        expect($code)->toBe(0);
        expect(getStreamContent($stdout))->toContain('No children found');
    });

    it('browses default node when no path given', function () {
        $cmd = new BrowseCommand();
        $client = MockClient::create()
            ->onBrowse('i=85', fn () => [
                createMockRef('Server', 0, 2253),
            ]);

        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $code = $cmd->execute($client, ['opc.tcp://localhost'], [], $output);
        expect($code)->toBe(0);
    });

    it('outputs JSON format', function () {
        $cmd = new BrowseCommand();
        $client = MockClient::create()
            ->onBrowse('i=85', fn () => [
                createMockRef('Server', 0, 2253),
            ]);

        [$stdout, $stderr] = createOutputStream();
        $output = new JsonOutput($stdout, $stderr);
        $code = $cmd->execute($client, ['opc.tcp://localhost', 'i=85'], ['json' => true], $output);
        expect($code)->toBe(0);
        $decoded = json_decode(getStreamContent($stdout), true);
        expect($decoded)->toBeArray();
        expect($decoded[0]['name'])->toBe('Server');
    });

    it('browses recursively', function () {
        $cmd = new BrowseCommand();
        $client = MockClient::create();

        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $code = $cmd->execute($client, ['opc.tcp://localhost', 'i=85'], ['recursive' => true, 'depth' => '2'], $output);
        expect($code)->toBe(0);
    });

    it('converts BrowseNode tree to array via browseNodesToArray', function () {
        $cmd = new BrowseCommand();
        $ref1 = createMockRef('Server', 0, 2253);
        $ref2 = createMockRef('Types', 0, 86);
        $childRef = createMockRef('BaseDataType', 0, 24, NodeClass::DataType);

        $parent = new BrowseNode($ref2);
        $parent->addChild(new BrowseNode($childRef));

        $method = new ReflectionMethod($cmd, 'browseNodesToArray');
        $result = $method->invoke($cmd, [new BrowseNode($ref1), $parent]);

        expect($result)->toHaveCount(2);
        expect($result[0]['name'])->toBe('Server');
        expect($result[0]['nodeId'])->toBe('i=2253');
        expect($result[1]['name'])->toBe('Types');
        expect($result[1]['children'])->toHaveCount(1);
        expect($result[1]['children'][0]['name'])->toBe('BaseDataType');
        expect($result[1]['children'][0]['class'])->toBe('DataType');
    });

});

describe('WatchCommand', function () {

    it('returns name and description', function () {
        $cmd = new WatchCommand();
        expect($cmd->getName())->toBe('watch');
        expect($cmd->getDescription())->toBeString();
        expect($cmd->getUsage())->toContain('watch');
        expect($cmd->requiresConnection())->toBeTrue();
    });

    it('returns 1 when insufficient arguments', function () {
        $cmd = new WatchCommand();
        $client = MockClient::create();
        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $code = $cmd->execute($client, ['opc.tcp://localhost'], [], $output);
        expect($code)->toBe(1);
    });

    it('watches with polling mode', function () {
        $cmd = new WatchCommand();
        $cmd->setMaxIterations(3);
        $client = MockClient::create()
            ->onRead('i=1001', fn () => DataValue::ofDouble(23.5));

        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $code = $cmd->execute($client, ['opc.tcp://localhost', 'i=1001'], ['interval' => '100'], $output);
        expect($code)->toBe(0);
        $content = getStreamContent($stdout);
        expect($content)->toContain('23.5');
        expect($client->callCount('read'))->toBe(3);
    });

    it('watches with subscription mode', function () {
        $cmd = new WatchCommand();
        $cmd->setMaxIterations(2);
        $client = MockClient::create();

        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $code = $cmd->execute($client, ['opc.tcp://localhost', 'i=1001'], [], $output);
        expect($code)->toBe(0);
        expect($client->callCount('createSubscription'))->toBe(1);
        expect($client->callCount('createMonitoredItems'))->toBe(1);
        expect($client->callCount('publish'))->toBe(2);
    });

    it('formats array value in polling', function () {
        $cmd = new WatchCommand();
        $cmd->setMaxIterations(1);
        $client = MockClient::create()
            ->onRead('i=1', fn () => DataValue::of([1, 2], Gianfriaur\OpcuaPhpClient\Types\BuiltinType::Int32));

        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $cmd->execute($client, ['opc.tcp://localhost', 'i=1'], ['interval' => '10'], $output);
        expect(getStreamContent($stdout))->toContain('[1,2]');
    });

    it('formats DateTime value in polling', function () {
        $cmd = new WatchCommand();
        $cmd->setMaxIterations(1);
        $now = new DateTimeImmutable('2026-03-24T12:00:00+00:00');
        $client = MockClient::create()
            ->onRead('i=1', fn () => DataValue::ofDateTime($now));

        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $cmd->execute($client, ['opc.tcp://localhost', 'i=1'], ['interval' => '10'], $output);
        expect(getStreamContent($stdout))->toContain('2026-03-24');
    });

    it('formats null, bool, array, and DateTime values in polling', function () {
        $iteration = 0;
        $values = [
            DataValue::ofBoolean(true),
            DataValue::ofString('test'),
            new DataValue(),
        ];
        $cmd = new WatchCommand();
        $cmd->setMaxIterations(3);
        $client = MockClient::create()
            ->onRead('i=1', function () use (&$iteration, $values) {
                return $values[$iteration++] ?? new DataValue();
            });

        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $cmd->execute($client, ['opc.tcp://localhost', 'i=1'], ['interval' => '10'], $output);
        $content = getStreamContent($stdout);
        expect($content)->toContain('true');
        expect($content)->toContain('test');
        expect($content)->toContain('null');
    });

});

describe('WriteCommand', function () {

    it('returns name and description', function () {
        $cmd = new WriteCommand();
        expect($cmd->getName())->toBe('write');
        expect($cmd->getDescription())->toBeString();
        expect($cmd->getUsage())->toContain('write');
        expect($cmd->requiresConnection())->toBeTrue();
    });

    it('returns 1 when insufficient arguments', function () {
        $cmd = new WriteCommand();
        $client = MockClient::create();
        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $code = $cmd->execute($client, ['opc.tcp://localhost', 'i=1001'], [], $output);
        expect($code)->toBe(1);
    });

    it('writes with explicit type', function () {
        $cmd = new WriteCommand();
        $client = MockClient::create()
            ->onRead('ns=2;i=1001', fn () => DataValue::ofInt32(0))
            ->onWrite('ns=2;i=1001', fn () => 0);

        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $code = $cmd->execute($client, ['opc.tcp://localhost', 'ns=2;i=1001', '42'], ['type' => 'Int32'], $output);
        expect($code)->toBe(0);
        $content = getStreamContent($stdout);
        expect($content)->toContain('ns=2;i=1001');
        expect($content)->toContain('42');
        expect($content)->toContain('Int32');
        expect($content)->toContain('Good');
    });

    it('writes without type (auto-detect)', function () {
        $cmd = new WriteCommand();
        $client = MockClient::create()
            ->onRead('ns=2;i=1001', fn () => DataValue::ofInt32(0))
            ->onWrite('ns=2;i=1001', fn () => 0);

        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $code = $cmd->execute($client, ['opc.tcp://localhost', 'ns=2;i=1001', '42'], [], $output);
        expect($code)->toBe(0);
        $content = getStreamContent($stdout);
        expect($content)->toContain('Int32');
    });

    it('returns 1 for unknown type', function () {
        $cmd = new WriteCommand();
        $client = MockClient::create();
        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $code = $cmd->execute($client, ['opc.tcp://localhost', 'i=1001', '42'], ['type' => 'FakeType'], $output);
        expect($code)->toBe(1);
        expect(getStreamContent($stderr))->toContain('Unknown type');
    });

    it('casts boolean values correctly', function () {
        $cmd = new WriteCommand();
        $writtenValue = null;
        $client = MockClient::create()
            ->onRead('ns=2;i=1001', fn () => DataValue::ofBoolean(false))
            ->onWrite('ns=2;i=1001', function ($v) use (&$writtenValue) {
                $writtenValue = $v;

                return 0;
            });

        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $cmd->execute($client, ['opc.tcp://localhost', 'ns=2;i=1001', 'true'], ['type' => 'Boolean'], $output);
        expect($writtenValue)->toBeTrue();
    });

    it('casts double values correctly', function () {
        $cmd = new WriteCommand();
        $writtenValue = null;
        $client = MockClient::create()
            ->onRead('ns=2;i=1001', fn () => DataValue::ofDouble(0.0))
            ->onWrite('ns=2;i=1001', function ($v) use (&$writtenValue) {
                $writtenValue = $v;

                return 0;
            });

        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $cmd->execute($client, ['opc.tcp://localhost', 'ns=2;i=1001', '3.14'], ['type' => 'Double'], $output);
        expect($writtenValue)->toBe(3.14);
    });

    it('auto-casts numeric string to int without type', function () {
        $cmd = new WriteCommand();
        $writtenValue = null;
        $client = MockClient::create()
            ->onRead('ns=2;i=1001', fn () => DataValue::ofInt32(0))
            ->onWrite('ns=2;i=1001', function ($v) use (&$writtenValue) {
                $writtenValue = $v;

                return 0;
            });

        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $cmd->execute($client, ['opc.tcp://localhost', 'ns=2;i=1001', '42'], [], $output);
        expect($writtenValue)->toBe(42);
        expect($writtenValue)->toBeInt();
    });

    it('returns 1 on bad status code', function () {
        $cmd = new WriteCommand();
        $client = MockClient::create()
            ->onRead('ns=2;i=1001', fn () => DataValue::ofInt32(0))
            ->onWrite('ns=2;i=1001', fn () => 0x803B0000);

        [$stdout, $stderr] = createOutputStream();
        $output = new ConsoleOutput($stdout, $stderr);
        $code = $cmd->execute($client, ['opc.tcp://localhost', 'ns=2;i=1001', '42'], [], $output);
        expect($code)->toBe(1);
    });

    it('outputs as JSON', function () {
        $cmd = new WriteCommand();
        $client = MockClient::create()
            ->onRead('ns=2;i=1001', fn () => DataValue::ofInt32(0))
            ->onWrite('ns=2;i=1001', fn () => 0);

        [$stdout, $stderr] = createOutputStream();
        $output = new JsonOutput($stdout, $stderr);
        $code = $cmd->execute($client, ['opc.tcp://localhost', 'ns=2;i=1001', '42'], ['type' => 'Int32'], $output);
        expect($code)->toBe(0);
        $decoded = json_decode(getStreamContent($stdout), true);
        expect($decoded['NodeId'])->toBe('ns=2;i=1001');
        expect($decoded['Value'])->toBe('42');
    });
});
