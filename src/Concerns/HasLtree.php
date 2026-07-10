<?php

declare(strict_types=1);

namespace Happenv\Ltree\Concerns;

use Happenv\Ltree\Builders\LtreeBuilder;
use Happenv\Ltree\Casts\LtreeCast;
use Happenv\Ltree\Collections\LtreeCollection;
use Happenv\Ltree\Contracts\Normalizer;
use Happenv\Ltree\Normalization\DefaultNormalizer;
use Happenv\Ltree\Observers\LtreeObserver;
use Happenv\Ltree\Relations\Ancestors;
use Happenv\Ltree\Relations\Descendants;
use Happenv\Ltree\Relations\Root;
use Happenv\Ltree\Relations\Siblings;
use Happenv\Ltree\ValueObjects\LtreePath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * @mixin Model
 */
trait HasLtree
{
    use PerformsLtreeOperations;
    use ResolvesLtreeRouteBinding;

    private ?Model $ltreeTransientParent = null;

    public static function bootHasLtree(): void
    {
        // Register the observer's events directly instead of static::observe():
        // observe() does `new static`, and bootHasLtree() runs while the model
        // is booting, so on Laravel 13 that re-enters bootIfNotBooted() and
        // throws "may not be called ... while it is being booted". Registering
        // the two events with closures is boot-safe on Laravel 12 and 13 and
        // covers the whole observer (LtreeObserver only handles creating/created).
        $observer = new LtreeObserver;
        static::creating(static function (Model $model) use ($observer): void {
            $observer->creating($model);
        });
        static::created(static function (Model $model) use ($observer): void {
            $observer->created($model);
        });
    }

    public function initializeHasLtree(): void
    {
        // Register the path cast by writing the casts array directly rather than
        // via mergeCasts(): initializeHasLtree() runs while the model is booting,
        // and on Laravel 13 mergeCasts() resolves the cast set (which boots the
        // model) and throws "may not be called ... while it is being booted".
        // A plain array write is boot-safe and behaves identically on 12 and 13.
        $this->casts[$this->getLtreePathColumn()] = LtreeCast::class;
    }

    /**
     * @param  QueryBuilder  $query
     * @return LtreeBuilder<Model>
     */
    public function newEloquentBuilder($query): LtreeBuilder
    {
        return new LtreeBuilder($query);
    }

    /**
     * @param  array<int, static>  $models
     * @return LtreeCollection<int, static>
     */
    public function newCollection(array $models = []): LtreeCollection
    {
        return new LtreeCollection($models);
    }

    /**
     * @return LtreeBuilder<static>
     */
    public static function roots(): LtreeBuilder
    {
        return static::query()->whereRoot();
    }

    /**
     * @return LtreeBuilder<static>
     */
    public static function leaves(): LtreeBuilder
    {
        return static::query()->whereLeaf();
    }

    /**
     * @return LtreeBuilder<static>
     */
    public static function ancestorsOf(Model|LtreePath|string $node): LtreeBuilder
    {
        return static::query()->whereAncestorOf($node);
    }

    /**
     * @return LtreeBuilder<static>
     */
    public static function descendantsOf(Model|LtreePath|string $node): LtreeBuilder
    {
        return static::query()->whereDescendantOf($node);
    }

    /**
     * @return LtreeBuilder<static>
     */
    public static function childrenOf(Model|LtreePath|string $node): LtreeBuilder
    {
        return static::query()->whereChildOf($node);
    }

    /**
     * @return LtreeBuilder<static>
     */
    public static function siblingsOf(Model|LtreePath|string $node): LtreeBuilder
    {
        return static::query()->whereSiblingOf($node);
    }

    /**
     * @return BelongsTo<static, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, $this->getLtreeParentColumn());
    }

    /**
     * @return HasMany<static, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(static::class, $this->getLtreeParentColumn());
    }

    /**
     * @return Descendants<static, $this>
     */
    public function descendants(): Descendants
    {
        return new Descendants($this->ltreeRelatedQuery(), $this, withSelf: false);
    }

    /**
     * @return Descendants<static, $this>
     */
    public function descendantsAndSelf(): Descendants
    {
        return new Descendants($this->ltreeRelatedQuery(), $this, withSelf: true);
    }

    /**
     * @return Ancestors<static, $this>
     */
    public function ancestors(): Ancestors
    {
        return new Ancestors($this->ltreeRelatedQuery(), $this, withSelf: false);
    }

