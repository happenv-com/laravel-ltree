<?php

declare(strict_types=1);

namespace Happenv\Ltree\Concerns;

use Happenv\Ltree\Collections\LtreeCollection;
use Happenv\Ltree\Exceptions\LtreeException;
use Happenv\Ltree\ValueObjects\LtreePath;
use Illuminate\Database\Eloquent\Model;
use Stringable;

/**
 * Ltree-aware finders and Laravel's implicit route-model binding, wired to
 * resolve by ltree path (rather than only by primary key) when a route's
 * bound field is the path column — e.g. `Route::get('/c/{category:path}')`.
 *
 * @mixin Model
 */
trait ResolvesLtreeRouteBinding
{
    /**
     * Find a model by its exact, literal ltree path.
     */
    public static function findByPath(string $path): ?static
    {
        $query = static::query();

        /** @var static|null $model */
        $model = $query->where($query->getModel()->getLtreePathColumn(), $path)->first();

        return $model;
    }

    /**
     * Find a model by its exact, literal ltree path, given as an LtreePath.
     */
    public static function findByLtree(LtreePath $path): ?static
    {
        return static::findByPath((string) $path);
    }

    /**
     * Find every strict descendant of the given node in one query.
     *
     * @return LtreeCollection<int, static>
     */
    public static function findDescendantsOf(Model|LtreePath|string $node): LtreeCollection
    {
        /** @var LtreeCollection<int, static> $result */
        $result = static::query()->whereDescendantOf($node)->get();

        return $result;
    }

    /**
     * Find every strict ancestor of the given node in one query.
     *
     * @return LtreeCollection<int, static>
     */
    public static function findAncestorsOf(Model|LtreePath|string $node): LtreeCollection
    {
        /** @var LtreeCollection<int, static> $result */
        $result = static::query()->whereAncestorOf($node)->get();

        return $result;
    }

    /**
     * Retrieve the model for a bound value.
     *
     * Overrides {@see Model::resolveRouteBinding()}: when the route's bound
     * field is the configured path column (or the literal `'path'`), resolve
     * by ltree path instead of the default route-key lookup — enabling
     * `Route::get('/categories/{category:path}', ...)`.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     * @return static|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field === $this->getLtreePathColumn() || $field === 'path') {
            return $this->resolveRouteBindingByPath($this->ltreeRouteValueToString($value));
        }

        /** @var static|null $model */
        $model = parent::resolveRouteBinding($value, $field);

        return $model;
    }

    /**
     * Explicit path-based route binding resolution.
     */
    public function resolveRouteBindingByPath(string $value): ?static
    {
        return static::findByPath($value);
    }

    /**
     * Explicit key-based route binding resolution.
     */
    public function resolveRouteBindingById(mixed $value): ?static
    {
        /** @var static|null $model */
        $model = static::query()->find($value);

        return $model;
    }

    /**
     * Retrieve the child model for a bound value.
     *
     * Overrides {@see Model::resolveChildRouteBinding()} for nested resources
     * (e.g. `Route::get('/categories/{category}/children/{child:path}')`):
     * honours path fields the same way {@see resolveRouteBinding()} does,
     * otherwise defers to the default relationship-based resolution.
     *
     * @param  string  $childType
     * @param  mixed  $value
     * @param  string|null  $field
     * @return Model|null
     */
    public function resolveChildRouteBinding($childType, $value, $field)
    {
        if ($field === $this->getLtreePathColumn() || $field === 'path') {
            // Resolve against the actual configured path column, never the raw
            // route field (which is the literal 'path' for `{child:path}` even
            // when the column has been renamed via config).
            /** @var Model|null $model */
            $model = $this->resolveChildRouteBindingQuery($childType, $this->ltreeRouteValueToString($value), $this->getLtreePathColumn())->first();

            return $model;
        }

        return parent::resolveChildRouteBinding($childType, $value, $field);
    }

    /**
     * Route parameter values are `mixed` in Eloquent's own signatures (they
     * are ordinarily plain strings straight off the URI, but the contract is
     * untyped), while path lookups need a definite string. Scalars and
     * Stringable values convert unambiguously; anything else (array, non-
     * Stringable object, ...) cannot represent an ltree path.
     */
    private function ltreeRouteValueToString(mixed $value): string
    {
        if ($value instanceof Stringable) {
            return $value->__toString();
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        throw new LtreeException(sprintf(
            'Cannot resolve an ltree route binding by path from a %s value.',
            get_debug_type($value),
        ));
    }
}
