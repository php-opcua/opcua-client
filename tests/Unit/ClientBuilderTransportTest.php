<?php

declare(strict_types=1);

use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Tests\Unit\Helpers\InMemoryTransport;
use PhpOpcua\Client\Transport\ClientTransportInterface;

describe('ClientBuilder transport wiring', function () {

    it('returns null by default — Client will fall back to TcpTransport', function () {
        $builder = ClientBuilder::create();
        expect($builder->getTransport())->toBeNull();
    });

    it('stores the transport set via setTransport()', function () {
        $custom = new InMemoryTransport();
        $builder = ClientBuilder::create()->setTransport($custom);

        expect($builder->getTransport())->toBe($custom);
        expect($builder->getTransport())->toBeInstanceOf(ClientTransportInterface::class);
    });

    it('returns self from setTransport() for fluent chaining', function () {
        $builder = ClientBuilder::create();
        $returned = $builder->setTransport(new InMemoryTransport());
        expect($returned)->toBe($builder);
    });

    it('allows overriding the configured transport with a fresh one', function () {
        $first = new InMemoryTransport();
        $second = new InMemoryTransport();

        $builder = ClientBuilder::create()
            ->setTransport($first)
            ->setTransport($second);

        expect($builder->getTransport())->toBe($second);
    });

});
