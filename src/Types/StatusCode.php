<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Types;

class StatusCode
{
    public const Good = 0x00000000;
    public const BadUnexpectedError = 0x80010000;
    public const BadInternalError = 0x80020000;
    public const BadOutOfMemory = 0x80030000;
    public const BadCommunicationError = 0x80050000;
    public const BadTimeout = 0x800A0000;
    public const BadServiceUnsupported = 0x800B0000;
    public const BadNothingToDo = 0x800F0000;
    public const BadTooManyOperations = 0x80100000;
    public const BadNodeIdUnknown = 0x80340000;
    public const BadAttributeIdInvalid = 0x80350000;
    public const BadIndexRangeInvalid = 0x80360000;
    public const BadNotWritable = 0x803B0000;
    public const BadNotReadable = 0x803E0000;
    public const BadTypeMismatch = 0x80740000;
    public const BadInvalidArgument = 0x80AB0000;
    public const BadNoData = 0x80B10000;
    public const BadUserAccessDenied = 0x801F0000;
    public const BadSessionIdInvalid = 0x80250000;
    public const BadSecureChannelIdInvalid = 0x80220000;
    public const BadMethodInvalid = 0x80750000;
    public const BadArgumentsMissing = 0x80760000;

    public const UncertainNoCommunicationLastUsableValue = 0x408F0000;

    private const NAMES = [
        self::Good => 'Good',
        self::BadUnexpectedError => 'BadUnexpectedError',
        self::BadInternalError => 'BadInternalError',
        self::BadOutOfMemory => 'BadOutOfMemory',
        self::BadCommunicationError => 'BadCommunicationError',
        self::BadTimeout => 'BadTimeout',
        self::BadServiceUnsupported => 'BadServiceUnsupported',
        self::BadNothingToDo => 'BadNothingToDo',
        self::BadTooManyOperations => 'BadTooManyOperations',
        self::BadNodeIdUnknown => 'BadNodeIdUnknown',
        self::BadAttributeIdInvalid => 'BadAttributeIdInvalid',
        self::BadIndexRangeInvalid => 'BadIndexRangeInvalid',
        self::BadNotWritable => 'BadNotWritable',
        self::BadNotReadable => 'BadNotReadable',
        self::BadTypeMismatch => 'BadTypeMismatch',
        self::BadInvalidArgument => 'BadInvalidArgument',
        self::BadNoData => 'BadNoData',
        self::BadUserAccessDenied => 'BadUserAccessDenied',
        self::BadSessionIdInvalid => 'BadSessionIdInvalid',
        self::BadSecureChannelIdInvalid => 'BadSecureChannelIdInvalid',
        self::BadMethodInvalid => 'BadMethodInvalid',
        self::BadArgumentsMissing => 'BadArgumentsMissing',
        self::UncertainNoCommunicationLastUsableValue => 'UncertainNoCommunicationLastUsableValue',
    ];

    /**
     * @param int $code
     */
    public static function isGood(int $code): bool
    {
        return ($code & 0xC0000000) === 0x00000000;
    }

    /**
     * @param int $code
     */
    public static function isBad(int $code): bool
    {
        return ($code & 0xC0000000) === 0x80000000;
    }

    /**
     * @param int $code
     */
    public static function isUncertain(int $code): bool
    {
        return ($code & 0xC0000000) === 0x40000000;
    }

    /**
     * @param int $code
     */
    public static function getName(int $code): string
    {
        return self::NAMES[$code] ?? sprintf('0x%08X', $code);
    }
}
