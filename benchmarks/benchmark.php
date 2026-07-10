<?php

declare(strict_types=1);

/**
 * ltree vs. adjacency-list (parent_id + recursive CTE) benchmark harness.
 *
 * Seeds an identical tree shape into two tables against a live PostgreSQL
 * instance:
 *
 *  - bench_ltree:     id, parent_id, path ltree, GiST index on path
 *  - bench_adjacency: id, parent_id, btree index on parent_id
 *
 * ...then times four operations on each and prints a Markdown comparison
 * table. This script lives outside src/ deliberately: it is not part of the
 * autoloaded package and is not covered by the mutation-tested value layer.
 *
 * Usage: php benchmarks/benchmark.php [nodes] [branching]
 *   nodes:     total node count to seed (default 50000)
 *   branching: children per node while building the tree (default 5)
 *
 * The ltree side is driven through the package's real public API
 * (LtreeBuilder::whereDescendantOf()/whereAncestorOf(), moveTo()) wherever
 * that API produces exactly the SQL being measured. The one exception is
 * "branch delete": the task compares the bare `DELETE ... WHERE path <@ ?`
 * statement (which is also the core statement inside
 * PerformsLtreeOperations::cascadeDelete()) against a bare recursive-CTE
 * DELETE, so both sides are timed as a single raw DELETE with no extra
 * fetching. The real cascadeDelete() additionally loads the affected
 * collection first (so cancellable events can inspect it) — see the note
 * printed with the results.
 */

require __DIR__.'/../vendor/autoload.php';

use Happenv\Ltree\Concerns\HasLtree;
use Happenv\Ltree\Database\Schema\BlueprintMacros;
use Happenv\Ltree\Database\Schema\LtreeGrammar;
use Happenv\Ltree\ValueObjects\LtreePath;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Facades\Facade;

// -----------------------------------------------------------------------
// Bootstrap: a bare Illuminate container + Capsule, no Testbench/Laravel
// app required. The package's schema macros and grammar macros are plain
// static registrations, so they work standalone.
// -----------------------------------------------------------------------

LtreeGrammar::register();
BlueprintMacros::register();

$container = new Container;
Container::setInstance($container);

/** @var array<string, mixed> $ltreeConfig */
$ltreeConfig = require __DIR__.'/../config/ltree.php';
$container->instance('config', new ConfigRepository(['ltree' => $ltreeConfig]));

$events = new EventDispatcher($container);
$container->instance('events', $events);

$capsule = new Capsule($container);
$capsule->addConnection([
    'driver' => 'pgsql',
    'host' => getenv('LTREE_DB_HOST') ?: '127.0.0.1',
    'port' => getenv('LTREE_DB_PORT') ?: '55432',
    'database' => getenv('LTREE_DB_DATABASE') ?: 'ltree_test',
    'username' => getenv('LTREE_DB_USERNAME') ?: 'postgres',
    'password' => getenv('LTREE_DB_PASSWORD') ?: 'secret',
    'charset' => 'utf8',
    'prefix' => '',
    'schema' => 'public',
    'sslmode' => 'prefer',
]);
$capsule->setEventDispatcher($events);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$container->instance('db', $capsule->getDatabaseManager());
Facade::setFacadeApplication($container);

/** @var Connection $connection */
$connection = $capsule->getConnection();

// -----------------------------------------------------------------------
// Eloquent model for the ltree table, using the package's real API.
// -----------------------------------------------------------------------

/**
 * @property int $id
 * @property int|null $parent_id
 * @property LtreePath|null $path
 */
final class BenchLtreeNode extends Model
{
    use HasLtree;

    protected $table = 'bench_ltree';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = ['id', 'parent_id', 'path'];
}

// -----------------------------------------------------------------------
// CLI args
// -----------------------------------------------------------------------

$nodeCount = max(2, (int) ($argv[1] ?? 50000));
$branching = max(2, (int) ($argv[2] ?? 5));
$iterations = 5;

// -----------------------------------------------------------------------
// Small helpers
// -----------------------------------------------------------------------

/** @return array{0: float, 1: array<int, float>} median in ms + all raw samples (warm-up excluded) */
function timeCalls(array $calls): array
{
    $samples = [];
    foreach ($calls as $call) {
        $start = hrtime(true);
        $call();
        $samples[] = (hrtime(true) - $start) / 1e6;
    }

    // First sample is always the warm-up; drop it from the reported figures.
    $warmup = array_shift($samples);
    unset($warmup);

    sort($samples);
    $count = count($samples);
    $mid = intdiv($count, 2);
    $median = $count % 2 === 0 ? ($samples[$mid - 1] + $samples[$mid]) / 2 : $samples[$mid];

    return [$median, $samples];
}

