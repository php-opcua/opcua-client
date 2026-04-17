<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Wire;

use BackedEnum;
use DateTimeImmutable;
use PhpOpcua\Client\Exception\EncodingException;
use UnitEnum;

/**
 * Allowlist of typed values that may traverse a JSON IPC boundary. Also the
 * encoder / decoder that wraps {@see WireSerializable}, {@see BackedEnum},
 * and {@see DateTimeImmutable} values with a `__t` discriminator. Unregistered
 * discriminators are rejected at decode time, so only explicitly registered
 * classes can be instantiated.
 */
final class WireTypeRegistry
{
    /** @var array<string, class-string<WireSerializable>> */
    private array $wireClasses = [];

    /** @var array<class-string<WireSerializable>, string> */
    private array $wireClassToId = [];

    /** @var array<string, class-string<UnitEnum>> */
    private array $enumClasses = [];

    /** @var array<class-string<UnitEnum>, string> */
    private array $enumClassToId = [];

    /** Reserved `__t` id for `DateTimeImmutable` (built-in special case). */
    public const DATETIME_TYPE_ID = 'DateTime';

    /**
     * Register a {@see WireSerializable} class.
     *
     * @param class-string<WireSerializable> $class
     * @return void
     * @throws EncodingException If the class is not a {@see WireSerializable} or the id collides.
     */
    public function register(string $class): void
    {
        if (! is_subclass_of($class, WireSerializable::class)) {
            throw new EncodingException(sprintf(
                'Cannot register %s: class does not implement %s.',
                $class,
                WireSerializable::class,
            ));
        }

        $typeId = $class::wireTypeId();
        $this->assertIdAvailable($typeId, $class);

        $this->wireClasses[$typeId] = $class;
        $this->wireClassToId[$class] = $typeId;
    }

    /**
     * Register a {@see UnitEnum} (backed or pure) under a stable wire id.
     *
     * Backed enums round-trip through `::from($scalar)`. Pure enums round-trip
     * through a case-name scan.
     *
     * @param class-string<UnitEnum> $enumClass
     * @param ?string $typeId If null, the unqualified short class name is used.
     * @return void
     * @throws EncodingException If the class is not an enum or the id collides.
     */
    public function registerEnum(string $enumClass, ?string $typeId = null): void
    {
        if (! is_subclass_of($enumClass, UnitEnum::class)) {
            throw new EncodingException(sprintf(
                'Cannot register enum %s: class is not a UnitEnum.',
                $enumClass,
            ));
        }

        $typeId ??= self::shortName($enumClass);
        $this->assertIdAvailable($typeId, $enumClass);

        $this->enumClasses[$typeId] = $enumClass;
        $this->enumClassToId[$enumClass] = $typeId;
    }

    /**
     * @param string $typeId
     * @return bool
     */
    public function has(string $typeId): bool
    {
        return isset($this->wireClasses[$typeId]) || isset($this->enumClasses[$typeId]);
    }

    /**
     * Walk a value and produce its JSON-ready representation, wrapping
     * {@see WireSerializable}, {@see UnitEnum}, and {@see DateTimeImmutable}
     * instances with a `__t` discriminator. Scalars and arrays pass through.
     *
     * @param mixed $value
     * @return mixed
     * @throws EncodingException If the value is an unregistered object / resource.
     */
    public function encode(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->encode($v);
            }

