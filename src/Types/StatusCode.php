<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Types;

/**
 * Provides OPC UA status code constants and utility methods for status code classification.
 */
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

    public const UncertainDataSubNormal = 0x40A30000;

    public const BadAggregateInvalidInputs = 0x80D60000;

    public const BadAggregateNotSupported = 0x80D80000;

    public const BadAggregateConfigurationRejected = 0x80DA0000;

    public const BadInvalidState = 0x80AF0000;

    public const BadFileHandleInvalid = 0x80E70000;

    public const BadFileNotOpened = 0x80E80000;

    public const InfoTypeDataValue = 0x00000400;

    public const HistorianCalculated = 0x00000001;

    public const HistorianInterpolated = 0x00000002;

    public const HistorianPartial = 0x00000004;

    public const HistorianExtraData = 0x00000008;

    public const HistorianMultiValue = 0x00000010;

    public const Overflow = 0x00000080;

    public const LimitLow = 0x00000100;

    public const LimitHigh = 0x00000200;

    public const LimitConstant = 0x00000300;

    private const INFO_TYPE_MASK = 0x00000C00;

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
        self::UncertainDataSubNormal => 'UncertainDataSubNormal',
        self::BadAggregateInvalidInputs => 'BadAggregateInvalidInputs',
        self::BadAggregateNotSupported => 'BadAggregateNotSupported',
        self::BadAggregateConfigurationRejected => 'BadAggregateConfigurationRejected',
        self::BadInvalidState => 'BadInvalidState',
        self::BadFileHandleInvalid => 'BadFileHandleInvalid',
        self::BadFileNotOpened => 'BadFileNotOpened',
    ];

    /**
     * Checks whether the given status code indicates a good (successful) result.
     *
     * @param int $code
     * @return bool
     */
    public static function isGood(int $code): bool
    {
        return ($code & 0xC0000000) === 0x00000000;
    }

    /**
     * Checks whether the given status code indicates a bad (failed) result.
     *
     * @param int $code
     * @return bool
     */
    public static function isBad(int $code): bool
    {
        return ($code & 0xC0000000) === 0x80000000;
    }

    /**
     * Checks whether the given status code indicates an uncertain result.
     *
     * @param int $code
     * @return bool
     */
    public static function isUncertain(int $code): bool
    {
        return ($code & 0xC0000000) === 0x40000000;
    }

    /**
     * Returns the human-readable name of the given status code, or a hex string if unknown.
     *
     * @param int $code
     * @return string
     */
    public static function getName(int $code): string
    {
        return self::NAMES[$code] ?? sprintf('0x%08X', $code);
    }

    /**
     * Combine a severity status code with DataValue InfoBits (Part 4 §7.34.2).
     *
     * @param int $severityCode
     * @param int $infoBits OR of HistorianCalculated, HistorianPartial, etc.
     * @return int
     */
    public static function withDataValueInfoBits(int $severityCode, int $infoBits): int
    {
        if ($infoBits === 0) {
            return $severityCode;
        }

        return ($severityCode | self::InfoTypeDataValue | ($infoBits & 0x3FF)) & 0xFFFFFFFF;
    }

    /**
     * Checks whether the status code carries DataValue InfoBits (Part 4 §7.34.2).
     *
     * @param int $code
     * @return bool
     */
    public static function hasDataValueInfoBits(int $code): bool
    {
        return ($code & self::INFO_TYPE_MASK) === self::InfoTypeDataValue;
    }

    /**
     * Checks whether the MonitoredItem queue overflowed and this value replaced a discarded one.
     *
     * @param int $code
     * @return bool
     * @see self::hasDataValueInfoBits()
     */
    public static function isOverflow(int $code): bool
    {
        return self::hasDataValueInfoBits($code) && ($code & self::Overflow) !== 0;
    }

    /**
     * Returns the LimitBits of a DataValue status code.
     *
     * @param int $code
     * @return DataValueLimit {@see DataValueLimit::None} when the code carries no DataValue InfoBits.
     * @see self::hasDataValueInfoBits()
     */
    public static function limit(int $code): DataValueLimit
    {
        if (! self::hasDataValueInfoBits($code)) {
            return DataValueLimit::None;
        }

        return DataValueLimit::from(($code & self::LimitConstant) >> 8);
    }
}
