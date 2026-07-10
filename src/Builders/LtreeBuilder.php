<?php

declare(strict_types=1);

namespace Happenv\Ltree\Builders;

use Closure;
use Happenv\Ltree\Contracts\Ltreeable;
use Happenv\Ltree\Exceptions\LtreeException;
use Happenv\Ltree\Query\PathConstraint;
use Happenv\Ltree\ValueObjects\LtreePath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
final class LtreeBuilder extends Builder
{
    public function whereRoot(): static
    {
        return $this->whereRaw('nlevel('.$this->ltreePathColumn().') = 1');
    }

    public function whereLeaf(): static
    {
        $table = $this->ltreeTable();
        $column = $this->ltreePathColumn();

        return $this->whereRaw(
            'NOT EXISTS (SELECT 1 FROM '.$table.' d WHERE d.'.$column.' <@ '.$table.'.'.$column
            .' AND d.'.$column.' <> '.$table.'.'.$column.')',
        );
    }

    public function whereDepth(int $depth): static
    {
        return $this->whereRaw('nlevel('.$this->ltreePathColumn().') = ?', [$depth]);
    }

    public function whereBetweenDepth(int $min, int $max): static
    {
        return $this->whereRaw('nlevel('.$this->ltreePathColumn().') BETWEEN ? AND ?', [$min, $max]);
    }

    public function whereAncestorOf(Model|LtreePath|string $node): static
    {
        $path = $this->resolveLtreePath($node);
        $column = $this->ltreePathColumn();

        return $this->whereRaw($column.' @> ?::ltree AND '.$column.' <> ?::ltree', [$path, $path]);
    }

    public function whereDescendantOf(Model|LtreePath|string $node): static
    {
        $path = $this->resolveLtreePath($node);
        $column = $this->ltreePathColumn();

        return $this->whereRaw($column.' <@ ?::ltree AND '.$column.' <> ?::ltree', [$path, $path]);
    }

    public function whereChildOf(Model|LtreePath|string $node): static
    {
        $path = $this->resolveLtreePath($node);
        $column = $this->ltreePathColumn();

        return $this->whereRaw(
            $column.' <@ ?::ltree AND nlevel('.$column.') = nlevel(?::ltree) + 1',
            [$path, $path],
        );
    }

    public function whereParentOf(Model|LtreePath|string $node): static
    {
        $path = $this->resolveLtreePath($node);

        return $this->whereRaw(
            $this->ltreePathColumn().' = subpath(?::ltree, 0, nlevel(?::ltree) - 1)',
            [$path, $path],
        );
    }

    public function whereSiblingOf(Model|LtreePath|string $node): static
    {
        $path = $this->resolveLtreePath($node);
        $column = $this->ltreePathColumn();

        return $this->whereRaw(
            'subpath('.$column.', 0, nlevel('.$column.') - 1) = subpath(?::ltree, 0, nlevel(?::ltree) - 1) AND '.$column.' <> ?::ltree',
            [$path, $path, $path],
        );
    }

    public function wherePathStartsWith(Model|LtreePath|string $prefix): static
    {
        $path = $this->resolveLtreePath($prefix);

        return $this->whereRaw('?::ltree @> '.$this->ltreePathColumn(), [$path]);
    }

    public function wherePathEndsWith(string $suffix): static
    {
        return $this->whereRaw(
            $this->ltreePathColumn().' ~ (\'*.\' || ?)::lquery',
            [$suffix],
        );
    }

    public function whereSegment(string $label): static
    {
        return $this->whereRaw(
            $this->ltreePathColumn().' ~ (\'*.\' || ? || \'.*\')::lquery',
            [$label],
        );
    }

    public function wherePathMatches(string $lquery): static
    {
        return $this->whereRaw($this->ltreePathColumn().' ~ ?::lquery', [$lquery]);
    }

    public function orWherePathMatches(string $lquery): static
    {
        return $this->orWhereRaw($this->ltreePathColumn().' ~ ?::lquery', [$lquery]);
    }

    /**
     * @param  array<int, string>  $lqueries
     */
    public function wherePathMatchesAny(array $lqueries): static
    {
        if ($lqueries === []) {
            // Equivalent mutant (RemoveEarlyReturn): falling through builds
            // `~ ANY(ARRAY[]::lquery[])`, which Postgres evaluates to false for
            // every row (verified) — the identical zero-row result to this
            // `1 = 0`, just less direct. The guard is a clarity/short-circuit
            // choice, not observable behaviour.
            return $this->whereRaw('1 = 0'); // @pest-mutate-ignore: RemoveEarlyReturn
        }

        // Equivalent mutants (Increment/DecrementInteger on the start index):
        // array_fill's first arg sets only the array KEYS; implode() consumes
        // the VALUES (all '?') and count() is unchanged, so any start index
        // yields the identical placeholder string.
        $placeholders = implode(',', array_fill(0, count($lqueries), '?')); // @pest-mutate-ignore: IncrementInteger,DecrementInteger

        return $this->whereRaw(
            // Equivalent mutant (UnwrapArrayValues): whereRaw binds positionally
            // and ignores keys, and the public input is a list, so array_values()
            // never changes the bound sequence.
            $this->ltreePathColumn().' ~ ANY(ARRAY['.$placeholders.']::lquery[])',
            array_values($lqueries), // @pest-mutate-ignore: UnwrapArrayValues
        );
    }

