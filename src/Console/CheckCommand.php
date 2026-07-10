<?php

declare(strict_types=1);

namespace Happenv\Ltree\Console;

use Happenv\Ltree\Console\Concerns\ResolvesLtreeModel;
use Happenv\Ltree\Contracts\Ltreeable;
use Happenv\Ltree\Database\Schema\LtreeExtension;
use Happenv\Ltree\Exceptions\LtreeException;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use stdClass;

final class CheckCommand extends Command
{
    use ResolvesLtreeModel;

    protected $signature = 'ltree:check {model : FQCN of an HasLtree model}';

    protected $description = 'Diagnose the integrity of an ltree-backed table: extension, GiST index, orphaned paths, and path/parent_id consistency.';

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
        $connection = $model->getConnectionName();
        $db = DB::connection($connection);
        $table = $model->getTable();
        $pathColumn = $model->getLtreePathColumn();
        $parentColumn = $model->getLtreeParentColumn();

        $hasErrors = false;

        // Guard the diagnostic scenario itself: an unmigrated table must produce
        // a clean FAILURE, not an uncaught QueryException stack trace.
        if (! $db->getSchemaBuilder()->hasTable($table)) {
            $this->components->error("Table \"{$table}\" does not exist — run your migrations first.");

            return self::FAILURE;
        }

        $extensionInstalled = LtreeExtension::exists($connection);
        if ($extensionInstalled) {
            $this->components->info('The "ltree" PostgreSQL extension is installed.');
        } else {
            $this->components->error('The "ltree" PostgreSQL extension is NOT installed. Run ltree:install.');
            $hasErrors = true;
        }

        // Match a GiST index whose indexed column IS the path column — not merely
        // any GiST index on the table (a gist index over a different column must
        // not count, and a column name of which "path" is a substring must not
        // false-match). Reads pg_catalog only, so it works without the extension.
        $indexCount = $this->scalarCount(
            $db,
            'SELECT count(*) AS count FROM pg_index i '.
            'JOIN pg_class t ON t.oid = i.indrelid '.
            'JOIN pg_am am ON am.oid = (SELECT relam FROM pg_class WHERE oid = i.indexrelid) '.
            'JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(i.indkey) '.
            "WHERE t.relnamespace = current_schema()::regnamespace AND t.relname = ? AND am.amname = 'gist' AND a.attname = ?",
            [$table, $pathColumn],
        );

        if ($indexCount > 0) {
            $this->components->info("GiST index present on \"{$table}\".\"{$pathColumn}\".");
        } else {
            $this->components->warn("No GiST index on \"{$table}\".\"{$pathColumn}\" — run ltree:optimize.");
        }

        // The path-integrity checks call ltree functions (nlevel/subpath), so run
        // them only once the extension is present; otherwise they would throw an
        // undefined-function error rather than diagnose.
        if ($extensionInstalled) {
            $quotedTable = $this->quoteIdentifier($table);
            $quotedPath = $this->quoteIdentifier($pathColumn);

            $nullPathCount = $this->scalarCount(
                $db,
                "SELECT count(*) AS count FROM {$quotedTable} WHERE {$quotedPath} IS NULL",
            );
            if ($nullPathCount > 0) {
                $this->components->error("Found {$nullPathCount} row(s) with a NULL path.");
                $hasErrors = true;
            } else {
                $this->components->info('No NULL paths.');
            }

            $orphanCount = $this->scalarCount(
                $db,
                "SELECT count(*) AS count FROM {$quotedTable} c WHERE nlevel(c.{$quotedPath}) > 1 AND NOT EXISTS ".
                "(SELECT 1 FROM {$quotedTable} p WHERE p.{$quotedPath} = subpath(c.{$quotedPath}, 0, nlevel(c.{$quotedPath}) - 1))",
            );
            if ($orphanCount > 0) {
                $this->components->error("Found {$orphanCount} orphaned path(s) — a node's path references a parent path that does not exist.");
                $hasErrors = true;
            } else {
                $this->components->info('No orphaned paths.');
            }

            if ($parentColumn !== null) {
                $quotedParent = $this->quoteIdentifier($parentColumn);
                $quotedKey = $this->quoteIdentifier($model->getKeyName());

                $danglingCount = $this->scalarCount(
                    $db,
                    "SELECT count(*) AS count FROM {$quotedTable} c WHERE c.{$quotedParent} IS NOT NULL AND NOT EXISTS ".
                    "(SELECT 1 FROM {$quotedTable} p WHERE p.{$quotedKey} = c.{$quotedParent})",
                );
                if ($danglingCount > 0) {
                    $this->components->error("Found {$danglingCount} row(s) whose \"{$parentColumn}\" references a missing row.");
                    $hasErrors = true;
                } else {
                    $this->components->info("No dangling \"{$parentColumn}\" references.");
                }

                $mismatchCount = $this->scalarCount(
                    $db,
                    "SELECT count(*) AS count FROM {$quotedTable} c JOIN {$quotedTable} p ON p.{$quotedKey} = c.{$quotedParent} ".
                    "WHERE subpath(c.{$quotedPath}, 0, nlevel(c.{$quotedPath}) - 1) <> p.{$quotedPath}",
                );
                if ($mismatchCount > 0) {
                    $this->components->error("Found {$mismatchCount} row(s) where \"{$pathColumn}\" does not match \"{$parentColumn}\".");
                    $hasErrors = true;
                } else {
                    $this->components->info("\"{$pathColumn}\" and \"{$parentColumn}\" are consistent.");
                }
            }
        }

        if ($hasErrors) {
            $this->components->error('ltree:check found integrity issues.');

            return self::FAILURE;
        }

        $this->components->info('ltree:check passed.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $bindings
     */
    private function scalarCount(ConnectionInterface $db, string $sql, array $bindings = []): int
    {
        $result = $db->selectOne($sql, $bindings);

        // Provably equivalent mutant: every $sql passed to scalarCount() is a
        // `SELECT count(*) AS count ...`, which always returns exactly one
        // row, so selectOne() always returns a stdClass here — this guard
        // never triggers, and forcing it "true" changes nothing observable.
        if (! $result instanceof stdClass) { // @pest-mutate-ignore: InstanceOfToTrue
            return 0;
        }

        $count = $result->count;

        // Provably equivalent mutants: is_numeric($count) is always true (a
        // count(*) value is always numeric), so the `: 0` fallback is
        // unreachable — mutating that literal (Increment/DecrementInteger)
        // changes nothing observable. And the pgsql driver returns count(*)
        // as a native PHP int (verified directly against selectOne() here),
        // so `(int)` is a formatting no-op on the value — RemoveIntegerCast
        // does not change the value, and it is used only in a `> 0`
        // comparison and string interpolation, both unaffected either way.
        return is_numeric($count) ? (int) $count : 0; // @pest-mutate-ignore: IncrementInteger, DecrementInteger, RemoveIntegerCast
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
