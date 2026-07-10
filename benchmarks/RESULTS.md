# Benchmark Results: ltree vs. adjacency list + recursive CTE

Captured by running `php benchmarks/benchmark.php 50000 5` against a live
PostgreSQL 18.4 instance (Docker, `postgres:18-alpine`).

**These numbers are indicative, not a guarantee.** They come from one run on
the maintainer's development machine (a container on a shared host, not
dedicated hardware) and will vary with hardware, PostgreSQL version, cache
state, and how full/shaped the tree is. Re-run `php benchmarks/benchmark.php`
yourself against your own PostgreSQL instance before relying on these figures
for capacity planning. The harness and its methodology live in
`benchmarks/benchmark.php` — every SQL statement it times is printed in that
file's comments and reproduced below.

## Environment

- PostgreSQL: 18.4
- Nodes seeded: 50,000 (identical structure in both tables: `adjacency` 50,000
  rows, `ltree` 50,000 rows)
- Tree shape: branching factor 5, observed max depth 7 (built breadth-first;
  ~50k nodes at branching 5 naturally bottoms out around depth 7)
- Both tables indexed and `ANALYZE`d after seeding: `bench_ltree` has a GiST
  index on `path` (the package's `$table->gist('path')` macro), `bench_adjacency`
  has a plain btree index on `parent_id`
- Descendants query touches 624 rows (mid-tree node at depth 3, ~1.25% of the
  tree — deliberately not the depth-based midpoint, which with branching 5
  skews to a tiny subtree since most of the tree's mass sits in the deepest
  levels)
- Ancestors query touches 7 rows (a leaf at depth 7 walking back to the root)
- Move pool: 5 timed nodes (+1 warm-up), each with a 31-row subtree
- Delete pool: 5 timed nodes (+1 warm-up), each with a 6-row subtree

## Results (median of 5 timed iterations, after 1 warm-up)

| Operation | ltree (ms) | adjacency+CTE (ms) | Speedup |
|---|---:|---:|---|
| descendants(mid-tree node) | 0.75 | 0.94 | 1.24x faster (ltree) |
| ancestors(deep leaf) | 0.67 | 0.67 | 1.00x faster (ltree) |
| subtree move | 3.47 | 0.68 | 5.07x faster (adjacency) |
| branch delete | 0.71 | 2.81 | 3.94x faster (ltree) |

Several more runs in the same session landed within a similar band:
descendants 1.24–1.48x faster (ltree), ancestors essentially tied (~1.0–1.03x,
sometimes a hair in adjacency's favor), move 4.3–5.2x faster (adjacency),
delete 1.7–3.9x faster (ltree). All four operations are sub-5ms at this tree
size — at 50k nodes the differences are real but small in absolute terms; the
gap widens with tree size and subtree size, since the adjacency side pays a
recursive join per tree level while the ltree side stays a single GiST index
scan.

## What each operation actually runs

- **descendants**: ltree — `LtreeBuilder::whereDescendantOf()`, i.e.
  `path <@ ?::ltree AND path <> ?::ltree` against the GiST index. adjacency —
  `WITH RECURSIVE sub AS (... UNION ALL SELECT a.id FROM bench_adjacency a
  JOIN sub s ON a.parent_id = s.id) SELECT id FROM sub WHERE id <> ?`, one
  join per tree level walked downward.
- **ancestors**: ltree — `LtreeBuilder::whereAncestorOf()`, i.e.
  `path @> ?::ltree AND path <> ?::ltree`. adjacency — a recursive walk up
  `parent_id` via primary-key lookups. Both are cheap here because an
  ancestor chain is bounded by tree depth (7 rows either way), not tree size.
- **subtree move**: ltree — the real `PerformsLtreeOperations::moveTo()` API:
  `UPDATE bench_ltree SET path = ?::ltree || subpath(path, nlevel(?::ltree) - 1)
  WHERE path <@ ?::ltree`, then a `parent_id` update, both inside one
  transaction, plus a final `refresh()`. adjacency — a single
  `UPDATE bench_adjacency SET parent_id = ? WHERE id = ?`.
- **branch delete**: ltree — `DELETE FROM bench_ltree WHERE path <@ ?::ltree`
  (the same core statement `PerformsLtreeOperations::cascadeDelete()` runs;
  the real API additionally loads the affected collection first so
  cancellable `CascadeDeleting`/`CascadeDeleted` events can inspect it, which
  this benchmark's raw-SQL timing does not include). adjacency —
  `WITH RECURSIVE sub AS (...) DELETE FROM bench_adjacency WHERE id IN
  (SELECT id FROM sub)`.

## The honest tradeoff on "move"

Adjacency wins the move benchmark by design, not by accident: reparenting a
node in an adjacency list is a single-row pointer update — O(1) regardless of
how large the moved subtree is, because nothing else has to change. ltree's
`moveTo()` has to rewrite the `path` column of the moved node **and every one
of its descendants**, because each row's path is a materialized,
self-contained ancestry string — that's the same property that makes
`descendants`/`ancestors`/`delete` a single indexed range condition instead
of a recursive join.

The cost doesn't disappear with adjacency, it moves (pun intended): every
future `descendants`/`ancestors` read against an adjacency list pays a
recursive CTE, walking one join per tree level, for the lifetime of the data.
ltree pays the rewrite cost once, at move time, and every subsequent read
stays a single GiST index probe. Which approach wins depends on your
read:write ratio for subtree membership queries — write-heavy, move-heavy
workloads with rare descendant/ancestor lookups favor adjacency; read-heavy
hierarchies (permissions, category trees, comment threads, org charts) that
are rarely restructured favor ltree.

Note the move figure is *conservative* toward ltree: it times the full
`moveTo()` API (a `findOrFail()` load, the subtree `UPDATE`, a `parent_id`
update, and a `refresh()`, all in one transaction), whereas the adjacency side
is a single bare `UPDATE ... WHERE id = ?`. The raw subtree-rewrite statement
alone is faster than the full-API number shown; adjacency still wins the pure
write, but by less than the table's 5x suggests.

## Reproducing

```
docker start ltree-test-pg   # PostgreSQL 18 on 127.0.0.1:55432
php benchmarks/benchmark.php 50000 5
```

See `benchmarks/README.md` for details.
