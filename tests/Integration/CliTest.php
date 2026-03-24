<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Cli\Application;
use Gianfriaur\OpcuaPhpClient\Cli\Commands\WatchCommand;
use Gianfriaur\OpcuaPhpClient\Cli\Output\ConsoleOutput;
use Gianfriaur\OpcuaPhpClient\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;

describe('CLI Integration', function () {

    it('browses Objects folder via CLI', function () {
        $app = new Application();
        $code = $app->run(['opcua-cli', 'browse', TestHelper::ENDPOINT_NO_SECURITY]);
        expect($code)->toBe(0);
    })->group('integration');

    it('browses with recursive flag via CLI', function () {
        $app = new Application();
        $code = $app->run(['opcua-cli', 'browse', TestHelper::ENDPOINT_NO_SECURITY, '/Objects', '--recursive', '--depth=2']);
        expect($code)->toBe(0);
    })->group('integration');

    it('browses with --json flag via CLI', function () {
        $app = new Application();
        $code = $app->run(['opcua-cli', 'browse', TestHelper::ENDPOINT_NO_SECURITY, '/Objects', '--json']);
        expect($code)->toBe(0);
    })->group('integration');

    it('reads server state via CLI', function () {
        $app = new Application();
        $code = $app->run(['opcua-cli', 'read', TestHelper::ENDPOINT_NO_SECURITY, 'i=2259']);
        expect($code)->toBe(0);
    })->group('integration');

    it('reads DisplayName attribute via CLI', function () {
        $app = new Application();
        $code = $app->run(['opcua-cli', 'read', TestHelper::ENDPOINT_NO_SECURITY, 'i=2259', '--attribute=DisplayName']);
        expect($code)->toBe(0);
    })->group('integration');

    it('reads with --json via CLI', function () {
        $app = new Application();
        $code = $app->run(['opcua-cli', 'read', TestHelper::ENDPOINT_NO_SECURITY, 'i=2259', '--json']);
        expect($code)->toBe(0);
    })->group('integration');

    it('discovers endpoints via CLI', function () {
        $app = new Application();
        $code = $app->run(['opcua-cli', 'endpoints', TestHelper::ENDPOINT_NO_SECURITY]);
        expect($code)->toBe(0);
    })->group('integration');

    it('discovers endpoints with --json via CLI', function () {
        $app = new Application();
        $code = $app->run(['opcua-cli', 'endpoints', TestHelper::ENDPOINT_NO_SECURITY, '--json']);
        expect($code)->toBe(0);
    })->group('integration');

    it('reads with debug-stderr via CLI', function () {
        $app = new Application();
        $code = $app->run(['opcua-cli', 'read', TestHelper::ENDPOINT_NO_SECURITY, 'i=2259', '--debug-stderr']);
        expect($code)->toBe(0);
    })->group('integration');

    it('reads with debug and no --json via CLI', function () {
        $app = new Application();
        $code = $app->run(['opcua-cli', 'read', TestHelper::ENDPOINT_NO_SECURITY, 'i=2259', '--debug']);
        expect($code)->toBe(0);
    })->group('integration');

    it('browses a path starting with / via CLI', function () {
        $app = new Application();
        $code = $app->run(['opcua-cli', 'browse', TestHelper::ENDPOINT_NO_SECURITY, '/Objects']);
        expect($code)->toBe(0);
    })->group('integration');

    it('watches Counter node with subscription mode and receives data change', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $counterNodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'Counter']);

            $cmd = new WatchCommand();
            $cmd->setMaxIterations(5);

            $stdout = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
            $stderr = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
            $output = new ConsoleOutput($stdout, $stderr);

            usleep(500_000);

            $code = $cmd->execute($client, [TestHelper::ENDPOINT_NO_SECURITY, $counterNodeId->__toString()], [], $output);

            expect($code)->toBe(0);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('watches Counter node with polling mode', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $counterNodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'Counter']);

            $cmd = new WatchCommand();
            $cmd->setMaxIterations(3);

            $stdout = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
            $stderr = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
            $output = new ConsoleOutput($stdout, $stderr);

            $code = $cmd->execute($client, [TestHelper::ENDPOINT_NO_SECURITY, $counterNodeId->__toString()], ['interval' => '200'], $output);

            rewind($stdout);
            $content = stream_get_contents($stdout);

            expect($code)->toBe(0);
            expect($content)->not->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('writes a value and watch CLI detects the change via subscription', function () {
        $watchClient = null;
        $writerClient = null;
        try {
            $writerClient = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($writerClient, ['TestServer', 'DataTypes', 'Scalar', 'Int32Value']);
            $nodeIdStr = $nodeId->__toString();

            $writerClient->write($nodeId, 1000, BuiltinType::Int32);

            $watchClient = TestHelper::connectNoSecurity();

            $sub = $watchClient->createSubscription(publishingInterval: 100.0);
            $watchClient->createMonitoredItems($sub->subscriptionId, [
                ['nodeId' => $nodeId, 'clientHandle' => 1, 'samplingInterval' => 100.0],
            ]);

            usleep(300_000);
            $watchClient->publish();

            $writerClient->write($nodeId, 9999, BuiltinType::Int32);
            usleep(500_000);

            $receivedNewValue = false;
            for ($i = 0; $i < 10; $i++) {
                $pub = $watchClient->publish();
                foreach ($pub->notifications as $notif) {
                    if ($notif['type'] === 'DataChange' && $notif['dataValue']->getValue() === 9999) {
                        $receivedNewValue = true;

                        break 2;
                    }
                }
                usleep(200_000);
            }

            expect($receivedNewValue)->toBeTrue();

            $watchClient->deleteSubscription($sub->subscriptionId);
        } finally {
            TestHelper::safeDisconnect($watchClient);
            TestHelper::safeDisconnect($writerClient);
        }
    })->group('integration');

    it('writes a value and watch CLI detects it via polling', function () {
        $watchClient = null;
        $writerClient = null;
        try {
            $writerClient = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($writerClient, ['TestServer', 'DataTypes', 'Scalar', 'Int32Value']);
            $nodeIdStr = $nodeId->__toString();

            $writerClient->write($nodeId, 7777, BuiltinType::Int32);

            $watchClient = TestHelper::connectNoSecurity();

            $cmd = new WatchCommand();
            $cmd->setMaxIterations(3);

            $stdout = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
            $stderr = fopen(tempnam(sys_get_temp_dir(), 'opcua-test-'), 'w+');
            $output = new ConsoleOutput($stdout, $stderr);

            $code = $cmd->execute($watchClient, [TestHelper::ENDPOINT_NO_SECURITY, $nodeIdStr], ['interval' => '100'], $output);

            rewind($stdout);
            $content = stream_get_contents($stdout);

            expect($code)->toBe(0);
            expect($content)->toContain('7777');
        } finally {
            TestHelper::safeDisconnect($watchClient);
            TestHelper::safeDisconnect($writerClient);
        }
    })->group('integration');

})->group('integration');
