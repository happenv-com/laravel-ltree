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

it('reports children/descendant counts and leaf state', function () {
    $root = Category::create([]);                          // path = id(root)
    $a = Category::create(['parent_id' => $root->id]);     // root.a
    $b = Category::create(['parent_id' => $root->id]);     // root.b
    $a1 = Category::create(['parent_id' => $a->id]);       // root.a.a1

    expect($root->countChildren())->toBe(2)                // a, b
        ->and($root->countDescendants())->toBe(3)          // a, b, a1
        ->and($root->hasChildren())->toBeTrue()
        ->and($root->hasDescendants())->toBeTrue()
        ->and($root->isLeaf())->toBeFalse();

    expect($a->countChildren())->toBe(1)                   // a1
        ->and($b->isLeaf())->toBeTrue()
        ->and($a1->isLeaf())->toBeTrue()
        ->and($a1->countDescendants())->toBe(0);

    // branch() = self + descendants
    expect($a->branch()->count())->toBe(2);                // a, a1
});

it('guards a null path against matching every persisted row', function () {
    $root = Category::create([]);                          // path = id(root), nlevel 1
    Category::create(['parent_id' => $root->id]);          // root.a, nlevel 2

    // With auto_update_path disabled, this row's path column stays NULL.
    config()->set('ltree.auto_update_path', false);
    $unset = Category::create([]);
    expect($unset->path())->toBeNull();

    // (string) null === '' — naively casting $this->path() before binding
    // it would bind the empty ltree '', which Postgres treats as the
    // ancestor of every path (nlevel 0 is a prefix of anything), silently
    // matching the whole table. The aggregate helpers guard the null-path
    // case explicitly and fall back to a structurally no-match query
    // instead, so an unsaved/unset model reports zero descendants/children.
    expect($unset->branch()->count())->toBe(0)
        ->and($unset->countDescendants())->toBe(0)
        ->and($unset->countChildren())->toBe(0)
        ->and($unset->hasChildren())->toBeFalse()
        ->and($unset->hasDescendants())->toBeFalse()
        ->and($unset->isLeaf())->toBeTrue();
});
