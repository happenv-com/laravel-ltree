<?php

declare(strict_types=1);

namespace Happenv\Ltree\Collections;

use Happenv\Ltree\Builders\LtreeBuilder;
use Happenv\Ltree\Contracts\Ltreeable;
use Happenv\Ltree\ValueObjects\LtreePath;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * @template TKey of array-key
 * @template TModel of Model
 *
 * @extends Collection<TKey, TModel>
 *
 * @phpstan-consistent-constructor
 */
class LtreeCollection extends Collection
{
    public function sortTree(): static
    {
        /** @var static $sorted */
        $sorted = $this->sortBy(fn (Model $m): string => (string) $this->pathOf($m), SORT_STRING)->values();

        return $sorted;
    }

    /**
     * Nest members by path and return the roots of this set (members whose
     * parent path is absent from the set), each with its `children` relation
     * populated with its (also nested) members from within the set.
     */
    public function toTree(): static
    {
        $byPath = [];

        foreach ($this as $model) {
            $byPath[(string) $this->pathOf($model)] = $model;
        }

        foreach ($this as $model) {
            $model->setRelation('children', new static);
        }

        /** @var static $roots */
        $roots = new static;

        foreach ($this->sortTree() as $model) {
            $parentPath = $this->pathOf($model)?->parent()?->toString();

            if ($parentPath !== null && isset($byPath[$parentPath])) {
                /** @var static $children */
                $children = $byPath[$parentPath]->getRelation('children');
                $children->push($model);
            } else {
                $roots->push($model);
            }
        }

        return $roots;
    }

    /**
     * Inverse of toTree(): walk the `children` relation recursively into one
     * flat collection. Also correct on a naturally flat set (no `children`
     * relation populated by toTree()): every member is pushed once and no
     * recursion happens, so the result is effectively a clone.
     *
     * @param  int|float  $depth  unused; kept to preserve Collection::flatten()'s signature
     */
    #[\Override]
    public function flatten($depth = INF): static
    {
        /** @var static $flat */
        $flat = new static;

        $this->flattenInto($this, $flat);

        return $flat;
    }

    /**
     * @param  iterable<int, TModel>  $nodes
     * @param  static  $flat
     */
    private function flattenInto(iterable $nodes, self $flat): void
    {
        foreach ($nodes as $node) {
            $flat->push($node);

            // Recurse ONLY into an already-loaded `children` relation (as set by
            // toTree()). Never use getRelationValue()/getRelation() here — that
            // would lazy-load the real `children` HasMany from the database,
            // turning flatten() into an N-query walk that pulls in non-members;
            // on a naturally-flat set (no loaded children) flatten() must be a
            // pure in-memory clone.
            $children = $node->relationLoaded('children') ? $node->getRelation('children') : null;

            if ($children instanceof Collection) {
                // The `children` relation is always self-referential (same
                // concrete model as $node), so this narrows to the class's own
                // TModel — structurally guaranteed by toTree(), not provable
                // generically.
                /** @var iterable<int, TModel> $children */
                $this->flattenInto($children, $flat);
            }
        }
    }

    public function roots(): static
    {
        $paths = $this->map(fn (Model $m): string => (string) $this->pathOf($m))->all();

        /** @var static $result */
        $result = $this->filter(function (Model $m) use ($paths): bool {
            $parent = $this->pathOf($m)?->parent()?->toString();

            return $parent === null || ! in_array($parent, $paths, true);
        })->values();

        return $result;
    }

    public function leaves(): static
    {
        $parentPaths = [];

        foreach ($this as $m) {
            $p = $this->pathOf($m)?->parent()?->toString();

            if ($p !== null) {
                // Equivalent mutant (TrueToFalse): $parentPaths is used purely as
                // a presence set — only isset($parentPaths[...]) below ever reads
                // it — so any non-null value here (true or false) behaves
                // identically; `false` is still `isset()`.
                $parentPaths[$p] = true; // @pest-mutate-ignore: TrueToFalse
            }
        }

        /** @var static $result */
        $result = $this->reject(fn (Model $m): bool => isset($parentPaths[(string) $this->pathOf($m)]))->values();

        return $result;
    }

    /**
     * One query: strict descendants of every member, across the whole table,
     * excluding the members themselves.
     */
    public function descendants(): static
    {
        $model = $this->first();

        if ($model === null) {
            /** @var static $empty */
            $empty = new static;

            return $empty;
        }

        /** @var LtreeBuilder<TModel> $query */
        $query = $model->newQuery();

        /** @var static $result */
        $result = $query->whereDescendantOfAny($this->all())->whereKeyNot($this->modelKeys())->get();

        return $result;
    }

    /**
     * One query: strict ancestors of every member, across the whole table,
     * excluding the members themselves.
     */
    public function ancestors(): static
    {
        $model = $this->first();

        if ($model === null) {
            /** @var static $empty */
            $empty = new static;

            return $empty;
        }

        /** @var LtreeBuilder<TModel> $query */
        $query = $model->newQuery();

        /** @var static $result */
        $result = $query->whereAncestorOfAny($this->all())->whereKeyNot($this->modelKeys())->get();

        return $result;
    }

    private function pathOf(Model $model): ?LtreePath
    {
        /** @var Model&Ltreeable $model */
        return $model->path();
    }
}
