<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Event;

use PhpOpcua\Client\OpcUaClientInterface;

/**
 * Dispatched after an existing session has been reactivated on a new secure channel.
 *
 * @see \PhpOpcua\Client\Client\ManagesSessionTrait::establishSession()
 */
readonly class SessionReactivated
{
    public function __construct(
        public OpcUaClientInterface $client,
        public string $endpointUrl,
    ) {
    }
}
