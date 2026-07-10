<?php

declare(strict_types=1);

namespace Happenv\Ltree\Casts;

use Happenv\Ltree\ValueObjects\LtreePath;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use TypeError;

/**
 * @implements CastsAttributes<LtreePath|null, LtreePath|string|iterable<int|string, string|int>|null>
 */
final class LtreeCast implements CastsAttributes, SerializesCastableAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?LtreePath
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'The [%s] attribute must be a string ltree value from storage; %s given.',
                $key,
                get_debug_type($value),
            ));
        }

        return new LtreePath($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return self::toLtreePath($value)->toString();
        } catch (TypeError $e) {
            throw new InvalidArgumentException(sprintf(
                'The [%s] attribute must be an LtreePath, string, or iterable of labels; %s given.',
                $key,
                get_debug_type($value),
            ), $e->getCode(), previous: $e);
        }
    }

    /**
     * Native param type is the guard: PHP throws a TypeError for anything
     * outside LtreePath|string|iterable, which set() above translates into
     * the package's InvalidArgumentException contract.
     *
     * @param  string|iterable<int|string, string|int>|LtreePath  $value
     */
    private static function toLtreePath(string|iterable|LtreePath $value): LtreePath
    {
        return $value instanceof LtreePath ? $value : new LtreePath($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof LtreePath) {
            return $value->toString();
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'The [%s] attribute must serialize from an LtreePath or string; %s given.',
                $key,
                get_debug_type($value),
            ));
        }

        return $value;
    }
}
