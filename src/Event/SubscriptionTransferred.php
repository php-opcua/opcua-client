<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Event;

use PhpOpcua\Client\OpcUaClientInterface;

/**
 * Dispatched after a subscription has been transferred from another session.
 *
 * @see \PhpOpcua\Client\Module\Subscription\SubscriptionModule::transferSubscriptions()
 */
readonly class SubscriptionTransferred
{
    public function __construct(
        public OpcUaClientInterface $client,
        public int $subscriptionId,
        public int $statusCode,
    ) {
    }
}
