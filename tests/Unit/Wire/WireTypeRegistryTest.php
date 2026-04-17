<?php

declare(strict_types=1);

use PhpOpcua\Client\Exception\EncodingException;
use PhpOpcua\Client\Wire\WireSerializable;
use PhpOpcua\Client\Wire\WireTypeRegistry;

// ─── Fixtures ─────────────────────────────────────────────────────────────

final class WireRegistryTestPoint implements WireSerializable
{
    public function __construct(
        public readonly int $x,
        public readonly int $y,
    ) {
    }

    public function jsonSerialize(): array
    {
        return ['x' => $this->x, 'y' => $this->y];
    }

    public static function fromWireArray(array $data): static
    {
        return new self($data['x'], $data['y']);
    }

    public static function wireTypeId(): string
    {
        return 'WireRegistryTestPoint';
    }
}

final class WireRegistryTestLine implements WireSerializable
{
    public function __construct(
        public readonly WireRegistryTestPoint $from,
        public readonly WireRegistryTestPoint $to,
        public readonly ?string $label,
    ) {
    }

    public function jsonSerialize(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'label' => $this->label];
    }

    public static function fromWireArray(array $data): static
    {
        return new self($data['from'], $data['to'], $data['label']);
    }

    public static function wireTypeId(): string
    {
        return 'WireRegistryTestLine';
    }
}

final class WireRegistryTestBadEmittedT implements WireSerializable
{
    public function jsonSerialize(): array
    {
        return ['__t' => 'injected'];
    }

    public static function fromWireArray(array $data): static
    {
        return new self();
    }

    public static function wireTypeId(): string
    {
        return 'WireRegistryTestBadEmittedT';
    }
}

enum WireRegistryTestColor: string
{
    case Red = 'r';
    case Green = 'g';
    case Blue = 'b';
}

enum WireRegistryTestLevel: int
{
    case Low = 0;
    case High = 1;
}

enum WireRegistryTestStatus
{
    case Active;
    case Paused;
    case Terminated;
}

// ─── Tests ─────────────────────────────────────────────────────────────────

describe('WireTypeRegistry: registration', function () {

    it('registers a WireSerializable class', function () {
        $registry = new WireTypeRegistry();
        $registry->register(WireRegistryTestPoint::class);

        expect($registry->has('WireRegistryTestPoint'))->toBeTrue();
        expect($registry->registeredIds())->toContain('WireRegistryTestPoint');
    });

    it('registers a BackedEnum with an explicit id', function () {
        $registry = new WireTypeRegistry();
        $registry->registerEnum(WireRegistryTestColor::class, 'Color');

        expect($registry->has('Color'))->toBeTrue();
        expect($registry->has('WireRegistryTestColor'))->toBeFalse();
    });

    it('registers a BackedEnum with the default short name', function () {
        $registry = new WireTypeRegistry();
        $registry->registerEnum(WireRegistryTestColor::class);

        expect($registry->has('WireRegistryTestColor'))->toBeTrue();
    });

    it('rejects a class that does not implement WireSerializable', function () {
        $registry = new WireTypeRegistry();
        expect(fn () => $registry->register(stdClass::class))
            ->toThrow(EncodingException::class, 'does not implement');
    });

    it('rejects an enum registration for a non-BackedEnum', function () {
        $registry = new WireTypeRegistry();
        expect(fn () => $registry->registerEnum(stdClass::class))
            ->toThrow(EncodingException::class, 'not a UnitEnum');
    });

    it('rejects colliding wire id', function () {
        $registry = new WireTypeRegistry();
        $registry->registerEnum(WireRegistryTestColor::class, 'Shared');
        expect(fn () => $registry->registerEnum(WireRegistryTestLevel::class, 'Shared'))
            ->toThrow(EncodingException::class, 'already bound');
    });

    it('rejects the reserved DateTime id', function () {
        $registry = new WireTypeRegistry();
        expect(fn () => $registry->registerEnum(WireRegistryTestColor::class, WireTypeRegistry::DATETIME_TYPE_ID))
            ->toThrow(EncodingException::class, 'reserved');
    });

    it('allows re-registering the same class with the same id (idempotent)', function () {
        $registry = new WireTypeRegistry();
        $registry->register(WireRegistryTestPoint::class);
        $registry->register(WireRegistryTestPoint::class);
        expect($registry->has('WireRegistryTestPoint'))->toBeTrue();
    });
});