    /**
     * @return Ancestors<static, $this>
     */
    public function ancestorsAndSelf(): Ancestors
    {
        return new Ancestors($this->ltreeRelatedQuery(), $this, withSelf: true);
    }

    /**
     * @return Siblings<static, $this>
     */
    public function siblings(): Siblings
    {
        return new Siblings($this->ltreeRelatedQuery(), $this);
    }

    /**
     * @return Root<static, $this>
     */
    public function root(): Root
    {
        return new Root($this->ltreeRelatedQuery(), $this);
    }

    public function getLtreePathColumn(): string
    {
        /** @var string $column */
        $column = config('ltree.path_column', 'path');

        return $column;
    }

    public function getLtreeParentColumn(): ?string
    {
        /** @var string|null $column */
        $column = config('ltree.parent_column', 'parent_id');

        return $column;
    }

    public function getLtreeOrderColumn(): ?string
    {
        /** @var string|null $column */
        $column = config('ltree.order_column');

        return $column;
    }

    public function getLtreeLabel(): string
    {
        /** @var int|string $key */
        $key = $this->getKey();

        return $this->normalizeLtreeLabel($key);
    }

    public function normalizeLtreeLabel(int|string $value): string
    {
        return $this->ltreeNormalizer()->normalize($value);
    }

    public function getLtreeLabelDependsOnKey(): bool
    {
        return true; // default label is the primary key; override to false for slug/column labels
    }

    public function path(): ?LtreePath
    {
        $value = $this->getAttribute($this->getLtreePathColumn());

        // Provably equivalent mutant: initializeHasLtree() merges LtreeCast
        // onto this column, and LtreeCast::get() (its only source) is typed
        // ?LtreePath and throws on any other non-null shape — so $value is
        // structurally guaranteed to already be LtreePath|null here. The
        // instanceof is a static-analysis narrowing of getAttribute()'s
        // `mixed` return, not a reachable runtime branch; no test can put a
        // third shape through this cast to distinguish `true ? $value : null`
        // from the real check.
        return $value instanceof LtreePath ? $value : null; // @pest-mutate-ignore: InstanceOfToTrue
    }

    public function depth(): int
    {
        return $this->path()?->depth() ?? 0;
    }

    public function level(): int
    {
        return $this->depth();
    }

    public function isRoot(): bool
    {
        return $this->path()?->isRoot() ?? false;
    }

    public function hasParent(): bool
    {
        return ! ($this->path()?->isRoot() ?? true);
    }

    public function setLtreeParent(?Model $parent): static
    {
        $this->ltreeTransientParent = $parent;

        return $this;
    }