    /**
     * @param  iterable<int, Model|LtreePath|string>  $nodes
     */
    public function whereDescendantOfAny(iterable $nodes): static
    {
        return $this->wherePathContainmentAny($nodes, '<@');
    }

    /**
     * @param  iterable<int, Model|LtreePath|string>  $nodes
     */
    public function whereAncestorOfAny(iterable $nodes): static
    {
        return $this->wherePathContainmentAny($nodes, '@>');
    }

    /**
     * @param  iterable<int, Model|LtreePath|string>  $nodes
     */
    protected function wherePathContainmentAny(iterable $nodes, string $operator): static
    {
        $paths = [];
        foreach ($nodes as $node) {
            $paths[] = $this->resolveLtreePath($node);
        }

        if ($paths === []) {
            // Equivalent mutant (RemoveEarlyReturn): falling through builds
            // `<op> ANY(ARRAY[]::ltree[])`, which Postgres evaluates to false for
            // every row (empty-array ANY) — identical zero-row result to this
            // `1 = 0`, just less direct. Same fact used by wherePathMatchesAny().
            return $this->whereRaw('1 = 0'); // @pest-mutate-ignore: RemoveEarlyReturn
        }

        // Equivalent mutants (Increment/DecrementInteger on the start index):
        // array_fill's first arg sets only the array KEYS; implode() consumes
        // the VALUES (all '?') and count() is unchanged, so any start index
        // yields the identical placeholder string.
        $placeholders = implode(',', array_fill(0, count($paths), '?')); // @pest-mutate-ignore: IncrementInteger,DecrementInteger

        return $this->whereRaw(
            // Equivalent mutants (ConcatRemoveRight/ConcatSwitchSides on the
            // whitespace around $operator): Postgres's tokenizer doesn't need
            // whitespace here — a double-quoted identifier self-delimits at its
            // closing quote, and `<@`/`@>` are lexed as complete operator tokens
            // before the following `ANY` (an alnum keyword can't extend an
            // operator token) — so every whitespace variant of
            // `"path" <@ ANY(...)` tokenises identically (verified).
            $this->ltreePathColumn().' '.$operator.' ANY(ARRAY['.$placeholders.']::ltree[])', // @pest-mutate-ignore: ConcatRemoveRight,ConcatSwitchSides
            $paths,
        );
    }

    public function wherePathContains(string $word): static
    {
        return $this->whereRaw(
            $this->ltreePathColumn().' @ ?::ltxtquery',
            [$this->ltxtqueryWord($word)],
        );
    }

    /**
     * @param  array<int, string>  $words
     */
    public function wherePathContainsAll(array $words): static
    {
        return $this->whereLtxtquerySet($words, '&');
    }

    /**
     * @param  array<int, string>  $words
     */
    public function wherePathContainsAny(array $words): static
    {
        return $this->whereLtxtquerySet($words, '|');
    }

    /**
     * Raw ltxtquery escape hatch: the string is passed through UNVALIDATED so
     * callers who know the syntax can use the full `& | ! ( )` grammar and the
     * `@ * %` flags themselves. Still bound (`?::ltxtquery`), so injection-safe;
     * unlike the wherePathContains helpers it does not guard against expression
     * operators, because using them is the whole point.
     */
    public function search(string $ltxtquery): static
    {
        return $this->whereRaw($this->ltreePathColumn().' @ ?::ltxtquery', [$ltxtquery]);
    }

    /**
     * @param  array<int, string>  $words
     */
    protected function whereLtxtquerySet(array $words, string $operator): static
    {
        if ($words === []) {
            // Fail-closed on an empty set: both containsAll([]) and containsAny([])
            // match NOTHING. This is a deliberate least-surprise choice — an
            // accidental empty array must not silently match the whole table (the
            // mathematical empty-AND identity would make containsAll([]) match
            // everything). Callers wanting "no constraint" simply omit the call.
            return $this->whereRaw('1 = 0');
        }

        // Equivalent mutants (ConcatRemoveLeft/Right/SwitchSides on the glue):
        // ltxtquery ignores whitespace around its operators (verified: `a&b` ==
        // `a & b` == `a  &  b`), so rearranging the two spaces around $operator
        // parses identically.
        $expression = implode(' '.$operator.' ', array_map($this->ltxtqueryWord(...), $words)); // @pest-mutate-ignore: ConcatRemoveLeft,ConcatRemoveRight,ConcatSwitchSides

        return $this->whereRaw($this->ltreePathColumn().' @ ?::ltxtquery', [$expression]);
    }