describe('WireTypeRegistry: encode', function () {

    it('passes scalars through unchanged', function () {
        $r = new WireTypeRegistry();
        expect($r->encode(null))->toBeNull();
        expect($r->encode(true))->toBeTrue();
        expect($r->encode(42))->toBe(42);
        expect($r->encode(3.14))->toBe(3.14);
        expect($r->encode('hello'))->toBe('hello');
    });

    it('walks arrays recursively preserving structure', function () {
        $r = new WireTypeRegistry();
        expect($r->encode([1, 2, 3]))->toBe([1, 2, 3]);
        expect($r->encode(['a' => 1, 'b' => 2]))->toBe(['a' => 1, 'b' => 2]);
        expect($r->encode([[1, 2], [3, 4]]))->toBe([[1, 2], [3, 4]]);
    });

    it('wraps a WireSerializable with its __t discriminator', function () {
        $r = new WireTypeRegistry();
        $r->register(WireRegistryTestPoint::class);

        $encoded = $r->encode(new WireRegistryTestPoint(1, 2));
        expect($encoded)->toBe(['__t' => 'WireRegistryTestPoint', 'x' => 1, 'y' => 2]);
    });

    it('wraps nested WireSerializable recursively', function () {
        $r = new WireTypeRegistry();
        $r->register(WireRegistryTestPoint::class);
        $r->register(WireRegistryTestLine::class);

        $line = new WireRegistryTestLine(
            new WireRegistryTestPoint(1, 2),
            new WireRegistryTestPoint(3, 4),
            'diagonal',
        );
        $encoded = $r->encode($line);

        expect($encoded)->toBe([
            '__t' => 'WireRegistryTestLine',
            'from' => ['__t' => 'WireRegistryTestPoint', 'x' => 1, 'y' => 2],
            'to' => ['__t' => 'WireRegistryTestPoint', 'x' => 3, 'y' => 4],
            'label' => 'diagonal',
        ]);
    });

    it('wraps a BackedEnum as {__t, v} using its backing scalar', function () {
        $r = new WireTypeRegistry();
        $r->registerEnum(WireRegistryTestColor::class);

        expect($r->encode(WireRegistryTestColor::Green))
            ->toBe(['__t' => 'WireRegistryTestColor', 'v' => 'g']);
    });

    it('wraps a pure (non-backed) UnitEnum as {__t, v} using its case name', function () {
        $r = new WireTypeRegistry();
        $r->registerEnum(WireRegistryTestStatus::class);

        expect($r->encode(WireRegistryTestStatus::Paused))
            ->toBe(['__t' => 'WireRegistryTestStatus', 'v' => 'Paused']);
    });

    it('wraps a DateTimeImmutable as {__t: DateTime, v: ISO8601}', function () {
        $r = new WireTypeRegistry();
        $dt = new DateTimeImmutable('2026-04-17T10:30:00.123456+00:00');

        $encoded = $r->encode($dt);
        expect($encoded['__t'])->toBe('DateTime');
        expect($encoded['v'])->toBe('2026-04-17T10:30:00.123456+00:00');
    });

    it('throws when encoding an unregistered WireSerializable', function () {
        $r = new WireTypeRegistry();
        expect(fn () => $r->encode(new WireRegistryTestPoint(1, 2)))
            ->toThrow(EncodingException::class, 'not registered');
    });

    it('throws when encoding an unregistered BackedEnum', function () {
        $r = new WireTypeRegistry();
        expect(fn () => $r->encode(WireRegistryTestColor::Red))
            ->toThrow(EncodingException::class, 'not registered');
    });

    it('throws when a WireSerializable emits the reserved __t key itself', function () {
        $r = new WireTypeRegistry();
        $r->register(WireRegistryTestBadEmittedT::class);
        expect(fn () => $r->encode(new WireRegistryTestBadEmittedT()))
            ->toThrow(EncodingException::class, 'reserved key "__t"');
    });

    it('throws for arbitrary objects that are not WireSerializable / BackedEnum / DateTime', function () {
        $r = new WireTypeRegistry();
        expect(fn () => $r->encode(new stdClass()))
            ->toThrow(EncodingException::class, 'Cannot encode value of class');
    });
});

