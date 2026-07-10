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

/**
 * root
 *  |- a
 *  |   |- a1
 *  |- b
 *
 * @return array{root: Category, a: Category, b: Category, a1: Category}
 */
function rebuildCommandTree(): array
{
    $root = Category::create([]);
    $a = Category::create(['parent_id' => $root->id]);
    $b = Category::create(['parent_id' => $root->id]);
    $a1 = Category::create(['parent_id' => $a->id]);

    return compact('root', 'a', 'b', 'a1');
}

it('restores corrupted paths and reports the changed row count', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'a1' => $a1] = rebuildCommandTree();

    // Corrupt two of the four rows' paths directly, bypassing the observer.
    DB::table('categories')->where('id', $a->id)->update(['path' => 'garbage']);
    DB::table('categories')->where('id', $a1->id)->update(['path' => 'also.garbage']);

    // Pre-state sanity check: confirm the corruption actually took.
    expect((string) DB::table('categories')->where('id', $a->id)->value('path'))->toBe('garbage')
        ->and((string) DB::table('categories')->where('id', $a1->id)->value('path'))->toBe('also.garbage');

    $this->artisan('ltree:rebuild', ['model' => Category::class])
        ->expectsOutputToContain('Rebuilt paths: 2 row(s) updated.')
        ->assertSuccessful();

    expect((string) $root->fresh()->path())->toBe("{$root->id}")
        ->and((string) $a->fresh()->path())->toBe("{$root->id}.{$a->id}")
        ->and((string) $b->fresh()->path())->toBe("{$root->id}.{$b->id}")
        ->and((string) $a1->fresh()->path())->toBe("{$root->id}.{$a->id}.{$a1->id}");
});

it('fails with a clear message when a parent_id cycle is present', function () {
    ['a' => $a, 'b' => $b] = rebuildCommandTree();

    // Introduce a cycle between two rows so rebuildPaths() cannot resolve them.
    DB::table('categories')->where('id', $a->id)->update(['parent_id' => $b->id]);
    DB::table('categories')->where('id', $b->id)->update(['parent_id' => $a->id]);

    $this->artisan('ltree:rebuild', ['model' => Category::class])
        ->expectsOutputToContain('Cycle detected')
        ->assertFailed();
});
