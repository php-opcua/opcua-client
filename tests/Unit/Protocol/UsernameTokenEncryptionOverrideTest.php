<?php

declare(strict_types=1);

use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Security\CertificateManager;
use PhpOpcua\Client\Security\MessageSecurity;
use PhpOpcua\Client\Security\SecureChannel;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;

if (! function_exists('generateCertForUsernameTokenTest')) {
    function generateCertForUsernameTokenTest(int $bits = 2048): array
    {
        $privKey = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['CN' => 'test'], $privKey);
        $cert = openssl_csr_sign($csr, null, $privKey, 365);
        openssl_x509_export($cert, $certPem);

        $cm = new CertificateManager();
        $tmp = tempnam(sys_get_temp_dir(), 'opcua_');
        file_put_contents($tmp, $certPem);
        $der = $cm->loadCertificatePem($tmp);
        unlink($tmp);

        return [$der, $privKey];
    }
}

function callWriteUsernameIdentityToken(SessionService $session, string $username, string $password, ?string $serverNonce): string
{
    $encoder = new BinaryEncoder();
    $ref = new ReflectionMethod($session, 'writeUsernameIdentityToken');
    $ref->invoke($session, $encoder, $username, $password, $serverNonce);

    return $encoder->getBuffer();
}

describe('UserName password encryption respects UserTokenPolicy.SecurityPolicyUri', function () {
    beforeEach(function () {
        [$serverDer, $serverKey] = generateCertForUsernameTokenTest();
        $this->serverDer = $serverDer;
        $this->serverKey = $serverKey;
        $this->serverNonce = random_bytes(32);
        $this->password = 'super-secret-password-123';
        $this->username = 'admin';
    });

    it('encrypts the password with UserTokenPolicy.SecurityPolicyUri on a SecureChannel=None session', function () {
        $session = new SessionService(100, 200, null);
        $session->setUserTokenPolicyIds(
            'UserName_Basic256Sha256_Token',
            null,
            null,
            SecurityPolicy::Basic256Sha256->value,
        );
        $session->setUserTokenEncryptionContext(
            $this->serverDer,
            new MessageSecurity(new CertificateManager()),
        );

        $tokenBytes = callWriteUsernameIdentityToken($session, $this->username, $this->password, $this->serverNonce);

        expect(str_contains($tokenBytes, $this->password))->toBeFalse();
        expect(str_contains($tokenBytes, SecurityPolicy::Basic256Sha256->getAsymmetricEncryptionUri()))->toBeTrue();
        expect(str_contains($tokenBytes, 'UserName_Basic256Sha256_Token'))->toBeTrue();
    });

    it('falls back to plaintext when SecureChannel=None and UserTokenPolicy has no SecurityPolicyUri', function () {
        $session = new SessionService(100, 200, null);
        $session->setUserTokenPolicyIds('plain-username', null, null, null);
        $session->setUserTokenEncryptionContext(
            $this->serverDer,
            new MessageSecurity(new CertificateManager()),
        );

        $tokenBytes = callWriteUsernameIdentityToken($session, $this->username, $this->password, $this->serverNonce);

        expect(str_contains($tokenBytes, $this->password))->toBeTrue();
    });

    it('keeps using the SecureChannel policy when UserTokenPolicy.SecurityPolicyUri is null', function () {
        $channel = new SecureChannel(
            SecurityPolicy::Basic256Sha256,
            SecurityMode::SignAndEncrypt,
            $this->serverDer,
            $this->serverKey,
            $this->serverDer,
        );
        $session = new SessionService(100, 200, $channel);
        $session->setUserTokenPolicyIds('UserName_ChannelPolicy', null, null, null);

        $tokenBytes = callWriteUsernameIdentityToken($session, $this->username, $this->password, $this->serverNonce);

        expect(str_contains($tokenBytes, $this->password))->toBeFalse();
        expect(str_contains($tokenBytes, SecurityPolicy::Basic256Sha256->getAsymmetricEncryptionUri()))->toBeTrue();
    });

    it('overrides the SecureChannel policy when UserTokenPolicy specifies a different one', function () {
        $channel = new SecureChannel(
            SecurityPolicy::Basic128Rsa15,
            SecurityMode::SignAndEncrypt,
            $this->serverDer,
            $this->serverKey,
            $this->serverDer,
        );
        $session = new SessionService(100, 200, $channel);
        $session->setUserTokenPolicyIds(
            'UserName_Basic256Sha256_Token',
            null,
            null,
            SecurityPolicy::Basic256Sha256->value,
        );

        $tokenBytes = callWriteUsernameIdentityToken($session, $this->username, $this->password, $this->serverNonce);

        expect(str_contains($tokenBytes, $this->password))->toBeFalse();
        expect(str_contains($tokenBytes, SecurityPolicy::Basic256Sha256->getAsymmetricEncryptionUri()))->toBeTrue();
        expect(str_contains($tokenBytes, SecurityPolicy::Basic128Rsa15->getAsymmetricEncryptionUri()))->toBeFalse();
    });
});
