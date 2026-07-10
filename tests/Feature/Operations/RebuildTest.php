<?php

declare(strict_types=1);

use Happenv\Ltree\Events\Rebuilding;
use Happenv\Ltree\Events\Rebuilt;
use Happenv\Ltree\Exceptions\LtreeException;
use Happenv\Ltree\Tests\DatabaseTestCase;
use Happenv\Ltree\Tests\Fixtures\Category;
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
function rebuildTree(): array
{
    $root = Category::create([]);
    $a = Category::create(['parent_id' => $root->id]);
    $b = Category::create(['parent_id' => $root->id]);
    $a1 = Category::create(['parent_id' => $a->id]);
    $a2 = Category::create(['parent_id' => $a->id]);

    return compact('root', 'a', 'b', 'a1', 'a2');
}

it('restores corrupted paths to match parent_id-derived truth and returns the changed count', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'a1' => $a1, 'a2' => $a2] = rebuildTree();

    // Corrupt three of the five rows' paths directly, bypassing the observer,
    // so they genuinely diverge from what parent_id says they should be.
    DB::table('categories')->where('id', $a->id)->update(['path' => 'garbage']);
    DB::table('categories')->where('id', $a1->id)->update(['path' => 'also.garbage']);
    DB::table('categories')->where('id', $b->id)->update(['path' => 'still.wrong']);

    // Pre-state sanity check: confirm the corruption actually took, via a raw query.
    expect((string) DB::table('categories')->where('id', $a->id)->value('path'))->toBe('garbage')
        ->and((string) DB::table('categories')->where('id', $a1->id)->value('path'))->toBe('also.garbage')
        ->and((string) DB::table('categories')->where('id', $b->id)->value('path'))->toBe('still.wrong');

    $changed = $root->rebuildPaths();

    expect($changed)->toBe(3);

    expect((string) $root->fresh()->path())->toBe("{$root->id}")
        ->and((string) $a->fresh()->path())->toBe("{$root->id}.{$a->id}")
        ->and((string) $b->fresh()->path())->toBe("{$root->id}.{$b->id}")
        ->and((string) $a1->fresh()->path())->toBe("{$root->id}.{$a->id}.{$a1->id}")
        ->and((string) $a2->fresh()->path())->toBe("{$root->id}.{$a->id}.{$a2->id}");
});

it('populates every path from parent_id alone when rebuilding an adjacency-only table (paths null)', function () {
    config(['ltree.auto_update_path' => false]);

    $root = Category::create(['parent_id' => null]);
    $a = Category::create(['parent_id' => $root->id]);
    $b = Category::create(['parent_id' => $root->id]);
    $a1 = Category::create(['parent_id' => $a->id]);
    $a2 = Category::create(['parent_id' => $a->id]);

    // Pre-state sanity check: with auto_update_path disabled, the observer
    // never touched the path column, so every row is genuinely null.
    expect(DB::table('categories')->whereNull('path')->count())->toBe(5);

    config(['ltree.auto_update_path' => true]);

    $changed = $root->rebuildPaths();

    expect($changed)->toBe(5);

    expect((string) $root->fresh()->path())->toBe("{$root->id}")
        ->and((string) $a->fresh()->path())->toBe("{$root->id}.{$a->id}")
        ->and((string) $b->fresh()->path())->toBe("{$root->id}.{$b->id}")
        ->and((string) $a1->fresh()->path())->toBe("{$root->id}.{$a->id}.{$a1->id}")
        ->and((string) $a2->fresh()->path())->toBe("{$root->id}.{$a->id}.{$a2->id}");
});

it('leaves already-correct paths untouched, returning 0, when nothing needs rebuilding', function () {
    ['root' => $root] = rebuildTree();

    $changed = $root->rebuildPaths();

    expect($changed)->toBe(0);
});

it('throws LtreeException and leaves data unchanged when a parent_id cycle is present', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'a1' => $a1, 'a2' => $a2] = rebuildTree();

    $originalRootPath = (string) $root->fresh()->path();
    $originalAPath = (string) $a->fresh()->path();
    $originalBPath = (string) $b->fresh()->path();
    $originalA1Path = (string) $a1->fresh()->path();
    $originalA2Path = (string) $a2->fresh()->path();

    // Introduce a 2-cycle: a1 <-> a2, unreachable from the real root.
    DB::table('categories')->where('id', $a1->id)->update(['parent_id' => $a2->id]);
    DB::table('categories')->where('id', $a2->id)->update(['parent_id' => $a1->id]);

    // Also corrupt an unrelated row's path so we can prove the whole
    // transaction rolled back, not just that the cyclic rows were skipped.
    DB::table('categories')->where('id', $b->id)->update(['path' => 'corrupted']);

    expect(fn () => $root->rebuildPaths())->toThrow(LtreeException::class);

    expect((string) $root->fresh()->path())->toBe($originalRootPath)
        ->and((string) $a->fresh()->path())->toBe($originalAPath)
        ->and($a1->fresh()->parent_id)->toBe($a2->id)
        ->and($a2->fresh()->parent_id)->toBe($a1->id)
        // paths for the cyclic rows are untouched (rolled back), not just skipped
        ->and((string) $a1->fresh()->path())->toBe($originalA1Path)
        ->and((string) $a2->fresh()->path())->toBe($originalA2Path)
        // the unrelated corrupted row's path was NOT fixed - the whole
        // transaction rolled back before any UPDATE could commit.
        ->and((string) DB::table('categories')->where('id', $b->id)->value('path'))->toBe('corrupted');
});

it('fires a cancellable Rebuilding event before rebuilding, and Rebuilt after a successful rebuild', function () {
    ['root' => $root, 'a' => $a] = rebuildTree();

    DB::table('categories')->where('id', $a->id)->update(['path' => 'garbage']);

    Event::fake([Rebuilding::class, Rebuilt::class]);

    $root->rebuildPaths();

    Event::assertDispatched(Rebuilding::class, fn (Rebuilding $event): bool => $event->node->is($root));
    Event::assertDispatched(Rebuilt::class, fn (Rebuilt $event): bool => $event->node->is($root));
});

it('cancels the rebuild, changing nothing and returning 0, when a Rebuilding listener returns false', function () {
    ['root' => $root, 'a' => $a] = rebuildTree();

    DB::table('categories')->where('id', $a->id)->update(['path' => 'garbage']);

    Event::listen(Rebuilding::class, fn () => false);

    $changed = $root->rebuildPaths();

    expect($changed)->toBe(0)
        ->and((string) DB::table('categories')->where('id', $a->id)->value('path'))->toBe('garbage');
});

it('throws LtreeException when the model has no configured parent column', function () {
    ['root' => $root] = rebuildTree();

    config(['ltree.parent_column' => null]);

    expect(fn () => $root->rebuildPaths())->toThrow(LtreeException::class);
});
