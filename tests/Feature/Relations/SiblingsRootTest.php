<?php

declare(strict_types=1);

use Happenv\Ltree\Tests\DatabaseTestCase;
use Happenv\Ltree\Tests\Fixtures\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

beforeEach(function () {
    Schema::create('categories', function ($table) {
        $table->id();
        $table->foreignId('parent_id')->nullable();
        $table->ltree('path')->nullable();
    });
});

function siblingsRootTree(): array
{
    $root = Category::create([]);
    $a = Category::create(['parent_id' => $root->id]);
    $b = Category::create(['parent_id' => $root->id]);
    $a1 = Category::create(['parent_id' => $a->id]);
    $a2 = Category::create(['parent_id' => $a->id]);
    $b1 = Category::create(['parent_id' => $b->id]);

    return compact('root', 'a', 'b', 'a1', 'a2', 'b1');
}

it('resolves siblings lazily as same-parent rows excluding self', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'a1' => $a1, 'a2' => $a2, 'b1' => $b1] = siblingsRootTree();

    expect($a->siblings->pluck('id')->all())->toBe([$b->id])
        ->and($b->siblings->pluck('id')->all())->toBe([$a->id])
        ->and($a1->siblings->pluck('id')->all())->toBe([$a2->id])
        ->and($a2->siblings->pluck('id')->all())->toBe([$a1->id])
        ->and($b1->siblings)->toHaveCount(0) // lone child
        ->and($root->siblings)->toHaveCount(0) // null parent_id -> no siblings by definition
        ->and($a->siblings->pluck('id')->all())->not->toContain($a->id);
});

it('returns an empty siblings relation for a node without a persisted path/parent, issuing zero queries', function () {
    siblingsRootTree(); // seed rows so a fallen-through, unconstrained query would return > 0
    $orphan = new Category;

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect($orphan->path())->toBeNull()
        ->and($orphan->siblings)->toHaveCount(0)
        ->and(DB::getQueryLog())->toHaveCount(0);
});

it('constrains an unsaved model\'s siblings query to zero rows via the null-parentId guard', function () {
    siblingsRootTree();
    $orphan = new Category;

    // Reaches addConstraints()'s `1 = 0` guard through the query-builder
    // terminal directly, bypassing getResults()' early return: without the
    // guard this would count every row in the table (6), not 0.
    expect($orphan->siblings()->count())->toBe(0)
        ->and($orphan->siblings()->get())->toHaveCount(0);
});

it('eager-loads siblings using the `parent_id IN (...)` constraint in SQL, not just PHP-side matching', function () {
    ['a' => $a, 'b' => $b] = siblingsRootTree();

    DB::flushQueryLog();
    DB::enableQueryLog();

    Category::query()->whereIn('id', [$a->id, $b->id])->with('siblings')->get();

    $eagerQuery = collect(DB::getQueryLog())->last();

    expect($eagerQuery['query'])->toContain('"parent_id" in (');
});

it('eager-loads leaving a root batch member (null parent_id) with a loaded empty siblings collection via initRelation', function () {
    ['root' => $root, 'a' => $a, 'b' => $b] = siblingsRootTree();

    $models = Category::query()->whereIn('id', [$root->id, $a->id, $b->id])->with('siblings')->get();
    $byId = $models->keyBy('id');

    expect($byId[$root->id]->relationLoaded('siblings'))->toBeTrue()
        ->and($byId[$root->id]->siblings)->toHaveCount(0)
        ->and($byId[$a->id]->siblings->pluck('id')->all())->toBe([$b->id]);
});

it('matches whereHas(siblings) filtered by a closure, requiring the aliased self-join table', function () {
    ['a' => $a, 'b' => $b, 'a1' => $a1, 'a2' => $a2] = siblingsRootTree();

    expect(Category::whereHas('siblings', fn ($q) => $q->whereKey($b->id))->pluck('id')->sort()->values()->all())
        ->toBe([$a->id]);
});

it('eager-loads siblings with exactly one extra query and correct bucketing', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'a1' => $a1, 'a2' => $a2, 'b1' => $b1] = siblingsRootTree();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $models = Category::query()
        ->whereIn('id', [$a->id, $b->id, $a1->id, $a2->id, $b1->id, $root->id])
        ->with('siblings')
        ->get();

    // One base SELECT for the whereIn(...) + one eager-constraint SELECT for
    // the `parent_id IN (...)` siblings query — never one query per parent.
    expect(DB::getQueryLog())->toHaveCount(2);

    $byId = $models->keyBy('id');

    expect($byId[$a->id]->relationLoaded('siblings'))->toBeTrue()
        ->and($byId[$a->id]->siblings->pluck('id')->all())->toBe([$b->id])
        ->and($byId[$b->id]->siblings->pluck('id')->all())->toBe([$a->id])
        ->and($byId[$a1->id]->siblings->pluck('id')->all())->toBe([$a2->id])
        ->and($byId[$a2->id]->siblings->pluck('id')->all())->toBe([$a1->id])
        ->and($byId[$b1->id]->siblings)->toHaveCount(0)
        ->and($byId[$root->id]->siblings)->toHaveCount(0);
});

