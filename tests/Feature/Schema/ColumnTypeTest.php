<?php

declare(strict_types=1);

use Happenv\Ltree\Tests\DatabaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

function columnType(string $table, string $column): string
{
    $row = DB::selectOne(
        'SELECT udt_name FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
        [$table, $column],
    );

    return $row->udt_name;
}

it('compiles ltree, lquery and ltxtquery column types via grammar macros', function () {
    Schema::create('nodes', function ($table) {
        $table->id();
        $table->addColumn('ltree', 'path');
        $table->addColumn('lquery', 'q');
        $table->addColumn('ltxtquery', 'tq');
    });

    expect(columnType('nodes', 'path'))->toBe('ltree')
        ->and(columnType('nodes', 'q'))->toBe('lquery')
        ->and(columnType('nodes', 'tq'))->toBe('ltxtquery');
});
