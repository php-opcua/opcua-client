<?php

declare(strict_types=1);

use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Exception\ServiceUnsupportedException;
use PhpOpcua\Client\Protocol\ServiceFault;
use PhpOpcua\Client\Protocol\ServiceTypeId;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;

describe('Protocol\\ServiceFault::throwIf', function () {

    it('throws ServiceException when typeId is ns=0;i=397', function () {
        $faultTypeId = NodeId::numeric(0, ServiceTypeId::SERVICE_FAULT);

        expect(fn () => ServiceFault::throwIf($faultTypeId, StatusCode::BadServiceUnsupported))
            ->toThrow(ServiceException::class);
    });

    it('preserves the ResponseHeader status code on the exception', function () {
        $faultTypeId = NodeId::numeric(0, ServiceTypeId::SERVICE_FAULT);

        try {
            ServiceFault::throwIf($faultTypeId, StatusCode::BadServiceUnsupported);
            fail('ServiceException should have been thrown');
        } catch (ServiceException $e) {
            expect($e->getStatusCode())->toBe(StatusCode::BadServiceUnsupported);
            expect($e->getMessage())->toContain('0x800B0000');
            expect($e->getMessage())->toContain('BadServiceUnsupported');
        }
    });

    it('does not throw for regular service response typeIds', function () {
        $addNodesResponseTypeId = NodeId::numeric(0, 491);

        ServiceFault::throwIf($addNodesResponseTypeId, StatusCode::Good);

        expect(true)->toBeTrue();
    });

    it('does not treat an identifier of 397 outside namespace 0 as a fault', function () {
        $lookalike = NodeId::numeric(2, ServiceTypeId::SERVICE_FAULT);

        ServiceFault::throwIf($lookalike, StatusCode::BadServiceUnsupported);

        expect(true)->toBeTrue();
    });

    it('does not treat a string identifier of 397 as a fault', function () {
        $stringIdentifier = NodeId::string(0, (string) ServiceTypeId::SERVICE_FAULT);

        ServiceFault::throwIf($stringIdentifier, StatusCode::BadServiceUnsupported);

        expect(true)->toBeTrue();
    });

    it('still throws when a buggy server reports ServiceFault with Good status', function () {
        $faultTypeId = NodeId::numeric(0, ServiceTypeId::SERVICE_FAULT);

        expect(fn () => ServiceFault::throwIf($faultTypeId, StatusCode::Good))
            ->toThrow(ServiceException::class);
    });

    it('raises ServiceUnsupportedException specifically for BadServiceUnsupported', function () {
        $faultTypeId = NodeId::numeric(0, ServiceTypeId::SERVICE_FAULT);

        expect(fn () => ServiceFault::throwIf($faultTypeId, StatusCode::BadServiceUnsupported))
            ->toThrow(ServiceUnsupportedException::class);
    });

    it('raises the base ServiceException for other fault status codes', function () {
        $faultTypeId = NodeId::numeric(0, ServiceTypeId::SERVICE_FAULT);

        try {
            ServiceFault::throwIf($faultTypeId, StatusCode::BadTimeout);
            fail('expected ServiceException');
        } catch (ServiceException $e) {
            expect($e)->not->toBeInstanceOf(ServiceUnsupportedException::class);
            expect($e->getStatusCode())->toBe(StatusCode::BadTimeout);
        }
    });

    it('ServiceUnsupportedException is a subclass of ServiceException (backward compat)', function () {
        $faultTypeId = NodeId::numeric(0, ServiceTypeId::SERVICE_FAULT);

        try {
            ServiceFault::throwIf($faultTypeId, StatusCode::BadServiceUnsupported);
            fail('expected ServiceUnsupportedException');
        } catch (ServiceException $e) {
            expect($e)->toBeInstanceOf(ServiceUnsupportedException::class);
            expect($e->getStatusCode())->toBe(StatusCode::BadServiceUnsupported);
        }
    });
});