function fmtMs(float $ms): string
{
    return number_format($ms, 2);
}

function speedupLabel(float $ltreeMs, float $adjacencyMs): string
{
    if ($ltreeMs <= 0.0 || $adjacencyMs <= 0.0) {
        return 'n/a';
    }

    if ($ltreeMs < $adjacencyMs) {
        return number_format($adjacencyMs / $ltreeMs, 2).'x faster (ltree)';
    }

    if ($adjacencyMs < $ltreeMs) {
        return number_format($ltreeMs / $adjacencyMs, 2).'x faster (adjacency)';
    }

    return '1.00x (tie)';
}

// -----------------------------------------------------------------------
// Tree generation: BFS assignment of ids so parent ids are always smaller
// than their children's, ~$branching children per node, until $nodeCount
// nodes exist. With unlimited depth this naturally settles around depth 7
// for ~50k nodes at branching factor 5.
// -----------------------------------------------------------------------

/**
 * @return array{
 *     parent: array<int, int|null>,
 *     depth: array<int, int>,
 *     path: array<int, string>,
 *     branch: array<int, int>,
 *     children: array<int, list<int>>,
 *     subtreeSize: array<int, int>,
 *     maxDepth: int,
 * }
 */
function generateTree(int $nodeCount, int $branching): array
{
    $parent = [1 => null];
    $depth = [1 => 0];
    $path = [1 => '1'];
    $branch = [1 => 1];
    $children = [];

    $queue = [1];
    $head = 0;
    $nextId = 2;

    while ($nextId <= $nodeCount && $head < count($queue)) {
        $current = $queue[$head++];

        for ($i = 0; $i < $branching && $nextId <= $nodeCount; $i++) {
            $id = $nextId++;
            $parent[$id] = $current;
            $depth[$id] = $depth[$current] + 1;
            $path[$id] = $path[$current].'.'.$id;
            $branch[$id] = $current === 1 ? $id : $branch[$current];
            $children[$current][] = $id;
            $queue[] = $id;
        }
    }

    $subtreeSize = array_fill(1, $nodeCount, 1);
    for ($id = $nodeCount; $id >= 2; $id--) {
        $p = $parent[$id];
        $subtreeSize[$p] += $subtreeSize[$id];
    }

    return [
        'parent' => $parent,
        'depth' => $depth,
        'path' => $path,
        'branch' => $branch,
        'children' => $children,
        'subtreeSize' => $subtreeSize,
        'maxDepth' => max($depth),
    ];
}

/**
 * Pick $count node ids belonging to $branch, as close as possible to
 * $desiredDepth, preferring the largest subtrees at that depth (so move/
 * delete pools carry a comparable, non-trivial amount of work). Falls back
 * to whatever is available if the branch is too small.
 *
 * @param  array<int, int>  $depth
 * @param  array<int, int>  $branchOf
 * @param  array<int, int>  $subtreeSize
 * @return list<int>
 */
function pickPool(array $depth, array $branchOf, array $subtreeSize, int $branch, int $desiredDepth, int $count): array
{
    $byDepth = [];
    foreach ($branchOf as $id => $b) {
        if ($b === $branch) {
            $byDepth[$depth[$id]][] = $id;
        }
    }

    if ($byDepth === []) {
        return [];
    }

    $depths = array_keys($byDepth);
    usort($depths, fn (int $a, int $b): int => abs($a - $desiredDepth) <=> abs($b - $desiredDepth));

    foreach ($depths as $d) {
        $ids = $byDepth[$d];
        if (count($ids) >= $count) {
            usort($ids, fn (int $a, int $b): int => $subtreeSize[$b] <=> $subtreeSize[$a]);

            return array_slice($ids, 0, $count);
        }
    }

    $pool = [];
    foreach ($depths as $d) {
        foreach ($byDepth[$d] as $id) {
            $pool[] = $id;
            if (count($pool) >= $count) {
                return $pool;
            }
        }
    }

    return $pool;
}

// -----------------------------------------------------------------------
// Schema
// -----------------------------------------------------------------------

$connection->statement('CREATE EXTENSION IF NOT EXISTS ltree');

$schema = $capsule->schema();
$schema->dropIfExists('bench_ltree');
$schema->dropIfExists('bench_adjacency');

