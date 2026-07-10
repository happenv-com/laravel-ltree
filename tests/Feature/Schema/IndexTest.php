<?php

declare(strict_types=1);

use Happenv\Ltree\Exceptions\UnsupportedIndexException;
use Happenv\Ltree\Tests\DatabaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

it('creates a gist index on an ltree column', function () {
    Schema::create('nodes', function ($table) {
        $table->id();
        $table->ltree('path');
        $table->gist('path');
    });

    $index = DB::selectOne(
        "SELECT indexdef FROM pg_indexes WHERE tablename = 'nodes' AND indexdef ILIKE '%using gist%'",
    );
    expect($index)->not->toBeNull()
        ->and($index->indexdef)->toContain('gist')
        ->and($index->indexdef)->toContain('path');
});

it('rejects gin on a scalar ltree column with a helpful error', function () {
    Schema::create('nodes', function ($table) {
        $table->ltree('path');
        $table->gin('path');
    });
})->throws(UnsupportedIndexException::class, 'GiST');
