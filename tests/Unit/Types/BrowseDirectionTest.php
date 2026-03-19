<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Types\BrowseDirection;

describe('BrowseDirection enum', function () {

    it('has three cases', function () {
        $cases = BrowseDirection::cases();
        expect($cases)->toHaveCount(3);
    });

    it('Forward has value 0', function () {
        expect(BrowseDirection::Forward->value)->toBe(0);
    });

    it('Inverse has value 1', function () {
        expect(BrowseDirection::Inverse->value)->toBe(1);
    });

    it('Both has value 2', function () {
        expect(BrowseDirection::Both->value)->toBe(2);
    });

    it('can be created from int values', function () {
        expect(BrowseDirection::from(0))->toBe(BrowseDirection::Forward);
        expect(BrowseDirection::from(1))->toBe(BrowseDirection::Inverse);
        expect(BrowseDirection::from(2))->toBe(BrowseDirection::Both);
    });

    it('tryFrom returns null for invalid values', function () {
        expect(BrowseDirection::tryFrom(3))->toBeNull();
        expect(BrowseDirection::tryFrom(-1))->toBeNull();
    });
});
