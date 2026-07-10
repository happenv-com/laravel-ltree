<?php

declare(strict_types=1);

use Happenv\Ltree\Collections\LtreeCollection;
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
 * Seed a fixed-label tree by writing paths directly (labels, not ids), mirroring
 * the phase-06 LqueryTest pattern: create() then forceFill(['path' => ...])->save()
 * overwrites the id-based default path — the observer only writes the path on
 * create (creating/created), never on a later update, so the overwrite sticks.
 *
 * Top
 *  |- Top.Science
 *  |   |- Top.Science.Astronomy
 *  |   `- Top.Science.Physics
 *  `- Top.Hobbies
 *      `- Top.Hobbies.Amateurs_Astronomy
 *
 * @return array<string, Category>
 */
function seedCollectionTree(): array
{
    $paths = [
        'top' => 'Top',
        'science' => 'Top.Science',
        'astronomy' => 'Top.Science.Astronomy',
        'physics' => 'Top.Science.Physics',
        'hobbies' => 'Top.Hobbies',
        'amateurs' => 'Top.Hobbies.Amateurs_Astronomy',
    ];

    $models = [];
    foreach ($paths as $key => $path) {
        $model = Category::create([]);
        $model->forceFill(['path' => $path])->save();
        $models[$key] = $model;
    }

    return $models;
}

it('resolves models via a newCollection() override, so every query returns an LtreeCollection', function () {
    seedCollectionTree();

    expect(Category::all())->toBeInstanceOf(LtreeCollection::class)
        ->and(Category::query()->get())->toBeInstanceOf(LtreeCollection::class);
});

it('builds toTree() from a subset, nesting children only from within the set, and flatten() reverses it', function () {
    ['science' => $science, 'astronomy' => $astronomy, 'physics' => $physics, 'hobbies' => $hobbies] = seedCollectionTree();

    $set = Category::query()
        ->whereIn('id', [$science->id, $astronomy->id, $physics->id, $hobbies->id])
        ->orderBy('id')
        ->get();

    expect($set)->toBeInstanceOf(LtreeCollection::class);

    $tree = $set->toTree();

    expect($tree)->toBeInstanceOf(LtreeCollection::class)
        // Science and Hobbies are roots of this set (their parent, Top, isn't a
        // member); sorted lexically depth-first: Top.Hobbies before Top.Science.
        ->and($tree->pluck('id')->all())->toBe([$hobbies->id, $science->id]);

    $scienceNode = $tree->firstWhere('id', $science->id);
    $hobbiesNode = $tree->firstWhere('id', $hobbies->id);

    expect($scienceNode->children->pluck('id')->all())->toBe([$astronomy->id, $physics->id])
        // Amateurs_Astronomy is NOT in the set, so Hobbies has no nested children here.
        ->and($hobbiesNode->children)->toHaveCount(0);

    $flat = $tree->flatten();

    expect($flat)->toBeInstanceOf(LtreeCollection::class)
        ->and($flat->pluck('id')->all())->toBe([$hobbies->id, $science->id, $astronomy->id, $physics->id])
        ->and($flat)->toHaveCount(4);
});

it('flatten() is a no-op clone on a set that never went through toTree()', function () {
    ['astronomy' => $astronomy, 'physics' => $physics] = seedCollectionTree();

    $set = Category::query()->whereIn('id', [$astronomy->id, $physics->id])->orderBy('id')->get();

    $flat = $set->flatten();

    expect($flat)->toBeInstanceOf(LtreeCollection::class)
        ->and($flat->pluck('id')->all())->toBe([$astronomy->id, $physics->id])
        ->and($flat)->not->toBe($set);
});

it('flatten() never lazy-loads the DB children relation on an internal-node set', function () {
    // parent_id IS wired here, so the FK-based children() relation COULD hit the
    // database — flatten() must not. $child is an internal node with a real DB
    // child ($grand); flattening a flat set of [$child] must return only [$child],
    // issuing zero queries (a getRelationValue()-based flatten would pull $grand in).
    $root = Category::create([]);
    $child = Category::create(['parent_id' => $root->id]);
    Category::create(['parent_id' => $child->id]); // $grand

    $set = Category::query()->whereKey($child->id)->get();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $flat = $set->flatten();

    expect(DB::getQueryLog())->toHaveCount(0)
        ->and($flat->pluck('id')->all())->toBe([$child->id]);
    DB::disableQueryLog();
});

it('computes roots() and leaves() of an arbitrary subset', function () {
    ['science' => $science, 'astronomy' => $astronomy, 'physics' => $physics, 'hobbies' => $hobbies] = seedCollectionTree();

    $set = Category::query()
        ->whereIn('id', [$science->id, $astronomy->id, $physics->id, $hobbies->id])
        ->orderBy('id')
        ->get();

    expect($set->roots()->pluck('id')->all())->toBe([$science->id, $hobbies->id])
        ->and($set->leaves()->pluck('id')->all())->toBe([$astronomy->id, $physics->id, $hobbies->id]);
});

/**
 * toTree()/roots()/leaves() all read `$this->pathOf($m)?->parent()?->toString()`,
 * chaining two nullsafe operators. Each is load-bearing for a different edge
 * case, so a set exercising only "normal" (depth > 1, persisted) members never
 * proves either matters:
 *   - the FIRST `?->` guards a null pathOf() result — an unsaved member whose
 *     path column was never set (path() returns null because the attribute is
 *     simply absent, not cast).
 *   - the SECOND `?->` guards a null parent() result — a real, persisted
 *     nlevel=1 root path (LtreePath::parent() returns null at depth <= 1).
 * Mixing both into one set, alongside a normal non-root member, means every
 * iteration of the foreach/map/filter inside each method touches both edge
 * cases, so a hand-removed nullsafe on either side throws for at least one
 * member.
 */
it('treats a real root member and an unsaved (null-path) member as roots in toTree(), without throwing', function () {
    ['top' => $top, 'science' => $science] = seedCollectionTree();

    $unsaved = new Category; // never saved: the path attribute is unset entirely, so path() is null, not ''.

    $set = Category::query()->whereIn('id', [$top->id, $science->id])->orderBy('id')->get();
    $set->push($unsaved);

    $tree = $set->toTree();

    // Top has no parent in-set (it's a real nlevel=1 root) and the unsaved
    // member has no path at all (no parent either) — both are roots. Science's
    // parent ('Top') IS in-set, so it nests under Top instead of being a root.
    // $set was fetched fresh from the DB, so compare persisted members by id;
    // only the (never round-tripped) unsaved instance can be identity-compared.
    expect($tree)->toHaveCount(2)
        ->and($tree->pluck('id')->all())->toContain($top->id)
        ->and(in_array($unsaved, $tree->all(), true))->toBeTrue();

    $topNode = $tree->first(fn (Category $m): bool => $m->id === $top->id);

    expect($topNode->children->pluck('id')->all())->toBe([$science->id]);
});

it('treats a real root member and an unsaved (null-path) member as roots(), without throwing', function () {
    ['top' => $top, 'science' => $science] = seedCollectionTree();

    $unsaved = new Category;

    $set = Category::query()->whereIn('id', [$top->id, $science->id])->orderBy('id')->get();
    $set->push($unsaved);

    $roots = $set->roots();

    expect($roots)->toHaveCount(2)
        ->and($roots->pluck('id')->all())->toContain($top->id)
        ->and($roots->pluck('id')->all())->not->toContain($science->id)
        ->and(in_array($unsaved, $roots->all(), true))->toBeTrue();
});

it('treats a real root member and an unsaved (null-path) member correctly in leaves(), without throwing', function () {
    ['top' => $top, 'science' => $science] = seedCollectionTree();

    $unsaved = new Category;

    $set = Category::query()->whereIn('id', [$top->id, $science->id])->orderBy('id')->get();
    $set->push($unsaved);

    $leaves = $set->leaves();

    // Top parents Science in-set, so Top is not a leaf. Science has no in-set
    // children, so it is a leaf. The unsaved member has no path/parent, so it
    // cannot be anyone's parent either, and is treated as a leaf too.
    expect($leaves)->toHaveCount(2)
        ->and($leaves->pluck('id')->all())->toContain($science->id)
        ->and($leaves->pluck('id')->all())->not->toContain($top->id)
        ->and(in_array($unsaved, $leaves->all(), true))->toBeTrue();
});

it('sorts a subset lexically depth-first via sortTree()', function () {
    ['science' => $science, 'astronomy' => $astronomy, 'physics' => $physics, 'hobbies' => $hobbies] = seedCollectionTree();

    $set = Category::query()
        ->whereIn('id', [$science->id, $astronomy->id, $physics->id, $hobbies->id])
        ->orderBy('id')
        ->get();

    expect($set->sortTree()->pluck('id')->all())
        ->toBe([$hobbies->id, $science->id, $astronomy->id, $physics->id]);
});

it('computes descendants() of a subset in exactly one query, excluding the members themselves', function () {
    ['science' => $science, 'astronomy' => $astronomy, 'physics' => $physics, 'hobbies' => $hobbies, 'amateurs' => $amateurs] = seedCollectionTree();

    $set = Category::query()
        ->whereIn('id', [$science->id, $astronomy->id, $physics->id, $hobbies->id])
        ->orderBy('id')
        ->get();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $descendants = $set->descendants();

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and($descendants)->toBeInstanceOf(LtreeCollection::class)
        // Astronomy/Physics are strict descendants of Science, but Science,
        // Astronomy, Physics and Hobbies are themselves members of $set and must
        // be excluded — only Amateurs_Astronomy (a descendant of Hobbies) remains.
        ->and($descendants->pluck('id')->all())->toBe([$amateurs->id]);
});

it('computes ancestors() of a subset in exactly one query, deduplicated and excluding the members themselves', function () {
    ['top' => $top, 'science' => $science, 'astronomy' => $astronomy, 'hobbies' => $hobbies, 'amateurs' => $amateurs] = seedCollectionTree();

    $set = Category::query()->whereIn('id', [$astronomy->id, $amateurs->id])->orderBy('id')->get();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $ancestors = $set->ancestors();

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and($ancestors)->toBeInstanceOf(LtreeCollection::class)
        ->and($ancestors->pluck('id')->sort()->values()->all())
        ->toBe(collect([$top->id, $science->id, $hobbies->id])->sort()->values()->all());
});

it('returns empty collections from descendants()/ancestors() on an empty set, issuing zero queries', function () {
    seedCollectionTree();

    $empty = Category::query()->whereIn('id', [])->get();

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect($empty->descendants())->toHaveCount(0)
        ->and($empty->ancestors())->toHaveCount(0)
        ->and(DB::getQueryLog())->toHaveCount(0);
});

it('filters via whereDescendantOfAny/whereAncestorOfAny at the builder level, including self, with a 1=0 empty guard', function () {
    ['top' => $top, 'science' => $science, 'astronomy' => $astronomy, 'physics' => $physics, 'hobbies' => $hobbies, 'amateurs' => $amateurs] = seedCollectionTree();

    expect(Category::query()->whereDescendantOfAny([$science, $hobbies])->pluck('id')->sort()->values()->all())
        ->toBe(collect([$science->id, $astronomy->id, $physics->id, $hobbies->id, $amateurs->id])->sort()->values()->all())
        ->and(Category::query()->whereAncestorOfAny([$astronomy, $amateurs])->pluck('id')->sort()->values()->all())
        ->toBe(collect([$top->id, $science->id, $astronomy->id, $hobbies->id, $amateurs->id])->sort()->values()->all())
        ->and(Category::query()->whereDescendantOfAny([])->count())->toBe(0)
        ->and(Category::query()->whereAncestorOfAny([])->count())->toBe(0);
});
