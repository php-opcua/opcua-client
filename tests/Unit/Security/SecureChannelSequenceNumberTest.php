<?php

declare(strict_types=1);

use PhpOpcua\Client\Security\SecureChannel;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;

describe('SecureChannel::getNextSequenceNumber — ECC starts from 0', function () {

    it('returns 0 on first call for ECC_nistP256', function () {
        $channel = new SecureChannel(SecurityPolicy::EccNistP256, SecurityMode::Sign);
        expect($channel->getNextSequenceNumber())->toBe(0);
    });

    it('returns 0 on first call for ECC_nistP384', function () {
        $channel = new SecureChannel(SecurityPolicy::EccNistP384, SecurityMode::Sign);
        expect($channel->getNextSequenceNumber())->toBe(0);
    });

    it('returns 0 on first call for ECC_brainpoolP256r1', function () {
        $channel = new SecureChannel(SecurityPolicy::EccBrainpoolP256r1, SecurityMode::Sign);
        expect($channel->getNextSequenceNumber())->toBe(0);
    });

    it('returns 0 on first call for ECC_brainpoolP384r1', function () {
        $channel = new SecureChannel(SecurityPolicy::EccBrainpoolP384r1, SecurityMode::Sign);
        expect($channel->getNextSequenceNumber())->toBe(0);
    });

    it('increments monotonically after the initial 0 for ECC', function () {
        $channel = new SecureChannel(SecurityPolicy::EccNistP256, SecurityMode::Sign);
        expect($channel->getNextSequenceNumber())->toBe(0);
        expect($channel->getNextSequenceNumber())->toBe(1);
        expect($channel->getNextSequenceNumber())->toBe(2);
        expect($channel->getNextSequenceNumber())->toBe(3);
    });
});

describe('SecureChannel::getNextSequenceNumber — RSA (legacy) starts from 1', function () {

    it('returns 1 on first call for SecurityPolicy::None (RSA/Legacy path)', function () {
        $channel = new SecureChannel(SecurityPolicy::None, SecurityMode::None);
        expect($channel->getNextSequenceNumber())->toBe(1);
    });

    it('returns 1 on first call for Basic256Sha256', function () {
        $channel = new SecureChannel(SecurityPolicy::Basic256Sha256, SecurityMode::Sign);
        expect($channel->getNextSequenceNumber())->toBe(1);
    });

    it('returns 1 on first call for Aes256Sha256RsaPss', function () {
        $channel = new SecureChannel(SecurityPolicy::Aes256Sha256RsaPss, SecurityMode::Sign);
        expect($channel->getNextSequenceNumber())->toBe(1);
    });

    it('increments monotonically after the initial 1 for RSA', function () {
        $channel = new SecureChannel(SecurityPolicy::Basic256Sha256, SecurityMode::Sign);
        expect($channel->getNextSequenceNumber())->toBe(1);
        expect($channel->getNextSequenceNumber())->toBe(2);
        expect($channel->getNextSequenceNumber())->toBe(3);
    });
});

describe('SecureChannel::getNextSequenceNumber — wrap logic', function () {

    it('wraps RSA sequence number at UInt32.MaxValue - 1024 and restarts from 1', function () {
        $channel = new SecureChannel(SecurityPolicy::Basic256Sha256, SecurityMode::Sign);
        $prop = new ReflectionProperty(SecureChannel::class, 'sequenceNumber');
        $prop->setValue($channel, 0xFFFFFBFF);

        expect($channel->getNextSequenceNumber())->toBe(0xFFFFFBFF);
        expect($channel->getNextSequenceNumber())->toBe(1);
        expect($channel->getNextSequenceNumber())->toBe(2);
    });

    it('wraps ECC sequence number at UInt32.MaxValue and restarts from 0', function () {
        $channel = new SecureChannel(SecurityPolicy::EccNistP256, SecurityMode::Sign);
        $prop = new ReflectionProperty(SecureChannel::class, 'sequenceNumber');
        $prop->setValue($channel, 0xFFFFFFFF);

        expect($channel->getNextSequenceNumber())->toBe(0xFFFFFFFF);
        expect($channel->getNextSequenceNumber())->toBe(0);
        expect($channel->getNextSequenceNumber())->toBe(1);
    });

    it('ECC does not wrap prematurely below UInt32.MaxValue', function () {
        $channel = new SecureChannel(SecurityPolicy::EccNistP256, SecurityMode::Sign);
        $prop = new ReflectionProperty(SecureChannel::class, 'sequenceNumber');
        $prop->setValue($channel, 0xFFFFFBFF);

        expect($channel->getNextSequenceNumber())->toBe(0xFFFFFBFF);
        expect($channel->getNextSequenceNumber())->toBe(0xFFFFFC00);
    });
});
