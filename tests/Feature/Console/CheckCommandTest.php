<?php

declare(strict_types=1);

use Happenv\Ltree\Tests\DatabaseTestCase;
use Happenv\Ltree\Tests\Fixtures\Category;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

// A real Eloquent model that deliberately does NOT use HasLtree, to exercise
// the "does not use the HasLtree trait" branch of ResolvesLtreeModel.
class PlainEloquentModelForCheckCommandTest extends Model
{
    protected $table = 'categories';

    protected $guarded = [];
}

/**
 * root
 *  |- a
 *      |- a1
 *
 * @return array{root: Category, a: Category, a1: Category}
 */
function checkTree(): array
{
    $root = Category::create([]);
    $a = Category::create(['parent_id' => $root->id]);
    $a1 = Category::create(['parent_id' => $a->id]);

    return compact('root', 'a', 'a1');
}

it('passes every check on a valid tree with a gist index', function () {
    Schema::create('categories', function ($table) {
        $table->id();
        $table->foreignId('parent_id')->nullable();
        $table->ltree('path')->nullable();
        $table->gist('path');
    });

    checkTree();

    // Pin every all-clear message individually — a generic 'ltree'
    // assertion is satisfied by several unrelated lines (e.g. the
    // extension-installed message), so it alone can't detect a single
    // removed info() call among the others.
    $this->artisan('ltree:check', ['model' => Category::class])
        ->expectsOutputToContain('extension is installed')
        ->expectsOutputToContain('GiST index present')
        ->expectsOutputToContain('No NULL paths')
        ->expectsOutputToContain('No orphaned paths')
        ->expectsOutputToContain('No dangling')
        ->expectsOutputToContain('are consistent')
        ->expectsOutputToContain('ltree:check passed')
        ->assertSuccessful();
});

it('fails and reports an orphaned path when a node references a non-existent parent path', function () {
    Schema::create('categories', function ($table) {
        $table->id();
        $table->foreignId('parent_id')->nullable();
        $table->ltree('path')->nullable();
        $table->gist('path');
    });

    checkTree();

    // Insert a row directly (bypassing the HasLtree observer) whose path
    // implies a parent ("999999") that has no row with that path at all —
    // a genuine orphan regardless of what parent_id says.
    DB::table('categories')->insert(['path' => '999999.888888', 'parent_id' => null]);

    // Sanity check the seeded row really is what we think it is.
    expect(DB::table('categories')->where('path', '999999.888888')->exists())->toBeTrue();

    // A single expectsOutputToContain() per distinct output line: Laravel's
    // console-testing mock resolves each printed line to at most one matching
    // expectation, so two substrings that both match the SAME line (e.g. a
    // generic 'orphan' alongside this precise one) would leave one of them
    // permanently unfulfilled even though the text is genuinely present.
    $this->artisan('ltree:check', ['model' => Category::class])
        ->expectsOutputToContain('orphaned path(s)')
        ->assertFailed();
});

it('fails and reports a mismatch when path and parent_id disagree', function () {
    Schema::create('categories', function ($table) {
        $table->id();
        $table->foreignId('parent_id')->nullable();
        $table->ltree('path')->nullable();
        $table->gist('path');
    });

    $root1 = Category::create([]);
    $root2 = Category::create([]);
    $child = Category::create(['parent_id' => $root1->id]);

    // Re-point parent_id at root2 directly (bypassing the observer), leaving
    // path untouched — path still encodes root1 as the parent, so path and
    // parent_id now disagree.
    DB::table('categories')->where('id', $child->id)->update(['parent_id' => $root2->id]);

    expect(DB::table('categories')->where('id', $child->id)->value('parent_id'))->toBe($root2->id);

    $this->artisan('ltree:check', ['model' => Category::class])
        ->expectsOutputToContain('does not match')
        ->assertFailed();
});

it('succeeds but warns when the table has no gist index', function () {
    Schema::create('categories', function ($table) {
        $table->id();
        $table->foreignId('parent_id')->nullable();
        $table->ltree('path')->nullable();
    });

    checkTree();

    // No gist index was created, so confirm that's really true before asserting on it.
    $index = DB::selectOne(
        "SELECT 1 FROM pg_indexes WHERE tablename = 'categories' AND indexdef ILIKE '%gist%'",
    );
    expect($index)->toBeNull();

    $this->artisan('ltree:check', ['model' => Category::class])
        ->expectsOutputToContain('No GiST index')
        ->assertSuccessful();
});

it('fails with a clear error for a class that is not an Eloquent model', function () {
    $this->artisan('ltree:check', ['model' => 'NoSuchClassAtAllForCheckCommandTest'])
        ->expectsOutputToContain('is not an Eloquent model')
        ->assertFailed();
});

it('fails with a clear error for an existing class that is not an Eloquent model at all', function () {
    // Distinct from the "no such class" case above: stdClass exists, so this
    // exercises the `is_subclass_of` half of the guard, not just `class_exists`.
    // With BooleanOrToBooleanAnd (`&&` instead of `||`) this would instead fall
    // through to instantiate stdClass and fail later with "does not use the
    // HasLtree trait" — a different, misleading message.
    $this->artisan('ltree:check', ['model' => stdClass::class])
        ->expectsOutputToContain('is not an Eloquent model')
        ->assertFailed();
});

it('fails with a clear error for an Eloquent model that does not use HasLtree', function () {
    $this->artisan('ltree:check', ['model' => PlainEloquentModelForCheckCommandTest::class])
        ->expectsOutputToContain('does not use the HasLtree trait')
        ->assertFailed();
});

it('fails cleanly (no stack trace) when the table has not been migrated', function () {
    // No Schema::create — the categories table does not exist.
    $this->artisan('ltree:check', ['model' => Category::class])
        ->expectsOutputToContain('does not exist')
        ->assertFailed();
});

it('does not count a gist index that is on a different column', function () {
    Schema::create('categories', function ($table) {
        $table->id();
        $table->foreignId('parent_id')->nullable();
        $table->ltree('path')->nullable();
        $table->ltree('other_path')->nullable();
        $table->gist('other_path'); // gist index on a DIFFERENT ltree column
    });

    checkTree();

    // A gist index exists on the table, but NOT on "path" — the command must
    // still warn about the missing index on the path column (not false-match).
    $this->artisan('ltree:check', ['model' => Category::class])
        ->expectsOutputToContain('No GiST index')
        ->assertSuccessful();
});

it('fails and reports a dangling parent_id that references a missing row', function () {
    Schema::create('categories', function ($table) {
        $table->id();
        $table->foreignId('parent_id')->nullable();
        $table->ltree('path')->nullable();
        $table->gist('path');
    });

    $root = Category::create([]);
    // Point parent_id at a row that does not exist, bypassing the observer.
    DB::table('categories')->where('id', $root->id)->update(['parent_id' => 999999]);

    $this->artisan('ltree:check', ['model' => Category::class])
        ->expectsOutputToContain('references a missing row')
        ->assertFailed();
});

it('fails and reports a NULL path on a persisted row', function () {
    Schema::create('categories', function ($table) {
        $table->id();
        $table->foreignId('parent_id')->nullable();
        $table->ltree('path')->nullable();
        $table->gist('path');
    });

    DB::table('categories')->insert(['path' => null, 'parent_id' => null]);

    $this->artisan('ltree:check', ['model' => Category::class])
        ->expectsOutputToContain('with a NULL path')
        ->expectsOutputToContain('found integrity issues')
        ->assertFailed();
});
