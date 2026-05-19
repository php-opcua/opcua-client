<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Aggregate;

/**
 * Configuration for client-side aggregate computation (Part 13 5.2.3).
 */
readonly class AggregateOptions
{
    /**
     * @param bool $stepped Use stepped interpolation instead of linear.
     * @param bool $treatUncertainAsBad Exclude Uncertain raw values from selection and averaging.
     * @param bool $useSlopedExtrapolation Extrapolate past the last raw sample by slope.
     * @param int $percentDataBad Bad-data threshold (0-100) above which the result is Bad.
     * @param int $percentDataGood Good-data threshold (0-100) below which the result is Bad.
     */
    public function __construct(
        public bool $stepped = false,
        public bool $treatUncertainAsBad = true,
        public bool $useSlopedExtrapolation = false,
        public int $percentDataBad = 100,
        public int $percentDataGood = 100,
    ) {
    }

    public static function default(): self
    {
        return new self();
    }
}
