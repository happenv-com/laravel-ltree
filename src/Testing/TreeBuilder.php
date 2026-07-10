<?php

declare(strict_types=1);

namespace Happenv\Ltree\Testing;

use Happenv\Ltree\Collections\LtreeCollection;
use Happenv\Ltree\Contracts\Ltreeable;
use Illuminate\Database\Eloquent\Model;

/**
 * Build a whole ltree tree (or forest) from a plain nested array in one call —
 * for seeders and tests, so callers don't have to hand-wire
 * `setLtreeParent()`/`save()` for every node.
 *
 * Canonical node-array shape: a list where each item is an associative
 * attribute array with an optional `children` key holding a list of child
 * node-arrays (same shape, recursively). A bare `[]` is a node with default
 * attributes and no children. Models here are keyed by label (e.g. an
 * auto-increment id), so — unlike a plain nested-array tree fixture — keys
 * can't double as labels; this is why the shape is a *list* of nodes rather
 * than an associative `label => children` map.
 *
 * Example, a 3-level tree with two roots:
 *
 *   TreeBuilder::fromArray(Category::class, [
 *       ['children' => [
 *           ['children' => [ [], [] ]],   // a child with two grandchildren
 *           [],                            // a leaf child
 *       ]],
 *       [],                                // a second root
 *   ]);
 */
final class TreeBuilder
{
    /**
     * Recursively create every node in $nodes (and their `children`) as
     * `$modelClass` instances, in pre-order (a node is saved before its
     * children are created), so the ltree observer can compute each child's
     * path from its already-persisted parent.
     *
     * Returns a FLAT `LtreeCollection` of every node created across the
     * whole call — not just the roots — in creation (pre-order) order.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @param  list<array<string, mixed>>  $nodes
     * @return LtreeCollection<int, TModel>
     */
    public static function fromArray(string $modelClass, array $nodes, ?Model $parent = null): LtreeCollection
    {
        /** @var LtreeCollection<int, TModel> $created */
        $created = self::withCreator(
            $nodes,
            $parent,
            fn (array $attributes): Model => Model::unguarded(fn (): Model => new $modelClass($attributes)),
        );

        return $created;
    }

    /**
     * The shared recursion behind fromArray() and the factory
     * `HasLtreeFactory::createTree()` mixin: for each node, build an
     * (unsaved) model instance via $creator, set its ltree parent, save it
     * (letting the observer compute the path from the now-persisted parent),
     * then recurse into its `children` with the new node as parent.
     *
     * Public so a factory's `createTree()` can delegate the nesting logic
     * here instead of duplicating it, while using its own factory-aware
     * $creator (which merges each node's explicit attributes over the
     * factory's `definition()`) instead of `new $modelClass()`.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  callable(array<string, mixed>): Model  $creator  builds (but
     *                                                          does not save) a model instance from a node's attributes
     * @return LtreeCollection<int, Model>
     */
    public static function withCreator(array $nodes, ?Model $parent, callable $creator): LtreeCollection
    {
        /** @var LtreeCollection<int, Model> $created */
        $created = new LtreeCollection;

        foreach ($nodes as $node) {
            /** @var list<array<string, mixed>> $childNodes */
            $childNodes = $node['children'] ?? [];
            unset($node['children']);

            $model = $creator($node);

            /** @var Model&Ltreeable $model */
            $model->setLtreeParent($parent);
            $model->save();

            $created->push($model);

            if ($childNodes !== []) {
                $created = $created->merge(self::withCreator($childNodes, $model, $creator));
            }
        }

        return $created;
    }
}
