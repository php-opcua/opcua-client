<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Types;

use JsonSerializable;
use PhpOpcua\Client\Exception\ConfigurationException;

/**
 * Identifies an OPC UA session so it can be reactivated on a new secure channel, also by another process.
 */
final readonly class SessionState implements JsonSerializable
{
    /**
     * @param string $endpointUrl
     * @param NodeId $authenticationToken
     * @param ?string $serverNonce Server nonce of the last CreateSession or ActivateSession response.
     * @param float $sessionTimeout Session timeout granted by the server, in milliseconds.
     */
    public function __construct(
        public string $endpointUrl,
        public NodeId $authenticationToken,
        public ?string $serverNonce,
        public float $sessionTimeout,
    ) {
    }

    /**
     * @return array{endpointUrl: string, authenticationToken: string, serverNonce: ?string, sessionTimeout: float}
     */
    public function jsonSerialize(): array
    {
        return [
            'endpointUrl' => $this->endpointUrl,
            'authenticationToken' => (string) $this->authenticationToken,
            'serverNonce' => $this->serverNonce === null ? null : base64_encode($this->serverNonce),
            'sessionTimeout' => $this->sessionTimeout,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return self
     *
     * @throws ConfigurationException If a field is missing or malformed.
     */
    public static function fromArray(array $data): self
    {
        $endpointUrl = $data['endpointUrl'] ?? null;
        $token = $data['authenticationToken'] ?? null;
        $nonce = $data['serverNonce'] ?? null;
        $timeout = $data['sessionTimeout'] ?? null;

        if (! is_string($endpointUrl) || ! is_string($token) || ! is_numeric($timeout) || ($nonce !== null && ! is_string($nonce))) {
            throw new ConfigurationException('Invalid session state: endpointUrl, authenticationToken and sessionTimeout are required');
        }

        $decodedNonce = $nonce === null ? null : base64_decode($nonce, true);
        if ($decodedNonce === false) {
            throw new ConfigurationException('Invalid session state: serverNonce is not valid base64');
        }

        return new self($endpointUrl, NodeId::parse($token), $decodedNonce, (float) $timeout);
    }
}
