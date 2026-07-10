<?php

declare(strict_types=1);

namespace Happenv\Ltree\Relations;

use Happenv\Ltree\Contracts\Ltreeable;
use Happenv\Ltree\ValueObjects\LtreePath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;

/**
 * All strict ancestors of a node (or, with `withSelf: true`, the node and
 * its ancestors — `ancestorsAndSelf`), related purely via the `path`
 * column's ltree `@>` ("ancestor of, or equal to") operator.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends LtreeRelation<TRelatedModel, TDeclaringModel>
 */
final class Ancestors extends LtreeRelation
{
    /**
     * @param  Builder<TRelatedModel>  $query
     * @param  TDeclaringModel  $parent
     */
    public function __construct(Builder $query, Model $parent, private readonly bool $withSelf = false)
    {
        parent::__construct($query, $parent);
    }

    public function addConstraints(): void
    {
        if (! self::$constraints) {
            return;
        }

        $parentPath = $this->parentPath();

        if (! $parentPath instanceof LtreePath) {
            $this->query->whereRaw('1 = 0');

            return;
        }

        if ($this->withSelf) {
            $this->ltreeQuery()->whereRaw($this->pathColumn().' @> ?::ltree', [$parentPath->toString()]);
        } else {
            $this->ltreeQuery()->whereAncestorOf($this->parent);
        }

        $this->ltreeQuery()->orderByDepth('asc');
    }

    /**
     * Deliberately fetches an *inclusive* superset (`@> ANY(...)`, no
     * `<> ALL(...)` self-exclusion): when the eager batch contains nested
     * parents (e.g. loading `ancestors` for both a node and its own child),
     * a SQL-level `<> ALL(literal)` would globally exclude the parent's row
     * the moment its path equals *any* other model's own path in the batch —
     * even though that row still legitimately belongs in the child's bucket.
     * `match()` already excludes exact self per parent via
     * `LtreePath::isAncestorOf()`'s strict depth check, so the
     * self-exclusion only needs to happen once, correctly, in PHP.
     *
     * @param  array<int, TDeclaringModel>  $models
     */
    public function addEagerConstraints(array $models): void
    {
        $literal = $this->pathLiteral($models);

        $this->ltreeQuery()
            ->whereRaw($this->pathColumn().' @> ANY(?::ltree[])', [$literal])
            ->orderByDepth('asc');
    }

    /**
     * A single result can belong to several parents at once — e.g. a root is
     * an ancestor of both its child and its grandchild, so if both are in
     * the loaded set it is bucketed onto both. No per-row query: bucketing
     * is pure-PHP path-prefix comparison (`LtreePath`).
     *
     * @param  array<int, TDeclaringModel>  $models
     * @param  EloquentCollection<int, TRelatedModel>  $results
     * @param  string  $relation
     * @return array<int, TDeclaringModel>
     */
    public function match(array $models, EloquentCollection $results, $relation): array
    {
        foreach ($models as $model) {
            /** @var Model&Ltreeable $model */
            $path = $model->path();

            if ($path === null) {
                continue;
            }

            $matches = $results->filter(function (Model $result) use ($path) {
                /** @var Model&Ltreeable $result */
                $resultPath = $result->path();

                if ($resultPath === null) {
                    return false;
                }

                return $this->withSelf
                    ? $resultPath->equals($path) || $resultPath->isAncestorOf($path)
                    : $resultPath->isAncestorOf($path);
            })->values();

            $model->setRelation($relation, $matches);
        }

        return $models;
    }

    protected function existenceOperator(): string
    {
        return '@>';
    }

    protected function includesSelf(): bool
    {
        return $this->withSelf;
    }
}
