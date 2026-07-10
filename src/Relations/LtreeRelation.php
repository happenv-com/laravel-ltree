<?php

declare(strict_types=1);

namespace Happenv\Ltree\Relations;

use Happenv\Ltree\Builders\LtreeBuilder;
use Happenv\Ltree\Contracts\Ltreeable;
use Happenv\Ltree\ValueObjects\LtreePath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Base for the FK-less, ltree-path-based relations (descendants, ancestors,
 * siblings, ...): both sides of the relation are the *same* Eloquent model,
 * related purely through the `path` column rather than a foreign key.
 *
 * Eager loading never issues one query per parent: subclasses build a single
 * `path <@/@> ANY(?::ltree[])` constraint over every loaded parent's path
 * (`pathLiteral()`), then `match()` re-associates each result to its
 * parent(s) in pure PHP via `LtreePath::isAncestorOf()`/`isDescendantOf()` —
 * a single result can legitimately attach to multiple parents at once (e.g.
 * a deep node is a descendant of several loaded ancestors).
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends Relation<TRelatedModel, TDeclaringModel, EloquentCollection<int, TRelatedModel>>
 */
abstract class LtreeRelation extends Relation
{
    /**
     * A model with no persisted path (unsaved, or path column null) can have
     * no meaningful ltree-derived relatives — never "everything". Short
     * circuits before hitting the database at all in the lazy-access path.
     *
     * @return EloquentCollection<int, TRelatedModel>
     */
    public function getResults()
    {
        if (! $this->parentPath() instanceof LtreePath) {
            return $this->related->newCollection();
        }

        /** @var EloquentCollection<int, TRelatedModel> $results */
        $results = $this->query->get();

        return $results;
    }

    /**
     * @param  array<int, TDeclaringModel>  $models
     * @param  string  $relation
     * @return array<int, TDeclaringModel>
     */
    public function initRelation(array $models, $relation)
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    /**
     * Correlated existence query for `whereHas('descendants')` and friends:
     * a self-relation, so the related side must be aliased (Eloquent's own
     * `HasOneOrMany`/`BelongsToMany` do the same for self-joins) before
     * comparing `<hash>.path` against the parent query's (unaliased) table.
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

        // This ignore suppresses only the *whitespace-adjacent* concat mutants
        // on this line, which are genuinely equivalent: a double-quoted
        // identifier is a self-delimiting SQL token, so Postgres parses
        // `"path"<@"path"` identically to `"path" <@ "path"` — dropping or
        // shuffling one of the literal ' ' segments around the operator is
        // pure formatting and cannot be killed. The mutants that *reorder the
        // operands* (moving `$parentColumn` ahead of `$selfColumn`) are NOT
        // equivalent — they emit invalid/incorrect SQL — but they are already
        // killed independently by the correlated-existence tests
        // (`whereHas('descendants'|'ancestors')`, filtered and unfiltered), so
        // no coverage is lost by ignoring the mutator wholesale here.
        $sql = $selfColumn.' '.$this->existenceOperator().' '.$parentColumn; // @pest-mutate-ignore: ConcatRemoveRight,ConcatSwitchSides

        if (! $this->includesSelf()) {
            $sql .= ' AND '.$selfColumn.' <> '.$parentColumn;
        }

        return $query->select($columns)->whereRaw($sql);
    }

    /**
     * The ltree containment operator used by the correlated existence query:
     * `<@` (descendant-or-equal) for descendants, `@>` (ancestor-or-equal)
     * for ancestors.
     */
    abstract protected function existenceOperator(): string;

    /**
     * Whether this relation instance includes the node itself (the "AndSelf"
     * variant) — governs both the lazy/eager constraints and whether the
     * existence query excludes self via `<>`.
     */
    abstract protected function includesSelf(): bool;

    /**
     * Builds the single PG array literal (`{a.b,c.d}`) that eager
     * constraints bind once via `?::ltree[]`, from every model's non-null
     * path. Models without a persisted path contribute nothing — they never
     * widen the eager query to "everything".
     *
     * @param  array<int, Model>  $models
     */
    protected function pathLiteral(array $models): string
    {
        $paths = [];

        foreach ($models as $model) {
            /** @var Model&Ltreeable $model */
            $path = $model->path();

            if ($path !== null) {
                $paths[] = $path->toString();
            }
        }

        return '{'.implode(',', $paths).'}';
    }

    protected function parentPath(): ?LtreePath
    {
        /** @var Model&Ltreeable $parent */
        $parent = $this->parent;

        return $parent->path();
    }

    protected function qualifiedPathColumn(string $table): string
    {
        /** @var Model&Ltreeable $related */
        $related = $this->related;

        return '"'.$table.'"."'.$related->getLtreePathColumn().'"';
    }

    /**
     * Narrows `$this->query` (typed `Builder<TRelatedModel>` on the base
     * `Relation` class) to the concrete `LtreeBuilder<TRelatedModel>` that is
     * always the real runtime instance here: these relations are only ever
     * constructed from a query built via `HasLtree::newEloquentBuilder()`,
     * which always returns an `LtreeBuilder`.
     *
     * @return LtreeBuilder<TRelatedModel>
     */
    protected function ltreeQuery(): LtreeBuilder
    {
        /** @var LtreeBuilder<TRelatedModel> $query */
        $query = $this->query;

        return $query;
    }

    protected function pathColumn(): string
    {
        /** @var Model&Ltreeable $related */
        $related = $this->related;

        return '"'.$related->getLtreePathColumn().'"';
    }
}
