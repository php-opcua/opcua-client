<?php

declare(strict_types=1);

use PhpOpcua\Client\Types\BuildInfo;

describe('BuildInfo', function () {

    it('stores all fields', function () {
        $date = new DateTimeImmutable('2026-01-15T10:30:00Z');
        $info = new BuildInfo(
            productName: 'TestServer',
            manufacturerName: 'Acme Corp',
            softwareVersion: '1.2.3',
            buildNumber: '4567',
            buildDate: $date,
        );

        expect($info->productName)->toBe('TestServer');
        expect($info->manufacturerName)->toBe('Acme Corp');
        expect($info->softwareVersion)->toBe('1.2.3');
        expect($info->buildNumber)->toBe('4567');
        expect($info->buildDate)->toBe($date);
    });

    it('allows null fields', function () {
        $info = new BuildInfo(
            productName: null,
            manufacturerName: null,
            softwareVersion: null,
            buildNumber: null,
            buildDate: null,
        );

        expect($info->productName)->toBeNull();
        expect($info->manufacturerName)->toBeNull();
        expect($info->softwareVersion)->toBeNull();
        expect($info->buildNumber)->toBeNull();
        expect($info->buildDate)->toBeNull();
    });

    it('is readonly', function () {
        $info = new BuildInfo('P', 'M', '1.0', '1', null);
        $ref = new ReflectionClass($info);

        expect($ref->isReadOnly())->toBeTrue();
    });
});
