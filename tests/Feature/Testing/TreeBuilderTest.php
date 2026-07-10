<?php

declare(strict_types=1);

use Happenv\Ltree\Collections\LtreeCollection;
use Happenv\Ltree\Testing\TreeBuilder;
use Happenv\Ltree\Tests\DatabaseTestCase;
use Happenv\Ltree\Tests\Fixtures\Category;
use Happenv\Ltree\Tests\Fixtures\FactoryCategory;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

beforeEach(function () {
    Schema::create('categories', function ($table) {
        $table->id();
        $table->foreignId('parent_id')->nullable();
        $table->ltree('path')->nullable();
    });
    Schema::create('factory_categories', function ($table) {
        $table->id();
        $table->foreignId('parent_id')->nullable();
        $table->ltree('path')->nullable();
        $table->string('name')->nullable();
    });
});

/**
 * Canonical TreeBuilder node-array shape:
 *
 *   Root A
 *    |- A1
 *    |   |- AA
 *    |   `- AB
 *    `- A2
 *   Root B
 *
 * Flat pre-order creation: A, A1, AA, AB, A2, B.
 *
 * @return list<array<string, mixed>>
 */
function threeLevelTreeNodes(): array
{
    return [
        ['children' => [
            ['children' => [
                [],
                [],
            ]],
            [],
        ]],
        [],
    ];
}

it('builds a 3-level tree with correct parent_id links and observer-computed paths, returning a flat LtreeCollection', function () {
    $flat = TreeBuilder::fromArray(Category::class, threeLevelTreeNodes());

    expect($flat)->toBeInstanceOf(LtreeCollection::class)
        ->and($flat)->toHaveCount(6);

    [$a, $a1, $aa, $ab, $a2, $b] = $flat->all();

    // Root A
    expect($a->parent_id)->toBeNull()
        ->and($a->isRoot())->toBeTrue()
        ->and($a->depth())->toBe(1)
        ->and($a->path()->toString())->toBe((string) $a->id);

    // A1, child of A
    expect($a1->parent_id)->toBe($a->id)
        ->and($a1->isRoot())->toBeFalse()
        ->and($a1->depth())->toBe(2)
        ->and($a1->path()->toString())->toBe($a->id.'.'.$a1->id);

    // AA, grandchild of A via A1
    expect($aa->parent_id)->toBe($a1->id)
        ->and($aa->depth())->toBe(3)
        ->and($aa->path()->toString())->toBe($a->id.'.'.$a1->id.'.'.$aa->id);

    // AB, grandchild of A via A1
    expect($ab->parent_id)->toBe($a1->id)
        ->and($ab->depth())->toBe(3)
        ->and($ab->path()->toString())->toBe($a->id.'.'.$a1->id.'.'.$ab->id);

    // A2, second child of A (sibling of A1)
    expect($a2->parent_id)->toBe($a->id)
        ->and($a2->depth())->toBe(2)
        ->and($a2->path()->toString())->toBe($a->id.'.'.$a2->id);

    // Root B, a second root
    expect($b->parent_id)->toBeNull()
        ->and($b->isRoot())->toBeTrue()
        ->and($b->depth())->toBe(1)
        ->and($b->path()->toString())->toBe((string) $b->id);

    // Persisted, not just in memory.
    expect(Category::find($aa->id)->path()->toString())->toBe($a->id.'.'.$a1->id.'.'.$aa->id);
});

it('nests a whole fromArray() tree under an explicit $parent', function () {
    $existingRoot = Category::create([]);

    $flat = TreeBuilder::fromArray(Category::class, [
        ['children' => [[]]],
        [],
    ], $existingRoot);

    expect($flat)->toHaveCount(3);

    [$child, $grandchild, $secondChild] = $flat->all();

    expect($child->parent_id)->toBe($existingRoot->id)
        ->and($child->depth())->toBe(2)
        ->and($grandchild->parent_id)->toBe($child->id)
        ->and($grandchild->depth())->toBe(3)
        ->and($secondChild->parent_id)->toBe($existingRoot->id)
        ->and($secondChild->depth())->toBe(2);
});

it('returns an empty LtreeCollection for an empty node list', function () {
    $flat = TreeBuilder::fromArray(Category::class, []);

    expect($flat)->toBeInstanceOf(LtreeCollection::class)
        ->and($flat)->toHaveCount(0);
});

it('builds a tree via the factory hasTree() mixin, merging explicit node attributes over the factory definition', function () {
    $flat = FactoryCategory::factory()->createTree([
        ['name' => 'custom-root', 'children' => [
            [],
            ['name' => 'custom-child'],
        ]],
    ]);

    expect($flat)->toBeInstanceOf(LtreeCollection::class)
        ->and($flat)->toHaveCount(3);

    [$root, $defaultChild, $customChild] = $flat->all();

    expect($root)->toBeInstanceOf(FactoryCategory::class)
        ->and($root->name)->toBe('custom-root')
        ->and($root->isRoot())->toBeTrue()
        ->and($root->depth())->toBe(1);

    expect($defaultChild->parent_id)->toBe($root->id)
        ->and($defaultChild->name)->toBe('default-category')
        ->and($defaultChild->depth())->toBe(2);

    expect($customChild->parent_id)->toBe($root->id)
        ->and($customChild->name)->toBe('custom-child')
        ->and($customChild->depth())->toBe(2);

    // Persisted, not just in memory.
    expect(FactoryCategory::find($customChild->id)->path()->toString())
        ->toBe($root->id.'.'.$customChild->id);
});

it('attaches a subtree beneath each created parent via the fluent hasTree() mixin', function () {
    // hasTree(...) chains before create(): the root is factory-created, then an
    // afterCreating hook builds the node array UNDER it (parent saved first, so
    // its path is computed before the subtree is created).
    $root = FactoryCategory::factory()->hasTree([
        ['name' => 'a', 'children' => [[], []]],
        [],
    ])->create();

    expect($root)->toBeInstanceOf(FactoryCategory::class)
        ->and($root->isRoot())->toBeTrue();

    // strict descendants of the root: 'a' + its two children + the lone leaf = 4
    $descendants = FactoryCategory::query()->whereDescendantOf($root)->get();
    expect($descendants)->toHaveCount(4);

    // and every one of them really nests under the root's path
    $descendants->each(fn ($d) => expect($d->path()->toString())->toStartWith($root->id.'.'));
});
