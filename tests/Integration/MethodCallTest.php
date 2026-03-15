<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;
use Gianfriaur\OpcuaPhpClient\Types\Variant;

describe('Method Call', function () {

    it('calls Add(3.0, 4.0) and gets 7.0', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $methodsNodeId = TestHelper::browseToNode($client, ['TestServer', 'Methods']);
            $refs = $client->browse($methodsNodeId);
            $addRef = TestHelper::findRefByName($refs, 'Add');
            expect($addRef)->not->toBeNull();

            $result = $client->call(
                $methodsNodeId,
                $addRef->getNodeId(),
                [
                    new Variant(BuiltinType::Double, 3.0),
                    new Variant(BuiltinType::Double, 4.0),
                ],
            );

            expect(StatusCode::isGood($result['statusCode']))->toBeTrue();
            expect($result['outputArguments'])->toHaveCount(1);
            expect($result['outputArguments'][0]->getValue())->toBe(7.0);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('calls Multiply(5.0, 6.0) and gets 30.0', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $methodsNodeId = TestHelper::browseToNode($client, ['TestServer', 'Methods']);
            $refs = $client->browse($methodsNodeId);
            $mulRef = TestHelper::findRefByName($refs, 'Multiply');
            expect($mulRef)->not->toBeNull();

            $result = $client->call(
                $methodsNodeId,
                $mulRef->getNodeId(),
                [
                    new Variant(BuiltinType::Double, 5.0),
                    new Variant(BuiltinType::Double, 6.0),
                ],
            );

            expect(StatusCode::isGood($result['statusCode']))->toBeTrue();
            expect($result['outputArguments'])->toHaveCount(1);
            expect($result['outputArguments'][0]->getValue())->toBe(30.0);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('calls Concatenate("hello", " world") and gets "hello world"', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $methodsNodeId = TestHelper::browseToNode($client, ['TestServer', 'Methods']);
            $refs = $client->browse($methodsNodeId);
            $concatRef = TestHelper::findRefByName($refs, 'Concatenate');
            expect($concatRef)->not->toBeNull();

            $result = $client->call(
                $methodsNodeId,
                $concatRef->getNodeId(),
                [
                    new Variant(BuiltinType::String, 'hello'),
                    new Variant(BuiltinType::String, ' world'),
                ],
            );

            expect(StatusCode::isGood($result['statusCode']))->toBeTrue();
            expect($result['outputArguments'])->toHaveCount(1);
            expect($result['outputArguments'][0]->getValue())->toBe('hello world');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('calls Reverse("abcdef") and gets "fedcba"', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $methodsNodeId = TestHelper::browseToNode($client, ['TestServer', 'Methods']);
            $refs = $client->browse($methodsNodeId);
            $reverseRef = TestHelper::findRefByName($refs, 'Reverse');
            expect($reverseRef)->not->toBeNull();

            $result = $client->call(
                $methodsNodeId,
                $reverseRef->getNodeId(),
                [
                    new Variant(BuiltinType::String, 'abcdef'),
                ],
            );

            expect(StatusCode::isGood($result['statusCode']))->toBeTrue();
            expect($result['outputArguments'])->toHaveCount(1);
            expect($result['outputArguments'][0]->getValue())->toBe('fedcba');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('calls GetServerTime and gets a recent DateTime', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $methodsNodeId = TestHelper::browseToNode($client, ['TestServer', 'Methods']);
            $refs = $client->browse($methodsNodeId);
            $timeRef = TestHelper::findRefByName($refs, 'GetServerTime');
            expect($timeRef)->not->toBeNull();

            $result = $client->call(
                $methodsNodeId,
                $timeRef->getNodeId(),
                [],
            );

            expect(StatusCode::isGood($result['statusCode']))->toBeTrue();
            expect($result['outputArguments'])->toHaveCount(1);

            $serverTime = $result['outputArguments'][0]->getValue();
            expect($serverTime)->toBeInstanceOf(DateTimeImmutable::class);

            // Server time should be within 60 seconds of now
            $now = new DateTimeImmutable();
            $diff = abs($now->getTimestamp() - $serverTime->getTimestamp());
            expect($diff)->toBeLessThan(60);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('calls Echo(42) and gets 42 back', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $methodsNodeId = TestHelper::browseToNode($client, ['TestServer', 'Methods']);
            $refs = $client->browse($methodsNodeId);
            $echoRef = TestHelper::findRefByName($refs, 'Echo');
            expect($echoRef)->not->toBeNull();

            $result = $client->call(
                $methodsNodeId,
                $echoRef->getNodeId(),
                [
                    new Variant(BuiltinType::Int32, 42),
                ],
            );

            expect(StatusCode::isGood($result['statusCode']))->toBeTrue();
            expect($result['outputArguments'])->toHaveCount(1);
            expect($result['outputArguments'][0]->getValue())->toBe(42);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('calls LongRunning(100) and gets true', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $methodsNodeId = TestHelper::browseToNode($client, ['TestServer', 'Methods']);
            $refs = $client->browse($methodsNodeId);
            $longRef = TestHelper::findRefByName($refs, 'LongRunning');
            expect($longRef)->not->toBeNull();

            $result = $client->call(
                $methodsNodeId,
                $longRef->getNodeId(),
                [
                    new Variant(BuiltinType::UInt32, 100),
                ],
            );

            expect(StatusCode::isGood($result['statusCode']))->toBeTrue();
            expect($result['outputArguments'])->toHaveCount(1);
            expect($result['outputArguments'][0]->getValue())->toBe(true);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('calls Failing() and gets BadInternalError', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $methodsNodeId = TestHelper::browseToNode($client, ['TestServer', 'Methods']);
            $refs = $client->browse($methodsNodeId);
            $failRef = TestHelper::findRefByName($refs, 'Failing');
            expect($failRef)->not->toBeNull();

            $result = $client->call(
                $methodsNodeId,
                $failRef->getNodeId(),
                [],
            );

            expect(StatusCode::isBad($result['statusCode']))->toBeTrue();
            expect($result['statusCode'])->toBe(StatusCode::BadInternalError);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('calls ArraySum([1.0, 2.0, 3.0]) and gets 6.0', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $methodsNodeId = TestHelper::browseToNode($client, ['TestServer', 'Methods']);
            $refs = $client->browse($methodsNodeId);
            $sumRef = TestHelper::findRefByName($refs, 'ArraySum');
            expect($sumRef)->not->toBeNull();

            $result = $client->call(
                $methodsNodeId,
                $sumRef->getNodeId(),
                [
                    new Variant(BuiltinType::Double, [1.0, 2.0, 3.0]),
                ],
            );

            expect(StatusCode::isGood($result['statusCode']))->toBeTrue();
            expect($result['outputArguments'])->toHaveCount(1);
            expect($result['outputArguments'][0]->getValue())->toBe(6.0);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('calls MultiOutput() and gets multiple return values', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $methodsNodeId = TestHelper::browseToNode($client, ['TestServer', 'Methods']);
            $refs = $client->browse($methodsNodeId);
            $multiRef = TestHelper::findRefByName($refs, 'MultiOutput');
            expect($multiRef)->not->toBeNull();

            $result = $client->call(
                $methodsNodeId,
                $multiRef->getNodeId(),
                [],
            );

            expect(StatusCode::isGood($result['statusCode']))->toBeTrue();
            expect($result['outputArguments'])->toHaveCount(3);

            // Expect (int, string, bool) outputs
            expect($result['outputArguments'][0]->getValue())->toBeInt();
            expect($result['outputArguments'][1]->getValue())->toBeString();
            expect($result['outputArguments'][2]->getValue())->toBeBool();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');
