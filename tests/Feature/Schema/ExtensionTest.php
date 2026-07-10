<?php

declare(strict_types=1);

use Happenv\Ltree\Database\Schema\LtreeExtension;
use Happenv\Ltree\Tests\DatabaseTestCase;
use Illuminate\Support\Facades\DB;

uses(DatabaseTestCase::class);

it('creates, detects, and drops the ltree extension', function () {
    DB::statement('DROP EXTENSION IF EXISTS ltree CASCADE');
    expect(LtreeExtension::exists())->toBeFalse();

    LtreeExtension::create();
    expect(LtreeExtension::exists())->toBeTrue();

    // idempotent
    LtreeExtension::create();
    expect(LtreeExtension::exists())->toBeTrue();
})->after(fn () => DB::statement('CREATE EXTENSION IF NOT EXISTS ltree'));
