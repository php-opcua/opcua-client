<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;

describe('Browse depth configuration', function () {

    it('getDefaultBrowseMaxDepth returns 10 by default', function () {
        $client = new Client();
        expect($client->getDefaultBrowseMaxDepth())->toBe(10);
    });

    it('setDefaultBrowseMaxDepth returns self for fluent chaining', function () {
        $client = new Client();
        $result = $client->setDefaultBrowseMaxDepth(5);
        expect($result)->toBe($client);
    });

    it('setDefaultBrowseMaxDepth stores the value', function () {
        $client = new Client();
        $client->setDefaultBrowseMaxDepth(20);
        expect($client->getDefaultBrowseMaxDepth())->toBe(20);
    });

    it('setDefaultBrowseMaxDepth accepts -1 for unlimited', function () {
        $client = new Client();
        $client->setDefaultBrowseMaxDepth(-1);
        expect($client->getDefaultBrowseMaxDepth())->toBe(-1);
    });

    it('setDefaultBrowseMaxDepth can be updated multiple times', function () {
        $client = new Client();
        $client->setDefaultBrowseMaxDepth(5);
        expect($client->getDefaultBrowseMaxDepth())->toBe(5);

        $client->setDefaultBrowseMaxDepth(50);
        expect($client->getDefaultBrowseMaxDepth())->toBe(50);
    });

    it('supports fluent chaining with other config methods', function () {
        $client = new Client();
        $result = $client
            ->setTimeout(10.0)
            ->setDefaultBrowseMaxDepth(20)
            ->setAutoRetry(2);
        expect($result)->toBe($client);
        expect($client->getDefaultBrowseMaxDepth())->toBe(20);
    });

    it('implements OpcUaClientInterface browse depth methods', function () {
        $reflection = new ReflectionClass(OpcUaClientInterface::class);
        expect($reflection->hasMethod('setDefaultBrowseMaxDepth'))->toBeTrue();
        expect($reflection->hasMethod('getDefaultBrowseMaxDepth'))->toBeTrue();
    });
});
