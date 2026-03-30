<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Client;

use PhpOpcua\Client\Event\ServerCertificateAutoAccepted;
use PhpOpcua\Client\Event\ServerCertificateManuallyTrusted;
use PhpOpcua\Client\Event\ServerCertificateRejected;
use PhpOpcua\Client\Event\ServerCertificateRemoved;
use PhpOpcua\Client\Event\ServerCertificateTrusted;
use PhpOpcua\Client\Exception\UntrustedCertificateException;
use PhpOpcua\Client\TrustStore\TrustPolicy;
use PhpOpcua\Client\TrustStore\TrustStoreInterface;

/**
 * Provides runtime server certificate trust management for the connected client.
 *
 * Handles certificate validation during connection, manual trust/untrust operations,
 * and auto-accept (TOFU) logic.
 */
trait ManagesTrustStoreRuntimeTrait
{
    /**
     * Get the current trust store.
     *
     * @return ?TrustStoreInterface
     */
    public function getTrustStore(): ?TrustStoreInterface
    {
        return $this->trustStore;
    }

    /**
     * Get the current trust policy. Null means validation is disabled.
     *
     * @return ?TrustPolicy
     */
    public function getTrustPolicy(): ?TrustPolicy
    {
        return $this->trustPolicy;
    }

    /**
     * Manually trust a server certificate and add it to the trust store.
     *
     * @param string $certDer DER-encoded certificate bytes.
     * @return void
     */
    public function trustCertificate(string $certDer): void
    {
        if ($this->trustStore === null) {
            return;
        }

        $this->trustStore->trust($certDer);
        $fingerprint = implode(':', str_split(sha1($certDer), 2));
        $this->logger->info('Server certificate manually trusted (fingerprint={fingerprint})', $this->logContext(['fingerprint' => $fingerprint]));
        $this->dispatch(fn () => new ServerCertificateManuallyTrusted($this, $fingerprint));
    }

    /**
     * Remove a server certificate from the trust store.
     *
     * @param string $fingerprint SHA-1 fingerprint (hex, colon-separated or plain hex).
     * @return void
     */
    public function untrustCertificate(string $fingerprint): void
    {
        if ($this->trustStore === null) {
            return;
        }

        $this->trustStore->untrust($fingerprint);
        $this->logger->info('Server certificate removed (fingerprint={fingerprint})', $this->logContext(['fingerprint' => $fingerprint]));
        $this->dispatch(fn () => new ServerCertificateRemoved($this, $fingerprint));
    }

    /**
     * Validate the server certificate against the trust store.
     *
     * @return void
     *
     * @throws UntrustedCertificateException If the certificate is not trusted and auto-accept is disabled.
     */
    private function validateServerCertificate(): void
    {
        if ($this->trustStore === null || $this->trustPolicy === null || $this->serverCertDer === null) {
            return;
        }

        $caCertPem = $this->caCertPath !== null ? @file_get_contents($this->caCertPath) ?: null : null;
        $result = $this->trustStore->validate($this->serverCertDer, $this->trustPolicy, $caCertPem);

        if ($result->trusted) {
            $this->logger->debug('Server certificate trusted (fingerprint={fingerprint})', $this->logContext(['fingerprint' => $result->fingerprint]));
            $this->dispatch(fn () => new ServerCertificateTrusted($this, $result->fingerprint, $result->subject));

            return;
        }

        if ($this->autoAcceptEnabled) {
            if ($this->autoAcceptForce) {
                $this->trustStore->trust($this->serverCertDer);
                $this->logger->info('Server certificate force-accepted (fingerprint={fingerprint})', $this->logContext(['fingerprint' => $result->fingerprint]));
                $this->dispatch(fn () => new ServerCertificateAutoAccepted($this, $result->fingerprint, $result->subject));

                return;
            }

            if (empty($this->trustStore->getTrustedCertificates())) {
                $this->trustStore->trust($this->serverCertDer);
                $this->logger->info('Server certificate auto-accepted (fingerprint={fingerprint})', $this->logContext(['fingerprint' => $result->fingerprint]));
                $this->dispatch(fn () => new ServerCertificateAutoAccepted($this, $result->fingerprint, $result->subject));

                return;
            }
        }

        $this->trustStore->reject($this->serverCertDer);
        $this->logger->warning('Server certificate rejected: {reason} (fingerprint={fingerprint})', $this->logContext([
            'reason' => $result->reason,
            'fingerprint' => $result->fingerprint,
        ]));
        $this->dispatch(fn () => new ServerCertificateRejected($this, $result->fingerprint, $result->reason, $result->subject));

        throw new UntrustedCertificateException(
            $result->fingerprint,
            $this->serverCertDer,
            sprintf(
                "Server certificate not trusted.\n  Fingerprint: %s\n  Subject: %s\n  Reason: %s",
                $result->fingerprint,
                $result->subject ?? 'Unknown',
                $result->reason ?? 'Unknown',
            ),
        );
    }
}
