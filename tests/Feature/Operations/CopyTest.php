<?php

declare(strict_types=1);

use Happenv\Ltree\Events\Copied;
use Happenv\Ltree\Events\Copying;
use Happenv\Ltree\Exceptions\LtreeException;
use Happenv\Ltree\Tests\DatabaseTestCase;
use Happenv\Ltree\Tests\Fixtures\Category;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
 *  |   |   |- a1x
 *  |   |- a2
 *  |- b
 *
 * @return array{root: Category, a: Category, b: Category, a1: Category, a2: Category, a1x: Category}
 */
function copyTree(): array
{
    $root = Category::create([]);
    $a = Category::create(['parent_id' => $root->id]);
    $b = Category::create(['parent_id' => $root->id]);
    $a1 = Category::create(['parent_id' => $a->id]);
    $a2 = Category::create(['parent_id' => $a->id]);
    $a1x = Category::create(['parent_id' => $a1->id]);

    return compact('root', 'a', 'b', 'a1', 'a2', 'a1x');
}

it('deep-copies a 3-level subtree under a new parent with new primary keys and correct new paths, leaving the original untouched', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'a1' => $a1, 'a2' => $a2, 'a1x' => $a1x] = copyTree();

    $countBefore = Category::count();

    $copyA = $a->copyTo($b);

    expect($copyA)->not->toBe($a)
        ->and($copyA->id)->not->toBe($a->id)
        ->and((string) $copyA->path())->toBe("{$root->id}.{$b->id}.{$copyA->id}")
        ->and($copyA->parent_id)->toBe($b->id);

    // the copied subtree has exactly 4 nodes (a, a1, a2, a1x): count doubles
    // for the subtree, i.e. the table grows by exactly that many new rows.
    expect(Category::count())->toBe($countBefore + 4);

    $copyChildren = Category::where('parent_id', $copyA->id)->get();
    expect($copyChildren)->toHaveCount(2);

    $copyA1 = $copyChildren->first(fn (Category $c) => $c->hasChildren());
    $copyA2 = $copyChildren->first(fn (Category $c) => ! $c->hasChildren());

    expect($copyA1)->not->toBeNull()
        ->and($copyA2)->not->toBeNull()
        ->and($copyA1->id)->not->toBe($a1->id)
        ->and($copyA2->id)->not->toBe($a2->id)
        ->and((string) $copyA1->path())->toBe("{$root->id}.{$b->id}.{$copyA->id}.{$copyA1->id}")
        ->and((string) $copyA2->path())->toBe("{$root->id}.{$b->id}.{$copyA->id}.{$copyA2->id}");

    $copyA1x = Category::where('parent_id', $copyA1->id)->sole();
    expect($copyA1x->id)->not->toBe($a1x->id)
        ->and((string) $copyA1x->path())->toBe("{$root->id}.{$b->id}.{$copyA->id}.{$copyA1->id}.{$copyA1x->id}");

    // the original subtree (and the rest of the tree) is completely untouched
    $freshA = $a->fresh();
    $freshA1 = $a1->fresh();
    $freshA2 = $a2->fresh();
    $freshA1x = $a1x->fresh();
    $freshB = $b->fresh();
    $freshRoot = $root->fresh();

    expect((string) $freshA->path())->toBe("{$root->id}.{$a->id}")
        ->and($freshA->parent_id)->toBe($root->id)
        ->and((string) $freshA1->path())->toBe("{$root->id}.{$a->id}.{$a1->id}")
        ->and((string) $freshA2->path())->toBe("{$root->id}.{$a->id}.{$a2->id}")
        ->and((string) $freshA1x->path())->toBe("{$root->id}.{$a->id}.{$a1->id}.{$a1x->id}")
        ->and((string) $freshB->path())->toBe("{$root->id}.{$b->id}")
        ->and((string) $freshRoot->path())->toBe((string) $root->id);
});

it('copies a subtree to become a new root when the target is null', function () {
    ['a' => $a] = copyTree();

    $copyA = $a->copyTo(null);

    expect((string) $copyA->path())->toBe((string) $copyA->id)
        ->and($copyA->id)->not->toBe($a->id)
        ->and($copyA->parent_id)->toBeNull()
        ->and($copyA->isRoot())->toBeTrue();
});

