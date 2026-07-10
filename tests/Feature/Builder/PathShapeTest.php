<?php

declare(strict_types=1);

use Happenv\Ltree\Tests\DatabaseTestCase;
use Happenv\Ltree\Tests\Fixtures\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

beforeEach(function () {
    Schema::create('categories', function ($table) {
        $table->id();
        $table->ltree('path')->nullable();
    });
    // seed explicit slug-ish label paths directly (no auto-path needed here)
    DB::table('categories')->insert([
        ['path' => 'top'],
        ['path' => 'top.gaming'],
        ['path' => 'top.gaming.laptops'],
        ['path' => 'top.office.laptops'],
    ]);
});

it('filters by path prefix, suffix, and segment membership', function () {
    expect(Category::query()->wherePathStartsWith('top.gaming')->count())->toBe(2)      // top.gaming, top.gaming.laptops
        ->and(Category::query()->wherePathEndsWith('laptops')->count())->toBe(2)        // both *.laptops
        ->and(Category::query()->whereSegment('gaming')->count())->toBe(2);             // top.gaming, top.gaming.laptops
});
