<?php

declare(strict_types=1);

use Happenv\Ltree\Tests\DatabaseTestCase;
use Happenv\Ltree\Tests\Fixtures\Category;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

beforeEach(function () {
    Schema::create('categories', function ($table) {
        $table->id();
        $table->foreignId('parent_id')->nullable();
        $table->ltree('path')->nullable();
    });
});

function tree(): array
{
    $root = Category::create([]);              // depth 1
    $a = Category::create(['parent_id' => $root->id]);   // 2
    $b = Category::create(['parent_id' => $root->id]);   // 2
    $a1 = Category::create(['parent_id' => $a->id]);     // 3

    return compact('root', 'a', 'b', 'a1');
}

it('filters by root, leaf, and depth', function () {
    ['root' => $root, 'b' => $b, 'a1' => $a1] = tree();

    expect(Category::query()->whereRoot()->pluck('id')->all())->toBe([$root->id])
        ->and(Category::query()->whereLeaf()->pluck('id')->sort()->values()->all())->toBe([$b->id, $a1->id])
        ->and(Category::query()->whereDepth(2)->count())->toBe(2)
        ->and(Category::query()->whereBetweenDepth(1, 2)->count())->toBe(3);
});
