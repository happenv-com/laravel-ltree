<?php

declare(strict_types=1);

use Happenv\Ltree\Tests\DatabaseTestCase;
use Illuminate\Support\Facades\DB;

uses(DatabaseTestCase::class);

it('connects to postgresql with the ltree extension available', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    $version = DB::selectOne("SELECT extversion FROM pg_extension WHERE extname = 'ltree'");
    expect($version)->not->toBeNull()
        ->and($version->extversion)->not->toBeEmpty();
});
