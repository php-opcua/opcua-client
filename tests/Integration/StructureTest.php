<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

describe('Structure', function () {

    it('browses the Structures folder', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Structures']);
            $refs = $client->browse($nodeId);

            expect($refs)->toBeArray()->not->toBeEmpty();

            $names = array_map(fn($r) => $r->getBrowseName()->getName(), $refs);
            expect($names)->toContain('TestPoint');
            expect($names)->toContain('TestRange');
            expect($names)->toContain('TestPerson');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    describe('TestPoint', function () {

        it('reads X value as 1.0', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Structures', 'TestPoint', 'X']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBe(1.0);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads Y value as 2.0', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Structures', 'TestPoint', 'Y']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBe(2.0);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads Z value as 3.0', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Structures', 'TestPoint', 'Z']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBe(3.0);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

    describe('TestRange', function () {

        it('reads Min value', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Structures', 'TestRange', 'Min']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->not->toBeNull();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads Max value', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Structures', 'TestRange', 'Max']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->not->toBeNull();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads Value', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Structures', 'TestRange', 'Value']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->not->toBeNull();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

    describe('TestPerson', function () {

        it('reads Name as "John Doe"', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Structures', 'TestPerson', 'Name']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBe('John Doe');
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads Age as 30', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Structures', 'TestPerson', 'Age']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBe(30);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads Active as true', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Structures', 'TestPerson', 'Active']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBe(true);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

    describe('TestNested', function () {

        it('reads Label as "origin"', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Structures', 'TestNested', 'Label']);
                $dv = $client->read($nodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBe('origin');
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads nested Point X, Y, Z values', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();

                $xNodeId = TestHelper::browseToNode($client, ['TestServer', 'Structures', 'TestNested', 'Point', 'X']);
                $dv = $client->read($xNodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeFloat();

                $yNodeId = TestHelper::browseToNode($client, ['TestServer', 'Structures', 'TestNested', 'Point', 'Y']);
                $dv = $client->read($yNodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeFloat();

                $zNodeId = TestHelper::browseToNode($client, ['TestServer', 'Structures', 'TestNested', 'Point', 'Z']);
                $dv = $client->read($zNodeId);
                expect($dv->getStatusCode())->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeFloat();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

    describe('PointCollection', function () {

        it('browses PointCollection with 5 points', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Structures', 'PointCollection']);
                $refs = $client->browse($nodeId);

                expect($refs)->toBeArray();
                // Should have at least 5 point children
                expect(count($refs))->toBeGreaterThanOrEqual(5);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

    describe('DeepNesting', function () {

        it('browses DeepNesting (10 levels deep)', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Structures', 'DeepNesting']);
                $refs = $client->browse($nodeId);

                expect($refs)->toBeArray()->not->toBeEmpty();

                // Navigate several levels deep to verify the structure
                $currentNodeId = $nodeId;
                $depth = 0;
                for ($i = 0; $i < 10; $i++) {
                    $children = $client->browse($currentNodeId);
                    $levelChild = null;
                    foreach ($children as $child) {
                        if (str_starts_with($child->getBrowseName()->getName(), 'Level_')) {
                            $levelChild = $child;
                            break;
                        }
                    }
                    if ($levelChild === null) {
                        break;
                    }
                    $currentNodeId = $levelChild->getNodeId();
                    $depth++;
                }

                // We should be able to go at least several levels deep
                expect($depth)->toBeGreaterThanOrEqual(3);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

})->group('integration');
