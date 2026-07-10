<?php

declare(strict_types=1);

namespace Happenv\Ltree\Concerns;

use Closure;
use Happenv\Ltree\Contracts\Ltreeable;
use Happenv\Ltree\Events\CascadeDeleted;
use Happenv\Ltree\Events\CascadeDeleting;
use Happenv\Ltree\Events\Copied;
use Happenv\Ltree\Events\Copying;
use Happenv\Ltree\Events\Moved;
use Happenv\Ltree\Events\Moving;
use Happenv\Ltree\Events\Rebuilding;
use Happenv\Ltree\Events\Rebuilt;
use Happenv\Ltree\Events\Renamed;
use Happenv\Ltree\Events\Renaming;
use Happenv\Ltree\Exceptions\InvalidMoveException;
use Happenv\Ltree\Exceptions\LtreeException;
use Happenv\Ltree\Exceptions\MissingSortColumnException;
use Happenv\Ltree\Observers\LtreeObserver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Transactional subtree operations for an ltree model. Consumed by HasLtree.
 *
 * @mixin Model
 * @mixin Ltreeable
 */
trait PerformsLtreeOperations
{
    /**
     * Move this node's whole subtree under $target (or to root when null).
     *
     * Rewrites the path of this node and every descendant, then updates this
     * node's parent_id. Runs inside a DB transaction. Fires a cancellable
     * `Moving` event before the mutation and `Moved` after it.
     *
     * Note: ltree does not enforce sibling-label uniqueness — moving this
     * node under a target that already has a child with the same label
     * silently produces two rows with the identical path.
     */
    public function moveTo(?Model $target): static
    {
        $old = $this->path();
        if ($old === null) {
            throw new InvalidMoveException('Cannot move a node without a persisted path.');
        }

        if ($target !== null) {
            /** @var Model&Ltreeable $target */
            $targetPath = $target->path();
            if ($targetPath === null) {
                throw new InvalidMoveException('Cannot move under a target without a persisted path.');
            }
            // cannot move under self or own descendant
            if ($targetPath->equals($old) || $targetPath->isDescendantOf($old)) {
                throw new InvalidMoveException('Cannot move a node under itself or its own descendant.');
            }
        }

        if (! $this->fireLtreeBefore(new Moving($this, $target))) {
            return $this;
        }

        $this->withLtreeTransaction(function () use ($old, $target): void {
            $col = $this->ltreePathColumnRaw();
            $table = $this->getTable();

            // Provably equivalent mutant (all (string) casts below): LtreePath
            // implements Stringable, and a PDO-bound query parameter is
            // coerced to string by the driver regardless (verified: binding
            // $old/$newParent directly still produces the identical bound
            // value). The cast documents intent but changes no observable
            // behavior.
            if ($target === null) {
                $sql = "UPDATE \"$table\" SET $col = subpath($col, nlevel(?::ltree) - 1) WHERE $col <@ ?::ltree";
                DB::connection($this->getConnectionName())->update($sql, [(string) $old, (string) $old]); // @pest-mutate-ignore: RemoveStringCast
            } else {
                /** @var Model&Ltreeable $target */
                $newParent = (string) $target->path(); // @pest-mutate-ignore: RemoveStringCast
                $sql = "UPDATE \"$table\" SET $col = ?::ltree || subpath($col, nlevel(?::ltree) - 1) WHERE $col <@ ?::ltree";
                DB::connection($this->getConnectionName())->update($sql, [$newParent, (string) $old, (string) $old]); // @pest-mutate-ignore: RemoveStringCast
            }

            $parentColumn = $this->getLtreeParentColumn();
            if ($parentColumn !== null) {
                $this->newQuery()->whereKey($this->getKey())->update([$parentColumn => $target?->getKey()]);
            }
        });

        $this->refresh();
        $this->fireLtreeAfter(new Moved($this, $target));

        return $this;
    }

    /**
     * Detach this node's subtree, moving it to become a new root.
     * Equivalent to `moveTo(null)`.
     */
    public function detach(): static
    {
        return $this->moveTo(null);
    }

