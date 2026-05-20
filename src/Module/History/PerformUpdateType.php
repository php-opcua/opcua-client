<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\History;

/**
 * Server-side semantics for a HistoryUpdate operation (Part 11 §6.9).
 *
 * @see https://reference.opcfoundation.org/Core/Part11/v105/docs/6.9.2
 */
enum PerformUpdateType: int
{
    case Insert = 1;

    case Replace = 2;

    case Update = 3;

    case Remove = 4;
}
