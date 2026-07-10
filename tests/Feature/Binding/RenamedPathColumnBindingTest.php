<?php

declare(strict_types=1);

use Happenv\Ltree\Tests\DatabaseTestCase;
use Happenv\Ltree\Tests\Fixtures\Category;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

/**
 * resolveRouteBinding()/resolveChildRouteBinding() special-case two possible
 * $field values: the model's CONFIGURED path column, and the literal string
 * 'path' — Laravel's implicit `{model:path}` binding syntax always passes
 * the literal route-parameter name as $field, which is 'path' regardless of
 * what the path column is actually named.
 *
 * Under the DEFAULT config (path_column === 'path') those two checks are
 * indistinguishable, and the fallback (`parent::resolve...Binding`) happens
 * to query the very same 'path' column either way — so mutating the `||` to
 * `&&`, or dropping the early return, produces no observable difference and
 * the mutant survives. Renaming the path column here breaks that
 * coincidence: with $field the literal 'path' and the real column named
 * 'mpath', the fallback would query a 'path' column that does not exist on
 * this table at all (a DB error), while the real code resolves correctly via
 * getLtreePathColumn() === 'mpath'.
 */
beforeEach(function () {
    config(['ltree.path_column' => 'mpath']);

    Schema::create('categories', function ($table) {
        $table->id();
        $table->foreignId('parent_id')->nullable();
        $table->ltree('mpath')->nullable();
    });
});

/**
 * Top
 *  `- Top.Science
 *
 * @return array<string, Category>
 */
function seedRenamedColumnTree(): array
{
    $top = Category::create([]);
    $top->forceFill(['mpath' => 'Top'])->save();

    $science = Category::create(['parent_id' => $top->id]);
    $science->forceFill(['mpath' => 'Top.Science'])->save();

    return ['top' => $top, 'science' => $science];
}

// --- resolveRouteBinding() --------------------------------------------------

it('resolves a route binding by path when the field is the renamed configured path column', function () {
    ['science' => $science] = seedRenamedColumnTree();

    $resolved = (new Category)->resolveRouteBinding('Top.Science', 'mpath');

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($science->id);
});

it('resolves a route binding by the renamed path column when the field is the literal "path", never the raw field name', function () {
    ['science' => $science] = seedRenamedColumnTree();

    // BooleanOrToBooleanAnd: 'path' === 'mpath' is false, so `&&` short-
    // circuits false and falls through. RemoveEarlyReturn: the if-branch
    // runs but doesn't return, also falling through. Either way the fallback
    // is `parent::resolveRouteBinding($value, 'path')`, which queries a
    // 'path' column this table doesn't have — a QueryException, not a match.
    $resolved = (new Category)->resolveRouteBinding('Top.Science', 'path');

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($science->id);
});

// --- resolveChildRouteBinding() --------------------------------------------

it('resolves a child route binding by the renamed configured path column when the field is that column name', function () {
    ['top' => $top, 'science' => $science] = seedRenamedColumnTree();

    $resolved = $top->resolveChildRouteBinding('child', 'Top.Science', 'mpath');

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($science->id);
});

it('resolves a child route binding by the renamed path column when the field is the literal "path", never the raw field name', function () {
    ['top' => $top, 'science' => $science] = seedRenamedColumnTree();

    // Same BooleanOrToBooleanAnd/RemoveEarlyReturn load-bearing check as
    // resolveRouteBinding() above, on the nested-resource path: the fallback
    // (`parent::resolveChildRouteBinding($childType, $value, 'path')`) would
    // query a nonexistent 'path' column on the children relation.
    $resolved = $top->resolveChildRouteBinding('child', 'Top.Science', 'path');

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($science->id);
});
