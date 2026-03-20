<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Exception\SecurityException;
use Gianfriaur\OpcuaPhpClient\Security\CertificateManager;
use Gianfriaur\OpcuaPhpClient\Security\MessageSecurity;

function callEnsureNotFalse(string $class, mixed $result, string $message): mixed
{
    $ref = new ReflectionMethod($class, 'ensureNotFalse');
    return $ref->invoke(null, $result, $message);
}

describe('CertificateManager::ensureNotFalse', function () {

    it('returns the value when not false', function () {
        $result = callEnsureNotFalse(CertificateManager::class, 'valid', 'test');
        expect($result)->toBe('valid');
    });

    it('returns non-false values of various types', function () {
        expect(callEnsureNotFalse(CertificateManager::class, 0, 'test'))->toBe(0);
        expect(callEnsureNotFalse(CertificateManager::class, '', 'test'))->toBe('');
        expect(callEnsureNotFalse(CertificateManager::class, null, 'test'))->toBeNull();
        expect(callEnsureNotFalse(CertificateManager::class, [], 'test'))->toBe([]);
    });

    it('throws SecurityException when result is false', function () {
        expect(fn() => callEnsureNotFalse(CertificateManager::class, false, 'Something failed'))
            ->toThrow(SecurityException::class, 'Something failed');
    });
});

describe('MessageSecurity::ensureNotFalse', function () {

    it('returns the value when not false', function () {
        $result = callEnsureNotFalse(MessageSecurity::class, 42, 'test');
        expect($result)->toBe(42);
    });

    it('throws SecurityException when result is false', function () {
        expect(fn() => callEnsureNotFalse(MessageSecurity::class, false, 'Crypto failed'))
            ->toThrow(SecurityException::class, 'Crypto failed');
    });
});