    /**
     * Delete this node and its whole subtree in one bulk statement.
     *
     * This is a *bulk* delete: it runs a single `DELETE ... WHERE path <@
     * :old` and does NOT instantiate or delete each descendant individually,
     * so per-model Eloquent `deleting`/`deleted` events (and anything that
     * only hooks those, e.g. `SoftDeletes` or model observers) do NOT fire
     * for the affected rows. Only the ltree-specific `CascadeDeleting`
     * (before, cancellable, carries the full self-and-descendants
     * collection) and `CascadeDeleted` (after, carries the deleted keys)
     * events are dispatched. Runs inside a DB transaction. Returns the
     * number of deleted rows (0 if this node has no persisted path, or a
     * `CascadeDeleting` listener vetoes the operation).
     */
    public function cascadeDelete(): int
    {
        $old = $this->path();
        if ($old === null) {
            return 0;
        }

        /** @var Collection<int, Model> $nodes */
        $nodes = $this->descendantsAndSelf()->get();

        if (! $this->fireLtreeBefore(new CascadeDeleting($this, $nodes))) {
            return 0;
        }

        $keys = $nodes->modelKeys();

        $deleted = $this->withLtreeTransaction(function () use ($old): int {
            $col = $this->ltreePathColumnRaw();
            $table = $this->getTable();

            // Provably equivalent mutant: LtreePath implements Stringable, and
            // a PDO-bound query parameter is coerced to string by the driver
            // regardless (verified: binding $old directly still produces the
            // identical bound value). The cast documents intent but changes
            // no observable behavior.
            return DB::connection($this->getConnectionName())
                ->delete("DELETE FROM \"$table\" WHERE $col <@ ?::ltree", [(string) $old]); // @pest-mutate-ignore: RemoveStringCast
        });

        $this->fireLtreeAfter(new CascadeDeleted($this, $keys));

        return $deleted;
    }

    /**
     * Alias for {@see cascadeDelete()}.
     */
    public function deleteBranch(): int
    {
        return $this->cascadeDelete();
    }

    /**
     * Rename this node's own path segment (its last label), cascading the
     * new prefix into every descendant's path. Runs inside a DB transaction.
     * Fires a cancellable `Renaming` event before the mutation and `Renamed`
     * after it.
     *
     * Note: ltree does not enforce sibling-label uniqueness — renaming this
     * node to a label that collides with an existing sibling's silently
     * produces two rows with the identical path.
     */
    public function renameSegment(string $newLabel): static
    {
        $old = $this->path();
        if ($old === null) {
            throw new LtreeException('Cannot rename a node without a persisted path.');
        }

        $label = $this->normalizeLtreeLabel($newLabel);
        $parent = $old->parent();
        // Provably equivalent mutant: LtreePath implements Stringable, and a
        // PDO-bound query parameter is coerced to string by the driver
        // regardless (verified: binding the LtreePath from append() directly
        // still produces the identical bound value). The cast documents
        // intent but changes no observable behavior.
        $newNode = $parent !== null ? (string) $parent->append($label) : $label; // @pest-mutate-ignore: RemoveStringCast

        if (! $this->fireLtreeBefore(new Renaming($this, $label))) {
            return $this;
        }

        $this->withLtreeTransaction(function () use ($old, $newNode): void {
            $col = $this->ltreePathColumnRaw();
            $table = $this->getTable();
            $sql = "UPDATE \"$table\" SET $col = CASE WHEN $col = ?::ltree THEN ?::ltree "
                ."ELSE ?::ltree || subpath($col, nlevel(?::ltree)) END WHERE $col <@ ?::ltree";
            // Provably equivalent mutant: LtreePath implements Stringable, and
            // a PDO-bound query parameter is coerced to string by the driver
            // regardless (verified: binding $old directly still produces the
            // identical bound value). The cast documents intent but changes
            // no observable behavior.
            DB::connection($this->getConnectionName())->update($sql, [(string) $old, $newNode, $newNode, (string) $old, (string) $old]); // @pest-mutate-ignore: RemoveStringCast
        });

        $this->refresh();
        $this->fireLtreeAfter(new Renamed($this, $label));

        return $this;
    }

