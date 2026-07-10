<?php

declare(strict_types=1);

use Happenv\Ltree\Collections\LtreeCollection;
use Happenv\Ltree\Tests\DatabaseTestCase;
use Happenv\Ltree\Tests\Fixtures\Category;
use Happenv\Ltree\ValueObjects\LtreePath;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
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
 * Top
 *  |- Top.Science
 *  |   |- Top.Science.Astronomy
 *  |   `- Top.Science.Physics
 *  `- Top.Hobbies
 *
 * Both `path` and `parent_id` are populated (unlike the phase-06/collection
 * fixtures, which only need `path`): resolveChildRouteBinding()'s default
 * fallback resolves through the FK-based children() relation, so the tree
 * must be wired both ways for these tests.
 *
 * @return array<string, Category>
 */
function seedBindingTree(): array
{
    $top = Category::create([]);
    $top->forceFill(['path' => 'Top'])->save();

    $science = Category::create(['parent_id' => $top->id]);
    $science->forceFill(['path' => 'Top.Science'])->save();

    $astronomy = Category::create(['parent_id' => $science->id]);
    $astronomy->forceFill(['path' => 'Top.Science.Astronomy'])->save();

    $physics = Category::create(['parent_id' => $science->id]);
    $physics->forceFill(['path' => 'Top.Science.Physics'])->save();

    $hobbies = Category::create(['parent_id' => $top->id]);
    $hobbies->forceFill(['path' => 'Top.Hobbies'])->save();

    return [
        'top' => $top,
        'science' => $science,
        'astronomy' => $astronomy,
        'physics' => $physics,
        'hobbies' => $hobbies,
    ];
}

// --- findByPath() / findByLtree() -----------------------------------------

it('finds a model by its raw string path via findByPath()', function () {
    ['science' => $science] = seedBindingTree();

    $found = Category::findByPath('Top.Science');

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($science->id);
});

it('returns null from findByPath() when nothing matches', function () {
    seedBindingTree();

    expect(Category::findByPath('Top.Nonexistent'))->toBeNull();
});

it('finds a model by an LtreePath value object via findByLtree()', function () {
    ['astronomy' => $astronomy] = seedBindingTree();

    $found = Category::findByLtree(new LtreePath('Top.Science.Astronomy'));

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($astronomy->id);
});

it('returns null from findByLtree() when nothing matches', function () {
    seedBindingTree();

    expect(Category::findByLtree(new LtreePath('Top.Nonexistent')))->toBeNull();
});

// --- findDescendantsOf() / findAncestorsOf() -------------------------------

it('finds descendants of a node as an LtreeCollection, excluding the node itself', function () {
    ['science' => $science, 'astronomy' => $astronomy, 'physics' => $physics] = seedBindingTree();

    $descendants = Category::findDescendantsOf($science);

    expect($descendants)->toBeInstanceOf(LtreeCollection::class)
        ->and($descendants->pluck('id')->sort()->values()->all())
        ->toBe(collect([$astronomy->id, $physics->id])->sort()->values()->all());
});

it('finds descendants of a node given as a path string', function () {
    ['astronomy' => $astronomy, 'physics' => $physics] = seedBindingTree();

    $descendants = Category::findDescendantsOf('Top.Science');

    expect($descendants)->toBeInstanceOf(LtreeCollection::class)
        ->and($descendants->pluck('id')->sort()->values()->all())
        ->toBe(collect([$astronomy->id, $physics->id])->sort()->values()->all());
});

it('finds ancestors of a node as an LtreeCollection, excluding the node itself', function () {
    ['top' => $top, 'science' => $science, 'astronomy' => $astronomy] = seedBindingTree();

    $ancestors = Category::findAncestorsOf($astronomy);

    expect($ancestors)->toBeInstanceOf(LtreeCollection::class)
        ->and($ancestors->pluck('id')->sort()->values()->all())
        ->toBe(collect([$top->id, $science->id])->sort()->values()->all());
});

it('finds ancestors of a node given as an LtreePath', function () {
    ['top' => $top, 'science' => $science, 'astronomy' => $astronomy] = seedBindingTree();

    $ancestors = Category::findAncestorsOf(new LtreePath((string) $astronomy->path()));

    expect($ancestors)->toBeInstanceOf(LtreeCollection::class)
        ->and($ancestors->pluck('id')->sort()->values()->all())
        ->toBe(collect([$top->id, $science->id])->sort()->values()->all());
});

// --- resolveRouteBinding() --------------------------------------------------

