<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\ReadWrite;

use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Wire\WireSerializable;

/**
 * Holds the result of an OPC UA method Call operation.
 *
 * @see ReadWriteModule::call()
 */
readonly class CallResult implements WireSerializable
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

    /**
     * @return array{status: int, inputResults: int[], output: Variant[]}
     */
    public function jsonSerialize(): array
    {
        return [
            'status' => $this->statusCode,
            'inputResults' => $this->inputArgumentResults,
            'output' => $this->outputArguments,
        ];
    }

    /**
     * @param array{status?: int, inputResults?: int[], output?: Variant[]} $data
     * @return static
     */
    public static function fromWireArray(array $data): static
    {
        return new self(
            $data['status'] ?? 0,
            $data['inputResults'] ?? [],
            $data['output'] ?? [],
        );
    }

    /**
     * @return string
     */
    public static function wireTypeId(): string
    {
        return 'CallResult';
    }
}
