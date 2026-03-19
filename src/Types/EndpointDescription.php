<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Types;

class EndpointDescription
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
        private readonly string  $endpointUrl,
        private readonly ?string $serverCertificate,
        private readonly int     $securityMode,
        private readonly string  $securityPolicyUri,
        private readonly array   $userIdentityTokens,
        private readonly string  $transportProfileUri,
        private readonly int     $securityLevel,
    )
    {
    }

    public function getEndpointUrl(): string
    {
        return $this->endpointUrl;
    }

    public function getServerCertificate(): ?string
    {
        return $this->serverCertificate;
    }

    public function getSecurityMode(): int
    {
        return $this->securityMode;
    }

    public function getSecurityPolicyUri(): string
    {
        return $this->securityPolicyUri;
    }

    /**
     * @return UserTokenPolicy[]
     */
    public function getUserIdentityTokens(): array
    {
        return $this->userIdentityTokens;
    }

    public function getTransportProfileUri(): string
    {
        return $this->transportProfileUri;
    }

    public function getSecurityLevel(): int
    {
        return $this->securityLevel;
    }
}
