<?php

declare(strict_types=1);

use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\StatusCode;

describe('Session timeout against a real server', function () {

    it('requests the configured session timeout and the server applies it', function () {
        $default = null;
        $short = null;
        $tooShort = null;
        try {
            $default = (new ClientBuilder())->connect(TestHelper::ENDPOINT_NO_SECURITY);
            $short = (new ClientBuilder())->setSessionTimeout(15000.0)->connect(TestHelper::ENDPOINT_NO_SECURITY);
            $tooShort = (new ClientBuilder())->setSessionTimeout(1000.0)->connect(TestHelper::ENDPOINT_NO_SECURITY);

            expect($default->getSessionTimeout())->toBe(120000.0);
            expect($short->getSessionTimeout())->toBe(15000.0);
            expect($tooShort->getSessionTimeout())->toBe(10000.0);

            usleep(20_000_000);

            expect($default->read('i=2258')->getValue())->toBeInstanceOf(DateTimeImmutable::class);

            try {
                $short->read('i=2258');
                $statusCode = StatusCode::Good;
            } catch (ServiceException $e) {
                $statusCode = $e->getStatusCode();
            }
            expect($statusCode)->toBe(StatusCode::BadSessionIdInvalid);
        } finally {
            TestHelper::safeDisconnect($default);
            TestHelper::safeDisconnect($short);
            TestHelper::safeDisconnect($tooShort);
        }
    })->group('integration');
});
