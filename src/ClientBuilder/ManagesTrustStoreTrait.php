<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ClientBuilder;

use PhpOpcua\Client\TrustStore\TrustPolicy;
use PhpOpcua\Client\TrustStore\TrustStoreInterface;

/**
 * Provides server certificate trust store configuration for the OPC UA client.
 *
 * When a trust store and policy are configured, the client validates the server certificate
 * during connection. Pass `setTrustPolicy(null)` to disable validation entirely (default).
 */
trait ManagesTrustStoreTrait
{
    /**
     * @var ?TrustStoreInterface
     */
    private ?TrustStoreInterface $trustStore = null;

    /**
     * @var ?TrustPolicy
     */
    private ?TrustPolicy $trustPolicy = null;

    /**
     * @var bool
     */
    private bool $autoAcceptEnabled = false;

    /**
     * @var bool
     */
    private bool $autoAcceptForce = false;

    /**
     * Set the trust store for server certificate validation.
     *
     * @param ?TrustStoreInterface $trustStore The trust store, or null to remove.
     * @return self
     */
    public function setTrustStore(?TrustStoreInterface $trustStore): self
    {
        $this->trustStore = $trustStore;

        return $this;
    }

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
     * Set the trust validation policy. Pass null to disable trust validation entirely.
     *
     * @param ?TrustPolicy $policy
     * @return self
     */
    public function setTrustPolicy(?TrustPolicy $policy): self
    {
        $this->trustPolicy = $policy;

        return $this;
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
     * Enable or disable auto-accept (TOFU) for unknown server certificates.
     *
     * When enabled, unknown certificates are accepted and saved to the trust store.
     * However, if a different certificate is already trusted for the same server,
     * the connection fails — auto-accept only works for genuinely new certificates.
     *
     * @param bool $enabled
     * @param bool $force
     * @return self
     */
    public function autoAccept(bool $enabled = true, bool $force = false): self
    {
        $this->autoAcceptEnabled = $enabled;
        $this->autoAcceptForce = $force;

        return $this;
    }

    /**
     * @var bool
     */
    private bool $verifyApplicationUri = true;

    /**
     * @param bool $enabled
     * @return self
     */
    public function verifyApplicationUri(bool $enabled = true): self
    {
        $this->verifyApplicationUri = $enabled;

        return $this;
    }
}
