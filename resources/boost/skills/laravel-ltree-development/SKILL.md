---
name: laravel-ltree-development
description: >-
  Build hierarchies on PostgreSQL ltree with happenv-com/laravel-ltree. Use when writing a
  migration with $table->ltree()/ltreeDepth()/gist(), adding the HasLtree trait to a model or
  overriding getLtreeLabel()/getLtreeLabelDependsOnKey(), querying a tree (descendants, ancestors,
  siblings, root relations; whereDescendantOf, whereAncestorOf, whereChildOf, whereDepth,
  wherePath(), wherePathMatches lquery, wherePathContains/search ltxtquery, commonAncestor),
  restructuring it (moveTo, detach, copyTo, appendChild, renameSegment, cascadeDelete,
  moveBefore/moveAfter, rebuildPaths), shaping results (LtreeCollection::toTree), binding routes
  by {model:path}, working with LtreePath, testing with TreeBuilder / HasLtreeFactory, or running
  ltree:install, ltree:check, ltree:rebuild, ltree:optimize.
---

# Laravel LTree

Eloquent integration for PostgreSQL's `ltree` extension. Each row stores its **materialized path** (`1.5.23`, or `electronics.laptops`) in an `ltree` column with a GiST index, so "everything under X", "every ancestor of Y" and pattern matches are single index-backed operators. The `parent_id` column stays as the structural anchor: the path is derived from it on insert and rebuilt from it on repair. **The path is written by the package (on create and by its tree operations), never by ordinary updates.**

Requirements: PHP 8.3+, Laravel 12 or 13, PostgreSQL 16-18. PostgreSQL only: no SQLite, no MySQL.

## When to use this skill

- Creating or migrating a table that holds a hierarchy (categories, org units, comment threads, permissions).
- Writing queries that walk a tree, or eager-loading subtrees and ancestor chains.
- Moving, copying, renaming or deleting branches.
- Building tree fixtures in tests or seeders; diagnosing broken paths.

## Installation

```bash
composer require happenv-com/laravel-ltree
php artisan ltree:install            # publishes config/ltree.php, runs CREATE EXTENSION IF NOT EXISTS ltree
php artisan ltree:install --no-extension   # when the DB user lacks the privilege
```

The extension must exist in **every** database the app migrates, test databases included. Either call `LtreeExtension::create()` at the top of the first migration that uses ltree (what the `ltree-migrations` stub does), or set `ltree.auto_create_extension => true` (creates it on `MigrationsStarted`, default connection only). `LtreeExtension::create('connection')` covers another connection.

## Migration

```php
use Happenv\Ltree\Database\Schema\LtreeExtension;

LtreeExtension::create();

Schema::create('categories', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
    $table->ltree('path')->nullable();   // nullable: an auto-increment label is only known after INSERT
    $table->ltreeDepth()->nullable();    // integer GENERATED ALWAYS AS (nlevel("path")) STORED
    $table->gist('path');                // the index every ltree operator needs
    $table->timestamps();
});
```

- Always add `$table->gist('path')` in the same migration. `$table->gin('path')` throws `UnsupportedIndexException` (GIN is for `ltree[]` only).
- `ltreeDepth($column = 'depth', $from = 'path')` is `NOT NULL` unless you chain `->nullable()`. With a nullable path (auto-increment labels) it must be nullable too, or the first `INSERT` (path still `NULL`) fails. Other macros: `lquery('col')`, `ltxtquery('col')`.
- A UUID/ULID or slug label is known during `creating`, so `path` may be `NOT NULL` there.

## Model

```php
use Happenv\Ltree\Concerns\HasLtree;

class Category extends Model
{
    use HasLtree;

    protected $fillable = ['name', 'parent_id'];
}
```

`HasLtree` does not change mass assignment or timestamps: declare `$fillable` (or `$guarded`) as usual. `HasLtree` registers the `LtreeCast` on the path column, swaps the builder for `LtreeBuilder`, the collection for `LtreeCollection`, and computes the path on create from the parent and the model's **label**.

