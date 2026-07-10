<?php

declare(strict_types=1);

use Happenv\Ltree\Tests\DatabaseTestCase;
use Happenv\Ltree\Tests\Fixtures\Category;
use Happenv\Ltree\ValueObjects\LtreePath;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

beforeEach(function () {
    Schema::create('categories', function ($table) {
        $table->id();
        $table->foreignId('parent_id')->nullable();
        $table->ltree('path')->nullable();
    });
});

it('supports depth extras, ordering, withDepth, commonAncestor, tapPath', function () {
    $root = Category::create([]);
    $a = Category::create(['parent_id' => $root->id]);
    $a1 = Category::create(['parent_id' => $a->id]);

    expect(Category::query()->maxDepth(2)->count())->toBe(2)          // root, a
        ->and(Category::query()->minDepth(2)->count())->toBe(2)       // a, a1
        ->and(Category::query()->whereWithinDepthOf($root, 1)->count())->toBe(2); // root, a (<= depth+1)

    // withDepth adds a depth column; orderByDepth orders by nlevel
    $ordered = Category::query()->withDepth()->orderByDepth('desc')->get();
    expect($ordered->first()->depth)->toBe(3)
        ->and($ordered->first()->id)->toBe($a1->id);

    // orderByDepth must lowercase its direction argument before comparing:
    // an uppercase 'DESC' must sort identically to 'desc' (a comparison
    // against the raw, un-lowercased input would fail to match 'desc' and
    // silently fall back to ascending order).
    $orderedUpper = Category::query()->withDepth()->orderByDepth('DESC')->get();
    expect($orderedUpper->first()->depth)->toBe(3)
        ->and($orderedUpper->first()->id)->toBe($a1->id);

    // commonAncestor of the two leaves' branch
    $lca = Category::query()->whereKey([$a->id, $a1->id])->commonAncestor();
    expect($lca)->toBeInstanceOf(LtreePath::class)
        ->and($lca->toString())->toBe((string) $root->path().'.'.$a->id);

    // tapPath returns the builder
    $count = 0;
    Category::query()->tapPath(function ($q) use (&$count) {
        $count++;
    })->count();
    expect($count)->toBe(1);
});

it('computes commonAncestor across divergent branches without corrupting the builder', function () {
    $root = Category::create([]);
    $a = Category::create(['parent_id' => $root->id]);
    $b = Category::create(['parent_id' => $root->id]);
    $a1 = Category::create(['parent_id' => $a->id]);

    // divergence branch (b under root, a1 under a) -> common ancestor is root (exercises the lca branch)
    expect((string) Category::query()->whereKey([$b->id, $a1->id])->commonAncestor())
        ->toBe((string) $root->path());

    // commonAncestor must NOT mutate the builder it is called on (it clones the base query)
    $q = Category::query()->whereKey([$a->id, $a1->id])->orderByDesc('id');
    $q->commonAncestor();
    expect($q->pluck('id')->all())->toBe([$a1->id, $a->id]);
});
