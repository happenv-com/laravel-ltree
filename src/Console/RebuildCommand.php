<?php

declare(strict_types=1);

namespace Happenv\Ltree\Console;

use Happenv\Ltree\Console\Concerns\ResolvesLtreeModel;
use Happenv\Ltree\Contracts\Ltreeable;
use Happenv\Ltree\Exceptions\LtreeException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

final class RebuildCommand extends Command
{
    use ResolvesLtreeModel;

    protected $signature = 'ltree:rebuild {model : FQCN of an HasLtree model}';

    protected $description = 'Recompute every row\'s ltree path from its parent_id column.';

    public function handle(): int
    {
        /** @var string $modelClass */
        $modelClass = $this->argument('model');

        try {
            $model = $this->resolveLtreeModel($modelClass);
        } catch (LtreeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        /** @var Model&Ltreeable $model */
        try {
            $changed = $model->rebuildPaths();
        } catch (LtreeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Rebuilt paths: {$changed} row(s) updated.");

        return self::SUCCESS;
    }
}