            return $out;
        }

        if ($value instanceof WireSerializable) {
            $class = $value::class;
            if (! isset($this->wireClassToId[$class])) {
                throw new EncodingException(sprintf('Class %s is not registered.', $class));
            }

            $payload = $value->jsonSerialize();
            $encoded = ['__t' => $this->wireClassToId[$class]];
            foreach ($payload as $k => $v) {
                if ($k === '__t') {
                    throw new EncodingException(sprintf('%s emitted reserved key "__t".', $class));
                }
                $encoded[$k] = $this->encode($v);
            }

            return $encoded;
        }

        if ($value instanceof UnitEnum) {
            $class = $value::class;
            if (! isset($this->enumClassToId[$class])) {
                throw new EncodingException(sprintf('Enum class %s is not registered.', $class));
            }

            return [
                '__t' => $this->enumClassToId[$class],
                'v' => $value instanceof BackedEnum ? $value->value : $value->name,
            ];
        }

        if ($value instanceof DateTimeImmutable) {
            return ['__t' => self::DATETIME_TYPE_ID, 'v' => $value->format('Y-m-d\TH:i:s.uP')];
        }

        if (is_object($value)) {
            throw new EncodingException(sprintf('Cannot encode value of class %s.', $value::class));
        }

        throw new EncodingException(sprintf('Cannot encode value of type %s.', get_debug_type($value)));
    }

    /**
     * Walk a decoded-JSON value and reconstruct PHP instances for every typed
     * child. Arrays carrying a `__t` key are materialised via the registered
     * class; unregistered `__t` values cause {@see EncodingException}.
     *
     * @param mixed $value
     * @return mixed
     * @throws EncodingException
     */
    public function decode(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            throw new EncodingException(sprintf(
                'Cannot decode value of type %s: expected JSON-decoded array or scalar.',
                get_debug_type($value),
            ));
        }

        if (! array_key_exists('__t', $value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->decode($v);
            }

            return $out;
        }

        $typeId = $value['__t'];
        if (! is_string($typeId)) {
            throw new EncodingException(sprintf(
                'Invalid wire payload: "__t" must be a string, got %s.',
                get_debug_type($typeId),
            ));
        }

        unset($value['__t']);

        if ($typeId === self::DATETIME_TYPE_ID) {
            if (! isset($value['v']) || ! is_string($value['v'])) {
                throw new EncodingException('Invalid DateTime wire payload: missing string "v" field.');
            }
            $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $value['v']);
            if ($dt === false) {
                throw new EncodingException(sprintf(
                    'Invalid DateTime wire payload: could not parse "%s".',
                    $value['v'],
                ));
            }

            return $dt;
        }

        if (isset($this->enumClasses[$typeId])) {
            $enumClass = $this->enumClasses[$typeId];
            if (! array_key_exists('v', $value)) {
                throw new EncodingException(sprintf(
                    'Invalid enum wire payload for "%s": missing "v" field.',
                    $typeId,
                ));
            }

            if (is_subclass_of($enumClass, BackedEnum::class)) {
                return $enumClass::from($value['v']);
            }

            $caseName = $value['v'];
            if (! is_string($caseName)) {
                throw new EncodingException(sprintf(
                    'Invalid pure enum wire payload for "%s": "v" must be a case name string.',
                    $typeId,
                ));
            }
            foreach ($enumClass::cases() as $case) {
                if ($case->name === $caseName) {
                    return $case;
                }
            }

            throw new EncodingException(sprintf(
                'Invalid pure enum wire payload for "%s": no case named "%s".',
                $typeId,
                $caseName,
            ));
        }

        if (! isset($this->wireClasses[$typeId])) {
            throw new EncodingException(sprintf('Unknown wire type id "%s".', $typeId));
        }

        $decoded = [];
        foreach ($value as $k => $v) {
            $decoded[$k] = $this->decode($v);
        }

        return ($this->wireClasses[$typeId])::fromWireArray($decoded);
    }

    /**
     * Return every registered wire id (both WireSerializable and enum).
     *
     * @return string[]
     */
    public function registeredIds(): array
    {
        return array_values(array_unique(array_merge(
            array_keys($this->wireClasses),
            array_keys($this->enumClasses),
        )));
    }

    /**
     * @param string $typeId
     * @param class-string $class
     * @return void
     * @throws EncodingException
     */
    private function assertIdAvailable(string $typeId, string $class): void
    {
        if ($typeId === '' || $typeId === self::DATETIME_TYPE_ID) {
            throw new EncodingException(sprintf(
                'Cannot register %s under wire id "%s": empty or reserved.',
                $class,
                $typeId,
            ));
        }
        if (isset($this->wireClasses[$typeId]) && $this->wireClasses[$typeId] !== $class) {
            throw new EncodingException(sprintf(
                'Wire id "%s" is already bound to %s; cannot re-bind to %s.',
                $typeId,
                $this->wireClasses[$typeId],
                $class,
            ));
        }
        if (isset($this->enumClasses[$typeId]) && $this->enumClasses[$typeId] !== $class) {
            throw new EncodingException(sprintf(
                'Wire id "%s" is already bound to enum %s; cannot re-bind to %s.',
                $typeId,
                $this->enumClasses[$typeId],
                $class,
            ));
        }
    }

    /**
     * @param class-string $class
     * @return string
     */
    private static function shortName(string $class): string
    {
        $parts = explode('\\', $class);

        return end($parts) ?: $class;
    }
}
