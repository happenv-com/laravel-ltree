# Laravel LTree

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://banners.beyondco.de/Laravel%20LTree.png?theme=dark&packageManager=composer+require&packageName=happenv-com%2Flaravel-ltree&pattern=topography&style=style_1&description=PostgreSQL+ltree%2C+lquery+%26+ltxtquery+through+expressive+Eloquent&md=1&showWatermark=0&fontSize=100px&images=https%3A%2F%2Flaravel.com%2Fimg%2Flogomark.min.svg">
  <img alt="Laravel LTree" src="https://banners.beyondco.de/Laravel%20LTree.png?theme=light&packageManager=composer+require&packageName=happenv-com%2Flaravel-ltree&pattern=topography&style=style_1&description=PostgreSQL+ltree%2C+lquery+%26+ltxtquery+through+expressive+Eloquent&md=1&showWatermark=0&fontSize=100px&images=https%3A%2F%2Flaravel.com%2Fimg%2Flogomark.min.svg">
</picture>

[![Latest Version on Packagist](https://img.shields.io/packagist/v/happenv-com/laravel-ltree.svg?style=flat-square)](https://packagist.org/packages/happenv-com/laravel-ltree)
[![Total Downloads](https://img.shields.io/packagist/dt/happenv-com/laravel-ltree.svg?style=flat-square)](https://packagist.org/packages/happenv-com/laravel-ltree)
[![Tests](https://img.shields.io/github/actions/workflow/status/happenv-com/laravel-ltree/tests.yml?branch=1.x&style=flat-square&label=tests)](https://github.com/happenv-com/laravel-ltree/actions/workflows/tests.yml)
[![Mutation](https://img.shields.io/github/actions/workflow/status/happenv-com/laravel-ltree/mutation.yml?branch=1.x&style=flat-square&label=mutation)](https://github.com/happenv-com/laravel-ltree/actions/workflows/mutation.yml)
[![PHPStan](https://img.shields.io/github/actions/workflow/status/happenv-com/laravel-ltree/phpstan.yml?branch=1.x&style=flat-square&label=phpstan)](https://github.com/happenv-com/laravel-ltree/actions/workflows/phpstan.yml)
[![Zizmor](https://img.shields.io/github/actions/workflow/status/happenv-com/laravel-ltree/zizmor.yml?branch=1.x&style=flat-square&label=zizmor)](https://github.com/happenv-com/laravel-ltree/actions/workflows/zizmor.yml)
[![Code Style](https://img.shields.io/github/actions/workflow/status/happenv-com/laravel-ltree/fix-code-style.yml?branch=1.x&style=flat-square&label=code%20style)](https://github.com/happenv-com/laravel-ltree/actions/workflows/fix-code-style.yml)

[![PHP 8.3+](https://img.shields.io/badge/php-8.3%2B-777bb4?style=flat-square)](https://www.php.net/)
[![Laravel 12 | 13](https://img.shields.io/badge/laravel-12%20%7C%2013-ff2d20?style=flat-square)](https://laravel.com/)
[![PostgreSQL 16–18](https://img.shields.io/badge/postgresql-16--18-336791?style=flat-square)](https://www.postgresql.org/docs/current/ltree.html)

> **This package is not another tree implementation. PostgreSQL already provides one.**
> **This package exposes it through an expressive Laravel API.**

PostgreSQL's [`ltree`](https://www.postgresql.org/docs/current/ltree.html) extension stores hierarchies as materialized paths and indexes them with GiST. Subtree membership, ancestor lookups, pattern matching and depth queries become single, index-backed operators — no recursive CTEs, no `lft`/`rgt` renumbering, no N+1.

`laravel-ltree` gives you that power through Eloquent: a typed query builder, real (eager-loadable) relations, transactional tree operations, `lquery`/`ltxtquery` pattern matching, a tree-aware collection, route-model binding, and Artisan tooling — all fully typed (PHPStan level max), with **no raw SQL in your application code**.

```php
$category->descendants;                              // eager relation, one query
Category::descendantsOf($electronics)->count();      // path <@ :node, GiST-indexed
Category::wherePathMatches('*.Gaming.*')->get();     // lquery
$laptop->moveTo($peripherals);                       // transactional subtree move
```

---

## Table of contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Migrations](#migrations)
- [Basic usage](#basic-usage)
- [Reading the tree](#reading-the-tree)
- [Relationships](#relationships)
- [The query builder](#the-query-builder)
- [The `wherePath` DSL](#the-wherepath-dsl)
- [lquery — path pattern matching](#lquery--path-pattern-matching)
- [ltxtquery — full-text label matching](#ltxtquery--full-text-label-matching)
- [Tree operations](#tree-operations)
- [Collections](#collections)
- [Finders & route-model binding](#finders--route-model-binding)
- [The `LtreePath` value object](#the-ltreepath-value-object)
- [Testing helpers](#testing-helpers)
- [Artisan commands](#artisan-commands)
- [Configuration](#configuration)
- [Indexes](#indexes)
- [Performance](#performance)
- [Best practices](#best-practices)
- [Why not `parent_id`?](#why-not-parent_id)
- [Why not nested sets?](#why-not-nested-sets)
- [Events](#events)
- [FAQ](#faq)
- [Quality](#quality)
- [License](#license)

---

## Requirements

- PHP **8.3+**
- Laravel **12 or 13**
- PostgreSQL **16, 17, or 18** (the suite is verified to pass identically on all three)

## Installation

```bash
composer require happenv-com/laravel-ltree
```

The service provider is auto-discovered. Then run the installer, which publishes the config file and creates the `ltree` extension on your default connection:

```bash
php artisan ltree:install
```

`ltree:install` accepts `--no-extension` (skip `CREATE EXTENSION`, e.g. when your database user lacks the privilege and a DBA will create it) and `--force` (overwrite an existing published config). To publish the config manually:

```bash
php artisan vendor:publish --tag=ltree-config
```

## Migrations

The package registers Blueprint macros so your migrations read naturally. A table backed by ltree typically keeps a `parent_id` adjacency column too — it's optional but recommended (see [below](#the-parent_id-column)):

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->ltree('path')->nullable();   // the materialized path
            $table->ltreeDepth();                 // STORED generated column: nlevel(path)
            $table->gist('path');                 // GiST index — the index that makes ltree fast
        });
    }
};
```

The Blueprint macros:

| Macro | Column type |
|---|---|
| `$table->ltree('path')` | `ltree` |
| `$table->lquery('col')` | `lquery` |
| `$table->ltxtquery('col')` | `ltxtquery` |
| `$table->ltreeDepth('depth', from: 'path')` | `integer GENERATED ALWAYS AS (nlevel(path)) STORED` |
| `$table->gist('path')` | GiST index using `gist_ltree_ops` |
| `$table->gin('path')` | **throws** `UnsupportedIndexException` — there is no GIN opclass for a scalar `ltree`; use `gist()` |

`path` is nullable because, with the default primary-key label, a new row's path can only be built once its auto-increment id exists — the package backfills it immediately after insert (see [Basic usage](#basic-usage)). The `ltreeDepth()` column is a real stored column PostgreSQL keeps in sync, so ordering and filtering by depth never call `nlevel()` at query time.

## Basic usage

Add the `HasLtree` trait to any Eloquent model:

```php
use Happenv\Ltree\Concerns\HasLtree;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasLtree;

    protected $guarded = [];
}
```

That's the whole setup. The package maintains `path` for you from the model's **label** and its parent. By default the label is the model's primary key, so paths look like `1`, `1.5`, `1.5.23`:

```php
$electronics = Category::create(['name' => 'Electronics']);            // path: "1"
$computers   = Category::create(['name' => 'Computers', 'parent_id' => $electronics->id]); // path: "1.2"
$laptops     = Category::create(['name' => 'Laptops', 'parent_id' => $computers->id]);     // path: "1.2.3"
```

Set the parent by `parent_id`, or with the fluent helper before saving:

```php
$laptops = new Category(['name' => 'Laptops']);
$laptops->setLtreeParent($computers)->save();          // path derived from $computers
```

### Human-readable labels

Prefer slugs in the path (`electronics.computers.laptops`)? Override two hooks — return your column as the label, and tell the package the label no longer depends on the primary key:

```php
class Category extends Model
{
    use HasLtree;

    public function getLtreeLabel(): string
    {
        return $this->normalizeLtreeLabel($this->slug);   // e.g. "gaming-laptops"
    }

    public function getLtreeLabelDependsOnKey(): bool
    {
        return false;   // label comes from the slug, not the auto-increment id
    }
}
```

`normalizeLtreeLabel()` runs the value through the configured [normalizer](#configuration), which by default replaces characters that are illegal in an ltree label (a hyphen becomes an underscore: `gaming-laptops` → `gaming_laptops`). Switch the normalizer to the `throw` strategy to reject illegal labels with an `InvalidLabelException` instead.

### The `path` attribute

`path` is cast to an immutable [`LtreePath`](#the-ltreepath-value-object) value object, and `path()` returns it (or `null` before the row is persisted):

```php
$laptops->path();              // LtreePath("1.2.3")
(string) $laptops->path();     // "1.2.3"
$laptops->path()->depth();     // 3
```

## Reading the tree

Every model gets a set of read helpers that answer structural questions without loading relations:

```php
$node->depth();            // nlevel(path) — 1 for a root
$node->level();            // alias of depth()
$node->isRoot();           // depth === 1
$node->isLeaf();           // has no descendants
$node->hasParent();
$node->hasChildren();
$node->hasDescendants();
$node->countChildren();    // direct children
$node->countDescendants(); // whole subtree, excluding self
$node->branch();           // query builder for this node's whole subtree (self + descendants)
```

## Relationships

All eight relations are real Eloquent relations — they eager-load (`with(...)`), count, and work with `whereHas`. The path-based ones issue **one query for an entire batch**, never one per parent:

```php
$node->parent;              // BelongsTo (via parent_id)
$node->children;            // HasMany  (via parent_id)

$node->descendants;         // strict descendants  (path <@ node)
$node->descendantsAndSelf;  // descendants including the node
$node->ancestors;           // strict ancestors    (path @> node), root-first
$node->ancestorsAndSelf;    // ancestors including the node
$node->siblings;            // same parent, excluding self
$node->root;                // the top-most (depth-1) ancestor; a root's root is itself
```

Eager loading a forest is a single extra query regardless of how many nodes you load:

```php
$roots = Category::whereNull('parent_id')->with('descendants')->get();  // 2 queries total
```

`whereHas` works because the relations compile to correlated ltree predicates:

```php
Category::whereHas('descendants', fn ($q) => $q->where('name', 'Laptops'))->get();
Category::whereHas('children')->get();   // only nodes that actually have children
```

## The query builder

`Category::query()` returns a typed `LtreeBuilder<Category>`, so every predicate below is a first-class, IDE-completed method (not a global macro) and PHPStan resolves it at level max. Each predicate accepts a `Model`, an `LtreePath`, or a raw path `string`.

**Structural**

```php
Category::query()->whereRoot();
Category::query()->whereLeaf();
Category::query()->whereAncestorOf($node);
Category::query()->whereDescendantOf($node);
Category::query()->whereChildOf($node);
Category::query()->whereParentOf($node);
Category::query()->whereSiblingOf($node);
Category::query()->whereDescendantOfAny([$a, $b, $c]);   // union across several nodes, one query
Category::query()->whereAncestorOfAny([$a, $b]);
```

**Depth**

```php
Category::query()->whereDepth(3);
Category::query()->whereBetweenDepth(2, 4);
Category::query()->maxDepth(3);
Category::query()->minDepth(2);
Category::query()->whereWithinDepthOf($node, 2);         // node + up to 2 levels below
Category::query()->orderByDepth('desc');
Category::query()->withDepth();                          // adds a "depth" column
```

**Path shape**

```php
Category::query()->wherePathStartsWith($ancestor);       // :ancestor @> path
Category::query()->wherePathEndsWith('Laptops');         // lquery *.Laptops
Category::query()->whereSegment('Gaming');               // label anywhere in the path
```

**Aggregates & utilities**

```php
Category::query()->whereKey([$a->id, $b->id])->commonAncestor();  // LtreePath|null (lca)
Category::query()->tapPath(fn ($q) => $q->where('active', true)); // tap, keep chaining
```

**Query scopes** — the same building blocks as convenient static entry points, each returning an `LtreeBuilder`:

```php
Category::roots()->get();
Category::leaves()->get();
Category::descendantsOf($node)->get();
Category::ancestorsOf($node)->get();
Category::childrenOf($node)->get();
Category::siblingsOf($node)->get();
```

## The `wherePath` DSL

Compose several path constraints in one grouped `WHERE` with a fluent, closure-based builder — handy when you want the constraints isolated from surrounding `orWhere` clauses:

```php
Category::wherePath(fn ($path) => $path
    ->descendantOf($electronics)
    ->depth(3)
    ->contains('Laptops'))
    ->get();
```

The closure receives a `PathConstraint` exposing every predicate as a chainable verb: `descendantOf`, `ancestorOf`, `childOf`, `parentOf`, `siblingOf`, `root`, `leaf`, `depth`, `betweenDepth`, `maxDepth`, `minDepth`, `withinDepth`, `startsWith`, `endsWith`, `segment`, `matches`, `matchesAny`, `contains`, `containsAll`, `containsAny`.

## lquery — path pattern matching

[`lquery`](https://www.postgresql.org/docs/current/ltree.html#LTREE-LQUERY) matches a path against a pattern with wildcards (`*`), quantifiers (`*{1,2}`), negation (`!`), and alternation:

```php
Category::wherePathMatches('*.Gaming.*')->get();          // Gaming anywhere in the path
Category::wherePathMatches('Top.*{1}.Laptops')->get();    // exactly one label between Top and Laptops
Category::wherePathMatches('*.!Physics')->get();          // last label is not "Physics"

Category::wherePathMatches('*.Science.*')
    ->orWherePathMatches('*.Hobbies.*')
    ->get();

Category::wherePathMatchesAny(['*.Astronomy', '*.Physics'])->get();  // match ANY of several lqueries
```

`lquery` is case-sensitive by default; append the `@` flag to a label to make just that label case-insensitive: `wherePathMatches('*.science@.*')`.

## ltxtquery — full-text label matching

[`ltxtquery`](https://www.postgresql.org/docs/current/ltree.html#LTREE-LTXTQUERY) matches whole labels anywhere in the path with boolean operators. The safe helpers validate each word so an operator can't be smuggled in and silently change the query's meaning:

```php
Category::wherePathContains('Gaming')->get();                     // has a "Gaming" label
Category::wherePathContainsAll(['Gaming', 'Laptop'])->get();      // Gaming AND Laptop
Category::wherePathContainsAny(['Laptop', 'Desktop'])->get();     // Laptop OR Desktop
```

Words match a **whole label** (so `Gam` does not match `Gaming`); append `*` for a prefix (`Gam*`) or `@` for case-insensitivity (`gaming@`). For the full raw grammar (`&`, `|`, `!`, grouping) use `search()`:

```php
Category::search('Gaming & !Console')->get();
```

## Tree operations

Every mutating operation runs inside a transaction (toggle with the `transactional_moves` config), fires cancellable "before" events and "after" events, and uses PostgreSQL's own path arithmetic — a subtree move or rename is a **single `UPDATE`**, not a row-by-row walk.

```php
$laptops->moveTo($peripherals);   // reparent the whole subtree; guards against moving under itself
$laptops->detach();               // make it a new root (moveTo(null))
$copy = $laptops->copyTo($store); // deep-copy the subtree with new keys; returns the new root

$node->appendChild($child);       // attach as a child
$node->prependChild($child);

$branch->cascadeDelete();         // DELETE the whole subtree; alias: deleteBranch()
$node->renameSegment('notebooks');// rename this node's label; cascades to every descendant path

Category::query()->first()->rebuildPaths();  // recompute every path from parent_id (repair / migration)
```

Moving under your own descendant throws an `InvalidMoveException`.

### Ordered siblings (opt-in)

Sibling ordering is opt-in. Add an integer column, point `order_column` at it (config or per model), and you get ordered moves:

```php
// config/ltree.php  →  'order_column' => 'sort_order'   (default is null = unordered)

$node->moveBefore($sibling);
$node->moveAfter($sibling);
$node->moveFirst();
$node->moveLast();
```

Calling an ordered move without a configured order column throws a `MissingSortColumnException`.

## Collections

Query results come back as an `LtreeCollection` with tree-shaping helpers — all in memory, plus two that hit the database once for the whole set:

```php
$flat = Category::query()->whereDescendantOf($root)->get();

$tree = $flat->toTree();     // nest into a tree; each node's `children` relation is populated
$tree->flatten();            // inverse of toTree()
$flat->roots();              // members with no parent in the set
$flat->leaves();             // members that aren't a parent of another member
$flat->sortTree();           // depth-first (path) order

$flat->descendants();        // ONE query: every descendant of every member, minus the members
$flat->ancestors();          // ONE query: every ancestor of every member
```

## Finders & route-model binding

```php
Category::findByPath('1.2.3');     // ?Category by path string
Category::findByLtree($ltreePath); // ?Category by LtreePath
Category::findDescendantsOf($node);// LtreeCollection
Category::findAncestorsOf($node);  // LtreeCollection
```

Bind a route to a model **by path** with the standard `{model:field}` syntax:

```php
Route::get('/categories/{category:path}', fn (Category $category) => $category);
// GET /categories/1.2.3  → resolves the category whose path is "1.2.3"
```

Non-path fields (and nested `resolveChildRouteBinding`) fall back to Laravel's default behavior, so `{category}` still binds by key.

## The `LtreePath` value object

`LtreePath` is a framework-independent, immutable (`final readonly`) value object — `Countable`, `IteratorAggregate`, `Stringable`, `JsonSerializable`. It never touches the database:

```php
use Happenv\Ltree\ValueObjects\LtreePath;

$path = new LtreePath('electronics.computers.laptops');

$path->segments();                 // ['electronics', 'computers', 'laptops']
$path->depth();                    // 3
$path->parent();                   // LtreePath("electronics.computers") | null at the root
$path->root();                     // LtreePath("electronics")
$path->first();  $path->last();    // "electronics" / "laptops"
$path->append('gaming');           // LtreePath("...laptops.gaming")  (returns a new instance)
$path->prepend('catalog');
$path->slice(1, 2);

$path->contains('computers');      // true
$path->startsWith('electronics');  // true
$path->endsWith('laptops');        // true
$path->isAncestorOf($other);
$path->isDescendantOf($other);
$path->equals($other);
$path->compareLexically($other);   // -1 | 0 | 1, for sorting
(string) $path;                    // "electronics.computers.laptops"
```

## Testing helpers

Build trees in tests and seeders without writing nested `create()` calls. `TreeBuilder::fromArray()` takes a nested `children` structure and returns the flat `LtreeCollection` of everything it created:

```php
use Happenv\Ltree\Testing\TreeBuilder;

TreeBuilder::fromArray(Category::class, [
    ['name' => 'Electronics', 'children' => [
        ['name' => 'Computers', 'children' => [
            ['name' => 'Laptops'],
            ['name' => 'Desktops'],
        ]],
        ['name' => 'Phones'],
    ]],
    ['name' => 'Books'],
]);
```

Add the `HasLtreeFactory` trait to a model's factory for a factory-native API:

```php
use Happenv\Ltree\Testing\HasLtreeFactory;

class CategoryFactory extends Factory
{
    use HasLtreeFactory;
    // ...
}

// Build a standalone tree from the factory:
Category::factory()->createTree([...]);

// Or attach a subtree beneath each created parent — mirrors Laravel's has():
Category::factory()->hasTree([
    ['children' => [[], []]],
])->create();
```

## Artisan commands

```bash
php artisan ltree:install                       # publish config + create the ltree extension
php artisan ltree:check "App\Models\Category"   # integrity diagnostic (see below)
php artisan ltree:rebuild "App\Models\Category" # recompute every path from parent_id
php artisan ltree:optimize "App\Models\Category"# ensure the GiST index, then REINDEX + ANALYZE
```

`ltree:check` verifies the extension is installed, the path column has a GiST index, and — reporting a non-zero exit code on any integrity problem — that there are no `NULL` paths, no orphaned paths (a node whose parent path is missing), no dangling `parent_id` references, and that `path` and `parent_id` agree. It fails cleanly (no stack trace) on an unmigrated table.

## Configuration

`config/ltree.php`:

```php
return [
    'path_column'          => 'path',
    'parent_column'        => 'parent_id',   // set null to run purely on ltree, no adjacency mirror
    'order_column'         => null,          // set to e.g. 'sort_order' to enable ordered siblings
    'auto_update_path'     => true,          // maintain `path` automatically via the model observer
    'auto_create_extension'=> false,         // CREATE EXTENSION during migrations
    'default_index'        => 'gist',
    'transactional_moves'  => true,          // wrap tree operations in a transaction
    'cascade_delete'       => false,
    'fk_on_delete'         => 'restrict',
    'normalizer' => [
        'class'        => \Happenv\Ltree\Normalization\DefaultNormalizer::class,
        'strategy'     => 'replace',         // 'replace' illegal chars, or 'throw'
        'replacements' => ['-' => '_'],
    ],
];
```

Any of the per-model column hooks (`getLtreePathColumn`, `getLtreeParentColumn`, `getLtreeOrderColumn`) can be overridden on a model to diverge from the global config.

## Indexes

The single index that matters is a **GiST** index on the path column (`$table->gist('path')`). It backs every containment (`<@`, `@>`), `lquery` (`~`), and `ltxtquery` (`@`) operator.

There is **no GIN operator class for a scalar `ltree`** in PostgreSQL — GIN only applies to `ltree[]` (arrays of paths). Calling `$table->gin('path')` therefore throws `UnsupportedIndexException` with a message pointing you at `gist()`, rather than silently creating an index that can't serve ltree queries. `ltree:optimize` creates the GiST index if it's missing.

## Performance

The `benchmarks/` directory contains a runnable harness comparing this package's `ltree` approach against a plain `parent_id` adjacency list queried with recursive CTEs. Indicative numbers from a 50,000-node tree on PostgreSQL 18 (see [`benchmarks/RESULTS.md`](benchmarks/RESULTS.md) for the full, caveated results):

| Operation | ltree | adjacency + recursive CTE |
|---|---|---|
| descendants of a node | **faster** (single GiST probe) | recursive walk, one join per level |
| ancestors of a node | tied (both bounded by depth) | tied |
| subtree move | rewrites the subtree's paths | **faster** (one pointer update) |
| branch delete | **faster** (`DELETE … WHERE path <@ ?`) | recursive delete |

The honest tradeoff: adjacency wins the *move* because it only re-points one `parent_id` — but it pays that back on **every** descendant/ancestor read, forever, via a recursive CTE. ltree pays the rewrite once, at move time, and keeps every read a single index probe. Read-heavy hierarchies (permissions, categories, comment threads, org charts) that are rarely restructured favor ltree; write-heavy, move-heavy trees with rare membership queries favor adjacency.

```bash
docker compose up -d
php benchmarks/benchmark.php 50000
```

## Best practices

- **Keep the `parent_id` column.** It's cheap, it's the source of truth `ltree:rebuild` and `ltree:check` rely on, it powers FKs/`ON DELETE` and the `parent`/`children`/`siblings` relations, and it makes importing from an existing adjacency list trivial. `ltree` powers the *queries*; `parent_id` stays the *structural anchor*. (Set `parent_column` to `null` only if you truly want a path-only table.)
- **Always create the GiST index** in the same migration as the `ltree` column. Without it, every query is a sequential scan.
- **Prefer stable labels** for human-readable paths. If a label can change, remember `renameSegment()` rewrites every descendant path — cheap, but a write.
- **Reach for the relations and scopes first.** Drop to `lquery`/`ltxtquery` for genuine pattern matching, and to `search()` only when you need the raw grammar.
- **Run `ltree:check` in CI or a scheduled task** if anything outside the package writes to the table.

## Why not `parent_id`?

A plain `parent_id` adjacency list is the natural first model, and this package keeps it around. But `parent_id` alone can't answer the questions hierarchies are actually about — "everything under this node", "the whole ancestor chain", "everything three levels deep" — without a **recursive CTE that re-walks the tree on every read**, one join per level, for the lifetime of the data. There's no single index that makes those reads fast.

ltree stores the answer — the materialized path — in an indexed column. "Everything under this node" becomes `path <@ :node`, a single GiST index probe, whatever the depth. You keep `parent_id` for what it's genuinely good at (a simple, FK-enforceable pointer to the immediate parent) and let ltree serve the subtree/ancestor/pattern queries.

## Why not nested sets?

Nested sets (`lft`/`rgt`) also make subtree reads fast, but they encode position as a pair of boundary numbers spanning the *entire* tree. Inserting or moving a node has to **renumber every node to its right** — a write touching a large fraction of the table, wrapped in a lock to stay consistent. That's painful for anything write-heavy, and the invariants are easy to corrupt.

ltree is a materialized *path*, not a global numbering. A move rewrites only the moved subtree's paths (`O(subtree)`, not `O(tree)`); inserts touch one row; and there is no fragile global invariant to keep in sync — a path is self-describing. You get nested-set-class read performance without nested-set write costs, and PostgreSQL maintains the index for you.

## Events

Each tree operation dispatches a cancellable "before" event (return `false` from a listener to abort) and an "after" event:

| Operation | Before | After |
|---|---|---|
| `moveTo` / `detach` | `Moving` | `Moved` |
| `copyTo` | `Copying` | `Copied` |
| `cascadeDelete` | `CascadeDeleting` | `CascadeDeleted` |
| `renameSegment` | `Renaming` | `Renamed` |
| `rebuildPaths` | `Rebuilding` | `Rebuilt` |

All live in `Happenv\Ltree\Events`.

## FAQ

**Do my models have to implement an interface?** No. Add the `HasLtree` trait and you're done — it's a zero-config, interface-free trait.

**Can the path use names/slugs instead of ids?** Yes — override `getLtreeLabel()` and return `false` from `getLtreeLabelDependsOnKey()`. See [Human-readable labels](#human-readable-labels).

**Does it work with UUID primary keys?** Yes. With a key-based label the path is composed of the keys; with `HasUuids` the path is built from the UUIDs.

**Do I have to write any SQL?** No. Every operator and function is exposed through typed methods with bound parameters. If you ever want the raw function expressions (`nlevel`, `subpath`, `lca`, …) they're available via `Happenv\Ltree\Support\Ltree`.

**Is it safe against SQL injection?** Yes. Every user-supplied value is bound; identifiers come from your config and are quoted.

**Which PostgreSQL versions are supported?** 16, 17, and 18 — the full suite is run against all three in CI.

## Quality

- **PHPStan level max** + Larastan `bleedingEdge`, no baseline. The analysis carries exactly one documented `ignoreErrors` entry: Laravel 13 types all raw SQL as `literal-string` to deter injection, which a configurable-column query builder cannot satisfy for its (config-derived, quoted identifier + fully bound values) SQL. That single, narrowly-scoped false positive is the only suppression — see the comment in `phpstan.neon`.
- **Pest-native mutation testing** with a required covered-mutation score of **100**.
- **Laravel Pint** for formatting.
- The full test suite runs against **PostgreSQL 16, 17, and 18** and **Laravel 12 and 13** in CI.

## License

The MIT License (MIT). See [LICENSE](LICENSE).
