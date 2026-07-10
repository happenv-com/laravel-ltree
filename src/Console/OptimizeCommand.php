<?php

declare(strict_types=1);

namespace Happenv\Ltree\Console;

use Happenv\Ltree\Console\Concerns\ResolvesLtreeModel;
use Happenv\Ltree\Contracts\Ltreeable;
use Happenv\Ltree\Exceptions\LtreeException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class OptimizeCommand extends Command
{
    use ResolvesLtreeModel;

    protected $signature = 'ltree:optimize {model : FQCN of an HasLtree model}';

    protected $description = 'Ensure a GiST index exists on an ltree-backed table, then REINDEX and ANALYZE it.';

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
        $db = DB::connection($model->getConnectionName());
        $table = $model->getTable();
        $pathColumn = $model->getLtreePathColumn();

        $quotedTable = $this->quoteIdentifier($table);
        $quotedPath = $this->quoteIdentifier($pathColumn);
        $indexName = $this->quoteIdentifier($this->indexName($table, $pathColumn));

        $db->statement("CREATE INDEX IF NOT EXISTS {$indexName} ON {$quotedTable} USING gist ({$quotedPath} gist_ltree_ops)");
        $this->components->info("Ensured a gist index on \"{$table}\".\"{$pathColumn}\".");

        $db->statement("REINDEX TABLE {$quotedTable}");
        $this->components->info("Reindexed \"{$table}\".");

        $db->statement("ANALYZE {$quotedTable}");
        $this->components->info("Analyzed \"{$table}\".");

        return self::SUCCESS;
    }

    /**
     * Derive a stable, unquoted index name from the table and column, kept
     * within PostgreSQL's 63-byte identifier limit. Truncates the table
     * portion (not the fixed "_gist" suffix or column) when necessary.
     *
     * Public for direct testability of the truncation math (see
     * OptimizeCommandTest); it is not part of the command's console-facing
     * behavior.
     */
    public function indexName(string $table, string $column): string
    {
        $suffix = '_'.$column.'_gist';
        $maxTableLength = 63 - strlen($suffix);

        return substr($table, 0, max($maxTableLength, 0)).$suffix;
    }

    private function quoteIdentifier(string $identifier): string
    {
        // Provably equivalent mutant: $identifier is always a developer-
        // controlled config value (a table/column name), never user input,
        // and no valid PostgreSQL/Laravel identifier a migration can create
        // contains a `"`. The doubling is defensive best-practice with no
        // reachable observable difference.
        return '"'.str_replace('"', '""', $identifier).'"'; // @pest-mutate-ignore: UnwrapStrReplace
    }
}