it('nests the copy correctly instead of flattening it when parent_column is null (parent resolved by path)', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'a1' => $a1, 'a2' => $a2, 'a1x' => $a1x] = copyTree();

    config(['ltree.parent_column' => null]);

    $copyA = $a->copyTo($b);

    expect((string) $copyA->path())->toBe("{$root->id}.{$b->id}.{$copyA->id}");

    // Path-based children/descendant lookups don't depend on parent_column,
    // so they're a parent_column-agnostic way to prove the copy actually
    // nested under its mapped parent copy rather than every non-root copy
    // flattening straight under $target (the bug: with parent_column null,
    // the old parent_id-keyed map always missed and fell back to null).
    $copyChildren = Category::childrenOf($copyA)->get();
    expect($copyChildren)->toHaveCount(2);

    $copyA1 = $copyChildren->first(fn (Category $c) => $c->hasChildren());
    $copyA2 = $copyChildren->first(fn (Category $c) => ! $c->hasChildren());

    expect($copyA1)->not->toBeNull()
        ->and($copyA2)->not->toBeNull()
        ->and($copyA1->id)->not->toBe($a1->id)
        ->and($copyA2->id)->not->toBe($a2->id)
        ->and((string) $copyA1->path())->toBe("{$root->id}.{$b->id}.{$copyA->id}.{$copyA1->id}")
        ->and((string) $copyA2->path())->toBe("{$root->id}.{$b->id}.{$copyA->id}.{$copyA2->id}");

    // one more level deep, and fetched fresh (a genuine round trip to
    // Postgres, not just the in-memory attribute set during the copy).
    $copyA1x = Category::childrenOf($copyA1)->sole();
    $freshCopyA1x = Category::find($copyA1x->id);
    expect($copyA1x->id)->not->toBe($a1x->id)
        ->and((string) $freshCopyA1x->path())->toBe("{$root->id}.{$b->id}.{$copyA->id}.{$copyA1->id}.{$copyA1x->id}");

    // the original subtree is untouched
    expect((string) $a1->fresh()->path())->toBe("{$root->id}.{$a->id}.{$a1->id}")
        ->and((string) $a1x->fresh()->path())->toBe("{$root->id}.{$a->id}.{$a1->id}.{$a1x->id}");
});

it('cancels the copy, creating nothing and returning $this, when a Copying listener returns false', function () {
    ['a' => $a, 'b' => $b] = copyTree();

    $countBefore = Category::count();

    Event::listen(Copying::class, fn () => false);

    $result = $a->copyTo($b);

    expect($result)->toBe($a)
        ->and(Category::count())->toBe($countBefore);
});

it('fires Copying with the node and target before, and Copied with the original and new root copy after, a successful copy', function () {
    ['a' => $a, 'b' => $b] = copyTree();

    Event::fake([Copying::class, Copied::class]);

    $copyA = $a->copyTo($b);

    Event::assertDispatched(
        Copying::class,
        fn (Copying $event): bool => $event->node->is($a) && $event->target?->is($b) === true,
    );

    Event::assertDispatched(
        Copied::class,
        fn (Copied $event): bool => $event->original->is($a) && $event->copy->is($copyA),
    );
});

it('assigns new labels matching the new primary keys throughout the copied subtree, since the label depends on the key', function () {
    ['a' => $a, 'b' => $b] = copyTree();

    $copyA = $a->copyTo($b);

    // every segment of the copy's own path is a *new* key, not any of the
    // original subtree's keys.
    $originalKeys = collect([$a->id])->map(fn ($id) => (string) $id);
    $copySegments = collect(explode('.', (string) $copyA->path()));

    expect($copySegments->intersect($originalKeys))->toBeEmpty()
        ->and($copySegments->last())->toBe((string) $copyA->id);
});

it('throws LtreeException when copying a node without a persisted path', function () {
    $detached = new Category(['id' => 999]);

    expect(fn () => $detached->copyTo(null))->toThrow(LtreeException::class);
});

it('rolls back all created rows when the copy transaction fails partway through', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'a1' => $a1, 'a2' => $a2, 'a1x' => $a1x] = copyTree();

    $countBefore = Category::count();

    // The categories table is freshly (re)created in beforeEach(), so its id
    // sequence is deterministic: the six copyTree() rows consume ids 1-6, so
    // the very first row copyTo() inserts (the subtree root copy, processed
    // first because it has the shallowest depth) gets the next id.
    $expectedCopyRootId = $a1x->id + 1;

    // Block any row from taking that id as its parent, forcing the second
    // insert (the first child copy) to fail *after* the root copy has
    // already been inserted and updated with its path, proving the whole
    // batch (including that already-inserted root copy) rolls back together.
    DB::statement("ALTER TABLE categories ADD CONSTRAINT block_copy_children CHECK (parent_id IS DISTINCT FROM {$expectedCopyRootId})");

    expect(fn () => $a->copyTo($b))->toThrow(QueryException::class);

    expect(Category::count())->toBe($countBefore)
        ->and(Category::find($expectedCopyRootId))->toBeNull();

    // original subtree still intact
    $freshA1 = $a1->fresh();
    $freshA2 = $a2->fresh();
    expect((string) $freshA1->path())->toBe("{$root->id}.{$a->id}.{$a1->id}")
        ->and((string) $freshA2->path())->toBe("{$root->id}.{$a->id}.{$a2->id}");
});
