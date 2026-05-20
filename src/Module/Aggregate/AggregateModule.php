<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Aggregate;

use DateTimeImmutable;
use PhpOpcua\Client\Event\AggregateComputed;
use PhpOpcua\Client\Module\Aggregate\Calculator\AverageCalculator;
use PhpOpcua\Client\Module\Aggregate\Calculator\CountCalculator;
use PhpOpcua\Client\Module\Aggregate\Calculator\InterpolateCalculator;
use PhpOpcua\Client\Module\Aggregate\Calculator\MaximumCalculator;
use PhpOpcua\Client\Module\Aggregate\Calculator\MinimumCalculator;
use PhpOpcua\Client\Module\History\HistoryModule;
use PhpOpcua\Client\Module\ServiceModule;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\NodeId;

/**
 * Computes OPC UA aggregate functions client-side
 * (Interpolate / Min / Max / Average / Count) from a raw DataValue buffer.
 * Exposed via Client::__call() — methods are not in OpcUaClientInterface.
 */
class AggregateModule extends ServiceModule
{
    /** @var array<value-of<AggregateFunction>, AggregateCalculatorInterface> */
    private array $calculators;

    public function __construct()
    {
        $this->calculators = [
            AggregateFunction::Interpolate->value => new InterpolateCalculator(),
            AggregateFunction::Minimum->value => new MinimumCalculator(),
            AggregateFunction::Maximum->value => new MaximumCalculator(),
            AggregateFunction::Average->value => new AverageCalculator(),
            AggregateFunction::Count->value => new CountCalculator(),
        ];
    }

    /**
     * @return array<class-string<ServiceModule>>
     */
    public function requires(): array
    {
        return [HistoryModule::class];
    }

    public function register(): void
    {
        $this->client->registerMethod('aggregate', $this->aggregate(...));
        $this->client->registerMethod('historyAggregate', $this->historyAggregate(...));
    }

    public function setCalculator(AggregateFunction $function, AggregateCalculatorInterface $calculator): void
    {
        $this->calculators[$function->value] = $calculator;
    }

    /**
     * Aggregate an in-memory list of raw DataValues.
     *
     * @param DataValue[] $rawValues Ascending by sourceTimestamp.
     * @param DateTimeImmutable $startTime
     * @param DateTimeImmutable $endTime
     * @param float $processingIntervalMs Window width (0 = single window).
     * @param AggregateFunction $function
     * @param ?AggregateOptions $options
     * @return DataValue[]
     *
     * @throws \InvalidArgumentException
     */
    public function aggregate(
        array $rawValues,
        DateTimeImmutable $startTime,
        DateTimeImmutable $endTime,
        float $processingIntervalMs,
        AggregateFunction $function,
        ?AggregateOptions $options = null,
    ): array {
        return $this->doAggregate(null, $rawValues, $startTime, $endTime, $processingIntervalMs, $function, $options);
    }

    /**
     * Fetch the raw history for {@param $nodeId} and aggregate it.
     *
     * @param NodeId|string $nodeId
     * @param DateTimeImmutable $startTime
     * @param DateTimeImmutable $endTime
     * @param float $processingIntervalMs
     * @param AggregateFunction $function
     * @param ?AggregateOptions $options
     * @return DataValue[]
     *
     * @throws \PhpOpcua\Client\Exception\ConnectionException
     * @throws \PhpOpcua\Client\Exception\ServiceException
     */
    public function historyAggregate(
        NodeId|string $nodeId,
        DateTimeImmutable $startTime,
        DateTimeImmutable $endTime,
        float $processingIntervalMs,
        AggregateFunction $function,
        ?AggregateOptions $options = null,
    ): array {
        $resolved = $nodeId instanceof NodeId
            ? $nodeId
            : (isset($this->kernel) ? $this->kernel->resolveNodeId($nodeId) : null);
        $raw = $this->client->historyReadRaw($nodeId, $startTime, $endTime, 0, true);

        return $this->doAggregate($resolved, $raw, $startTime, $endTime, $processingIntervalMs, $function, $options);
    }

    /**
     * @param DataValue[] $rawValues
     * @return DataValue[]
     */
    private function doAggregate(
        ?NodeId $nodeId,
        array $rawValues,
        DateTimeImmutable $startTime,
        DateTimeImmutable $endTime,
        float $processingIntervalMs,
        AggregateFunction $function,
        ?AggregateOptions $options,
    ): array {
        $options ??= AggregateOptions::default();
        $calculator = $this->calculators[$function->value];

        $intervals = Interval::sliceSequence($startTime, $endTime, $processingIntervalMs, $rawValues);

        $out = [];
        foreach ($intervals as $interval) {
            $out[] = $calculator->compute($interval, $options, $rawValues);
        }

        if (isset($this->kernel)) {
            $client = $this->client;
            $rawCount = count($rawValues);
            $intervalCount = count($intervals);
            $this->kernel->dispatch(fn () => new AggregateComputed(
                $client,
                $function,
                $rawCount,
                $intervalCount,
                $nodeId,
            ));
        }

        return $out;
    }
}
