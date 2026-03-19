<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Types;

class UserTokenPolicy
{
    /**
     * @param ?string $policyId
     * @param int $tokenType
     * @param ?string $issuedTokenType
     * @param ?string $issuerEndpointUrl
     * @param ?string $securityPolicyUri
     */
    public function __construct(
        private readonly ?string $policyId,
        private readonly int     $tokenType,
        private readonly ?string $issuedTokenType,
        private readonly ?string $issuerEndpointUrl,
        private readonly ?string $securityPolicyUri,
    )
    {
    }

    public function getPolicyId(): ?string
    {
        return $this->policyId;
    }

    public function getTokenType(): int
    {
        return $this->tokenType;
    }

    public function getIssuedTokenType(): ?string
    {
        return $this->issuedTokenType;
    }

    public function getIssuerEndpointUrl(): ?string
    {
        return $this->issuerEndpointUrl;
    }

    public function getSecurityPolicyUri(): ?string
    {
        return $this->securityPolicyUri;
    }
}
