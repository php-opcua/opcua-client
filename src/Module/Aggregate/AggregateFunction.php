<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Aggregate;

/**
 * Built-in client-side aggregate functions supported by {@see AggregateModule}.
 */
enum AggregateFunction: string
{
    case Interpolate = 'interpolate';

    case Minimum = 'minimum';

    case Maximum = 'maximum';

    case Average = 'average';

    case Count = 'count';
}
