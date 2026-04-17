<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\ReadWrite;

use PhpOpcua\Client\Types\Variant;

/**
 * Holds the result of an OPC UA method Call operation.
 *
 * @see ReadWriteModule::call()
 */
readonly class CallResult
{
    /**
     * @param int $statusCode
     * @param int[] $inputArgumentResults
     * @param Variant[] $outputArguments
     */
    public function __construct(
        public int $statusCode,
        public array $inputArgumentResults,
        public array $outputArguments,
    ) {
    }
}
