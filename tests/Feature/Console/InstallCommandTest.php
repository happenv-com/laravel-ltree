<?php

declare(strict_types=1);

use Happenv\Ltree\Database\Schema\LtreeExtension;
use Happenv\Ltree\Tests\DatabaseTestCase;
use Illuminate\Support\Facades\DB;

uses(DatabaseTestCase::class);

// `ltree:install` really invokes `vendor:publish`, which physically writes
// `config/ltree.php` into the Testbench skeleton app on disk. Left alone,
// that file would leak across test runs and permanently mask any bug in
// LaravelLtreeServiceProvider::register()'s mergeConfigFrom() call, since
// Laravel auto-loads every file under config/ regardless of that call. Every
// test below that runs the install command must clean the published file up.
it('installs the ltree extension via the command', function () {
    DB::statement('DROP EXTENSION IF EXISTS ltree CASCADE');

    $this->artisan('ltree:install')
        ->expectsOutputToContain('Published config/ltree.php')
        ->expectsOutputToContain('Ensured the "ltree" PostgreSQL extension.')
        ->expectsOutputToContain('Publish the example migration with: php artisan vendor:publish --tag=ltree-migrations')
        ->assertSuccessful();

    expect(LtreeExtension::exists())->toBeTrue();
})->after(function () {
    @unlink($this->app->configPath('ltree.php'));
    DB::statement('CREATE EXTENSION IF NOT EXISTS ltree');
});

it('can skip extension creation with --no-extension', function () {
    DB::statement('DROP EXTENSION IF EXISTS ltree CASCADE');

    $this->artisan('ltree:install --no-extension')
        ->expectsOutputToContain('Published config/ltree.php')
        ->doesntExpectOutputToContain('Ensured the "ltree" PostgreSQL extension.')
        ->expectsOutputToContain('Publish the example migration with: php artisan vendor:publish --tag=ltree-migrations')
        ->assertSuccessful();

    expect(LtreeExtension::exists())->toBeFalse();
})->after(function () {
    @unlink($this->app->configPath('ltree.php'));
    DB::statement('CREATE EXTENSION IF NOT EXISTS ltree');
});

it('publishes config/ltree.php to disk', function () {
    @unlink($this->app->configPath('ltree.php'));
    expect(file_exists($this->app->configPath('ltree.php')))->toBeFalse();

    $this->artisan('ltree:install --no-extension')->assertSuccessful();

    expect(file_exists($this->app->configPath('ltree.php')))->toBeTrue();
})->after(function () {
    @unlink($this->app->configPath('ltree.php'));
    DB::statement('CREATE EXTENSION IF NOT EXISTS ltree');
});

it('does not overwrite an already-published config without --force', function () {
    @unlink($this->app->configPath('ltree.php'));
    $this->artisan('ltree:install --no-extension')->assertSuccessful();

    file_put_contents($this->app->configPath('ltree.php'), "<?php\n\nreturn ['tampered' => true];\n");

    $this->artisan('ltree:install --no-extension')->assertSuccessful();

    expect(file_get_contents($this->app->configPath('ltree.php')))->toContain('tampered');
})->after(function () {
    @unlink($this->app->configPath('ltree.php'));
    DB::statement('CREATE EXTENSION IF NOT EXISTS ltree');
});

it('overwrites an already-published config with --force', function () {
    @unlink($this->app->configPath('ltree.php'));
    $this->artisan('ltree:install --no-extension')->assertSuccessful();

    file_put_contents($this->app->configPath('ltree.php'), "<?php\n\nreturn ['tampered' => true];\n");

    $this->artisan('ltree:install --no-extension --force')->assertSuccessful();

    expect(file_get_contents($this->app->configPath('ltree.php')))->not->toContain('tampered');
})->after(function () {
    @unlink($this->app->configPath('ltree.php'));
    DB::statement('CREATE EXTENSION IF NOT EXISTS ltree');
});
