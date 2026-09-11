<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Types;

/**
 * LimitBits of a DataValue status code: whether the value is clamped at a limit.
 *
 * @see StatusCode::limit()
 * @see DataValue::limit()
 */
enum DataValueLimit: int
{
    case None = 0;
    case Low = 1;
    case High = 2;
    case Constant = 3;
}