- Default label: the primary key (normalized: `-` becomes `_`, so a UUID label is `0199..._...`). With an auto-increment key the row is inserted with a `NULL` path and an extra `UPDATE` fills it in `created`.
- Slug labels: override both hooks.

```php
public function getLtreeLabel(): string
{
    return $this->normalizeLtreeLabel($this->slug);  // runs the configured Normalizer
}

public function getLtreeLabelDependsOnKey(): bool
{
    return false;
}
```

- Legal label characters are `[A-Za-z0-9_]`. The default normalizer replaces anything else with `_` (`'strategy' => 'replace'`); set `ltree.normalizer.strategy` to `'throw'` to get `InvalidLabelException` instead. A custom normalizer implements `Happenv\Ltree\Contracts\Normalizer`.
- Per-model column overrides: `getLtreePathColumn()`, `getLtreeParentColumn()` (return `null` for a path-only table), `getLtreeOrderColumn()`.
- Set the parent with `parent_id` or `->setLtreeParent($parentModel)` before the first `save()`.

```php
$laptops = Category::create(['name' => 'Laptops', 'parent_id' => $computers->id]);
$laptops->path();            // LtreePath "1.2.3" (null until persisted)
$laptops->depth();           // 3, from the path; isRoot(), hasParent() also read the path
$laptops->countDescendants(); // query; also countChildren(), hasChildren(), hasDescendants(), isLeaf()
$laptops->branch();          // builder: self + descendants
```

## Relations

All are real Eloquent relations: lazy, `with()`, `withCount()`, `whereHas()`. Path-based ones load a whole batch in one query.

| Relation | Based on | Notes |
|---|---|---|
| `parent`, `children` | `parent_id` | `BelongsTo` / `HasMany` |
| `descendants`, `descendantsAndSelf` | path `<@` | |
| `ancestors`, `ancestorsAndSelf` | path `@>` | root first |
| `siblings` | `parent_id` | **roots have no siblings**; throws `LogicException` with no parent column |
| `root` | path | single model; a root's root is itself |

```php
Category::whereNull('parent_id')->with('descendants')->get();       // 2 queries
Category::whereHas('descendants', fn ($q) => $q->where('name', 'Laptops'))->get();
```

## Query builder

`Category::query()` is an `LtreeBuilder`. Every node argument accepts a `Model`, an `LtreePath` or a path string; static calls (`Category::wherePathMatches(...)`) forward to it.

```php
// structural (strict: the node itself is excluded)
->whereDescendantOf($node)   ->whereAncestorOf($node)   ->whereChildOf($node)
->whereParentOf($node)       ->whereSiblingOf($node)    ->whereRoot()   ->whereLeaf()
->whereDescendantOfAny([$a, $b])   ->whereAncestorOfAny([$a, $b])   // INCLUSIVE: the given nodes match too; empty list matches nothing
// depth
->whereDepth(3)  ->whereBetweenDepth(2, 4)  ->minDepth(2)  ->maxDepth(3)
->whereWithinDepthOf($node, 2)   // node itself + up to 2 levels below
->orderByDepth('desc')  ->withDepth('depth')
// shape and patterns
->wherePathStartsWith($node)      // INCLUDES the node itself (@>)
->wherePathEndsWith('Laptops')    ->whereSegment('Gaming')
->wherePathMatches('*.Gaming.*')  ->orWherePathMatches('*.Hobbies.*')  ->wherePathMatchesAny([...])
->wherePathContains('Gaming')     ->wherePathContainsAll([...])   ->wherePathContainsAny([...])
->search('Gaming & !Console')     // raw ltxtquery, unvalidated
// utilities
->commonAncestor()                // ?LtreePath, the lca of the matched rows
->tapPath(fn ($q) => ...)
```

Static scopes return the same builder: `Category::roots()`, `leaves()`, `descendantsOf($n)`, `ancestorsOf($n)`, `childrenOf($n)`, `siblingsOf($n)`. Finders: `findByPath('1.2.3')`, `findByLtree($path)`, `findDescendantsOf($n)`, `findAncestorsOf($n)`.