    public function getLtreeParent(): ?Model
    {
        if ($this->ltreeTransientParent !== null) {
            return $this->ltreeTransientParent;
        }

        $parentColumn = $this->getLtreeParentColumn();

        // Equivalent mutant (RemoveEarlyReturn): with no parent column, removing
        // this early return falls through to getAttribute($parentColumn) — but
        // $parentColumn is null and Eloquent's getAttribute() returns null for a
        // falsy key, so $parentKey is null and the next guard returns null all
        // the same. No observable difference; the guard is just a clearer
        // short-circuit.
        if ($parentColumn === null) {
            return null; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        $parentKey = $this->getAttribute($parentColumn);

        if ($parentKey === null) {
            return null;
        }

        /** @var int|string $parentKey */
        return $this->newQuery()->find($parentKey);
    }

    public function countChildren(): int
    {
        return $this->ltreeChildrenQuery()->count();
    }

    public function countDescendants(): int
    {
        return $this->ltreeDescendantsQuery()->count();
    }

    public function hasChildren(): bool
    {
        return $this->ltreeChildrenQuery()->exists();
    }

    public function hasDescendants(): bool
    {
        return $this->ltreeDescendantsQuery()->exists();
    }

    public function isLeaf(): bool
    {
        return ! $this->hasDescendants();
    }

    /**
     * @return Builder<static>
     */
    public function branch(): Builder
    {
        if ($this->path() === null) {
            return $this->ltreeNoMatchQuery();
        }

        /** @var Builder<static> $query */
        $query = $this->newQuery();

        // Provably equivalent mutant: LtreePath implements Stringable, and a
        // PDO-bound query parameter is coerced to string by the driver
        // regardless (verified: passing $this->path() here directly still
        // produces the identical bound value). The cast documents intent
        // but changes no observable behavior.
        return $query->whereRaw(
            $this->ltreeColumnSql().' <@ ?::ltree',
            [(string) $this->path()], // @pest-mutate-ignore: RemoveStringCast
        );
    }

    /**
     * @return Builder<static>
     */
    private function ltreeDescendantsQuery(): Builder
    {
        if ($this->path() === null) {
            return $this->ltreeNoMatchQuery();
        }

        $column = $this->ltreeColumnSql();

        /** @var Builder<static> $query */
        $query = $this->newQuery();

        // Provably equivalent mutant: LtreePath implements Stringable, and a
        // PDO-bound query parameter is coerced to string by the driver
        // regardless (verified: passing $this->path() here directly still
        // produces the identical bound value). The cast documents intent
        // but changes no observable behavior.
        return $query
            ->whereRaw($column.' <@ ?::ltree', [(string) $this->path()]) // @pest-mutate-ignore: RemoveStringCast
            ->whereRaw($column.' <> ?::ltree', [(string) $this->path()]); // @pest-mutate-ignore: RemoveStringCast
    }

    /**
     * @return Builder<static>
     */
    private function ltreeChildrenQuery(): Builder
    {
        if ($this->path() === null) {
            return $this->ltreeNoMatchQuery();
        }

        $column = $this->ltreeColumnSql();

        /** @var Builder<static> $query */
        $query = $this->newQuery();

        // Provably equivalent mutant: LtreePath implements Stringable, and a
        // PDO-bound query parameter is coerced to string by the driver
        // regardless (verified: passing $this->path() here directly still
        // produces the identical bound value). The cast documents intent
        // but changes no observable behavior.
        return $query
            ->whereRaw($column.' <@ ?::ltree', [(string) $this->path()]) // @pest-mutate-ignore: RemoveStringCast
            ->whereRaw('nlevel('.$column.') = nlevel(?::ltree) + 1', [(string) $this->path()]); // @pest-mutate-ignore: RemoveStringCast
    }

    /**
     * A null path means this model isn't (or isn't yet) placed in the tree —
     * empty-casting it (`(string) null === ''`) would otherwise bind
     * `''::ltree`, which Postgres treats as an ancestor of every path
     * (nlevel 0 is a prefix of anything), silently matching the whole table.
     * Return a query that structurally matches no rows instead.
     *
     * @return Builder<static>
     */
    private function ltreeNoMatchQuery(): Builder
    {
        /** @var Builder<static> $query */
        $query = $this->newQuery();

        return $query->whereRaw('1 = 0');
    }

    private function ltreeColumnSql(): string
    {
        return '"'.$this->getLtreePathColumn().'"';
    }

    /**
     * A fresh, standalone query/model pair for the "related" side of the
     * FK-less ltree relations (`descendants()`, `descendantsAndSelf()`, ...)
     * — deliberately *not* `$this->newQuery()`, which reuses `$this` itself
     * as the builder's model. Since these relations are self-referential
     * (parent and related are the same class), reusing `$this` would make
     * `Relation::$related` and `Relation::$parent` literally the same PHP
     * object; `LtreeRelation::getRelationExistenceQuery()` (for `whereHas`)
     * must alias the related side via `Model::setTable()`, which would then
     * silently also rename the parent side out from under it. Mirrors how
     * core `belongsTo(static::class, ...)`/`hasMany(static::class, ...)`
     * avoid the same trap via `newRelatedInstance()`.
     *
     * @return LtreeBuilder<static>
     */
    private function ltreeRelatedQuery(): LtreeBuilder
    {
        /** @var LtreeBuilder<static> $query */
        $query = $this->newRelatedInstance(static::class)->newQuery();

        return $query;
    }

    private function ltreeNormalizer(): Normalizer
    {
        /** @var class-string<Normalizer> $class */
        $class = config('ltree.normalizer.class', DefaultNormalizer::class);
        /** @var 'replace'|'throw' $strategy */
        $strategy = config('ltree.normalizer.strategy', 'replace');
        /** @var array<string, string> $replacements */
        $replacements = config('ltree.normalizer.replacements', ['-' => '_']);

        return new $class($strategy, $replacements);
    }
}
