<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Types;

use PhpOpcua\Client\Wire\WireSerializable;

/**
 * Represents an OPC UA UserTokenPolicy describing an accepted user identity token type.
 */
readonly class UserTokenPolicy implements WireSerializable
{
    /**
     * @param ?string $policyId
     * @param int $tokenType
     * @param ?string $issuedTokenType
     * @param ?string $issuerEndpointUrl
     * @param ?string $securityPolicyUri
     */
    public function __construct(
        public ?string $policyId,
        public int $tokenType,
        public ?string $issuedTokenType,
        public ?string $issuerEndpointUrl,
        public ?string $securityPolicyUri,
    ) {
    }

    /**
     * @deprecated Access the public property directly instead. Use ->policyId instead.
     * @return ?string
     * @see UserTokenPolicy::$policyId
     */
    public function getPolicyId(): ?string
    {
        return $this->policyId;
    }

    /**
     * @deprecated Access the public property directly instead. Use ->tokenType instead.
     * @return int
     * @see UserTokenPolicy::$tokenType
     */
    public function getTokenType(): int
    {
        return $this->tokenType;
    }

    /**
     * @deprecated Access the public property directly instead. Use ->issuedTokenType instead.
     * @return ?string
     * @see UserTokenPolicy::$issuedTokenType
     */
    public function getIssuedTokenType(): ?string
    {
        return $this->issuedTokenType;
    }

    /**
     * @deprecated Access the public property directly instead. Use ->issuerEndpointUrl instead.
     * @return ?string
     * @see UserTokenPolicy::$issuerEndpointUrl
     */
    public function getIssuerEndpointUrl(): ?string
    {
        return $this->issuerEndpointUrl;
    }

    /**
     * @deprecated Access the public property directly instead. Use ->securityPolicyUri instead.
     * @return ?string
     * @see UserTokenPolicy::$securityPolicyUri
     */
    public function getSecurityPolicyUri(): ?string
    {
        return $this->securityPolicyUri;
    }

    /**
     * @return array{policyId: ?string, tokenType: int, issuedType: ?string, issuer: ?string, policy: ?string}
     */
    public function jsonSerialize(): array
    {
        return [
            'policyId' => $this->policyId,
            'tokenType' => $this->tokenType,
            'issuedType' => $this->issuedTokenType,
            'issuer' => $this->issuerEndpointUrl,
            'policy' => $this->securityPolicyUri,
        ];
    }

    /**
     * @param array{policyId?: ?string, tokenType?: int, issuedType?: ?string, issuer?: ?string, policy?: ?string} $data
     * @return static
     */
    public static function fromWireArray(array $data): static
    {
        return new self(
            $data['policyId'] ?? null,
            $data['tokenType'] ?? 0,
            $data['issuedType'] ?? null,
            $data['issuer'] ?? null,
            $data['policy'] ?? null,
        );
    }

    /**
     * @return string
     */
    public static function wireTypeId(): string
    {
        return 'UserTokenPolicy';
    }
}
