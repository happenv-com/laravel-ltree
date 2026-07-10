<?php

declare(strict_types=1);

use Happenv\Ltree\Database\Schema\LtreeExtension;
use Happenv\Ltree\LaravelLtreeServiceProvider;
use Happenv\Ltree\Tests\DatabaseTestCase;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(DatabaseTestCase::class);

it('does not create the ltree extension on MigrationsStarted when auto_create_extension is not enabled', function () {
    // Default config (`ltree.auto_create_extension` unset, falls back to
    // `false`): the listener registered in the service provider's boot()
    // must not fire and create the extension.
    DB::statement('DROP EXTENSION IF EXISTS ltree CASCADE');
    expect(LtreeExtension::exists())->toBeFalse();

    Event::dispatch(new MigrationsStarted('up'));

    expect(LtreeExtension::exists())->toBeFalse();
})->after(fn () => DB::statement('CREATE EXTENSION IF NOT EXISTS ltree'));

it('falls back to disabled when the auto_create_extension config key is entirely absent', function () {
    // `config('ltree.auto_create_extension', false)`'s own default argument
    // only matters when the key is truly absent from the config array — not
    // merely `false` — and register() (which runs before every boot()) always
    // merges the package's own default in, so the key is never actually
    // absent in normal operation. Remove it and re-run boot() directly to
    // reach that argument and prove it (not some other already-set value) is
    // what keeps auto-create off.
    config(['ltree' => Arr::except(config('ltree'), ['auto_create_extension'])]);
    expect(array_key_exists('auto_create_extension', config('ltree')))->toBeFalse();

    DB::statement('DROP EXTENSION IF EXISTS ltree CASCADE');

    (new LaravelLtreeServiceProvider($this->app))->boot();
    Event::dispatch(new MigrationsStarted('up'));

    expect(LtreeExtension::exists())->toBeFalse();
})->after(fn () => DB::statement('CREATE EXTENSION IF NOT EXISTS ltree'));
