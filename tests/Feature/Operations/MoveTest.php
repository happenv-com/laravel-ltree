<?php

declare(strict_types=1);

use Happenv\Ltree\Events\Moved;
use Happenv\Ltree\Events\Moving;
use Happenv\Ltree\Exceptions\InvalidMoveException;
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
function moveTree(): array
{
    $root = Category::create([]);
    $a = Category::create(['parent_id' => $root->id]);
    $b = Category::create(['parent_id' => $root->id]);
    $a1 = Category::create(['parent_id' => $a->id]);
    $a2 = Category::create(['parent_id' => $a->id]);

    return compact('root', 'a', 'b', 'a1', 'a2');
}

it('reparents a subtree under a new parent, rewriting every descendant path, updating parent_id, and leaving siblings untouched', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'a1' => $a1, 'a2' => $a2] = moveTree();

    $result = $a->moveTo($b);

    expect($result)->toBe($a)
        ->and((string) $a->path())->toBe("{$root->id}.{$b->id}.{$a->id}")
        ->and($a->parent_id)->toBe($b->id);

    $freshA1 = $a1->fresh();
    $freshA2 = $a2->fresh();
    $freshB = $b->fresh();
    $freshRoot = $root->fresh();

    expect((string) $freshA1->path())->toBe("{$root->id}.{$b->id}.{$a->id}.{$a1->id}")
        ->and((string) $freshA2->path())->toBe("{$root->id}.{$b->id}.{$a->id}.{$a2->id}")
        // sibling `b` (the new parent) is otherwise untouched
        ->and((string) $freshB->path())->toBe("{$root->id}.{$b->id}")
        ->and($freshB->parent_id)->toBe($root->id)
        // root is untouched
        ->and((string) $freshRoot->path())->toBe((string) $root->id)
        ->and($freshRoot->parent_id)->toBeNull();
});

it('detaches a subtree so it becomes a root, dropping the ancestor prefix from every descendant', function () {
    ['a' => $a, 'a1' => $a1, 'a2' => $a2] = moveTree();

    $result = $a->detach();

    expect($result)->toBe($a)
        ->and((string) $a->path())->toBe((string) $a->id)
        ->and($a->parent_id)->toBeNull()
        ->and($a->isRoot())->toBeTrue();

    $freshA1 = $a1->fresh();
    $freshA2 = $a2->fresh();

    expect((string) $freshA1->path())->toBe("{$a->id}.{$a1->id}")
        ->and((string) $freshA2->path())->toBe("{$a->id}.{$a2->id}");
});

it('throws InvalidMoveException when moving a node under its own descendant', function () {
    ['root' => $root, 'a' => $a, 'a1' => $a1] = moveTree();

    expect(fn () => $a->moveTo($a1))->toThrow(InvalidMoveException::class);

    $fresh = $a->fresh();
    expect((string) $fresh->path())->toBe("{$root->id}.{$a->id}")
        ->and($fresh->parent_id)->toBe($root->id);
});

it('throws InvalidMoveException when moving a node under itself', function () {
    ['root' => $root, 'a' => $a] = moveTree();

    expect(fn () => $a->moveTo($a))->toThrow(InvalidMoveException::class);

    $fresh = $a->fresh();
    expect((string) $fresh->path())->toBe("{$root->id}.{$a->id}")
        ->and($fresh->parent_id)->toBe($root->id);
});

it('aborts the move, leaving paths unchanged, when a Moving listener returns false', function () {
    ['root' => $root, 'a' => $a, 'b' => $b] = moveTree();

    Event::listen(Moving::class, fn () => false);

    $result = $a->moveTo($b);

    expect($result)->toBe($a)
        ->and((string) $a->path())->toBe("{$root->id}.{$a->id}");

    $fresh = $a->fresh();
    expect((string) $fresh->path())->toBe("{$root->id}.{$a->id}")
        ->and($fresh->parent_id)->toBe($root->id);
});

it('fires Moved with the node and target after a successful move', function () {
    ['a' => $a, 'b' => $b] = moveTree();

    Event::fake([Moved::class]);

    $a->moveTo($b);

    Event::assertDispatched(
        Moved::class,
        fn (Moved $event): bool => $event->node->is($a) && $event->target?->is($b) === true,
    );
});

it('fires Moved with a null target after a successful detach', function () {
    ['a' => $a] = moveTree();

    Event::fake([Moved::class]);

    $a->detach();

    Event::assertDispatched(
        Moved::class,
        fn (Moved $event): bool => $event->node->is($a) && $event->target === null,
    );
});

it('rolls back all path changes when the transaction fails partway through', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'a1' => $a1, 'a2' => $a2] = moveTree();

    $originalAPath = (string) $a->path();
    $originalA1Path = (string) $a1->path();
    $originalA2Path = (string) $a2->path();
    $originalBPath = (string) $b->path();

    // Force the second statement inside moveTo()'s transaction (the parent_id
    // update) to fail after the first statement (the bulk path UPDATE) has
    // already run, proving both are rolled back together rather than the
    // path rewrite silently sticking.
    DB::statement("ALTER TABLE categories ADD CONSTRAINT block_reparent_to_b CHECK (parent_id IS DISTINCT FROM {$b->id})");

    expect(fn () => $a->moveTo($b))->toThrow(QueryException::class);

    $freshA = $a->fresh();
    $freshA1 = $a1->fresh();
    $freshA2 = $a2->fresh();
    $freshB = $b->fresh();

    expect((string) $freshA->path())->toBe($originalAPath)
        ->and($freshA->parent_id)->toBe($root->id)
        ->and((string) $freshA1->path())->toBe($originalA1Path)
        ->and((string) $freshA2->path())->toBe($originalA2Path)
        ->and((string) $freshB->path())->toBe($originalBPath);
});
