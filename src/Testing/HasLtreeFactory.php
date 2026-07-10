<?php

declare(strict_types=1);

namespace Happenv\Ltree\Testing;

use Happenv\Ltree\Collections\LtreeCollection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Mix into a model's Factory (`use HasLtreeFactory;` alongside
 * `extends Factory<TModel>`) to build a whole tree of factory-generated
 * models in one call — the factory analogue of `Testing\TreeBuilder::fromArray()`.
 *
 * Two entry points:
 *  - fluent `hasTree([...])` — mirrors Laravel's `has()`: chain it before a
 *    terminal `create()`, and each created parent gets the subtree built
 *    beneath it. It sidesteps Laravel 12's `Factory::has()`/`Relationship`
 *    machinery (which drives real relationships and always saves the parent
 *    before the child's `createFor()`, clashing with ltree's
 *    `setLtreeParent()`-before-`save()` observer ordering) by using an
 *    `afterCreating` hook — which runs once the parent IS saved (and its path
 *    computed), so `TreeBuilder` can then create the subtree under it.
 *  - terminal `createTree([...])` — build a standalone tree straight from a
 *    factory instance (no surrounding parent).
 *
 * @mixin Factory<Model>
 */
trait HasLtreeFactory
{
    /**
     * Fluent: attach a subtree beneath each parent this factory creates. The
     * subtree is built in an `afterCreating` hook (parent already saved with a
     * computed path), via `TreeBuilder::fromArray()`.
     *
     * @param  list<array<string, mixed>>  $nodes
     */
    public function hasTree(array $nodes): static
    {
        return $this->afterCreating(function (Model $parent) use ($nodes): void {
            TreeBuilder::fromArray($this->modelName(), $nodes, $parent);
        });
    }

    /**
     * Terminal: build a tree from the canonical `TreeBuilder::fromArray()`
     * node-array shape, using this factory — its current state chain plus
     * each node's explicit attributes — to generate every node's attributes
     * (merged over `definition()`, exactly like `Factory::create()`/`make()`
     * merge any other explicit attribute array over the definition).
     *
     * Delegates the actual nesting/parent-linking/save/recursion to
     * `TreeBuilder::withCreator()` so that logic lives in exactly one place;
     * this method only supplies the factory-aware node "creator".
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return LtreeCollection<int, Model>
     */
    public function createTree(array $nodes): LtreeCollection
    {
        return TreeBuilder::withCreator($nodes, null, function (array $attributes): Model {
            /** @var Model $model */
            $model = $this->count(null)->make($attributes);

            return $model;
        });
    }
}
