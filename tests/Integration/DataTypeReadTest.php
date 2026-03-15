<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

describe('DataType Read', function () {

    // ── Scalar reads ───────────────────────────────────────────────────

    describe('Scalar', function () {

        it('reads BooleanValue', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'BooleanValue']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeBool();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads SByteValue', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'SByteValue']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeInt();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads ByteValue', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'ByteValue']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeInt();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads Int16Value', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'Int16Value']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeInt();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads UInt16Value', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'UInt16Value']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeInt();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads Int32Value', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'Int32Value']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeInt();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads UInt32Value', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'UInt32Value']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeInt();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads Int64Value', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'Int64Value']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeInt();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads UInt64Value', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'UInt64Value']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeInt();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads FloatValue', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'FloatValue']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeFloat();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads DoubleValue', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'DoubleValue']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeFloat();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads StringValue', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'StringValue']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeString();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads DateTimeValue', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'DateTimeValue']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeInstanceOf(DateTimeImmutable::class);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads GuidValue', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'GuidValue']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeString();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads ByteStringValue', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'ByteStringValue']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                // ByteString is typically returned as a string (binary)
                expect($dv->getValue())->toBeString();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads XmlElementValue', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'XmlElementValue']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeString();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads NodeIdValue', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'NodeIdValue']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                // NodeId could be returned as NodeId object or a structured value
                expect($dv->getValue())->not->toBeNull();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads ExpandedNodeIdValue', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'ExpandedNodeIdValue']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->not->toBeNull();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads StatusCodeValue', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'StatusCodeValue']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeInt();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads QualifiedNameValue', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'QualifiedNameValue']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->not->toBeNull();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads LocalizedTextValue', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'LocalizedTextValue']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->not->toBeNull();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

    // ── ReadOnly scalars ───────────────────────────────────────────────

    describe('ReadOnly Scalars', function () {

        $readOnlyTypes = [
            'Boolean_RO'  => 'toBeBool',
            'Int32_RO'    => 'toBeInt',
            'Double_RO'   => 'toBeFloat',
            'String_RO'   => 'toBeString',
        ];

        foreach ($readOnlyTypes as $nodeName => $assertion) {
            it("reads {$nodeName}", function () use ($nodeName, $assertion) {
                $client = null;
                try {
                    $client = TestHelper::connectNoSecurity();
                    $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'ReadOnly', $nodeName]);
                    $dv = $client->read($nodeId);
                    expect($dv->getStatusCode())->toBe(StatusCode::Good);
                    expect($dv->getValue())->$assertion();
                } finally {
                    TestHelper::safeDisconnect($client);
                }
            })->group('integration');
        }

    });

    // ── Array reads ────────────────────────────────────────────────────

    describe('Array', function () {

        $arrayTypes = [
            'BooleanArray',
            'Int32Array',
            'DoubleArray',
            'StringArray',
        ];

        foreach ($arrayTypes as $nodeName) {
            it("reads {$nodeName}", function () use ($nodeName) {
                $client = null;
                try {
                    $client = TestHelper::connectNoSecurity();
                    $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Array', $nodeName]);
                    $dv = $client->read($nodeId);
                    expect($dv->getStatusCode())->toBe(StatusCode::Good);
                    expect($dv->getValue())->toBeArray()->not->toBeEmpty();
                } finally {
                    TestHelper::safeDisconnect($client);
                }
            })->group('integration');
        }

    });

    // ── Empty array reads ──────────────────────────────────────────────

    describe('Empty Arrays', function () {

        $emptyArrayTypes = [
            'EmptyBooleanArray',
            'EmptyInt32Array',
        ];

        foreach ($emptyArrayTypes as $nodeName) {
            it("reads {$nodeName} as empty array", function () use ($nodeName) {
                $client = null;
                try {
                    $client = TestHelper::connectNoSecurity();
                    $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Array', 'Empty', $nodeName]);
                    $dv = $client->read($nodeId);
                    expect($dv->getStatusCode())->toBe(StatusCode::Good);
                    $value = $dv->getValue();
                    // Empty arrays may be null or empty array
                    if ($value !== null) {
                        expect($value)->toBeArray()->toBeEmpty();
                    }
                } finally {
                    TestHelper::safeDisconnect($client);
                }
            })->group('integration');
        }

    });

    // ── Multi-dimensional arrays ───────────────────────────────────────

    describe('Multi-dimensional', function () {

        it('reads Matrix2D_Double', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'MultiDimensional', 'Matrix2D_Double']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeArray()->not->toBeEmpty();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads Matrix2D_Int32', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'MultiDimensional', 'Matrix2D_Int32']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeArray()->not->toBeEmpty();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads Cube3D_Byte', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'MultiDimensional', 'Cube3D_Byte']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeArray()->not->toBeEmpty();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

    // ── AnalogDataItems ────────────────────────────────────────────────

    describe('WithRange', function () {

        it('reads Temperature with value ~22.5', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'WithRange', 'Temperature']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeFloat();
                expect($dv->getValue())->toBeGreaterThan(20.0)->toBeLessThan(25.0);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads Pressure with value ~101.325', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'WithRange', 'Pressure']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeFloat();
                expect($dv->getValue())->toBeGreaterThan(99.0)->toBeLessThan(103.0);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads ReadOnlyValue with value 42.0', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'WithRange', 'ReadOnlyValue']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeFloat();
                expect($dv->getValue())->toBe(42.0);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

})->group('integration');
