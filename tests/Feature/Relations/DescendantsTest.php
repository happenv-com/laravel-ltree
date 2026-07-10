<?php

declare(strict_types=1);

use Happenv\Ltree\Tests\DatabaseTestCase;
use Happenv\Ltree\Tests\Fixtures\Category;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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

function descTree(): array
{
    $root = Category::create([]);
    $a = Category::create(['parent_id' => $root->id]);
    $b = Category::create(['parent_id' => $root->id]);
    $a1 = Category::create(['parent_id' => $a->id]);

    return compact('root', 'a', 'b', 'a1');
}

it('resolves descendants and descendantsAndSelf lazily', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'a1' => $a1] = descTree();

    expect($root->descendants->pluck('id')->sort()->values()->all())->toBe([$a->id, $b->id, $a1->id])
        ->and($a->descendants->pluck('id')->all())->toBe([$a1->id])
        ->and($b->descendants)->toHaveCount(0)
        ->and($a1->descendants)->toHaveCount(0)
        ->and($root->descendantsAndSelf->pluck('id')->sort()->values()->all())->toBe([$root->id, $a->id, $b->id, $a1->id])
        ->and($a->descendantsAndSelf->pluck('id')->sort()->values()->all())->toBe([$a->id, $a1->id])
        ->and($a->descendants->pluck('id')->all())->not->toContain($a->id);
});

it('returns an empty relation (no query match) for a node without a persisted path, issuing zero queries', function () {
    Category::create([]); // seed a row so a fallen-through, unconstrained query would return > 0
    $orphan = new Category;

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect($orphan->path())->toBeNull()
        ->and($orphan->descendants)->toHaveCount(0)
        ->and(DB::getQueryLog())->toHaveCount(0);

    DB::flushQueryLog();

    expect($orphan->descendantsAndSelf)->toHaveCount(0)
        ->and(DB::getQueryLog())->toHaveCount(0);
});

it('constrains an unsaved model\'s descendants query to zero rows via the null-path guard', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'a1' => $a1] = descTree();
    $orphan = new Category;

    // Reaches addConstraints()'s `1 = 0` guard through the query-builder
    // terminal directly, bypassing getResults()' early return: without the
    // guard this would count every row in the table (4), not 0.
    expect($orphan->descendants()->count())->toBe(0)
        ->and($orphan->descendants()->get())->toHaveCount(0);
});

it('eager-loads descendants using the `<@ ANY` path-containment constraint in SQL, not just PHP-side matching', function () {
    ['root' => $root] = descTree();

    DB::flushQueryLog();
    DB::enableQueryLog();

    Category::query()->whereIn('id', [$root->id])->with('descendants')->get();

    $eagerQuery = collect(DB::getQueryLog())->last();

    expect($eagerQuery['query'])->toContain('"path" <@ ANY(?::ltree[])');
});

it('eager-loads leaving an unsaved batch member with a loaded empty descendants collection via initRelation', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'a1' => $a1] = descTree();
    $unsaved = new Category(['parent_id' => null]);

    // The null-path $unsaved is placed FIRST on purpose: match() skips it with
    // `continue`, and the following $root must STILL be matched. If that
    // `continue` were a `break`, matching would halt at $unsaved and $root
    // would keep its initRelation empty default — the $root assertion below
    // is what makes the null-path skip a `continue` rather than a `break`.
    $collection = new EloquentCollection([$unsaved, $root]);
    $collection->load('descendants');

    expect($unsaved->relationLoaded('descendants'))->toBeTrue()
        ->and($unsaved->descendants)->toHaveCount(0)
        ->and($root->relationLoaded('descendants'))->toBeTrue()
        ->and($root->descendants->pluck('id')->sort()->values()->all())->toBe([$a->id, $b->id, $a1->id]);
});

it('matches whereHas(descendants) filtered by a closure, requiring the aliased self-join table', function () {
    ['root' => $root, 'a' => $a, 'a1' => $a1] = descTree();

    expect(Category::whereHas('descendants', fn ($q) => $q->whereKey($a1->id))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$root->id, $a->id])->sort()->values()->all());
});

it('eager-loads descendants with exactly one extra query and correct multi-parent bucketing', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'a1' => $a1] = descTree();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $models = Category::query()->whereIn('id', [$root->id, $a->id])->with('descendants')->get();

    // One base SELECT for the whereIn(...) + one eager-constraint SELECT for
    // the `ANY(?::ltree[])` descendants query — never one query per parent.
    expect(DB::getQueryLog())->toHaveCount(2);

    $byId = $models->keyBy('id');

    expect($byId[$root->id]->relationLoaded('descendants'))->toBeTrue()
        ->and($byId[$root->id]->descendants->pluck('id')->sort()->values()->all())->toBe([$a->id, $b->id, $a1->id])
        ->and($byId[$a->id]->descendants->pluck('id')->all())->toBe([$a1->id]);
});

it('eager-loads descendantsAndSelf including each node itself', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'a1' => $a1] = descTree();

    $models = Category::query()->whereIn('id', [$root->id, $a->id])->with('descendantsAndSelf')->get();
    $byId = $models->keyBy('id');

    expect($byId[$root->id]->descendantsAndSelf->pluck('id')->sort()->values()->all())
        ->toBe([$root->id, $a->id, $b->id, $a1->id])
        ->and($byId[$a->id]->descendantsAndSelf->pluck('id')->sort()->values()->all())
        ->toBe([$a->id, $a1->id]);
});

it('matches whereHas(descendants) only for nodes that actually have descendants', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'a1' => $a1] = descTree();

    expect(Category::whereHas('descendants')->pluck('id')->sort()->values()->all())
        ->toBe([$root->id, $a->id]);
});
