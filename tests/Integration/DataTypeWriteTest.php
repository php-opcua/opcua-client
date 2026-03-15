<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

describe('DataType Write', function () {

    // ── Scalar writes ──────────────────────────────────────────────────

    describe('Write and read back scalars', function () {

        it('writes true to BooleanValue and reads it back', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'BooleanValue']);

                $statusCode = $client->write($nodeId, true, BuiltinType::Boolean);
                expect(StatusCode::isGood($statusCode))->toBeTrue();

                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeTrue();

                // Write false
                $statusCode = $client->write($nodeId, false, BuiltinType::Boolean);
                expect(StatusCode::isGood($statusCode))->toBeTrue();

                $dv = $client->read($nodeId);
                expect($dv->getValue())->toBeFalse();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('writes an integer to Int32Value and reads it back', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'Int32Value']);

                $statusCode = $client->write($nodeId, 12345, BuiltinType::Int32);
                expect(StatusCode::isGood($statusCode))->toBeTrue();

                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBe(12345);

                // Write negative value
                $statusCode = $client->write($nodeId, -9876, BuiltinType::Int32);
                expect(StatusCode::isGood($statusCode))->toBeTrue();

                $dv = $client->read($nodeId);
                expect($dv->getValue())->toBe(-9876);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('writes a float to DoubleValue and reads it back', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'DoubleValue']);

                $statusCode = $client->write($nodeId, 3.14159, BuiltinType::Double);
                expect(StatusCode::isGood($statusCode))->toBeTrue();

                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeFloat();
                expect(abs($dv->getValue() - 3.14159))->toBeLessThan(0.0001);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('writes a string to StringValue and reads it back', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'StringValue']);

                $testString = 'Hello OPC UA from PHP! ' . time();
                $statusCode = $client->write($nodeId, $testString, BuiltinType::String);
                expect(StatusCode::isGood($statusCode))->toBeTrue();

                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBe($testString);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('writes a UInt16Value and reads it back', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'UInt16Value']);

                $statusCode = $client->write($nodeId, 65000, BuiltinType::UInt16);
                expect(StatusCode::isGood($statusCode))->toBeTrue();

                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBe(65000);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('writes a ByteValue and reads it back', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'ByteValue']);

                $statusCode = $client->write($nodeId, 200, BuiltinType::Byte);
                expect(StatusCode::isGood($statusCode))->toBeTrue();

                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBe(200);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('writes a FloatValue and reads it back', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'FloatValue']);

                $statusCode = $client->write($nodeId, 2.718, BuiltinType::Float);
                expect(StatusCode::isGood($statusCode))->toBeTrue();

                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeFloat();
                expect(abs($dv->getValue() - 2.718))->toBeLessThan(0.01);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

    // ── Array writes ───────────────────────────────────────────────────

    describe('Write and read back arrays', function () {

        it('writes an Int32 array and reads it back', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Array', 'Int32Array']);

                $values = [10, 20, 30, 40, 50];
                $statusCode = $client->write($nodeId, $values, BuiltinType::Int32);
                expect(StatusCode::isGood($statusCode))->toBeTrue();

                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBe($values);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('writes a Double array and reads it back', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Array', 'DoubleArray']);

                $values = [1.1, 2.2, 3.3];
                $statusCode = $client->write($nodeId, $values, BuiltinType::Double);
                expect(StatusCode::isGood($statusCode))->toBeTrue();

                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                $readBack = $dv->getValue();
                expect($readBack)->toBeArray()->toHaveCount(3);
                foreach ($values as $i => $expected) {
                    expect(abs($readBack[$i] - $expected))->toBeLessThan(0.0001);
                }
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('writes a String array and reads it back', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Array', 'StringArray']);

                $values = ['alpha', 'beta', 'gamma'];
                $statusCode = $client->write($nodeId, $values, BuiltinType::String);
                expect(StatusCode::isGood($statusCode))->toBeTrue();

                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBe($values);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

    // ── Write to read-only should fail ─────────────────────────────────

    describe('Write to read-only variables', function () {

        it('fails to write to BooleanValue_RO with BadNotWritable', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'ReadOnly', 'Boolean_RO']);

                $statusCode = $client->write($nodeId, true, BuiltinType::Boolean);
                expect(StatusCode::isBad($statusCode))->toBeTrue();
                // Expect BadNotWritable (0x803B0000) or BadUserAccessDenied (0x801F0000)
                expect($statusCode)->toBeIn([StatusCode::BadNotWritable, StatusCode::BadUserAccessDenied]);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('fails to write to AnalogDataItems ReadOnlyValue', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'WithRange', 'ReadOnlyValue']);

                $statusCode = $client->write($nodeId, 99.9, BuiltinType::Double);
                expect(StatusCode::isBad($statusCode))->toBeTrue();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

})->group('integration');
