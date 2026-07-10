<?php

declare(strict_types=1);

use Happenv\Ltree\Console\OptimizeCommand;
use Happenv\Ltree\Tests\DatabaseTestCase;
use Happenv\Ltree\Tests\Fixtures\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

function categoriesHasGistIndex(): bool
{
    $index = DB::selectOne(
        "SELECT 1 FROM pg_indexes WHERE tablename = 'categories' AND indexdef ILIKE '%gist%'",
    );

    return $index !== null;
}

it('creates a gist index, reindexes, and analyzes a table with no gist index', function () {
    Schema::create('categories', function ($table) {
        $table->id();
        $table->foreignId('parent_id')->nullable();
        $table->ltree('path')->nullable();
    });

    Category::create([]);

    // No gist index was created, so confirm that's really true before asserting on it.
    expect(categoriesHasGistIndex())->toBeFalse();

    $this->artisan('ltree:optimize', ['model' => Category::class])
        ->expectsOutputToContain('Ensured a gist index')
        ->expectsOutputToContain('Reindexed')
        ->expectsOutputToContain('Analyzed')
        ->assertSuccessful();

    expect(categoriesHasGistIndex())->toBeTrue();
});

it('actually issues REINDEX and ANALYZE statements (not just their info lines)', function () {
    Schema::create('categories', function ($table) {
        $table->id();
        $table->foreignId('parent_id')->nullable();
        $table->ltree('path')->nullable();
    });

    Category::create([]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->artisan('ltree:optimize', ['model' => Category::class])->assertSuccessful();

    // REINDEX/ANALYZE have no effect on query results, so pin that they were
    // genuinely issued via the emitted SQL — removing either $db->statement()
    // call drops it from the log.
    $sql = collect(DB::getQueryLog())->pluck('query')->implode(' | ');
    expect($sql)->toContain('REINDEX TABLE')
        ->and($sql)->toContain('ANALYZE');

    DB::disableQueryLog();
});

it('is idempotent when run again on an already-optimized table', function () {
    Schema::create('categories', function ($table) {
        $table->id();
        $table->foreignId('parent_id')->nullable();
        $table->ltree('path')->nullable();
    });

    Category::create([]);

    $this->artisan('ltree:optimize', ['model' => Category::class])->assertSuccessful();
    expect(categoriesHasGistIndex())->toBeTrue();

    $this->artisan('ltree:optimize', ['model' => Category::class])
        ->assertSuccessful();

    expect(categoriesHasGistIndex())->toBeTrue();
});

it('derives a normal-length index name from the table and column', function () {
    $command = new OptimizeCommand;

    expect($command->indexName('categories', 'path'))->toBe('categories_path_gist');
});

it('truncates a long table name to stay within PostgreSQL\'s 63-byte identifier limit', function () {
    $command = new OptimizeCommand;
    $table = str_repeat('a', 70);

    $indexName = $command->indexName($table, 'path');

    // Assert the exact string (not just length/prefix/suffix in isolation) so
    // any off-by-one in the truncation math (63 - strlen(suffix)) changes it.
    expect($indexName)->toBe(str_repeat('a', 53).'_path_gist')
        ->and(strlen($indexName))->toBeLessThanOrEqual(63)
        ->and($indexName)->toEndWith('_path_gist')
        ->and($indexName)->toStartWith(str_repeat('a', 53));
});

it('floors the table-name length at zero when the column alone exhausts the 63-byte limit', function () {
    $command = new OptimizeCommand;
    // A 60-char column makes the "_<col>_gist" suffix 66 bytes, so the available
    // table length is negative; the max(..., 0) floor must clamp substr()'s
    // length to 0 (never pass a negative length). max(neg, 1) or max(neg, -1)
    // would instead keep part of the table name, so this pins the exact result.
    $column = str_repeat('c', 60);

    expect($command->indexName('categories', $column))->toBe('_'.$column.'_gist');
});
