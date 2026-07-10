<?php

declare(strict_types=1);

use Happenv\Ltree\Events\Moving;
use Happenv\Ltree\Exceptions\InvalidMoveException;
use Happenv\Ltree\Exceptions\MissingSortColumnException;
use Happenv\Ltree\Tests\DatabaseTestCase;
use Happenv\Ltree\Tests\Fixtures\Category;
use Happenv\Ltree\Tests\Fixtures\OrderedCategory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

beforeEach(function () {
    Schema::create('ordered_categories', function ($table) {
        $table->id();
        $table->foreignId('parent_id')->nullable();
        $table->ltree('path')->nullable();
        $table->integer('sort_order')->nullable();
    });

    Schema::create('categories', function ($table) {
        $table->id();
        $table->foreignId('parent_id')->nullable();
        $table->ltree('path')->nullable();
    });
});

/**
 * root
 *  |- a (0)
 *  |- b (1)
 *  |- c (2)
 *
 * @return array{root: OrderedCategory, a: OrderedCategory, b: OrderedCategory, c: OrderedCategory}
 */
function orderedTree(): array
{
    $root = OrderedCategory::create([]);
    $a = OrderedCategory::create(['parent_id' => $root->id, 'sort_order' => 0]);
    $b = OrderedCategory::create(['parent_id' => $root->id, 'sort_order' => 1]);
    $c = OrderedCategory::create(['parent_id' => $root->id, 'sort_order' => 2]);

    return compact('root', 'a', 'b', 'c');
}

/**
 * @return list<int>
 */
function orderedChildIds(OrderedCategory $parent): array
{
    /** @var list<int> $ids */
    $ids = $parent->children()->orderBy('sort_order')->pluck('id')->all();

    return $ids;
}

it('appendChild attaches the child under the parent with the correct path and places it last', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'c' => $c] = orderedTree();

    $child = new OrderedCategory;
    $result = $root->appendChild($child);

    expect($result)->toBe($child)
        ->and($child->exists)->toBeTrue()
        ->and($child->parent_id)->toBe($root->id)
        ->and((string) $child->path())->toBe("{$root->id}.{$child->id}")
        ->and($child->sort_order)->toBe(3);

    expect(orderedChildIds($root))->toBe([$a->id, $b->id, $c->id, $child->id]);
});

it('appendChild forces parent_id to the real parent even when the child arrives with a stale value', function () {
    // attachChild's own `$child->setAttribute($parentColumn, $this->getKey())`
    // (as opposed to LtreeObserver::creating(), which redundantly fills the
    // parent column too, but only when it's still null) is the only thing
    // that GUARANTEES the parent column ends up correct: pre-seed a bogus
    // value the observer's null-guard would never touch, so only attachChild's
    // own unconditional overwrite can fix it.
    $root = Category::create([]);
    $child = new Category(['parent_id' => 999999]);

    $root->appendChild($child);

    expect($child->parent_id)->toBe($root->id);
});

it('appendChild nests the child under the correct parent via the transient parent alone, when no parent column is configured', function () {
    // Table deliberately has NO parent_id column at all: with parent_column
    // nulled out, attachChild()'s only way to tell LtreeObserver the child's
    // parent is the transient setLtreeParent() call — there is no parent_id
    // fallback for the observer to fall back on here.
    config(['ltree.parent_column' => null]);

    Schema::create('parentless_categories', function ($table) {
        $table->id();
        $table->ltree('path')->nullable();
    });

    $root = (new Category)->setTable('parentless_categories');
    $root->save();

    $child = (new Category)->setTable('parentless_categories');
    $result = $root->appendChild($child);

    expect($result)->toBe($child)
        ->and($child->exists)->toBeTrue()
        ->and((string) $child->path())->toBe("{$root->id}.{$child->id}");
});

it('appendChild places the child last (0) when the parent had no prior children', function () {
    $root = OrderedCategory::create([]);
    $child = new OrderedCategory;

    $root->appendChild($child);

    expect($child->sort_order)->toBe(0)
        ->and(orderedChildIds($root))->toBe([$child->id]);
});

