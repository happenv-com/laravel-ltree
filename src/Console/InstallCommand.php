<?php

declare(strict_types=1);

namespace Happenv\Ltree\Console;

use Happenv\Ltree\Database\Schema\LtreeExtension;
use Illuminate\Console\Command;

final class InstallCommand extends Command
{
    protected $signature = 'ltree:install {--no-extension : Do not create the ltree extension} {--force : Overwrite existing config}';

    protected $description = 'Install laravel-ltree: publish config and create the ltree extension.';

    public function handle(): int
    {
        $this->callSilent('vendor:publish', [
            '--tag' => 'ltree-config',
            // Provably equivalent mutant: `--force` is declared as a bare
            // flag (`{--force}`, no `=value`), which Symfony Console parses
            // as InputOption::VALUE_NONE — its `getOption()` (and Laravel's
            // passthrough `Command::option()`) always returns a native bool
            // for that option type (verified directly against ArgvInput).
            // `(bool)` on an already-bool value cannot change it.
            '--force' => (bool) $this->option('force'), // @pest-mutate-ignore: RemoveBooleanCast
        ]);
        $this->components->info('Published config/ltree.php');

        if (! $this->option('no-extension')) {
            LtreeExtension::create();
            $this->components->info('Ensured the "ltree" PostgreSQL extension.');
        }

        $this->components->info('Publish the example migration with: php artisan vendor:publish --tag=ltree-migrations');

        return self::SUCCESS;
    }
}
