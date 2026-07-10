<?php

declare(strict_types=1);

use Happenv\Ltree\Database\Schema\LtreeExtension;
use Happenv\Ltree\Tests\AutoCreateExtensionEnabledTestCase;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(AutoCreateExtensionEnabledTestCase::class);

it('creates the ltree extension on MigrationsStarted when auto_create_extension is enabled', function () {
    DB::statement('DROP EXTENSION IF EXISTS ltree CASCADE');
    expect(LtreeExtension::exists())->toBeFalse();

    Event::dispatch(new MigrationsStarted('up'));

    expect(LtreeExtension::exists())->toBeTrue();
})->after(fn () => DB::statement('CREATE EXTENSION IF NOT EXISTS ltree'));
