<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Types;

use PhpOpcua\Client\Wire\WireSerializable;

/**
 * Represents an OPC UA EndpointDescription describing a server endpoint and its security configuration.
 */
readonly class EndpointDescription implements WireSerializable
{
    /**
     * @param string $endpointUrl
     * @param ?string $serverCertificate
     * @param int $securityMode
     * @param string $securityPolicyUri
     * @param UserTokenPolicy[] $userIdentityTokens
     * @param string $transportProfileUri
     * @param int $securityLevel
     */
    public function __construct(
        public string $endpointUrl,
        public ?string $serverCertificate,
        public int $securityMode,
        public string $securityPolicyUri,
        public array $userIdentityTokens,
        public string $transportProfileUri,
        public int $securityLevel,
    ) {
    }

    /**
     * @deprecated Access the public property directly instead. Use ->endpointUrl instead.
     * @return string
     * @see EndpointDescription::$endpointUrl
     */
    public function getEndpointUrl(): string
    {
        return $this->endpointUrl;
    }

    /**
     * @deprecated Access the public property directly instead. Use ->serverCertificate instead.
     * @return ?string
     * @see EndpointDescription::$serverCertificate
     */
    public function getServerCertificate(): ?string
    {
        return $this->serverCertificate;
    }

    /**
     * @deprecated Access the public property directly instead. Use ->securityMode instead.
     * @return int
     * @see EndpointDescription::$securityMode
     */
    public function getSecurityMode(): int
    {
        return $this->securityMode;
    }

    /**
     * @deprecated Access the public property directly instead. Use ->securityPolicyUri instead.
     * @return string
     * @see EndpointDescription::$securityPolicyUri
     */
    public function getSecurityPolicyUri(): string
    {
        return $this->securityPolicyUri;
    }

    /**
     * @deprecated Access the public property directly instead. Use ->userIdentityTokens instead.
     * @return UserTokenPolicy[]
     * @see EndpointDescription::$userIdentityTokens
     */
    public function getUserIdentityTokens(): array
    {
        return $this->userIdentityTokens;
    }

    /**
     * @deprecated Access the public property directly instead. Use ->transportProfileUri instead.
     * @return string
     * @see EndpointDescription::$transportProfileUri
     */
    public function getTransportProfileUri(): string
    {
        return $this->transportProfileUri;
    }

    /**
     * @deprecated Access the public property directly instead. Use ->securityLevel instead.
     * @return int
     * @see EndpointDescription::$securityLevel
     */
    public function getSecurityLevel(): int
    {
        return $this->securityLevel;
    }

    /**
     * @return array{url: string, cert: ?string, mode: int, policy: string, tokens: UserTokenPolicy[], profile: string, level: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'url' => $this->endpointUrl,
            'cert' => $this->serverCertificate !== null ? base64_encode($this->serverCertificate) : null,
            'mode' => $this->securityMode,
            'policy' => $this->securityPolicyUri,
            'tokens' => $this->userIdentityTokens,
            'profile' => $this->transportProfileUri,
            'level' => $this->securityLevel,
        ];
    }

    /**
     * @param array{url?: string, cert?: ?string, mode?: int, policy?: string, tokens?: array, profile?: string, level?: int} $data
     * @return static
     */
    public static function fromWireArray(array $data): static
    {
        $cert = null;
        if (isset($data['cert']) && is_string($data['cert'])) {
            $cert = base64_decode($data['cert'], true) ?: null;
        }

        return new self(
            $data['url'] ?? '',
            $cert,
            $data['mode'] ?? 0,
            $data['policy'] ?? '',
            $data['tokens'] ?? [],
            $data['profile'] ?? '',
            $data['level'] ?? 0,
        );
    }

    /**
     * @return string
     */
    public static function wireTypeId(): string
    {
        return 'EndpointDescription';
    }
}
