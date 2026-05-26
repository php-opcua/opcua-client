<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\FileTransfer;

/**
 * Open-mode bit field for the File Transfer Open method.
 *
 * @see https://reference.opcfoundation.org/Core/Part5/v105/docs/C.2.1 OPC UA Part 5 §C.2.1
 * @see FileTransferModule::openFile()
 */
enum OpenFileMode: int
{
    case Read = 1;

    case Write = 2;

    case EraseExisting = 4;

    case Append = 8;

    /**
     * Combine multiple modes into the single Byte the wire format expects.
     *
     * @param OpenFileMode ...$modes
     * @return int
     */
    public static function toByte(OpenFileMode ...$modes): int
    {
        $result = 0;
        foreach ($modes as $mode) {
            $result |= $mode->value;
        }

        return $result;
    }
}