it('prependChild attaches the child first, shifting existing children up, when ordered', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'c' => $c] = orderedTree();

    $child = new OrderedCategory;
    $result = $root->prependChild($child);

    expect($result)->toBe($child)
        ->and($child->exists)->toBeTrue()
        ->and($child->parent_id)->toBe($root->id)
        ->and((string) $child->path())->toBe("{$root->id}.{$child->id}")
        ->and($child->sort_order)->toBe(0);

    expect(orderedChildIds($root))->toBe([$child->id, $a->id, $b->id, $c->id]);

    expect($a->fresh()->sort_order)->toBe(1)
        ->and($b->fresh()->sort_order)->toBe(2)
        ->and($c->fresh()->sort_order)->toBe(3);
});

it('prependChild only bumps sort_order among the target parent\'s own children, leaving a second parent\'s children untouched', function () {
    // ltreeIncrementChildrenOrder() scopes its bulk increment to `WHERE
    // parent_column = $this->getKey()`. Seed a second, unrelated parent with
    // its own ordered children so that if that scope were ever dropped, the
    // increment would leak across the whole table instead of staying scoped
    // to $root's own children.
    $root = OrderedCategory::create([]);
    $a = OrderedCategory::create(['parent_id' => $root->id, 'sort_order' => 0]);
    $b = OrderedCategory::create(['parent_id' => $root->id, 'sort_order' => 1]);

    $otherRoot = OrderedCategory::create([]);
    $x = OrderedCategory::create(['parent_id' => $otherRoot->id, 'sort_order' => 0]);
    $y = OrderedCategory::create(['parent_id' => $otherRoot->id, 'sort_order' => 1]);

    $child = new OrderedCategory;
    $root->prependChild($child);

    expect($child->sort_order)->toBe(0)
        ->and($a->fresh()->sort_order)->toBe(1)
        ->and($b->fresh()->sort_order)->toBe(2);

    expect($x->fresh()->sort_order)->toBe(0)
        ->and($y->fresh()->sort_order)->toBe(1);
});

it('moveBefore reorders a sibling to appear before another, densely renumbering sort_order', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'c' => $c] = orderedTree();

    // a(0) b(1) c(2) -> move c before a => c(0) a(1) b(2)
    $result = $c->moveBefore($a);

    expect($result)->toBe($c);

    expect(orderedChildIds($root))->toBe([$c->id, $a->id, $b->id]);
    expect($c->fresh()->sort_order)->toBe(0)
        ->and($a->fresh()->sort_order)->toBe(1)
        ->and($b->fresh()->sort_order)->toBe(2)
        ->and($c->sort_order)->toBe(0);
});

it('moveAfter reorders a sibling to appear after another, densely renumbering sort_order', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'c' => $c] = orderedTree();

    // a(0) b(1) c(2) -> move a after c => b(0) c(1) a(2)
    $result = $a->moveAfter($c);

    expect($result)->toBe($a);

    expect(orderedChildIds($root))->toBe([$b->id, $c->id, $a->id]);
    expect($b->fresh()->sort_order)->toBe(0)
        ->and($c->fresh()->sort_order)->toBe(1)
        ->and($a->fresh()->sort_order)->toBe(2)
        ->and($a->sort_order)->toBe(2);
});

it('moveAfter splices the node immediately after the target, not one slot further, when siblings remain past it', function () {
    // With only 2 siblings left after excluding $this (as in the test
    // above), $index + 1 and a wrong $index + 2 both land past the end of
    // the array (array_splice clamps an out-of-range offset to the end), so
    // they'd produce an identical result there. A 4th sibling AFTER the
    // target is required to make the off-by-one actually land in a
    // different, wrong slot.
    $root = OrderedCategory::create([]);
    $a = OrderedCategory::create(['parent_id' => $root->id, 'sort_order' => 0]);
    $b = OrderedCategory::create(['parent_id' => $root->id, 'sort_order' => 1]);
    $c = OrderedCategory::create(['parent_id' => $root->id, 'sort_order' => 2]);
    $d = OrderedCategory::create(['parent_id' => $root->id, 'sort_order' => 3]);

    // a(0) b(1) c(2) d(3) -> move a after b => b(0) a(1) c(2) d(3)
    $result = $a->moveAfter($b);

    expect($result)->toBe($a);
    expect(orderedChildIds($root))->toBe([$b->id, $a->id, $c->id, $d->id]);
    expect($b->fresh()->sort_order)->toBe(0)
        ->and($a->fresh()->sort_order)->toBe(1)
        ->and($c->fresh()->sort_order)->toBe(2)
        ->and($d->fresh()->sort_order)->toBe(3);
});