describe('WireTypeRegistry: decode', function () {

    it('passes scalars through unchanged', function () {
        $r = new WireTypeRegistry();
        expect($r->decode(null))->toBeNull();
        expect($r->decode(true))->toBeTrue();
        expect($r->decode(42))->toBe(42);
        expect($r->decode('hello'))->toBe('hello');
    });

    it('reconstructs a WireSerializable from its wire payload', function () {
        $r = new WireTypeRegistry();
        $r->register(WireRegistryTestPoint::class);

        $decoded = $r->decode(['__t' => 'WireRegistryTestPoint', 'x' => 7, 'y' => 11]);
        expect($decoded)->toBeInstanceOf(WireRegistryTestPoint::class);
        expect($decoded->x)->toBe(7);
        expect($decoded->y)->toBe(11);
    });

    it('reconstructs nested WireSerializable recursively', function () {
        $r = new WireTypeRegistry();
        $r->register(WireRegistryTestPoint::class);
        $r->register(WireRegistryTestLine::class);

        $wire = [
            '__t' => 'WireRegistryTestLine',
            'from' => ['__t' => 'WireRegistryTestPoint', 'x' => 1, 'y' => 2],
            'to' => ['__t' => 'WireRegistryTestPoint', 'x' => 3, 'y' => 4],
            'label' => null,
        ];
        $line = $r->decode($wire);

        expect($line)->toBeInstanceOf(WireRegistryTestLine::class);
        expect($line->from)->toBeInstanceOf(WireRegistryTestPoint::class);
        expect($line->from->x)->toBe(1);
        expect($line->to->y)->toBe(4);
        expect($line->label)->toBeNull();
    });

    it('reconstructs a BackedEnum via ::from()', function () {
        $r = new WireTypeRegistry();
        $r->registerEnum(WireRegistryTestColor::class);

        $decoded = $r->decode(['__t' => 'WireRegistryTestColor', 'v' => 'b']);
        expect($decoded)->toBe(WireRegistryTestColor::Blue);
    });

    it('reconstructs a pure UnitEnum by case-name scan', function () {
        $r = new WireTypeRegistry();
        $r->registerEnum(WireRegistryTestStatus::class);

        $decoded = $r->decode(['__t' => 'WireRegistryTestStatus', 'v' => 'Terminated']);
        expect($decoded)->toBe(WireRegistryTestStatus::Terminated);
    });

    it('rejects a pure UnitEnum payload with a non-string v', function () {
        $r = new WireTypeRegistry();
        $r->registerEnum(WireRegistryTestStatus::class);
        expect(fn () => $r->decode(['__t' => 'WireRegistryTestStatus', 'v' => 42]))
            ->toThrow(EncodingException::class, 'must be a case name string');
    });

    it('rejects a pure UnitEnum payload with an unknown case name', function () {
        $r = new WireTypeRegistry();
        $r->registerEnum(WireRegistryTestStatus::class);
        expect(fn () => $r->decode(['__t' => 'WireRegistryTestStatus', 'v' => 'Frobnicated']))
            ->toThrow(EncodingException::class, 'no case named "Frobnicated"');
    });

    it('reconstructs a DateTimeImmutable', function () {
        $r = new WireTypeRegistry();
        $decoded = $r->decode(['__t' => 'DateTime', 'v' => '2026-04-17T10:30:00.123456+00:00']);
        expect($decoded)->toBeInstanceOf(DateTimeImmutable::class);
        expect($decoded->format('Y-m-d\TH:i:s.uP'))->toBe('2026-04-17T10:30:00.123456+00:00');
    });

    it('rejects an unregistered __t', function () {
        $r = new WireTypeRegistry();
        expect(fn () => $r->decode(['__t' => 'NotRegistered', 'x' => 1]))
            ->toThrow(EncodingException::class, 'Unknown wire type id');
    });

    it('rejects a non-string __t', function () {
        $r = new WireTypeRegistry();
        expect(fn () => $r->decode(['__t' => 123]))
            ->toThrow(EncodingException::class, 'must be a string');
    });

    it('rejects an enum payload missing the v field', function () {
        $r = new WireTypeRegistry();
        $r->registerEnum(WireRegistryTestColor::class);
        expect(fn () => $r->decode(['__t' => 'WireRegistryTestColor']))
            ->toThrow(EncodingException::class, 'missing "v" field');
    });

    it('rejects a malformed DateTime payload', function () {
        $r = new WireTypeRegistry();
        expect(fn () => $r->decode(['__t' => 'DateTime', 'v' => 'not-a-date']))
            ->toThrow(EncodingException::class, 'could not parse');
    });

    it('walks plain arrays without a __t unchanged', function () {
        $r = new WireTypeRegistry();
        expect($r->decode(['a' => 1, 'b' => [2, 3]]))->toBe(['a' => 1, 'b' => [2, 3]]);
    });
});

describe('WireTypeRegistry: round-trip', function () {

    it('round-trips scalars, arrays, WireSerializable, enum, and DateTime via JSON', function () {
        $r = new WireTypeRegistry();
        $r->register(WireRegistryTestPoint::class);
        $r->register(WireRegistryTestLine::class);
        $r->registerEnum(WireRegistryTestColor::class);

        $original = [
            'scalar' => 42,
            'list' => [1, 2, 3],
            'obj' => new WireRegistryTestLine(
                new WireRegistryTestPoint(1, 2),
                new WireRegistryTestPoint(3, 4),
                'lbl',
            ),
            'enum' => WireRegistryTestColor::Red,
            'when' => new DateTimeImmutable('2026-04-17T10:30:00.123456+00:00'),
            'nullable' => null,
        ];

        $encoded = $r->encode($original);
        $json = json_encode($encoded, JSON_THROW_ON_ERROR);
        $decoded = $r->decode(json_decode($json, true, flags: JSON_THROW_ON_ERROR));

        expect($decoded['scalar'])->toBe(42);
        expect($decoded['list'])->toBe([1, 2, 3]);
        expect($decoded['obj'])->toBeInstanceOf(WireRegistryTestLine::class);
        expect($decoded['obj']->from->x)->toBe(1);
        expect($decoded['obj']->to->y)->toBe(4);
        expect($decoded['obj']->label)->toBe('lbl');
        expect($decoded['enum'])->toBe(WireRegistryTestColor::Red);
        expect($decoded['when'])->toBeInstanceOf(DateTimeImmutable::class);
        expect($decoded['when']->format('Y-m-d\TH:i:s.uP'))->toBe('2026-04-17T10:30:00.123456+00:00');
        expect($decoded['nullable'])->toBeNull();
    });
});
