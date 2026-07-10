<?php

declare(strict_types=1);

use Happenv\Ltree\Builders\LtreeBuilder;
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

it('exposes model query scopes', function () {
    $root = Category::create([]);
    $a = Category::create(['parent_id' => $root->id]);
    $a1 = Category::create(['parent_id' => $a->id]);

    expect(Category::roots())->toBeInstanceOf(LtreeBuilder::class)
        ->and(Category::roots()->count())->toBe(1)
        ->and(Category::leaves()->count())->toBe(1)                 // a1
        ->and(Category::descendantsOf($root)->count())->toBe(2)     // a, a1
        ->and(Category::childrenOf($root)->count())->toBe(1)        // a
        ->and(Category::ancestorsOf($a1)->count())->toBe(2);        // root, a
});

it('exposes the siblingsOf scope', function () {
    $root = Category::create([]);
    $a = Category::create(['parent_id' => $root->id]);
    $b = Category::create(['parent_id' => $root->id]);

    expect(Category::siblingsOf($a))->toBeInstanceOf(LtreeBuilder::class)
        ->and(Category::siblingsOf($a)->pluck('id')->all())->toBe([$b->id]);
});

it('does not treat an unsaved (null-path) model as ancestor of everything', function () {
    Category::create([]);
    $ghost = new Category; // no path
    expect($ghost->countDescendants())->toBe(0)
        ->and($ghost->hasDescendants())->toBeFalse()
        ->and($ghost->isLeaf())->toBeTrue();
});