    /**
     * Deep-copy this node's whole subtree under $target (or as a new root
     * when null), assigning every copy a fresh primary key.
     *
     * Unlike the other operations, this one does NOT write paths with raw
     * SQL: each copy is `replicate()`d (minus its path) and `save()`d, which
     * lets {@see LtreeObserver} compute the copy's path the normal way —
     * from its (mapped) new parent and its own new key. Nodes are processed
     * top-down (`orderByDepth('asc')`), so a
     * parent's copy is always persisted, with its key and path assigned,
     * before any of its children's copies are created — which is what lets
     * `setLtreeParent()` hand the observer a parent it can actually read a
     * path from. Runs inside a DB transaction. Fires a cancellable `Copying`
     * event before the mutation and `Copied` after it. Returns the new copy
     * of *this* node (the root of the copied subtree).
     */
    public function copyTo(?Model $target): static
    {
        if ($this->path() === null) {
            throw new LtreeException('Cannot copy a node without a persisted path.');
        }

        if (! $this->fireLtreeBefore(new Copying($this, $target))) {
            return $this;
        }

        $pathColumn = $this->getLtreePathColumn();
        $parentColumn = $this->getLtreeParentColumn();

        $copy = $this->withLtreeTransaction(function () use ($target, $pathColumn, $parentColumn): Model {
            // Keyed by each node's OLD path (not its parent_id): this makes
            // the parent lookup work regardless of whether a parent column
            // is configured at all, since every node's path already encodes
            // its real position in the tree. Nodes are processed
            // depth-ascending, so a parent's map entry always exists by the
            // time its children are reached.
            /** @var array<string, Model> $byOldPath */
            $byOldPath = [];
            $rootCopy = null;

            foreach ($this->descendantsAndSelf()->orderByDepth('asc')->get() as $node) {
                /** @var Model&Ltreeable $node */
                $replica = $node->replicate([$pathColumn]);

                if ($node->is($this)) {
                    $newParent = $target;
                } else {
                    // Provably equivalent mutant (both ?-> below): this branch
                    // (the else of $node->is($this)) only runs for a strict
                    // descendant of $this, whose path() is therefore always
                    // non-null AND has depth >= 2 (it's nested at least one
                    // level under $this, which itself has depth >= 1) — so
                    // parent() always returns a non-null LtreePath here too.
                    // Both nullsafe operators are defensive and never take the
                    // null branch on this call site.
                    $oldParentPath = $node->path()?->parent()?->toString(); // @pest-mutate-ignore: RemoveNullSafeOperator
                    $newParent = $oldParentPath !== null ? ($byOldPath[$oldParentPath] ?? $target) : $target;
                }

                $replica->setLtreeParent($newParent); // sets transient parent; observer computes path
                if ($parentColumn !== null) {
                    $replica->setAttribute($parentColumn, $newParent?->getKey());
                }
                $replica->save();

                $byOldPath[(string) $node->path()] = $replica;
                $rootCopy ??= $replica;
            }

            return $rootCopy ?? throw new LtreeException('copyTo found no nodes to copy.');
        });

        $this->fireLtreeAfter(new Copied($this, $copy));

        /** @var static $copy */
        return $copy;
    }

    /**
     * Attach $child as a new child of $this: sets its transient ltree parent
     * (+ parent_id, when a parent column is configured) and saves it, which
     * drives {@see LtreeObserver} to compute the child's path. When an order
     * column is configured, the child is placed *last* among $this's
     * children (dense index = current child count).
     *
     * Like {@see copyTo()}, this relies on the observer's `creating`/
     * `created` hooks to compute the path, so $child must be a new
     * (not-yet-persisted) model — use {@see moveTo()} to relocate an
     * existing, already-persisted node instead.
     */
    public function appendChild(Model $child): Model
    {
        return $this->attachChild($child, atEnd: true);
    }

    /**
     * Same as {@see appendChild()}, but places $child *first* among $this's
     * children when an order column is configured (shifting every existing
     * child's order up by one). With no order column configured, ltree has
     * no concept of sibling order, so this is identical to appendChild().
     */
    public function prependChild(Model $child): Model
    {
        return $this->attachChild($child, atEnd: false);
    }

    /**
     * Move this node to sit immediately before $sibling among its children.
     * If $sibling belongs to a different parent, this node is reparented
     * there first (via {@see moveTo()}) before reordering.
     *
     * @throws MissingSortColumnException if no order column is configured.
     */
    public function moveBefore(Model $sibling): static
    {
        return $this->reorderAmongSiblings($sibling, 'before');
    }

    /**
     * Move this node to sit immediately after $sibling among its children.
     * If $sibling belongs to a different parent, this node is reparented
     * there first (via {@see moveTo()}) before reordering.
     *
     * @throws MissingSortColumnException if no order column is configured.
     */
    public function moveAfter(Model $sibling): static
    {
        return $this->reorderAmongSiblings($sibling, 'after');
    }