it('moveFirst places the node at the front of its siblings', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'c' => $c] = orderedTree();

    $result = $c->moveFirst();

    expect($result)->toBe($c);
    expect(orderedChildIds($root))->toBe([$c->id, $a->id, $b->id]);
    expect($c->sort_order)->toBe(0);
});

it('moveLast places the node at the end of its siblings', function () {
    ['root' => $root, 'a' => $a, 'b' => $b, 'c' => $c] = orderedTree();

    $result = $a->moveLast();

    expect($result)->toBe($a);
    expect(orderedChildIds($root))->toBe([$b->id, $c->id, $a->id]);
    expect($a->sort_order)->toBe(2);
});

it('moveFirst/moveLast reorder ROOT-level siblings too, with no parent to look up', function () {
    // ltreeOrderedChildrenOf() is also reached with $parent === null for
    // root-level ordered moves (moveFirst()/moveLast() default the target
    // parent to $this->getLtreeParent(), which is null for a root node). It
    // must resolve `$parent?->getKey()` without dereferencing that null.
    $x = OrderedCategory::create(['sort_order' => 0]);
    $y = OrderedCategory::create(['sort_order' => 1]);
    $z = OrderedCategory::create(['sort_order' => 2]);

    $result = $z->moveFirst();

    expect($result)->toBe($z)
        ->and($z->sort_order)->toBe(0);

    $rootIds = OrderedCategory::whereNull('parent_id')->orderBy('sort_order')->pluck('id')->all();
    expect($rootIds)->toBe([$z->id, $x->id, $y->id]);

    $result = $x->moveLast();

    expect($result)->toBe($x)
        ->and($x->sort_order)->toBe(2);

    $rootIds = OrderedCategory::whereNull('parent_id')->orderBy('sort_order')->pluck('id')->all();
    expect($rootIds)->toBe([$z->id, $y->id, $x->id]);
});

it('moveBefore across a different parent reparents the node (path + parent_id change) and sets its order', function () {
    $root = OrderedCategory::create([]);
    $x = OrderedCategory::create(['parent_id' => $root->id, 'sort_order' => 0]);
    $y = OrderedCategory::create(['parent_id' => $root->id, 'sort_order' => 1]);
    $x1 = OrderedCategory::create(['parent_id' => $x->id, 'sort_order' => 0]);
    $y1 = OrderedCategory::create(['parent_id' => $y->id, 'sort_order' => 0]);
    $y2 = OrderedCategory::create(['parent_id' => $y->id, 'sort_order' => 1]);

    $result = $x1->moveBefore($y2);

    expect($result)->toBe($x1)
        ->and($x1->parent_id)->toBe($y->id)
        ->and((string) $x1->path())->toBe("{$root->id}.{$y->id}.{$x1->id}");

    expect(orderedChildIds($y))->toBe([$y1->id, $x1->id, $y2->id]);
    expect($x1->sort_order)->toBe(1)
        ->and($y1->fresh()->sort_order)->toBe(0)
        ->and($y2->fresh()->sort_order)->toBe(2);

    // x lost its only child and old parent's own ordering is untouched
    expect($x->children()->count())->toBe(0);
});

it('moveBefore reparents a root-level node under a sibling that has a parent', function () {
    // ltreeParentsMatch() short-circuits on `$a === null || $b === null` before
    // ever calling $a->is($b), specifically so it never dereferences a null
    // side. A root node (null parent) moving to sit beside a sibling that has
    // a real parent is the only way to exercise that one-side-null branch:
    // $this's own parent is null, $sibling's parent (root) is not.
    $root = OrderedCategory::create([]);
    $y1 = OrderedCategory::create(['parent_id' => $root->id, 'sort_order' => 0]);
    $y2 = OrderedCategory::create(['parent_id' => $root->id, 'sort_order' => 1]);
    $x = OrderedCategory::create([]); // root-level: no parent at all

    $result = $x->moveBefore($y2);

    expect($result)->toBe($x)
        ->and($x->parent_id)->toBe($root->id)
        ->and((string) $x->path())->toBe("{$root->id}.{$x->id}");

    expect(orderedChildIds($root))->toBe([$y1->id, $x->id, $y2->id]);
});

