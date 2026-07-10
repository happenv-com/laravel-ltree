<?php

declare(strict_types=1);

namespace Happenv\Ltree\Relations;

use Happenv\Ltree\Contracts\Ltreeable;
use Happenv\Ltree\ValueObjects\LtreePath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The single top-most (depth-1) ancestor of a node, related purely via the
 * `path` column: the row whose `path` equals `subpath($this->path, 0, 1)`.
 * For a root node itself (`nlevel(path) = 1`), that row *is* $this — `root()`
 * is reflexive on roots, mirroring how `ancestorsAndSelf()` includes self.
 *
 * A single-result relation (`?TRelatedModel`, not a collection) — shaped
 * after core's `BelongsTo`/`HasOne`: `getResults()` returns one model or
 * null, `initRelation()` defaults every model to null, `match()` assigns
 * (at most) one related model per declaring model.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends Relation<TRelatedModel, TDeclaringModel, ?TRelatedModel>
 */
final class Root extends Relation
{
    /**
     * A model with no persisted path (unsaved, or path column null) has no
     * meaningful root. Short circuits before hitting the database at all in
     * the lazy-access path.
     *
     * @return TRelatedModel|null
     */
    public function getResults()
    {
        if (! $this->parentPath() instanceof LtreePath) {
            return null;
        }

        /** @var TRelatedModel|null $result */
        $result = $this->query->first();

        return $result;
    }

    public function addConstraints(): void
    {
        if (! self::$constraints) {
            return;
        }

        $rootPath = $this->parentRootPath();

        if ($rootPath === null) {
            $this->query->whereRaw('1 = 0');

            return;
        }

        $this->query->whereRaw($this->pathColumn().' = ?::ltree', [$rootPath]);
    }

    /**
     * A single `path = ANY(?::ltree[])` constraint over every loaded node's
     * *root* path (not its own path) — the candidate set is every distinct
     * root row the batch could resolve to, fetched in one query. `match()`
     * then assigns each declaring model the one candidate whose path equals
     * its own root path.
     *
     * @param  array<int, TDeclaringModel>  $models
     */
    public function addEagerConstraints(array $models): void
    {
        $literal = $this->rootPathLiteral($models);

        if ($literal === '{}') {
            $this->query->whereRaw('1 = 0');

            return;
        }

        $this->query->whereRaw($this->pathColumn().' = ANY(?::ltree[])', [$literal]);
    }

    /**
     * Provably equivalent mutant (both the loop and the setRelation() call):
     * match() below unconditionally revisits *every* model in this same
     * $models array afterward — unlike Descendants/Ancestors/Siblings' loops,
     * Root's match() has no "skip" branch (no null-path/null-parentId
     * `continue`), so every default this sets is guaranteed to be overwritten
     * by match() before the caller ever observes it. Verified by
     * hand-mutating the loop (to `foreach ([] as $model)`) and the
     * setRelation() call (removed) independently, on a batch that includes
     * an unsaved model, and confirming the full suite still passes.
     *
     * @param  array<int, TDeclaringModel>  $models
     * @param  string  $relation
     * @return array<int, TDeclaringModel>
     */
    public function initRelation(array $models, $relation): array
    {
        foreach ($models as $model) { // @pest-mutate-ignore: ForeachEmptyIterable
            $model->setRelation($relation, null); // @pest-mutate-ignore: RemoveMethodCall
        }

        return $models;
    }

    /**
     * @param  array<int, TDeclaringModel>  $models
     * @param  EloquentCollection<int, TRelatedModel>  $results
     * @param  string  $relation
     * @return array<int, TDeclaringModel>
     */
    public function match(array $models, EloquentCollection $results, $relation): array
    {
        $byPath = [];

        foreach ($results as $result) {
            /** @var Model&Ltreeable $result */
            $resultPath = $result->path();

            if ($resultPath !== null) {
                $byPath[$resultPath->toString()] = $result;
            }
        }

        foreach ($models as $model) {
            /** @var Model&Ltreeable $model */
            $path = $model->path();

            $model->setRelation($relation, $path === null ? null : ($byPath[$path->root()->toString()] ?? null));
        }

        return $models;
    }

    /**
     * Correlated existence query for `whereHas('root')`: a self-relation, so
     * the related side must be aliased before comparing `<hash>.path`
     * against the parent query's (unaliased) table's root path. Every node
     * with a persisted path resolves to *some* root row (itself, for a root
     * node), so an unfiltered `whereHas('root')` matches every such node —
     * a filtered `whereHas('root', fn ($q) => ...)` still correlates
     * per-row, restricting to nodes whose actual root satisfies the closure.
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  Builder<TDeclaringModel>  $parentQuery
     * @param  mixed  $columns
     * @return Builder<TRelatedModel>
     */
    #[\Override]
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        $hash = $this->getRelationCountHash();

        $query->from($query->getModel()->getTable().' as '.$hash);
        $query->getModel()->setTable($hash);

        $selfColumn = $this->qualifiedPathColumn($hash);
        $parentColumn = $this->qualifiedPathColumn($parentQuery->getModel()->getTable());

        $sql = $selfColumn.' = subpath('.$parentColumn.', 0, 1)';

        return $query->select($columns)->whereRaw($sql);
    }

    /**
     * Builds the single PG array literal (`{a,b,c}`) that the eager
     * constraint binds once via `?::ltree[]`, from every model's *root*
     * path — deduplicated, since many nodes in a batch typically share the
     * same root.
     *
     * @param  array<int, Model>  $models
     */
    private function rootPathLiteral(array $models): string
    {
        $paths = [];

        foreach ($models as $model) {
            /** @var Model&Ltreeable $model */
            $path = $model->path();

            if ($path !== null) {
                // Provably equivalent mutant: only the array KEY (the root
                // path string) is ever read below, via array_keys() — the
                // value stored here is write-only, used purely as a dedup
                // marker. Verified by hand-mutating `true` to `false` and
                // confirming the full suite still passes.
                $paths[$path->root()->toString()] = true; // @pest-mutate-ignore: TrueToFalse
            }
        }

        return '{'.implode(',', array_keys($paths)).'}';
    }

    private function parentPath(): ?LtreePath
    {
        /** @var Model&Ltreeable $parent */
        $parent = $this->parent;

        return $parent->path();
    }

    private function parentRootPath(): ?string
    {
        return $this->parentPath()?->root()->toString();
    }

    private function pathColumn(): string
    {
        /** @var Model&Ltreeable $related */
        $related = $this->related;

        return '"'.$related->getLtreePathColumn().'"';
    }

    private function qualifiedPathColumn(string $table): string
    {
        /** @var Model&Ltreeable $related */
        $related = $this->related;

        return '"'.$table.'"."'.$related->getLtreePathColumn().'"';
    }
}