    /**
     * Move this node to the front of its current siblings.
     *
     * @throws MissingSortColumnException if no order column is configured.
     */
    public function moveFirst(): static
    {
        return $this->reorderAmongSiblings(null, 'first');
    }

    /**
     * Move this node to the end of its current siblings.
     *
     * @throws MissingSortColumnException if no order column is configured.
     */
    public function moveLast(): static
    {
        return $this->reorderAmongSiblings(null, 'last');
    }

    /**
     * Recompute EVERY row's path in this model's table from `parent_id`,
     * top-down. This is a whole-table maintenance/migration operation — the
     * instance it's called on is only the entry point (its class/connection),
     * not the scope of what gets rebuilt. Use case: repairing corrupted
     * paths, or migrating an existing adjacency-list table into ltree.
     *
     * Requires a configured parent column; throws {@see LtreeException}
     * otherwise. Fires a cancellable `Rebuilding` event before the mutation
     * (returns 0 without touching anything if vetoed) and `Rebuilt` after a
     * successful rebuild. Runs inside a DB transaction: a detected
     * `parent_id` cycle throws {@see LtreeException} and rolls back every
     * change. Returns the number of rows whose path actually changed.
     */
    public function rebuildPaths(): int
    {
        $parentColumn = $this->getLtreeParentColumn();
        if ($parentColumn === null) {
            throw new LtreeException('rebuildPaths requires a configured parent column.');
        }

        if (! $this->fireLtreeBefore(new Rebuilding($this))) {
            return 0;
        }

        $changed = $this->withLtreeTransaction(function () use ($parentColumn): int {
            $rows = $this->newQuery()->get();
            $byKey = $rows->keyBy($this->getKeyName());
            $pathColumn = $this->getLtreePathColumn();
            $count = 0;

            /**
             * Compute a node's path by walking to root; detect cycles via the
             * $seen map of keys visited so far on this particular walk.
             *
             * @param  array<int|string, bool>  $seen
             */
            $resolve = function (Model $node, array $seen = []) use (&$resolve, $byKey, $parentColumn): string {
                /** @var Model&Ltreeable $node */
                /** @var int|string $key */
                $key = $node->getKey();
                if (isset($seen[$key])) {
                    throw new LtreeException("Cycle detected at key [{$key}] while rebuilding paths.");
                }
                // Provably equivalent mutant: only isset($seen[$key]) is ever
                // read above (key existence, to detect a cycle) — the stored
                // value itself is never read, so `false` works identically to
                // `true` here.
                $seen[$key] = true; // @pest-mutate-ignore: TrueToFalse
                $label = $node->getLtreeLabel();
                /** @var int|string|null $parentKey */
                $parentKey = $node->getAttribute($parentColumn);
                $parent = $parentKey !== null ? $byKey->get($parentKey) : null;

                return $parent !== null ? $resolve($parent, $seen).'.'.$label : $label;
            };

            foreach ($rows as $node) {
                /** @var Model&Ltreeable $node */
                $new = $resolve($node);
                if ((string) $node->path() !== $new) {
                    $this->newQuery()->whereKey($node->getKey())->update([$pathColumn => $new]);
                    $count++;
                }
            }

            return $count;
        });

        $this->fireLtreeAfter(new Rebuilt($this));

        return $changed;
    }

    /**
     * Shared implementation for {@see appendChild()}/{@see prependChild()}:
     * wires up $child's transient parent (+ parent_id) and, when an order
     * column is configured, assigns its dense sort_order (0 when prepending
     * — after bulk-shifting existing children up by one — or the current
     * child count when appending) inside a transaction before saving.
     */
    private function attachChild(Model $child, bool $atEnd): Model
    {
        /** @var Model&Ltreeable $child */
        $child->setLtreeParent($this);

        $parentColumn = $this->getLtreeParentColumn();
        if ($parentColumn !== null) {
            $child->setAttribute($parentColumn, $this->getKey());
        }

        $orderColumn = $this->getLtreeOrderColumn();
        if ($orderColumn === null) {
            $child->save();

            return $child;
        }

        $this->withLtreeTransaction(function () use ($child, $orderColumn, $atEnd): void {
            if ($atEnd) {
                $child->setAttribute($orderColumn, count($this->ltreeOrderedChildrenOf($this, $orderColumn)));
            } else {
                $this->ltreeIncrementChildrenOrder($orderColumn);
                $child->setAttribute($orderColumn, 0);
            }

            $child->save();
        });

        return $child;
    }

