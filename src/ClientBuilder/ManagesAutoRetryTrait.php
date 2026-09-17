<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ClientBuilder;

use PhpOpcua\Client\Types\SessionState;

/**
 * Provides automatic reconnection retry configuration for failed operations.
 */
trait ManagesAutoRetryTrait
{
    private ?int $autoRetry = null;

    private bool $recreateExpiredSession = true;

    private bool $renewSecurityToken = true;

    private bool $reactivateSession = true;

    private ?SessionState $resumeSessionState = null;

    /**
     * Set the maximum number of automatic reconnection retries on connection loss.
     *
     * @param int $maxRetries Maximum retry count (0 to disable).
     * @return self
     */
    public function setAutoRetry(int $maxRetries): self
    {
        $this->autoRetry = $maxRetries;

        return $this;
    }

    /**
     * Get the current automatic retry count.
     *
     * @return int
     */
    public function getAutoRetry(): int
    {
        return $this->autoRetry ?? 0;
    }

    /**
     * Recreate the session and repeat the call once when the server reports the session as no longer valid.
     *
     * @param bool $enabled
     * @return self
     */
    public function setRecreateExpiredSession(bool $enabled = true): self
    {
        $this->recreateExpiredSession = $enabled;

        return $this;
    }

    /**
     * Whether an expired session is recreated automatically.
     *
     * @return bool
     */
    public function isRecreateExpiredSession(): bool
    {
        return $this->recreateExpiredSession;
    }

    /**
     * Renew the secure channel security token at 75% of its lifetime.
     *
     * @param bool $enabled
     * @return self
     */
    public function setRenewSecurityToken(bool $enabled = true): self
    {
        $this->renewSecurityToken = $enabled;

        return $this;
    }

    /**
     * Whether the secure channel security token is renewed automatically.
     *
     * @return bool
     */
    public function isRenewSecurityToken(): bool
    {
        return $this->renewSecurityToken;
    }

    /**
     * Reactivate the current session on the new secure channel when reconnecting, instead of creating a new one.
     *
     * @param bool $enabled
     * @return self
     */
    public function setReactivateSession(bool $enabled = true): self
    {
        $this->reactivateSession = $enabled;

        return $this;
    }

    /**
     * Whether a reconnect reactivates the current session.
     *
     * @return bool
     */
    public function isReactivateSession(): bool
    {
        return $this->reactivateSession;
    }

    /**
     * Reactivate a session saved with {@see \PhpOpcua\Client\Client::getSessionState()} on connect; a new session is created if the server rejects it.
     *
     * @param ?SessionState $state
     * @return self
     */
    public function resumeSession(?SessionState $state): self
    {
        $this->resumeSessionState = $state;

        return $this;
    }
}
