<?php

declare(strict_types=1);

use PhpOpcua\Client\Module\Aggregate\AggregateFunction;
use PhpOpcua\Client\Module\Aggregate\AggregateModule;
use PhpOpcua\Client\Module\Aggregate\AggregateOptions;
use PhpOpcua\Client\Module\History\HistoryModule;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\StatusCode;
use PhpOpcua\Client\Types\Variant;

class AggregateFakeClient
{
    /** @var array<string, callable> */
    public array $methodHandlers = [];

    /** @var array<int, array{string, array<mixed>}> */
    public array $calls = [];

    public function registerMethod(string $name, callable $handler): void
    {
        $this->methodHandlers[$name] = $handler;
    }

    public function __call(string $name, array $args): mixed
    {
        $this->calls[] = [$name, $args];
        if (! isset($this->methodHandlers[$name])) {
            throw new BadMethodCallException("not registered: {$name}");
        }

        return ($this->methodHandlers[$name])(...$args);
    }
}

function aggModuleDv(float $tsSeconds, float $value): DataValue
{
    $ts = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6f', $tsSeconds));
    if ($ts === false) {
        throw new RuntimeException('bad timestamp');
    }

    return new DataValue(new Variant(BuiltinType::Double, $value), StatusCode::Good, $ts);
}

describe('AggregateModule', function () {

    it('declares HistoryModule as a hard dependency', function () {
        $module = new AggregateModule();
        expect($module->requires())->toBe([HistoryModule::class]);
    });

    it('registers aggregate and historyAggregate methods', function () {
        $client = new AggregateFakeClient();
        $module = new AggregateModule();
        $module->setClient($client);
        $module->register();

        expect($client->methodHandlers)->toHaveKey('aggregate');
        expect($client->methodHandlers)->toHaveKey('historyAggregate');
    });

    it('dispatches to the right calculator per function', function () {
        $client = new AggregateFakeClient();
        $module = new AggregateModule();
        $module->setClient($client);
        $module->register();

        $start = new DateTimeImmutable('@1000');
        $end = new DateTimeImmutable('@1010');
        $raw = [
            aggModuleDv(1001.0, 1.0),
            aggModuleDv(1003.0, 5.0),
            aggModuleDv(1005.0, 3.0),
            aggModuleDv(1009.0, 9.0),
        ];

        $minResults = $client->aggregate($raw, $start, $end, 10000.0, AggregateFunction::Minimum);
        expect($minResults)->toHaveCount(1);
        expect($minResults[0]->getValue())->toBe(1.0);

        $maxResults = $client->aggregate($raw, $start, $end, 10000.0, AggregateFunction::Maximum);
        expect($maxResults[0]->getValue())->toBe(9.0);

        $avgResults = $client->aggregate($raw, $start, $end, 10000.0, AggregateFunction::Average);
        expect($avgResults[0]->getValue())->toBe(4.5);

        $countResults = $client->aggregate($raw, $start, $end, 10000.0, AggregateFunction::Count);
        expect($countResults[0]->getValue())->toBe(4);
    });

    it('historyAggregate fetches raw then aggregates', function () {
        $client = new AggregateFakeClient();

        // Stub historyReadRaw on the fake client.
        $rawFetched = [aggModuleDv(1000.0, 10.0), aggModuleDv(1002.0, 30.0)];
        $client->registerMethod('historyReadRaw', function (...$_) use ($rawFetched) {
            return $rawFetched;
        });

        $module = new AggregateModule();
        $module->setClient($client);
        $module->register();

        $start = new DateTimeImmutable('@1000');
        $end = new DateTimeImmutable('@1003');

        $result = $client->historyAggregate(
            'ns=2;i=1001',
            $start,
            $end,
            3000.0,
            AggregateFunction::Average,
        );

        expect($result)->toHaveCount(1);
        expect($result[0]->getValue())->toBe(20.0);

        // Verify historyReadRaw was invoked exactly once with our nodeId.
        $historyCalls = array_filter($client->calls, fn ($c) => $c[0] === 'historyReadRaw');
        expect($historyCalls)->toHaveCount(1);
        expect(array_values($historyCalls)[0][1][0])->toBe('ns=2;i=1001');
    });

    it('allows replacing a calculator', function () {
        $client = new AggregateFakeClient();
        $module = new AggregateModule();
        $module->setClient($client);
        $module->register();

        $fakeCalc = new class() implements PhpOpcua\Client\Module\Aggregate\AggregateCalculatorInterface {
            public function compute(
                PhpOpcua\Client\Module\Aggregate\Interval $interval,
                AggregateOptions $options,
                array $rawBuffer,
            ): DataValue {
                return new DataValue(
                    new Variant(BuiltinType::Double, 999.0),
                    StatusCode::Good,
                    $interval->startTime,
                );
            }
        };

        $module->setCalculator(AggregateFunction::Minimum, $fakeCalc);

        $start = new DateTimeImmutable('@1000');
        $end = new DateTimeImmutable('@1010');
        $result = $client->aggregate(
            [aggModuleDv(1001.0, 1.0)],
            $start,
            $end,
            10000.0,
            AggregateFunction::Minimum,
        );

        expect($result[0]->getValue())->toBe(999.0);
    });
});