it('matches whereHas(siblings) only for nodes that actually have siblings', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'a1' => $a1, 'a2' => $a2, 'b1' => $b1] = siblingsRootTree();

    expect(Category::whereHas('siblings')->pluck('id')->sort()->values()->all())
        ->toBe(collect([$a->id, $b->id, $a1->id, $a2->id])->sort()->values()->all());
});

it('resolves root lazily: a deep node resolves to the depth-1 ancestor, a root resolves to itself', function () {
    ['root' => $root, 'a' => $a, 'a1' => $a1, 'a2' => $a2, 'b1' => $b1] = siblingsRootTree();

    expect($a1->root->is($root))->toBeTrue()
        ->and($a2->root->is($root))->toBeTrue()
        ->and($b1->root->is($root))->toBeTrue()
        ->and($a->root->is($root))->toBeTrue()
        ->and($root->root->is($root))->toBeTrue();
});

it('returns a null root for a node without a persisted path, issuing zero queries', function () {
    siblingsRootTree(); // seed rows so a fallen-through, unconstrained query would return a row
    $orphan = new Category;

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect($orphan->path())->toBeNull()
        ->and($orphan->root)->toBeNull()
        ->and(DB::getQueryLog())->toHaveCount(0);
});

it('constrains an unsaved model\'s root query to zero rows via the null-path guard', function () {
    siblingsRootTree();
    $orphan = new Category;

    // Reaches addConstraints()'s `1 = 0` guard through the query-builder
    // terminal directly, bypassing getResults()' early return: without the
    // guard this would count every row in the table (6), not 0.
    expect($orphan->root()->count())->toBe(0);
});

it('constrains a saved node\'s root query to exactly its own root row via the main path constraint', function () {
    ['a1' => $a1] = siblingsRootTree(); // 6 total rows in the table

    // Without the `path = ?::ltree` constraint, this would count every row
    // in the table (6), not just the single matching root row.
    expect($a1->root()->count())->toBe(1);
});

it('eager-loads root using the `= ANY` root-path constraint in SQL, not just PHP-side matching', function () {
    ['root' => $root, 'a1' => $a1] = siblingsRootTree();

    DB::flushQueryLog();
    DB::enableQueryLog();

    Category::query()->whereIn('id', [$a1->id])->with('root')->get();

    $eagerQuery = collect(DB::getQueryLog())->last();

    expect($eagerQuery['query'])->toContain('"path" = ANY(?::ltree[])');
});

it('eager-loads root using the deduplicated per-model root-path literal (array_keys), not raw boolean values', function () {
    $decoy = Category::create([]); // id 1, its own path is literally "1"
    ['root' => $root, 'a1' => $a1, 'b1' => $b1] = siblingsRootTree(); // root gets id 2+

    $models = Category::query()->whereIn('id', [$a1->id, $b1->id])->with('root')->get();
    $byId = $models->keyBy('id');

    expect($byId[$a1->id]->root?->id)->toBe($root->id)
        ->and($byId[$b1->id]->root?->id)->toBe($root->id)
        ->and($byId[$a1->id]->root?->id)->not->toBe($decoy->id);
});

it('eager-loads root with exactly one extra query and correct single-result matching', function () {
    ['root' => $root, 'a' => $a, 'a1' => $a1, 'b1' => $b1] = siblingsRootTree();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $models = Category::query()->whereIn('id', [$root->id, $a1->id, $b1->id])->with('root')->get();

    // One base SELECT for the whereIn(...) + one eager-constraint SELECT for
    // the candidate root rows — never one query per parent.
    expect(DB::getQueryLog())->toHaveCount(2);

    $byId = $models->keyBy('id');

    expect($byId[$root->id]->relationLoaded('root'))->toBeTrue()
        ->and($byId[$root->id]->root->is($root))->toBeTrue()
        ->and($byId[$a1->id]->root->is($root))->toBeTrue()
        ->and($byId[$b1->id]->root->is($root))->toBeTrue();
});

it('matches whereHas(root) for every node with a persisted path, correlated to the actual root row', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'a1' => $a1, 'a2' => $a2, 'b1' => $b1] = siblingsRootTree();

    $otherRoot = Category::create([]);
    $otherChild = Category::create(['parent_id' => $otherRoot->id]);

    // Every node in a tree resolves to a root, so an unfiltered whereHas('root')
    // matches everything with a persisted path (a root node's root is itself).
    expect(Category::whereHas('root')->pluck('id')->sort()->values()->all())
        ->toBe(
            collect([$root->id, $a->id, $b->id, $a1->id, $a2->id, $b1->id, $otherRoot->id, $otherChild->id])
                ->sort()->values()->all()
        );

    // A filtered whereHas('root', ...) proves the correlation is real (per-row,
    // not a constant-true existence check): only nodes whose root is $root match.
    expect(Category::whereHas('root', fn ($query) => $query->whereKey($root->id))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$root->id, $a->id, $b->id, $a1->id, $a2->id, $b1->id])->sort()->values()->all())
        ->and(Category::whereHas('root', fn ($query) => $query->whereKey($root->id))->pluck('id'))
        ->not->toContain($otherRoot->id, $otherChild->id);
});
