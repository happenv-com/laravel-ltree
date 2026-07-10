# Benchmarks

`benchmark.php` is a standalone PHP script (not part of the autoloaded
`Happenv\Ltree` package, not covered by the mutation-tested value layer in
`src/`) that compares this package's PostgreSQL `ltree` approach against a
plain `parent_id` adjacency list queried with recursive CTEs.

It seeds an identical tree shape into two dedicated tables —
`bench_ltree` (id, parent_id, `path ltree` + GiST index) and
`bench_adjacency` (id, parent_id + btree index on parent_id) — then times:

1. **descendants** of a mid-tree node
2. **ancestors** of a deep leaf
3. **subtree move** (reparent a node)
4. **branch delete** (remove a node and its whole subtree)

on both representations, and prints a Markdown results table. Both
benchmark tables are dropped at the end of the run (even on failure), so it
never leaves state behind in whichever database you point it at.

## Running it

Requires a reachable PostgreSQL instance with permission to
`CREATE EXTENSION ltree`. Uses the same environment variables as the test
suite (`tests/DatabaseTestCase.php`):

```
LTREE_DB_HOST=127.0.0.1
LTREE_DB_PORT=55432
LTREE_DB_DATABASE=ltree_test
LTREE_DB_USERNAME=postgres
LTREE_DB_PASSWORD=secret
```

(These are already the defaults baked into the script, matching the
project's local Docker Postgres — `docker start ltree-test-pg`.)

```
php benchmarks/benchmark.php [nodes] [branching]
```

- `nodes` — total node count to seed (default 50000)
- `branching` — children per node while building the tree (default 5)

Example:

```
php benchmarks/benchmark.php 50000 5
```

If 50k nodes takes too long to seed on your hardware, pass a smaller count,
e.g. `php benchmarks/benchmark.php 10000 5`.

## Numbers are indicative

The figures printed by this script — and the ones committed in
`benchmarks/RESULTS.md` — depend heavily on hardware, PostgreSQL version,
cache state, and tree shape. Treat them as a rough guide to *shape* (which
operation favors which representation, and by roughly what order of
magnitude), not as an absolute performance guarantee. Run it yourself against
your own database before making a decision based on these numbers.

See `benchmarks/RESULTS.md` for a captured run and a discussion of the
move/read tradeoff between the two approaches.
