<?php

declare(strict_types=1);

namespace Happenv\Ltree\Tests;

/**
 * Boots the app with `ltree.auto_create_extension` forced on, so tests using
 * this case can assert the `MigrationsStarted` listener registered in
 * `LaravelLtreeServiceProvider::boot()` actually fires. Config must be set in
 * `defineEnvironment()` — it runs after providers are registered (so the
 * package's own config defaults are already merged) but before they are
 * booted, which is when the provider reads the flag.
 */
abstract class AutoCreateExtensionEnabledTestCase extends DatabaseTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('ltree.auto_create_extension', true);
    }
}
