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

/**
 * Seed deterministic named labels. The auto-path observer only writes `path`
 * on creating/created (never on update), so overwriting it with forceFill+save
 * after create is safe and sticks. Returns the 'Top' root for node arguments.
 */
function seedDsl(): Category
{
    $rows = [];
    foreach (['Top', 'Top.Science', 'Top.Science.Astronomy', 'Top.Science.Physics', 'Top.Hobbies'] as $p) {
        $row = Category::create([]);
        $row->forceFill(['path' => $p])->save();
        $rows[$p] = $row;
    }

    return $rows['Top'];
}

it('composes constraints in one grouped WHERE via wherePath', function () {
    $top = seedDsl();

    // descendants of Top, at depth 3, whose labels contain Astronomy
    $result = Category::query()
        ->wherePath(fn ($path) => $path
            ->descendantOf($top)
            ->depth(3)
            ->contains('Astronomy'))
        ->pluck('path')->map->__toString()->all();

    expect($result)->toBe(['Top.Science.Astronomy']);
});

it('emits wherePath constraints as one parenthesised group and combines with an outer orWhere', function () {
    seedDsl();

    // ( matches Top.Science.* AND depth 3 )  OR  matches Top.Hobbies
    $builder = Category::query()
        ->wherePath(fn ($path) => $path->matches('Top.Science.*')->depth(3))
        ->orWherePathMatches('Top.Hobbies');

    // wherePath must wrap its constraints in a parenthesised group before the
    // outer OR. With AND-only verbs the group is semantically redundant (AND
    // binds tighter than OR), so a result-only assertion cannot catch its
    // removal — assert the compiled SQL actually emits the group. Without
    // grouping the only ')' is nlevel(...)'s, never followed by ' or '.
    expect($builder->toSql())->toContain(') or ');

    expect($builder->pluck('path')->map->__toString()->all())
        ->toEqualCanonicalizing(['Top.Science.Astronomy', 'Top.Science.Physics', 'Top.Hobbies']);
});

it('applies descendantOf and matches as real, load-bearing constraints', function () {
    seedDsl();
    $science = Category::query()->wherePathMatches('Top.Science')->first();

    // descendantOf: without the delegation wherePath adds no constraint and all
    // five rows would return — this pins that descendantOf actually constrains.
    expect(Category::query()->wherePath(fn ($p) => $p->descendantOf($science))->pluck('path')->map->__toString()->all())
        ->toEqualCanonicalizing(['Top.Science.Astronomy', 'Top.Science.Physics']);

    // matches: likewise load-bearing on its own.
    expect(Category::query()->wherePath(fn ($p) => $p->matches('*.Astronomy'))->pluck('path')->map->__toString()->all())
        ->toBe(['Top.Science.Astronomy']);
});

it('exposes the lquery/ltxtquery verbs through the DSL', function () {
    seedDsl();

    expect(Category::query()->wherePath(fn ($p) => $p->matchesAny(['*.Astronomy', '*.Physics']))->count())->toBe(2)
        ->and(Category::query()->wherePath(fn ($p) => $p->containsAny(['Astronomy', 'Hobbies']))->count())->toBe(2)
        ->and(Category::query()->wherePath(fn ($p) => $p->startsWith('Top.Science')->leaf())->count())->toBe(2);
});