    /**
     * Bulk-increments the sort_order of every current child of $this by one
     * (used by prependChild() to make room for a new first child).
     */
    private function ltreeIncrementChildrenOrder(string $orderColumn): void
    {
        $parentColumn = $this->getLtreeParentColumn();
        $query = $this->newQuery();

        if ($parentColumn !== null) {
            $query->where($parentColumn, $this->getKey());
        }

        $query->increment($orderColumn);
    }

    /**
     * Shared implementation for moveBefore()/moveAfter()/moveFirst()/
     * moveLast(): resolves the target parent (reparenting first when
     * $sibling — for before/after — belongs elsewhere), builds the target
     * parent's current child list (sort_order ascending, this node
     * excluded), splices this node back in at the resolved index, then
     * densely renumbers every child's sort_order (0..n).
     *
     * The reparent and the renumber run inside ONE transaction (nested
     * transactions use savepoints, so this composes fine with moveTo()'s own
     * transaction), so a cross-parent move is atomic: if the reparent step
     * is vetoed by a `Moving` listener, {@see ltreeReparentToSiblingOf()}
     * throws and the whole operation — including the renumber — rolls back,
     * instead of leaving sort_order updated under a parent this node was
     * never actually moved to.
     *
     * @param  'before'|'after'|'first'|'last'  $position
     */
    private function reorderAmongSiblings(?Model $sibling, string $position): static
    {
        $orderColumn = $this->getLtreeOrderColumn();
        if ($orderColumn === null) {
            throw new MissingSortColumnException(sprintf(
                '%s has no ltree order column configured; ordered sibling moves are unavailable.',
                static::class,
            ));
        }

        $this->withLtreeTransaction(function () use ($sibling, $position, $orderColumn): void {
            // $sibling is non-null exactly when $position is 'before'/'after'
            // (guaranteed by the public API: moveBefore()/moveAfter() type
            // $sibling as a non-null Model and pass it straight through;
            // moveFirst()/moveLast() pass null). Branching on $sibling's
            // nullability (rather than re-checking $position) lets the
            // non-null branch call ltreeReparentToSiblingOf()/
            // ltreeSiblingIndex() with a statically non-null Model.
            if ($sibling !== null) {
                $targetParent = $this->ltreeReparentToSiblingOf($sibling);
                $ordered = $this->ltreeOrderedChildrenOf($targetParent, $orderColumn);
                $index = $this->ltreeSiblingIndex($ordered, $sibling, $position);
            } else {
                $targetParent = $this->getLtreeParent();
                $ordered = $this->ltreeOrderedChildrenOf($targetParent, $orderColumn);
                $index = $position === 'first' ? 0 : count($ordered);
            }

            array_splice($ordered, $index, 0, [$this]);

            foreach ($ordered as $newOrder => $node) {
                $node->newQuery()->whereKey($node->getKey())->update([$orderColumn => $newOrder]);
            }
        });

        $this->refresh();

        return $this;
    }

    /**
     * Resolves the sibling's current parent for moveBefore()/moveAfter(),
     * reparenting $this there first (via moveTo()) when it currently sits
     * under a different parent. Returns the (possibly new) shared parent.
     *
     * Verifies the reparent actually took effect: if a `Moving` listener
     * vetoed the inner {@see moveTo()} call, this node's parent still won't
     * match $sibling's — throw so the whole ordered move (this call always
     * runs inside reorderAmongSiblings()'s transaction) rolls back instead of
     * silently renumbering sort_order under the wrong parent.
     */
    private function ltreeReparentToSiblingOf(Model $sibling): ?Model
    {
        /** @var Model&Ltreeable $sibling */
        $siblingParent = $sibling->getLtreeParent();

        if (! $this->ltreeParentsMatch($this->getLtreeParent(), $siblingParent)) {
            $this->moveTo($siblingParent);
            // Provably equivalent mutant: moveTo() already calls $this->refresh()
            // internally right before it returns (unless vetoed, in which case
            // it changed nothing in the DB or in $this's in-memory attributes
            // either, so a refresh here would fetch back the same values this
            // object already holds). This second refresh() is therefore a
            // no-op in every reachable case (verified: the full suite,
            // including the cross-parent ordered-move and Moving-veto tests,
            // stays green with this call removed).
            $this->refresh(); // @pest-mutate-ignore: RemoveMethodCall

            if (! $this->ltreeParentsMatch($this->getLtreeParent(), $siblingParent)) {
                throw new InvalidMoveException('The reparent step of an ordered move was cancelled.');
            }
        }

        return $siblingParent;
    }

