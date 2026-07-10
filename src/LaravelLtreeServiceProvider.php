<?php

declare(strict_types=1);

namespace Happenv\Ltree;

use Happenv\Ltree\Console\CheckCommand;
use Happenv\Ltree\Console\InstallCommand;
use Happenv\Ltree\Console\OptimizeCommand;
use Happenv\Ltree\Console\RebuildCommand;
use Happenv\Ltree\Database\Schema\BlueprintMacros;
use Happenv\Ltree\Database\Schema\LtreeExtension;
use Happenv\Ltree\Database\Schema\LtreeGrammar;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\ServiceProvider;

final class LaravelLtreeServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ltree.php', 'ltree');
    }

    public function boot(): void
    {
        LtreeGrammar::register();
        BlueprintMacros::register();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ltree.php' => $this->app->configPath('ltree.php'),
            ], 'ltree-config');

            $this->commands([InstallCommand::class, CheckCommand::class, RebuildCommand::class, OptimizeCommand::class]);
            $this->publishes([
                __DIR__.'/../database/stubs/create_ltree_table.stub' => $this->app->databasePath('migrations/'.date('Y_m_d_His').'_create_ltree_table.php'),
            ], 'ltree-migrations');
        }

        // Provably equivalent mutant: `if ($x)` already coerces $x to bool
        // using the exact same rules as an explicit `(bool)` cast (PHP's
        // conditional-jump opcode and cast operator share one truthiness
        // table). No value `config()` can return — string, int, null, array
        // — makes `if ((bool) $x)` and `if ($x)` branch differently.
        if ((bool) config('ltree.auto_create_extension', false)) { // @pest-mutate-ignore: RemoveBooleanCast
            $this->app->make(Dispatcher::class)->listen(
                MigrationsStarted::class,
                fn () => LtreeExtension::create(),
            );
        }
    }
}
