<?php

declare(strict_types=1);

use Happenv\Ltree\Tests\DatabaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

it('creates an ltree column and an auto-maintained stored depth column', function () {
    Schema::create('nodes', function ($table) {
        $table->id();
        $table->ltree('path');
        $table->ltreeDepth('depth', 'path');
    });

    // stored generated column computes nlevel automatically
    DB::table('nodes')->insert(['path' => 'a.b.c']);
    $row = DB::table('nodes')->first();

    expect($row->path)->toBe('a.b.c')
        ->and($row->depth)->toBe(3);

    $isGenerated = DB::selectOne(
        "SELECT is_generated FROM information_schema.columns WHERE table_name = 'nodes' AND column_name = 'depth'",
    );
    expect($isGenerated->is_generated)->toBe('ALWAYS');
});

it('creates lquery and ltxtquery columns', function () {
    Schema::create('q', function ($table) {
        $table->id();
        $table->lquery('pattern');
        $table->ltxtquery('search');
    });

    expect(Schema::hasColumn('q', 'pattern'))->toBeTrue()
        ->and(Schema::hasColumn('q', 'search'))->toBeTrue();
});
