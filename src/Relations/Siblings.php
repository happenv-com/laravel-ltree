<?php

declare(strict_types=1);

namespace Happenv\Ltree\Relations;

use Happenv\Ltree\Contracts\Ltreeable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Concerns\InteractsWithDictionary;
use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;

/**
 * All other rows sharing $this's maintained `parent_id` (siblings), excluding
 * $this itself.
 *
 * Deliberately keyed on the maintained `parent_id` foreign key rather than
 * the `path` column: cheaper (plain integer equality, no ltree function
 * calls) and unambiguous, since `parent_id` is authoritative for "same
 * parent" and is already kept in sync by `LtreeObserver` alongside `path`.
 *
 * A node whose `parent_id` is null (a root) has no siblings by definition
 * here — roots are not considered siblings of one another. This
 * intentionally diverges from `LtreeBuilder::whereSiblingOf()`, which is
 * path-based and would treat any two roots as siblings of each other (both
 * their `subpath(path, 0, nlevel-1)` reduce to the same empty path).
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends Relation<TRelatedModel, TDeclaringModel, EloquentCollection<int, TRelatedModel>>
 */
final class Siblings extends Relation
{
    use InteractsWithDictionary;

    /**
     * A node with no maintained `parent_id` (unsaved, or a root) has no
     * siblings — never "everything". Short circuits before hitting the
     * database at all in the lazy-access path.
     *
     * @return EloquentCollection<int, TRelatedModel>
     */
    public function getResults()
    {
        if ($this->parentId() === null) {
            return $this->related->newCollection();
        }

        /** @var EloquentCollection<int, TRelatedModel> $results */
        $results = $this->query->get();

        return $results;
    }

    public function addConstraints(): void
    {
        if (! self::$constraints) {
            return;
        }

        $parentId = $this->parentId();

        if ($parentId === null) {
            $this->query->whereRaw('1 = 0');

            return;
        }

        $this->query
            ->where($this->parentColumnName(), $parentId)
            ->whereKeyNot($this->parent->getKey());
    }

    /**
     * Fetches an *inclusive* superset (`parent_id IN (...)`, no per-row
     * self-exclusion): the batch may contain several models that share a
     * parent, so a self-row belongs in every one of its siblings' buckets
     * except its own — `match()` excludes each model's own row individually
     * via `Model::is()`, exactly once per model, in pure PHP.
     *
     * @param  array<int, TDeclaringModel>  $models
     */
    public function addEagerConstraints(array $models): void
    {
        $parentIds = [];

        foreach ($models as $model) {
            $parentId = $this->getDictionaryKey($model->getAttribute($this->parentColumnName()));

            if ($parentId !== null) {
                $parentIds[$parentId] = $parentId;
            }
        }

        if ($parentIds === []) {
            $this->query->whereRaw('1 = 0');

            return;
        }

        // Provably equivalent mutant: $parentIds is built above as
        // $parentIds[$parentId] = $parentId (key === value by construction),
        // and whereIn()'s query grammar iterates the array's values for its
        // bindings regardless of its keys — array_values() only normalizes
        // the keys to 0..n-1, which changes nothing observable about the
        // generated SQL or bindings. Verified by hand-mutating this call to
        // pass $parentIds directly and confirming the full suite still
        // passes.
        $this->query->whereIn($this->parentColumnName(), array_values($parentIds)); // @pest-mutate-ignore: UnwrapArrayValues
    }

    /**
     * @param  array<int, TDeclaringModel>  $models
     * @param  string  $relation
     * @return array<int, TDeclaringModel>
     */
    public function initRelation(array $models, $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
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
        /** @var array<int|string, list<TRelatedModel>> $byParent */
        $byParent = [];

        foreach ($results as $result) {
            $parentId = $this->getDictionaryKey($result->getAttribute($this->parentColumnName()));

            if ($parentId === null) {
                continue;
            }

            $byParent[$parentId][] = $result;
        }

        foreach ($models as $model) {
            $parentId = $this->getDictionaryKey($model->getAttribute($this->parentColumnName()));

            if ($parentId === null) {
                continue;
            }

            /** @var EloquentCollection<int, TRelatedModel> $bucket */
            $bucket = $this->related->newCollection($byParent[$parentId] ?? []);

            $matches = $bucket->reject(fn (Model $result) => $result->is($model))->values();

            $model->setRelation($relation, $matches);
        }

        return $models;
    }

    /**
     * Correlated existence query for `whereHas('siblings')`: a self-relation,
     * so the related side must be aliased before comparing `<hash>.parent_id`
     * against the parent query's (unaliased) table — mirrors
     * `LtreeRelation::getRelationExistenceQuery()`'s approach for the
     * path-containment relations, adapted for `parent_id` equality. A null
     * `parent_id` on the parent side naturally yields no matches: SQL's
     * `NULL = NULL` is unknown, never true.
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

        $selfParent = $this->qualifiedParentColumn($hash);
        $selfKey = $this->qualifiedKeyColumn($hash);
        $parentParent = $this->qualifiedParentColumn($parentQuery->getModel()->getTable());
        $parentKey = $this->qualifiedKeyColumn($parentQuery->getModel()->getTable());

        $sql = $selfParent.' = '.$parentParent.' AND '.$selfKey.' <> '.$parentKey;

        return $query->select($columns)->whereRaw($sql);
    }

    private function parentId(): int|string|null
    {
        /** @var int|string|null $id */
        $id = $this->parent->getAttribute($this->parentColumnName());

        return $id;
    }

    private function parentColumnName(): string
    {
        /** @var Model&Ltreeable $related */
        $related = $this->related;

        $column = $related->getLtreeParentColumn();

        if ($column === null) {
            throw new LogicException('Siblings relation requires a configured ltree parent column.');
        }

        return $column;
    }

    private function qualifiedParentColumn(string $table): string
    {
        return '"'.$table.'"."'.$this->parentColumnName().'"';
    }

    private function qualifiedKeyColumn(string $table): string
    {
        /** @var Model&Ltreeable $related */
        $related = $this->related;

        return '"'.$table.'"."'.$related->getKeyName().'"';
    }
}