try {
    $schema->create('bench_adjacency', function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('parent_id')->nullable();
        $table->index('parent_id');
    });

    $schema->create('bench_ltree', function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('parent_id')->nullable();
        $table->ltree('path');
        $table->gist('path');
    });

    // ---------------------------------------------------------------
    // Seed: identical parent structure in both tables, bulk-inserted
    // (chunked) inside one transaction per table. Seeding itself is not
    // one of the benchmarked operations, so it uses the fastest honest
    // path rather than per-model Eloquent saves.
    // ---------------------------------------------------------------

    $seedStart = hrtime(true);
    $tree = generateTree($nodeCount, $branching);
    $genMs = (hrtime(true) - $seedStart) / 1e6;

    $chunkSize = 2000;

    $insertStart = hrtime(true);

    $connection->transaction(function () use ($connection, $tree, $nodeCount, $chunkSize): void {
        $adjacencyRows = [];
        $ltreeRows = [];

        for ($id = 1; $id <= $nodeCount; $id++) {
            $adjacencyRows[] = ['id' => $id, 'parent_id' => $tree['parent'][$id]];
            $ltreeRows[] = ['id' => $id, 'parent_id' => $tree['parent'][$id], 'path' => $tree['path'][$id]];

            if (count($adjacencyRows) >= $chunkSize) {
                $connection->table('bench_adjacency')->insert($adjacencyRows);
                $connection->table('bench_ltree')->insert($ltreeRows);
                $adjacencyRows = [];
                $ltreeRows = [];
            }
        }

        if ($adjacencyRows !== []) {
            $connection->table('bench_adjacency')->insert($adjacencyRows);
            $connection->table('bench_ltree')->insert($ltreeRows);
        }
    });

    $insertMs = (hrtime(true) - $insertStart) / 1e6;

    $connection->statement('ANALYZE bench_adjacency');
    $connection->statement('ANALYZE bench_ltree');

    $adjacencyCount = (int) $connection->table('bench_adjacency')->count();
    $ltreeCount = (int) $connection->table('bench_ltree')->count();

    // ---------------------------------------------------------------
    // Node selection
    // ---------------------------------------------------------------

    $rootChildren = $tree['children'][1] ?? [];
    $branchCount = max(1, count($rootChildren));

    $targetBranch = $rootChildren[0 % $branchCount] ?? 1;
    $moveBranch = $rootChildren[1 % $branchCount] ?? $targetBranch;
    $deleteBranch = $rootChildren[2 % $branchCount] ?? $targetBranch;

    $maxDepth = $tree['maxDepth'];

    // Node used for the "descendants" benchmark: not the depth-based
    // midpoint (with branching ~5 that skews tiny — most of the tree's
    // mass sits in the deepest couple of levels) but the non-root,
    // non-leaf node whose subtree size is closest to ~1% of the tree, a
    // more representative "fetch this whole branch" query size.
    $targetSubtreeSize = max(50, (int) ($nodeCount * 0.01));
    $midNodeId = 1;
    $bestDelta = PHP_INT_MAX;
    foreach ($tree['depth'] as $id => $d) {
        if ($d < 1 || $d >= $maxDepth) {
            continue; // skip the root and leaves
        }
        $delta = abs($tree['subtreeSize'][$id] - $targetSubtreeSize);
        if ($delta < $bestDelta) {
            $bestDelta = $delta;
            $midNodeId = $id;
        }
    }
    $midDepth = $tree['depth'][$midNodeId];

    // Node used for the "ancestors" benchmark: any leaf at maximum depth.
    $deepNodeId = 1;
    foreach ($tree['depth'] as $id => $d) {
        if ($d === $maxDepth) {
            $deepNodeId = $id;
            break;
        }
    }

    $movePool = pickPool($tree['depth'], $tree['branch'], $tree['subtreeSize'], $moveBranch, max(1, $maxDepth - 2), $iterations + 1);
    $deletePool = pickPool($tree['depth'], $tree['branch'], $tree['subtreeSize'], $deleteBranch, max(1, $maxDepth - 2), $iterations + 1);

    if (count($movePool) < 2 || count($deletePool) < 2) {
        fwrite(STDERR, "Tree too small for the requested node/branching combination to build move/delete pools; use a larger node count.\n");
        exit(1);
    }

    $midDescendantCount = $tree['subtreeSize'][$midNodeId] - 1;
    $deepAncestorCount = $tree['depth'][$deepNodeId];
    $moveSubtreeSizes = array_map(fn (int $id): int => $tree['subtreeSize'][$id], $movePool);
    $deleteSubtreeSizes = array_map(fn (int $id): int => $tree['subtreeSize'][$id], $deletePool);

    // ---------------------------------------------------------------
    // Benchmark: descendants
    //   ltree:      path <@ ?  (via LtreeBuilder::whereDescendantOf())
    //   adjacency:  WITH RECURSIVE ... (walk down via parent_id)
    // ---------------------------------------------------------------

    $midPath = $tree['path'][$midNodeId];

    $ltreeDescendantsQuery = (new BenchLtreeNode)->newQuery()->select('id')->whereDescendantOf($midPath);
    $ltreeDescendantsSql = $ltreeDescendantsQuery->toSql();
    $ltreeDescendantsBindings = $ltreeDescendantsQuery->getBindings();

    $adjacencyDescendantsSql = <<<'SQL'
        WITH RECURSIVE sub AS (
            SELECT id FROM bench_adjacency WHERE id = ?
            UNION ALL
            SELECT a.id FROM bench_adjacency a JOIN sub s ON a.parent_id = s.id
        )
        SELECT id FROM sub WHERE id <> ?
        SQL;

    [$descendantsLtreeMs] = timeCalls(array_fill(0, $iterations + 1, function () use ($connection, $ltreeDescendantsSql, $ltreeDescendantsBindings): void {
        $connection->select($ltreeDescendantsSql, $ltreeDescendantsBindings);
    }));

    [$descendantsAdjacencyMs] = timeCalls(array_fill(0, $iterations + 1, function () use ($connection, $adjacencyDescendantsSql, $midNodeId): void {
        $connection->select($adjacencyDescendantsSql, [$midNodeId, $midNodeId]);
    }));

    // ---------------------------------------------------------------
    // Benchmark: ancestors
    //   ltree:      path @> ?  (via LtreeBuilder::whereAncestorOf())
    //   adjacency:  WITH RECURSIVE ... (walk up via parent_id)
    // ---------------------------------------------------------------

    $deepPath = $tree['path'][$deepNodeId];

    $ltreeAncestorsQuery = (new BenchLtreeNode)->newQuery()->select('id')->whereAncestorOf($deepPath);
    $ltreeAncestorsSql = $ltreeAncestorsQuery->toSql();
    $ltreeAncestorsBindings = $ltreeAncestorsQuery->getBindings();

    $adjacencyAncestorsSql = <<<'SQL'
        WITH RECURSIVE anc AS (
            SELECT id, parent_id FROM bench_adjacency WHERE id = ?
            UNION ALL
            SELECT a.id, a.parent_id FROM bench_adjacency a JOIN anc x ON a.id = x.parent_id
        )
        SELECT id FROM anc WHERE id <> ?
        SQL;

    [$ancestorsLtreeMs] = timeCalls(array_fill(0, $iterations + 1, function () use ($connection, $ltreeAncestorsSql, $ltreeAncestorsBindings): void {
        $connection->select($ltreeAncestorsSql, $ltreeAncestorsBindings);
    }));

    [$ancestorsAdjacencyMs] = timeCalls(array_fill(0, $iterations + 1, function () use ($connection, $adjacencyAncestorsSql, $deepNodeId): void {
        $connection->select($adjacencyAncestorsSql, [$deepNodeId, $deepNodeId]);
    }));

    // ---------------------------------------------------------------
    // Benchmark: subtree move
    //   ltree:      $node->moveTo($target) — real API: rewrites the whole
    //               subtree's path, then updates parent_id, in one
    //               transaction.
    //   adjacency:  UPDATE bench_adjacency SET parent_id = ? WHERE id = ?
    //               — a single pointer update, O(1) regardless of subtree
    //               size.
    // ---------------------------------------------------------------

    $moveTargetId = $targetBranch;
    /** @var BenchLtreeNode $moveTargetModel */
    $moveTargetModel = BenchLtreeNode::query()->findOrFail($moveTargetId);

    $moveLtreeCalls = [];
    foreach ($movePool as $nodeId) {
        $moveLtreeCalls[] = function () use ($nodeId, $moveTargetModel): void {
            /** @var BenchLtreeNode $node */
            $node = BenchLtreeNode::query()->findOrFail($nodeId);
            $node->moveTo($moveTargetModel);
        };
    }
    [$moveLtreeMs] = timeCalls($moveLtreeCalls);

    // Each pool node is only ever moved once (the whole point is timing a
    // single moveTo() call per node), and the "delete" benchmark below
    // operates on an entirely different, untouched branch, so there is
    // nothing to restore here — the bench_* tables are dropped wholesale
    // once the report is printed.

    $moveAdjacencyCalls = [];
    foreach ($movePool as $nodeId) {
        $moveAdjacencyCalls[] = function () use ($connection, $nodeId, $moveTargetId): void {
            $connection->table('bench_adjacency')->where('id', $nodeId)->update(['parent_id' => $moveTargetId]);
        };
    }
    [$moveAdjacencyMs] = timeCalls($moveAdjacencyCalls);

    // ---------------------------------------------------------------
    // Benchmark: branch delete
    //   ltree:      DELETE FROM bench_ltree WHERE path <@ ?
    //               (the core statement inside PerformsLtreeOperations::
    //               cascadeDelete(); the real API additionally loads the
    //               affected collection first for cancellable events —
    //               see the note in RESULTS.md)
    //   adjacency:  WITH RECURSIVE ... DELETE ... — recursive CTE delete
    // ---------------------------------------------------------------

    $deleteLtreeCalls = [];
    foreach ($deletePool as $nodeId) {
        $path = $tree['path'][$nodeId];
        $deleteLtreeCalls[] = function () use ($connection, $path): void {
            $connection->delete('DELETE FROM "bench_ltree" WHERE "path" <@ ?::ltree', [$path]);
        };
    }
    [$deleteLtreeMs] = timeCalls($deleteLtreeCalls);

    $adjacencyDeleteSql = <<<'SQL'
        WITH RECURSIVE sub AS (
            SELECT id FROM bench_adjacency WHERE id = ?
            UNION ALL
            SELECT a.id FROM bench_adjacency a JOIN sub s ON a.parent_id = s.id
        )
        DELETE FROM bench_adjacency WHERE id IN (SELECT id FROM sub)
        SQL;

    $deleteAdjacencyCalls = [];
    foreach ($deletePool as $nodeId) {
        $deleteAdjacencyCalls[] = function () use ($connection, $adjacencyDeleteSql, $nodeId): void {
            $connection->delete($adjacencyDeleteSql, [$nodeId]);
        };
    }
    [$deleteAdjacencyMs] = timeCalls($deleteAdjacencyCalls);

    // ---------------------------------------------------------------
    // Report
    // ---------------------------------------------------------------

    $pgVersion = (string) $connection->selectOne('SHOW server_version')->server_version;

    echo "## Environment\n\n";
    echo '- PostgreSQL: '.$pgVersion."\n";
    echo '- Nodes seeded: '.number_format($nodeCount)." (adjacency: {$adjacencyCount}, ltree: {$ltreeCount})\n";
    echo '- Branching factor: '.$branching.", observed max depth: {$maxDepth}\n";
    echo '- Tree generation: '.fmtMs($genMs).' ms, seed insert: '.fmtMs($insertMs)." ms\n";
    echo '- Descendants query touches '.number_format($midDescendantCount)." rows (mid-tree node id {$midNodeId}, depth {$midDepth})\n";
    echo '- Ancestors query touches '.number_format($deepAncestorCount)." rows (leaf node id {$deepNodeId}, depth {$maxDepth})\n";
    echo '- Move pool subtree sizes: '.implode(', ', $moveSubtreeSizes)."\n";
    echo '- Delete pool subtree sizes: '.implode(', ', $deleteSubtreeSizes)."\n\n";

    echo "## Results (median of {$iterations} timed iterations, after 1 warm-up)\n\n";
    echo "| Operation | ltree (ms) | adjacency+CTE (ms) | Speedup |\n";
    echo "|---|---:|---:|---|\n";
    printf(
        "| descendants(mid-tree node) | %s | %s | %s |\n",
        fmtMs($descendantsLtreeMs),
        fmtMs($descendantsAdjacencyMs),
        speedupLabel($descendantsLtreeMs, $descendantsAdjacencyMs),
    );
    printf(
        "| ancestors(deep leaf) | %s | %s | %s |\n",
        fmtMs($ancestorsLtreeMs),
        fmtMs($ancestorsAdjacencyMs),
        speedupLabel($ancestorsLtreeMs, $ancestorsAdjacencyMs),
    );
    printf(
        "| subtree move | %s | %s | %s |\n",
        fmtMs($moveLtreeMs),
        fmtMs($moveAdjacencyMs),
        speedupLabel($moveLtreeMs, $moveAdjacencyMs),
    );
    printf(
        "| branch delete | %s | %s | %s |\n",
        fmtMs($deleteLtreeMs),
        fmtMs($deleteAdjacencyMs),
        speedupLabel($deleteLtreeMs, $deleteAdjacencyMs),
    );
} finally {
    // Never leak bench_* tables into the test database.
    $schema->dropIfExists('bench_ltree');
    $schema->dropIfExists('bench_adjacency');
}
