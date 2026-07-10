<?php

declare(strict_types=1);

use Happenv\Ltree\Events\CascadeDeleted;
use Happenv\Ltree\Events\CascadeDeleting;
use Happenv\Ltree\Events\Renamed;
use Happenv\Ltree\Events\Renaming;
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
 *  |   |- a2
 *  |- b
 *
 * @return array{root: Category, a: Category, b: Category, a1: Category, a2: Category}
 */
function cascadeDeleteRenameTree(): array
{
    $root = Category::create([]);
    $a = Category::create(['parent_id' => $root->id]);
    $b = Category::create(['parent_id' => $root->id]);
    $a1 = Category::create(['parent_id' => $a->id]);
    $a2 = Category::create(['parent_id' => $a->id]);

    return compact('root', 'a', 'b', 'a1', 'a2');
}

// --- cascadeDelete / deleteBranch -----------------------------------------

it('deletes a node and its whole subtree, returns the deleted count, and leaves siblings untouched', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'a1' => $a1, 'a2' => $a2] = cascadeDeleteRenameTree();

    $deleted = $a->cascadeDelete();

    expect($deleted)->toBe(3)
        ->and(Category::find($a->id))->toBeNull()
        ->and(Category::find($a1->id))->toBeNull()
        ->and(Category::find($a2->id))->toBeNull();

    $freshRoot = $root->fresh();
    $freshB = $b->fresh();

    expect($freshRoot)->not->toBeNull()
        ->and((string) $freshRoot->path())->toBe((string) $root->id)
        ->and($freshB)->not->toBeNull()
        ->and((string) $freshB->path())->toBe("{$root->id}.{$b->id}");
});

it('is aliased by deleteBranch', function () {
    ['a' => $a, 'a1' => $a1, 'a2' => $a2] = cascadeDeleteRenameTree();

    $deleted = $a->deleteBranch();

    expect($deleted)->toBe(3)
        ->and(Category::find($a->id))->toBeNull()
        ->and(Category::find($a1->id))->toBeNull()
        ->and(Category::find($a2->id))->toBeNull();
});

it('fires CascadeDeleting with the full self-and-descendants collection before deleting, and CascadeDeleted with the deleted keys after', function () {
    ['a' => $a, 'a1' => $a1, 'a2' => $a2] = cascadeDeleteRenameTree();

    Event::fake([CascadeDeleting::class, CascadeDeleted::class]);

    $deleted = $a->cascadeDelete();

    expect($deleted)->toBe(3);

    $expectedKeys = collect([$a->id, $a1->id, $a2->id])->sort()->values()->all();

    Event::assertDispatched(
        CascadeDeleting::class,
        fn (CascadeDeleting $event): bool => $event->node->is($a)
            && collect($event->nodes->modelKeys())->sort()->values()->all() === $expectedKeys,
    );

    Event::assertDispatched(
        CascadeDeleted::class,
        fn (CascadeDeleted $event): bool => $event->node->is($a)
            && collect($event->keys)->sort()->values()->all() === $expectedKeys,
    );
});

it('cancels the cascade delete, deleting nothing and returning 0, when a CascadeDeleting listener returns false', function () {
    ['a' => $a, 'a1' => $a1, 'a2' => $a2] = cascadeDeleteRenameTree();

    Event::listen(CascadeDeleting::class, fn () => false);

    $deleted = $a->cascadeDelete();

    expect($deleted)->toBe(0)
        ->and(Category::find($a->id))->not->toBeNull()
        ->and(Category::find($a1->id))->not->toBeNull()
        ->and(Category::find($a2->id))->not->toBeNull();
});

// --- renameSegment -----------------------------------------------------

it('renames the node and cascades the new prefix into every descendant path, leaving siblings untouched', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'a1' => $a1, 'a2' => $a2] = cascadeDeleteRenameTree();

    $result = $a->renameSegment('renamed');

    expect($result)->toBe($a)
        ->and((string) $a->path())->toBe("{$root->id}.renamed");

    $freshA1 = $a1->fresh();
    $freshA2 = $a2->fresh();
    $freshB = $b->fresh();
    $freshRoot = $root->fresh();

    expect((string) $freshA1->path())->toBe("{$root->id}.renamed.{$a1->id}")
        ->and((string) $freshA2->path())->toBe("{$root->id}.renamed.{$a2->id}")
        ->and((string) $freshB->path())->toBe("{$root->id}.{$b->id}")
        ->and((string) $freshRoot->path())->toBe((string) $root->id);
});

it('normalizes an illegal label according to the configured normalizer before using it as the new path segment', function () {
    ['root' => $root, 'a' => $a, 'a1' => $a1] = cascadeDeleteRenameTree();

    $result = $a->renameSegment('foo-bar');

    expect($result)->toBe($a)
        ->and((string) $a->path())->toBe("{$root->id}.foo_bar");

    $freshA1 = $a1->fresh();
    expect((string) $freshA1->path())->toBe("{$root->id}.foo_bar.{$a1->id}");
});

it('aborts the rename, leaving paths unchanged, when a Renaming listener returns false', function () {
    ['root' => $root, 'a' => $a, 'a1' => $a1] = cascadeDeleteRenameTree();

    Event::listen(Renaming::class, fn () => false);

    $result = $a->renameSegment('nope');

    expect($result)->toBe($a)
        ->and((string) $a->path())->toBe("{$root->id}.{$a->id}");

    $freshA = $a->fresh();
    $freshA1 = $a1->fresh();

    expect((string) $freshA->path())->toBe("{$root->id}.{$a->id}")
        ->and((string) $freshA1->path())->toBe("{$root->id}.{$a->id}.{$a1->id}");
});

it('fires Renamed with the node and the normalized label after a successful rename', function () {
    ['a' => $a] = cascadeDeleteRenameTree();

    Event::fake([Renamed::class]);

    $a->renameSegment('new-label');

    Event::assertDispatched(
        Renamed::class,
        fn (Renamed $event): bool => $event->node->is($a) && $event->newLabel === 'new_label',
    );
});

it('rolls back all path changes when the rename transaction fails partway through', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'a1' => $a1, 'a2' => $a2] = cascadeDeleteRenameTree();

    $originalAPath = (string) $a->path();
    $originalA1Path = (string) $a1->path();
    $originalA2Path = (string) $a2->path();
    $originalBPath = (string) $b->path();

    // The bulk CASE-UPDATE inside renameSegment() rewrites the node's own
    // path AND every descendant's path in one statement. Block the
    // descendant's expected post-rename path with a CHECK constraint so the
    // statement fails partway through (after conceptually computing $a's new
    // row but before the whole statement commits), proving the change is
    // atomic rather than leaving $a renamed while a1/a2 keep stale paths.
    $blockedPath = "{$root->id}.renamed.{$a1->id}";
    DB::statement("ALTER TABLE categories ADD CONSTRAINT block_rename_a1 CHECK (path IS DISTINCT FROM '{$blockedPath}'::ltree)");

    expect(fn () => $a->renameSegment('renamed'))->toThrow(QueryException::class);

    $freshA = $a->fresh();
    $freshA1 = $a1->fresh();
    $freshA2 = $a2->fresh();
    $freshB = $b->fresh();

    expect((string) $freshA->path())->toBe($originalAPath)
        ->and((string) $freshA1->path())->toBe($originalA1Path)
        ->and((string) $freshA2->path())->toBe($originalA2Path)
        ->and((string) $freshB->path())->toBe($originalBPath);
});