Group constraints so a surrounding `orWhere` cannot leak in:

```php
Category::wherePath(fn ($path) => $path->descendantOf($electronics)->depth(3)->contains('Laptops'))->get();
```

`PathConstraint` verbs: `descendantOf, ancestorOf, childOf, parentOf, siblingOf, root, leaf, depth, betweenDepth, maxDepth, minDepth, withinDepth, startsWith, endsWith, segment, matches, matchesAny, contains, containsAll, containsAny`.

lquery and ltxtquery are case-sensitive; append `@` to a label for case-insensitive, `*` for a prefix (`Gam*`). `wherePathContains*()` reject words with spaces or `& | ! ( )` (`LtreeException`); use `search()` for the full grammar. Raw SQL expressions are in `Happenv\Ltree\Support\Ltree` (`nlevel`, `subpath`, `subltree`, `index`, `lca`, `text2ltree`, `ltree2text`, `concat`).

## Tree operations

`moveTo`, `renameSegment` and `cascadeDelete` rewrite (or delete) the whole subtree in one SQL statement; `copyTo` saves each copied node individually and `rebuildPaths` updates row by row. Those five run in a transaction (`ltree.transactional_moves`) and dispatch a cancellable before-event and an after-event (`Happenv\Ltree\Events`, table below). `appendChild`/`prependChild` dispatch no tree events and only open a transaction when an order column is configured; the ordered moves dispatch events only through the `moveTo()` they call when reparenting.

```php
$node->moveTo($newParent);     // whole subtree; also updates parent_id. moveTo(null) == detach()
$copy = $node->copyTo($target); // deep copy with new keys, returns the copy of $node
$parent->appendChild(new Category([...]));   // or prependChild(); child must be NEW (unsaved)
$node->renameSegment('notebooks');           // last label, cascades into descendant paths
$deleted = $node->cascadeDelete();           // alias deleteBranch(); returns row count
$node->rebuildPaths();                       // WHOLE TABLE from parent_id, returns changed rows
```

| Operation | Before (return `false` to veto) | After |
|---|---|---|
| `moveTo` / `detach` | `Moving($node, $target)` | `Moved` |
| `copyTo` | `Copying($node, $target)` | `Copied($original, $copy)` |
| `cascadeDelete` | `CascadeDeleting($node, $nodes)` | `CascadeDeleted($node, $keys)` |
| `renameSegment` | `Renaming($node, $newLabel)` | `Renamed` |
| `rebuildPaths` | `Rebuilding($node)` | `Rebuilt` |

A vetoed operation returns quietly (`$this`, or `0`); it does not throw.

Ordered siblings are opt-in: add an integer column and set `ltree.order_column` (or override `getLtreeOrderColumn()`). Then `appendChild`/`prependChild` set it and `moveBefore($sibling)`, `moveAfter($sibling)`, `moveFirst()`, `moveLast()` renumber siblings densely from 0 (reparenting first when `$sibling` sits elsewhere). Without the column these throw `MissingSortColumnException`.

## Things that bite