    private function ltreeParentsMatch(?Model $a, ?Model $b): bool
    {
        return $a === null || $b === null ? $a === $b : $a->is($b);
    }

    /**
     * The children of $parent (or root-level nodes when $parent is null),
     * ordered by $orderColumn ascending, as a plain list — this node itself
     * excluded, since callers splice it back in at a computed index.
     *
     * @return array<int, Model>
     */
    private function ltreeOrderedChildrenOf(?Model $parent, string $orderColumn): array
    {
        $parentColumn = $this->getLtreeParentColumn();
        $query = $this->newQuery()->orderBy($orderColumn);

        if ($parentColumn !== null) {
            $query->where($parentColumn, $parent?->getKey());
        }

        return $query->get()
            ->reject(fn (Model $model): bool => $model->is($this))
            ->values()
            ->all();
    }

    /**
     * The index at which this node should be spliced back into $ordered to
     * sit immediately before/after $sibling (used for the moveBefore()/
     * moveAfter() branch only — moveFirst()/moveLast() need no sibling
     * lookup and are resolved inline in reorderAmongSiblings()).
     *
     * @param  array<int, Model>  $ordered
     * @param  'before'|'after'|'first'|'last'  $position
     */
    private function ltreeSiblingIndex(array $ordered, Model $sibling, string $position): int
    {
        foreach ($ordered as $index => $node) {
            if ($node->is($sibling)) {
                return $position === 'before' ? $index : $index + 1;
            }
        }

        throw new InvalidMoveException('The given sibling is not a child of the resolved parent.');
    }

    /**
     * Run $callback inside a DB transaction when transactional_moves is on
     * (default), else inline. Returns the callback's result.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function withLtreeTransaction(Closure $callback): mixed
    {
        // Provably equivalent mutant: the published config/ltree.php always
        // sets this key (merged in by LaravelLtreeServiceProvider), so the
        // `true` fallback default is dead code — only reachable if the key
        // were absent, which it never is in a configured app/test.
        if (config('ltree.transactional_moves', true)) { // @pest-mutate-ignore: TrueToFalse
            return DB::connection($this->getConnectionName())->transaction($callback);
        }

        return $callback();
    }

    /**
     * Dispatch a cancellable "before" event. Returns false iff a listener
     * vetoed the operation (returned false).
     *
     * Dispatches in "halt" mode (mirrors Eloquent's own cancellable model
     * events, e.g. `saving`/`deleting`, via `Model::fireModelEvent()`): with
     * $halt = true, the dispatcher returns the first non-null listener
     * response directly instead of collecting responses into an array. This
     * matters because a plain (non-halting) dispatch() *drops* `false`
     * responses from its returned array entirely (it only uses them to stop
     * propagation) — so checking `in_array(false, $responses)` against a
     * non-halting dispatch can never observe a veto (verified: an
     * always-empty $responses array either way).
     */
    protected function fireLtreeBefore(object $event): bool
    {
        return $this->dispatchUntil($event) !== false;
    }

    /**
     * Thin wrapper carrying an accurate `mixed` return type for the event
     * dispatcher's halting `until()`.
     *
     * `until()` is declared `array|null` on both the concrete Dispatcher and
     * the Event facade — a docblock that only describes its *non-halting*
     * shape. Called with $halt = true (as here), it actually returns
     * whatever the first responding listener returned — verified above to
     * include literal `false` from a vetoing listener. Routing the call
     * through this wrapper's honest `mixed` return type means the `!==
     * false` veto check in fireLtreeBefore() is evaluated against the real
     * contract instead of the narrower (and here misleading) upstream one.
     */
    private function dispatchUntil(object $event): mixed
    {
        return app('events')->until($event);
    }

    protected function fireLtreeAfter(object $event): void
    {
        app('events')->dispatch($event);
    }

    /** Fully-qualified, quoted path column for raw statements. */
    protected function ltreePathColumnRaw(): string
    {
        return '"'.$this->getLtreePathColumn().'"';
    }
}
