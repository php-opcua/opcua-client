<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Subscription;

/**
 * Result of transferring a single subscription to a new session.
 *
 * @see SubscriptionModule::transferSubscriptions()
 */
readonly class TransferResult
{
    /**
     * @param int $statusCode
     * @param int[] $availableSequenceNumbers
     */
    public function __construct(
        public int $statusCode,
        public array $availableSequenceNumbers,
    ) {
    }
}
