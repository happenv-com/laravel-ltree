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

it('resolves parent and children, and eager-loads them', function () {
    $root = Category::create([]);
    $a = Category::create(['parent_id' => $root->id]);
    $b = Category::create(['parent_id' => $root->id]);

    expect($a->parent->is($root))->toBeTrue()
        ->and($root->children->pluck('id')->sort()->values()->all())->toBe([$a->id, $b->id])
        ->and($root->parent)->toBeNull();

    // eager loading: exactly one base query + one eager query for children of
    // all roots — never one query per parent (no N+1).
    DB::flushQueryLog();
    DB::enableQueryLog();
    $loaded = Category::whereNull('parent_id')->with('children')->get();
    expect($loaded->first()->relationLoaded('children'))->toBeTrue()
        ->and($loaded->first()->children)->toHaveCount(2)
        ->and(DB::getQueryLog())->toHaveCount(2);
    DB::disableQueryLog();

    // whereHas
    expect(Category::whereHas('children')->pluck('id')->all())->toBe([$root->id]);
});