- **Changing `parent_id` with `update()`/`save()` does not move anything.** The observer only handles `creating`/`created`. Use `moveTo()`. Likewise, changing a slug does not touch the path: call `renameSegment($newSlug)` as well.
- **Plain `$node->delete()` leaves the descendants' paths pointing at a dead node** (with `nullOnDelete()` their `parent_id` also goes `NULL`, and `ltree:check` reports path/parent mismatches). Delete branches with `cascadeDelete()`, or `moveTo()` the children first.
- **`cascadeDelete()` is one bulk `DELETE`**: no per-model `deleting`/`deleted` events, observers do not run, and `SoftDeletes` is bypassed (rows are really deleted). Listen to `CascadeDeleting`/`CascadeDeleted` instead.
- `moveTo()` and `renameSegment()` `refresh()` only the instance you called them on. Other loaded models (the descendants collection, a cached relation) hold stale paths: re-query them.
- `moveTo()` refuses a target under the node itself or a target without a path (`InvalidMoveException`). ltree does not enforce unique sibling labels: moving or renaming onto an existing label creates two rows with the same path.
- `moveTo()` keeps the old `order_column` value; with ordered siblings use `moveBefore/After/First/Last`.
- A model with a `NULL` path passed to a builder predicate throws `LtreeException` ("empty path"); relations and `branch()` on an unsaved node return nothing.
- Builder predicates use the unqualified, quoted path column. In a `join` with another table that also has `path`, the SQL is ambiguous: use a subquery or `whereIn` on keys.
- `LtreeCollection::toTree()` overwrites each member's `children` relation with the members from the set and returns only the roots of the set; `flatten()` reverses it. `descendants()` / `ancestors()` on the collection run one query.
- `rebuildPaths()` / `ltree:rebuild` load the whole table into memory and throw `LtreeException` on a `parent_id` cycle (everything rolls back). Use it to adopt an existing adjacency list: add the column, then run it once.
- `whereSiblingOf()` (path-based) treats all roots as siblings; the `siblings` relation (`parent_id`-based) does not.
- With `parent_column => null`, `siblings`, `rebuildPaths()` and `ltree:rebuild` are unavailable.
- Bulk imports: set `ltree.auto_update_path` to `false`, insert rows with `parent_id` only, then `rebuildPaths()`. Do not assign `path` by hand otherwise; for slug/UUID labels the observer overwrites it.
- Config keys `default_index`, `cascade_delete` and `fk_on_delete` are not read by any code; setting them changes nothing.

## Route binding and `LtreePath`

```php
Route::get('/categories/{category:path}', fn (Category $category) => $category); // /categories/1.2.3
```

`{category}` still binds by key; nested `resolveChildRouteBinding` with `:path` is supported.

`LtreePath` is an immutable, framework-free value object (`new LtreePath('a.b')`, `LtreePath::of([...])`, `LtreePath::empty()`): `segments()`, `depth()`, `parent()`, `root()`, `first()`, `last()`, `append()`, `prepend()`, `slice()`, `pop()`, `shift()`, `contains()`, `startsWith()`, `endsWith()`, `isAncestorOf()`, `isDescendantOf()`, `equals()`, `compareLexically()`, `toString()`. A segment may not be empty or contain `.` (`LtreeException`). The cast also accepts a string or an array of labels on assignment.

## Testing

Tests need a real PostgreSQL database with the `ltree` extension (see Installation); use `RefreshDatabase` or `DatabaseTransactions` as usual.

```php
use Happenv\Ltree\Testing\TreeBuilder;

$nodes = TreeBuilder::fromArray(Category::class, [     // flat LtreeCollection, pre-order
    ['name' => 'Electronics', 'children' => [
        ['name' => 'Laptops'],
        ['name' => 'Phones'],
    ]],
    ['name' => 'Books'],
]);
```

`fromArray()` fills attributes unguarded. For factories, add `Happenv\Ltree\Testing\HasLtreeFactory` to the factory:

```php
Category::factory()->createTree([['children' => [[], []]]]);   // standalone tree; every node gets the factory definition
Category::factory()->hasTree([                                   // subtree under each created parent
    ['name' => 'Laptops', 'children' => [['name' => 'Gaming']]],
])->create();
```

`hasTree()` builds the subtree with `TreeBuilder::fromArray()`, not the factory, so the descendants get **only** the attributes you list: supply every required column. Prefer `hasTree()` / `createTree()` over chaining Laravel's `has()` for nested trees: each node is saved after its parent, so its path can be computed.

## Artisan

```bash
php artisan ltree:check "App\Models\Category"    # extension, NULL/orphaned paths, dangling parent_id, path vs parent_id fail (non-zero exit); a missing GiST index only warns
php artisan ltree:rebuild "App\Models\Category"  # rebuildPaths()
php artisan ltree:optimize "App\Models\Category" # create the GiST index if missing, REINDEX, ANALYZE
```

Run `ltree:check` in CI or on a schedule if anything other than the package writes to the table.
