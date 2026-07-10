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

function ancestorsTree(): array
{
    $root = Category::create([]);
    $a = Category::create(['parent_id' => $root->id]);
    $b = Category::create(['parent_id' => $root->id]);
    $a1 = Category::create(['parent_id' => $a->id]);
    $b1 = Category::create(['parent_id' => $b->id]);

    return compact('root', 'a', 'b', 'a1', 'b1');
}

it('resolves ancestors and ancestorsAndSelf lazily, root-first', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'a1' => $a1] = ancestorsTree();

    expect($a1->ancestors->pluck('id')->all())->toBe([$root->id, $a->id])
        ->and($a->ancestors->pluck('id')->all())->toBe([$root->id])
        ->and($root->ancestors)->toHaveCount(0)
        ->and($a1->ancestorsAndSelf->pluck('id')->all())->toBe([$root->id, $a->id, $a1->id])
        ->and($a->ancestorsAndSelf->pluck('id')->all())->toBe([$root->id, $a->id])
        ->and($a1->ancestors->pluck('id')->all())->not->toContain($a1->id);
});

it('returns an empty relation (no query match) for a node without a persisted path, issuing zero queries', function () {
    ancestorsTree(); // seed rows so a fallen-through, unconstrained query would return > 0
    $orphan = new Category;

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect($orphan->path())->toBeNull()
        ->and($orphan->ancestors)->toHaveCount(0)
        ->and(DB::getQueryLog())->toHaveCount(0);

    DB::flushQueryLog();

    expect($orphan->ancestorsAndSelf)->toHaveCount(0)
        ->and(DB::getQueryLog())->toHaveCount(0);
});

it('constrains an unsaved model\'s ancestors query to zero rows via the null-path guard', function () {
    ancestorsTree();
    $orphan = new Category;

    // Reaches addConstraints()'s `1 = 0` guard through the query-builder
    // terminal directly, bypassing getResults()' early return: without the
    // guard this would count every row in the table (5), not 0.
    expect($orphan->ancestors()->count())->toBe(0)
        ->and($orphan->ancestors()->get())->toHaveCount(0);
});

it('orders ancestors root-first via an explicit depth ordering, not incidental row order', function () {
    ['root' => $root, 'a' => $a, 'a1' => $a1] = ancestorsTree();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $a1->ancestors;

    $lazyQuery = collect(DB::getQueryLog())->last();

    expect($lazyQuery['query'])->toContain('order by nlevel("path") asc');
});

it('eager-loads ancestors using the `@> ANY` path-containment constraint in SQL, not just PHP-side matching', function () {
    ['root' => $root, 'a' => $a] = ancestorsTree();

    DB::flushQueryLog();
    DB::enableQueryLog();

    Category::query()->whereIn('id', [$a->id])->with('ancestors')->get();

    $eagerQuery = collect(DB::getQueryLog())->last();

    expect($eagerQuery['query'])->toContain('"path" @> ANY(?::ltree[])');
});

it('matches whereHas(ancestors) filtered by a closure, requiring the aliased self-join table', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'a1' => $a1, 'b1' => $b1] = ancestorsTree();

    expect(Category::whereHas('ancestors', fn ($q) => $q->whereKey($root->id))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$a->id, $b->id, $a1->id, $b1->id])->sort()->values()->all());
});

it('eager-loads ancestors with exactly one extra query and correct multi-child bucketing', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'a1' => $a1, 'b1' => $b1] = ancestorsTree();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $models = Category::query()->whereIn('id', [$a1->id, $b1->id])->with('ancestors')->get();

    // One base SELECT for the whereIn(...) + one eager-constraint SELECT for
    // the `ANY(?::ltree[])` ancestors query — never one query per parent.
    expect(DB::getQueryLog())->toHaveCount(2);

    $byId = $models->keyBy('id');

    expect($byId[$a1->id]->relationLoaded('ancestors'))->toBeTrue()
        ->and($byId[$a1->id]->ancestors->pluck('id')->all())->toBe([$root->id, $a->id])
        ->and($byId[$b1->id]->ancestors->pluck('id')->all())->toBe([$root->id, $b->id]);
});

it('eager-loads ancestors with correct bucketing for a nested batch (parent and child both loaded)', function () {
    ['root' => $root, 'a' => $a, 'a1' => $a1] = ancestorsTree();

    $models = Category::query()->whereIn('id', [$a->id, $a1->id])->with('ancestors')->get();
    $byId = $models->keyBy('id');

    expect($byId[$a->id]->ancestors->pluck('id')->all())->toBe([$root->id])
        ->and($byId[$a1->id]->ancestors->pluck('id')->all())->toBe([$root->id, $a->id]);
});

it('eager-loads ancestorsAndSelf including each node itself, root-first', function () {
    ['root' => $root, 'a' => $a, 'a1' => $a1] = ancestorsTree();

    $models = Category::query()->whereIn('id', [$root->id, $a1->id])->with('ancestorsAndSelf')->get();
    $byId = $models->keyBy('id');

    expect($byId[$root->id]->ancestorsAndSelf->pluck('id')->all())->toBe([$root->id])
        ->and($byId[$a1->id]->ancestorsAndSelf->pluck('id')->all())->toBe([$root->id, $a->id, $a1->id]);
});

it('matches whereHas(ancestors) only for nodes that actually have ancestors', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'a1' => $a1, 'b1' => $b1] = ancestorsTree();

    expect(Category::whereHas('ancestors')->pluck('id')->sort()->values()->all())
        ->toBe(collect([$a->id, $b->id, $a1->id, $b1->id])->sort()->values()->all());
});
