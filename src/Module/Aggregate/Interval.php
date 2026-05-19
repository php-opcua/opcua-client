<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Aggregate;

use DateTimeImmutable;
use InvalidArgumentException;
use PhpOpcua\Client\Types\DataValue;

/**
 * A half-open [startTime, endTime) window over a raw DataValue buffer.
 */
readonly class Interval
{
    /**
     * @param DateTimeImmutable $startTime
     * @param DateTimeImmutable $endTime
     * @param float $processingIntervalMs
     * @param int $index Position of the first contained raw DataValue in the buffer.
     * @param int $count Number of raw DataValues whose sourceTimestamp falls in the window.
     * @param bool $isPartial True when no raw sample exists at or past endTime.
     */
    public function __construct(
        public DateTimeImmutable $startTime,
        public DateTimeImmutable $endTime,
        public float $processingIntervalMs,
        public int $index,
        public int $count,
        public bool $isPartial,
    ) {
    }

    /**
     * Slice [start, end) into windows of `processingIntervalMs` (0 = single window).
     *
     * @param DateTimeImmutable $startTime
     * @param DateTimeImmutable $endTime
     * @param float $processingIntervalMs
     * @param DataValue[] $rawBuffer Ascending by sourceTimestamp.
     * @return Interval[]
     *
     * @throws InvalidArgumentException
     */
    public static function sliceSequence(
        DateTimeImmutable $startTime,
        DateTimeImmutable $endTime,
        float $processingIntervalMs,
        array $rawBuffer,
    ): array {
        if ($endTime <= $startTime) {
            throw new InvalidArgumentException('endTime must be strictly greater than startTime');
        }

        if ($processingIntervalMs < 0) {
            throw new InvalidArgumentException('processingIntervalMs must be >= 0');
        }

        $totalMs = self::diffMs($startTime, $endTime);

        if ($processingIntervalMs === 0.0) {
            return [self::buildOne($startTime, $endTime, $totalMs, $rawBuffer, 0)];
        }

        $intervals = [];
        $cursor = 0;
        $currentStart = $startTime;
        $nMs = $processingIntervalMs;

        for ($offset = 0.0; $offset < $totalMs; $offset += $nMs) {
            $currentEnd = self::addMs($startTime, $offset + $nMs);
            if ($currentEnd > $endTime) {
                $currentEnd = $endTime;
            }
            $built = self::buildOne($currentStart, $currentEnd, $nMs, $rawBuffer, $cursor);
            $intervals[] = $built;
            $cursor = $built->index + $built->count;
            $currentStart = $currentEnd;
        }

        return $intervals;
    }

    /**
     * @param DataValue[] $rawBuffer
     */
    private static function buildOne(
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        float $widthMs,
        array $rawBuffer,
        int $indexHint,
    ): self {
        $startMs = (float) $start->format('U.u') * 1000.0;
        $endMs = (float) $end->format('U.u') * 1000.0;
        $bufferSize = count($rawBuffer);

        $firstIndex = $bufferSize;
        for ($i = $indexHint; $i < $bufferSize; $i++) {
            $ts = $rawBuffer[$i]->sourceTimestamp;
            if ($ts === null) {
                continue;
            }
            $tsMs = (float) $ts->format('U.u') * 1000.0;
            if ($tsMs < $startMs) {
                continue;
            }
            $firstIndex = $i;
            break;
        }

        $count = 0;
        for ($i = $firstIndex; $i < $bufferSize; $i++) {
            $ts = $rawBuffer[$i]->sourceTimestamp;
            if ($ts === null) {
                break;
            }
            $tsMs = (float) $ts->format('U.u') * 1000.0;
            if ($tsMs >= $endMs) {
                break;
            }
            $count++;
        }

        $hasDataPastInterval = ($firstIndex + $count) < $bufferSize;

        return new self(
            startTime: $start,
            endTime: $end,
            processingIntervalMs: $widthMs,
            index: $firstIndex < $bufferSize ? $firstIndex : 0,
            count: $count,
            isPartial: ! $hasDataPastInterval,
        );
    }

    private static function diffMs(DateTimeImmutable $a, DateTimeImmutable $b): float
    {
        return ((float) $b->format('U.u') - (float) $a->format('U.u')) * 1000.0;
    }

    private static function addMs(DateTimeImmutable $base, float $offsetMs): DateTimeImmutable
    {
        $baseUs = (int) round((float) $base->format('U.u') * 1_000_000);
        $offsetUs = (int) round($offsetMs * 1000);
        $totalUs = $baseUs + $offsetUs;
        $sec = intdiv($totalUs, 1_000_000);
        $us = $totalUs - $sec * 1_000_000;
        if ($us < 0) {
            $sec--;
            $us += 1_000_000;
        }

        return DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $sec, $us), $base->getTimezone())
            ?: $base;
    }
}
