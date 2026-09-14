<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Event;

use PhpOpcua\Client\OpcUaClientInterface;

/**
 * Dispatched after the security token of the open secure channel has been renewed.
 *
 * @see \PhpOpcua\Client\Client\ManagesSecureChannelTrait::renewSecureChannel()
 */
readonly class SecureChannelRenewed
{
    public function __construct(
        public OpcUaClientInterface $client,
        public int $channelId,
        public int $tokenId,
        public int $revisedLifetime,
    ) {
    }
}