it('resolves a route binding by path when the field is the configured path column', function () {
    ['science' => $science] = seedBindingTree();

    $resolved = (new Category)->resolveRouteBinding('Top.Science', 'path');

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($science->id);
});

it('resolves a route binding by path when the field is the literal "path" string even if configured differently', function () {
    ['science' => $science] = seedBindingTree();

    $resolved = (new Category)->resolveRouteBinding('Top.Science', 'path');

    expect($resolved->id)->toBe($science->id);
});

it('returns null resolving a route binding by path when nothing matches', function () {
    seedBindingTree();

    expect((new Category)->resolveRouteBinding('Top.Nonexistent', 'path'))->toBeNull();
});

it('resolves a route binding by path when the value is a Stringable, not a literal string', function () {
    ['science' => $science] = seedBindingTree();

    // LtreePath implements Stringable. ltreeRouteValueToString() must accept
    // it via the `instanceof Stringable` branch — dropping to `is_scalar()`
    // (which is false for any object) would throw an LtreeException instead
    // of resolving.
    $resolved = (new Category)->resolveRouteBinding(new LtreePath('Top.Science'), 'path');

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($science->id);
});

it('resolves a route binding by path when the value is an integer scalar against a numeric-label path', function () {
    $node = Category::create([]);
    $node->forceFill(['path' => '123'])->save();

    // Route parameter values are `mixed` (Eloquent's own signature is
    // untyped); an int route value must resolve exactly like the equivalent
    // string. ltreeRouteValueToString() casts scalars via `(string) $value`
    // — dropping that cast returns the raw int from a `: string`-typed
    // method under strict_types, a TypeError, not a successful resolution.
    $resolved = (new Category)->resolveRouteBinding(123, 'path');

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($node->id);
});

it('falls back to the default id-based resolution when the field is not a path field', function () {
    ['hobbies' => $hobbies] = seedBindingTree();

    $resolved = (new Category)->resolveRouteBinding($hobbies->id, null);

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($hobbies->id);
});

it('resolveRouteBindingByPath() and resolveRouteBindingById() work as explicit helpers', function () {
    ['science' => $science] = seedBindingTree();

    $byPath = (new Category)->resolveRouteBindingByPath('Top.Science');
    $byId = (new Category)->resolveRouteBindingById($science->id);

    expect($byPath)->not->toBeNull()->and($byPath->id)->toBe($science->id)
        ->and($byId)->not->toBeNull()->and($byId->id)->toBe($science->id);

    expect((new Category)->resolveRouteBindingByPath('Top.Nonexistent'))->toBeNull()
        ->and((new Category)->resolveRouteBindingById(999999))->toBeNull();
});

// --- resolveChildRouteBinding() --------------------------------------------

it('resolves a child route binding by path, honouring the path field on nested resources', function () {
    ['top' => $top, 'science' => $science] = seedBindingTree();

    // childRouteBindingRelationshipName('child') => 'children', matching
    // HasLtree::children().
    $resolved = $top->resolveChildRouteBinding('child', 'Top.Science', 'path');

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($science->id);
});

it('falls back to the default child route binding resolution for non-path fields', function () {
    ['top' => $top, 'hobbies' => $hobbies] = seedBindingTree();

    $resolved = $top->resolveChildRouteBinding('child', $hobbies->id, null);

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($hobbies->id);
});

it('returns null resolving a child route binding by path when nothing matches', function () {
    ['top' => $top] = seedBindingTree();

    expect($top->resolveChildRouteBinding('child', 'Top.Nonexistent', 'path'))->toBeNull();
});

// --- end-to-end route resolution --------------------------------------------

it('resolves an implicit {model:path} route binding end-to-end through the HTTP kernel', function () {
    ['science' => $science] = seedBindingTree();

    Route::get('/c/{category:path}', function (Category $category) {
        return response()->json(['id' => $category->id, 'path' => (string) $category->path()]);
    })->middleware(SubstituteBindings::class);

    $response = $this->get('/c/Top.Science');

    $response->assertOk()->assertJson(['id' => $science->id, 'path' => 'Top.Science']);
});

it('returns a 404 for an implicit {model:path} route binding when nothing matches', function () {
    seedBindingTree();

    Route::get('/c2/{category:path}', function (Category $category) {
        return response()->json(['id' => $category->id]);
    })->middleware(SubstituteBindings::class);

    $response = $this->get('/c2/Top.Nonexistent');

    $response->assertNotFound();
});