    /**
     * Guard a single ltxtquery word against smuggling operators/whitespace into an
     * assembled expression. Values are always bound, so this is not an injection
     * guard — it prevents a caller from silently changing the query's meaning.
     * Labels + trailing flags (@ prefix-insensitive, * prefix, %) stay legal.
     */
    protected function ltxtqueryWord(string $word): string
    {
        if ($word === '' || preg_match('/[\s&|!()]/', $word) === 1) {
            throw new LtreeException("Invalid ltxtquery word [{$word}]: contains an operator or whitespace. Use search() for raw ltxtquery expressions.");
        }

        return $word;
    }

    public function maxDepth(int $depth): static
    {
        return $this->whereRaw('nlevel('.$this->ltreePathColumn().') <= ?', [$depth]);
    }

    public function minDepth(int $depth): static
    {
        return $this->whereRaw('nlevel('.$this->ltreePathColumn().') >= ?', [$depth]);
    }

    public function whereWithinDepthOf(Model|LtreePath|string $node, int $levels): static
    {
        $path = $this->resolveLtreePath($node);
        $column = $this->ltreePathColumn();

        return $this->whereRaw(
            $column.' <@ ?::ltree AND nlevel('.$column.') <= nlevel(?::ltree) + ?',
            [$path, $path, $levels],
        );
    }

    /**
     * Compose several path constraints inside one grouped `WHERE (...)`. The
     * callback receives a PathConstraint whose verbs AND-compose within the group,
     * so a surrounding orWhere cannot leak into it.
     *
     * @param  Closure(PathConstraint<TModel>): void  $callback
     */
    public function wherePath(Closure $callback): static
    {
        return $this->where(function (self $query) use ($callback): void {
            $callback(new PathConstraint($query));
        });
    }

    public function orderByDepth(string $direction = 'asc'): static
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        return $this->orderByRaw('nlevel('.$this->ltreePathColumn().') '.$direction);
    }

    public function withDepth(string $alias = 'depth'): static
    {
        if ($this->getQuery()->columns === null) {
            $this->select($this->qualifyColumn('*'));
        }

        return $this->addSelect(DB::raw('nlevel('.$this->ltreePathColumn().') AS "'.$alias.'"'));
    }

    public function commonAncestor(): ?LtreePath
    {
        $column = $this->ltreePathColumn();

        // clone: toBase() returns the shared underlying query builder when the model has no
        // global scopes; reorder()/select() mutate in place, so work on a copy to leave the
        // caller's builder reusable (the standard Eloquent terminal-aggregate contract).
        $rows = (clone $this->toBase())->reorder()->select(DB::raw($column));

        // Postgres's native lca() under-counts by one level whenever one of the
        // constrained paths is a literal ancestor of (or equal to) another: it
        // only confirms a shared level once it observes an actual divergence
        // between two distinct children. When the shallowest path in the set
        // already dominates (is an ancestor-or-self of) every other path, that
        // path *is* the true common ancestor; otherwise fall back to lca().
        $sql = 'with __ltree_rows as ('.$rows->toSql().'), '
            .'__ltree_shallow as (select '.$column.' from __ltree_rows order by nlevel('.$column.') asc limit 1) '
            .'select case '
            .'when (select bool_and(__ltree_rows.'.$column.' <@ __ltree_shallow.'.$column.') from __ltree_rows, __ltree_shallow) '
            .'then (select '.$column.' from __ltree_shallow) '
            .'else (select lca(array_agg(__ltree_rows.'.$column.')) from __ltree_rows) '
            .'end as lca';

        $result = $this->getConnection()->selectOne($sql, $rows->getBindings());

        // Provably equivalent mutant: the compiled $sql above is a bare
        // `select case ... end as lca` with no top-level FROM clause, so it
        // is a scalar SELECT that always yields exactly one row (verified:
        // even against a zero-row constrained set, the CASE's scalar
        // subqueries evaluate to NULL rather than the query returning zero
        // rows — Postgres confirmed via direct execution). selectOne() can
        // therefore only ever return a stdClass here, never null; no real
        // query can drive this instanceof check to its false branch.
        if (! $result instanceof stdClass) { // @pest-mutate-ignore: InstanceOfToTrue
            return null;
        }

        $value = $result->lca;

        return is_string($value) ? new LtreePath($value) : null;
    }

    public function tapPath(callable $callback): static
    {
        $callback($this);

        return $this;
    }

    protected function resolveLtreePath(Model|LtreePath|string $node): string
    {
        $path = match (true) {
            $node instanceof LtreePath => $node->toString(),
            $node instanceof Model => $this->modelPath($node),
            default => $node,
        };

        if ($path === '') {
            throw new LtreeException('Cannot build an ltree predicate from an empty path.');
        }

        return $path;
    }

    private function modelPath(Model $node): string
    {
        /** @var Model&Ltreeable $node */
        return (string) $node->path();
    }

    protected function ltreePathColumn(): string
    {
        /** @var Model&Ltreeable $model */
        $model = $this->getModel();

        return '"'.$model->getLtreePathColumn().'"';
    }

    protected function ltreeTable(): string
    {
        return '"'.$this->getModel()->getTable().'"';
    }
}