it('moveAfter across a different parent reparents the node and appends it after the sibling', function () {
    $root = OrderedCategory::create([]);
    $x = OrderedCategory::create(['parent_id' => $root->id, 'sort_order' => 0]);
    $y = OrderedCategory::create(['parent_id' => $root->id, 'sort_order' => 1]);
    $x1 = OrderedCategory::create(['parent_id' => $x->id, 'sort_order' => 0]);
    $y1 = OrderedCategory::create(['parent_id' => $y->id, 'sort_order' => 0]);

    $result = $x1->moveAfter($y1);

    expect($result)->toBe($x1)
        ->and($x1->parent_id)->toBe($y->id)
        ->and((string) $x1->path())->toBe("{$root->id}.{$y->id}.{$x1->id}");

    expect(orderedChildIds($y))->toBe([$y1->id, $x1->id]);
    expect($y1->fresh()->sort_order)->toBe(0)
        ->and($x1->sort_order)->toBe(1);
});

it('leaves parent_id/path AND sort_order fully unchanged when a Moving listener vetoes a cross-parent moveBefore', function () {
    // Cross-parent moveBefore first reparents (moveTo, which fires a
    // cancellable Moving event) and then renumbers sort_order among the new
    // siblings. A veto of the inner reparent must abort the WHOLE ordered
    // move atomically: no partial state where sort_order reflects the new
    // parent's list but parent_id/path still point at the old parent.
    $root = OrderedCategory::create([]);
    $x = OrderedCategory::create(['parent_id' => $root->id, 'sort_order' => 0]);
    $y = OrderedCategory::create(['parent_id' => $root->id, 'sort_order' => 1]);
    $x1 = OrderedCategory::create(['parent_id' => $x->id, 'sort_order' => 0]);
    $y1 = OrderedCategory::create(['parent_id' => $y->id, 'sort_order' => 0]);
    $y2 = OrderedCategory::create(['parent_id' => $y->id, 'sort_order' => 1]);

    $originalParentId = $x1->parent_id;
    $originalPath = (string) $x1->path();

    Event::listen(Moving::class, fn () => false);

    expect(fn () => $x1->moveBefore($y2))->toThrow(InvalidMoveException::class);

    $freshX1 = $x1->fresh();
    expect($freshX1->parent_id)->toBe($originalParentId)
        ->and((string) $freshX1->path())->toBe($originalPath)
        ->and($freshX1->sort_order)->toBe(0);

    // the target parent's own children are untouched too
    expect(orderedChildIds($y))->toBe([$y1->id, $y2->id]);
    expect($y1->fresh()->sort_order)->toBe(0)
        ->and($y2->fresh()->sort_order)->toBe(1);
});

it('throws MissingSortColumnException for every ordered move when no order column is configured', function () {
    // `Category` never overrides getLtreeOrderColumn(), and the package
    // default (config/ltree.php) is null out of the box, so no config
    // juggling is needed to reach the unordered path here.
    $root = Category::create([]);
    $a = Category::create(['parent_id' => $root->id]);
    $b = Category::create(['parent_id' => $root->id]);

    expect(fn () => $a->moveBefore($b))->toThrow(MissingSortColumnException::class)
        ->and(fn () => $a->moveAfter($b))->toThrow(MissingSortColumnException::class)
        ->and(fn () => $a->moveFirst())->toThrow(MissingSortColumnException::class)
        ->and(fn () => $a->moveLast())->toThrow(MissingSortColumnException::class);
});

it('appendChild and prependChild behave identically (no sort_order write) when no order column is configured', function () {
    config(['ltree.order_column' => null]);

    $root = Category::create([]);
    $childA = new Category;
    $childB = new Category;

    $root->appendChild($childA);
    $root->prependChild($childB);

    expect((string) $childA->path())->toBe("{$root->id}.{$childA->id}")
        ->and((string) $childB->path())->toBe("{$root->id}.{$childB->id}")
        ->and($root->children()->count())->toBe(2);
});

it('appendChild on a plain out-of-the-box model (no order column configured) just attaches, without crashing', function () {
    // Pins the Critical fix: `categories` has no sort_order column at all, so
    // if getLtreeOrderColumn() ever fell back to 'sort_order' again here,
    // attachChild() would try to UPDATE/INSERT a nonexistent column and this
    // test would fail with a SQL error rather than an assertion failure. Uses
    // the package's default config untouched (no config() call) to prove the
    // out-of-the-box path — not just a manually-nulled one.
    expect(config('ltree.order_column'))->toBeNull();

    $root = Category::create([]);
    $child = new Category;

    $result = $root->appendChild($child);

    expect($result)->toBe($child)
        ->and($child->exists)->toBeTrue()
        ->and($child->parent_id)->toBe($root->id)
        ->and((string) $child->path())->toBe("{$root->id}.{$child->id}");
});
