<?php

declare(strict_types=1);

use Happenv\Ltree\Exceptions\LtreeException;
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

it('filters by structural relationship to a node', function () {
    $root = Category::create([]);
    $a = Category::create(['parent_id' => $root->id]);
    $b = Category::create(['parent_id' => $root->id]);
    $a1 = Category::create(['parent_id' => $a->id]);

    // descendants of root (exclude self): a, b, a1
    expect(Category::query()->whereDescendantOf($root)->count())->toBe(3)
        // ancestors of a1 (exclude self): root, a
        ->and(Category::query()->whereAncestorOf($a1)->pluck('id')->sort()->values()->all())->toBe([$root->id, $a->id])
        // children of root (depth+1): a, b
        ->and(Category::query()->whereChildOf($root)->count())->toBe(2)
        // parent of a1: a
        ->and(Category::query()->whereParentOf($a1)->pluck('id')->all())->toBe([$a->id])
        // siblings of a (same parent, exclude self): b
        ->and(Category::query()->whereSiblingOf($a)->pluck('id')->all())->toBe([$b->id]);

    // accepts a raw path string / LtreePath too
    expect(Category::query()->whereDescendantOf((string) $root->path())->count())->toBe(3);
});

it('accepts an actual LtreePath instance as the predicate node', function () {
    $root = Category::create([]);
    $a = Category::create(['parent_id' => $root->id]);
    Category::create(['parent_id' => $a->id]);

    $rootPath = new LtreePath((string) $root->path());

    expect($rootPath)->toBeInstanceOf(LtreePath::class)
        ->and(Category::query()->whereDescendantOf($rootPath)->count())->toBe(2);
});

it('rejects an empty path when building a structural predicate', function () {
    Category::create([]);

    expect(fn () => Category::query()->whereDescendantOf(''))
        ->toThrow(LtreeException::class, 'Cannot build an ltree predicate from an empty path.');
});
