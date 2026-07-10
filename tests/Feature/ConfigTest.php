<?php

declare(strict_types=1);

use Happenv\Ltree\LaravelLtreeServiceProvider;
use Happenv\Ltree\Tests\Fixtures\Category;
use Illuminate\Support\ServiceProvider;

it('getLtreeOrderColumn reads the configured order column, defaulting to null', function () {
    expect((new Category)->getLtreeOrderColumn())->toBeNull();

    config(['ltree.order_column' => 'sort_order']);

    expect((new Category)->getLtreeOrderColumn())->toBe('sort_order');
});

it('publishes ltree config defaults into the app', function () {
    expect(config('ltree.path_column'))->toBe('path')
        ->and(config('ltree.parent_column'))->toBe('parent_id')
        ->and(config('ltree.order_column'))->toBeNull()
        ->and(config('ltree.normalizer.strategy'))->toBe('replace')
        ->and(config('ltree.normalizer.replacements'))->toBe(['-' => '_']);
});

it('registers the config file for publishing under the ltree-config tag', function () {
    // Test runs are `runningInConsole()` (CLI SAPI), so boot() always takes
    // the publishing branch; assert the exact source => destination pair it
    // registers, to pin both the `runningInConsole()` guard and the source
    // path string built from __DIR__.
    $configSource = dirname((new ReflectionClass(LaravelLtreeServiceProvider::class))->getFileName())
        .'/../config/ltree.php';

    expect(ServiceProvider::pathsToPublish(LaravelLtreeServiceProvider::class, 'ltree-config'))
        ->toBe([$configSource => $this->app->configPath('ltree.php')]);
});

it('registers the migration stub for publishing under the ltree-migrations tag', function () {
    // Pins the exact source key (built from __DIR__ . the stub path) and the
    // exact destination shape (a `migrations/` directory, a `Y_m_d_His`
    // timestamp from `date()`, and the `_create_ltree_table.php` suffix) so
    // that any of the source/destination concatenation being reordered,
    // truncated, or dropped fails this assertion. The timestamp itself is
    // asserted by pattern (not exact value) since `date()` runs at boot time
    // and could tick a second apart from this assertion.
    $stubSource = dirname((new ReflectionClass(LaravelLtreeServiceProvider::class))->getFileName())
        .'/../database/stubs/create_ltree_table.stub';

    $paths = ServiceProvider::pathsToPublish(LaravelLtreeServiceProvider::class, 'ltree-migrations');

    expect($paths)->toHaveCount(1)
        ->and($paths)->toHaveKey($stubSource)
        ->and($paths[$stubSource])->toMatch(
            '#^'.preg_quote($this->app->databasePath('migrations/'), '#').'\d{4}_\d{2}_\d{2}_\d{6}_create_ltree_table\.php$#'
        );
});
