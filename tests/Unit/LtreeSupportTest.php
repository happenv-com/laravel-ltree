<?php

declare(strict_types=1);

use Happenv\Ltree\Support\Ltree;
use Happenv\Ltree\Tests\DatabaseTestCase;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// `Illuminate\Database\Grammar\PostgresGrammar` (as sketched in the task brief)
// does not exist on this Laravel version — the real class is
// `Illuminate\Database\Query\Grammars\PostgresGrammar`, and constructing one
// standalone (outside a resolved Connection) is awkward and fragile. Per the
// brief's own fallback note, this asserts the SQL via a live DatabaseTestCase
// + DB::selectOne() instead: the point is that each helper produces valid,
// correct `nlevel(...)`-style ltree SQL, not the exact string shape.
uses(DatabaseTestCase::class);

it('returns Expression instances for every ltree function helper', function () {
    expect(Ltree::nlevel('a'))->toBeInstanceOf(Expression::class)
        ->and(Ltree::subpath('a', 0, 2))->toBeInstanceOf(Expression::class)
        ->and(Ltree::subpath('a', 0))->toBeInstanceOf(Expression::class)
        ->and(Ltree::subltree('a', 0, 2))->toBeInstanceOf(Expression::class)
        ->and(Ltree::index('a', 'b'))->toBeInstanceOf(Expression::class)
        ->and(Ltree::lca('a', 'b'))->toBeInstanceOf(Expression::class)
        ->and(Ltree::text2ltree('t'))->toBeInstanceOf(Expression::class)
        ->and(Ltree::ltree2text('a'))->toBeInstanceOf(Expression::class)
        ->and(Ltree::concat('a', 'b'))->toBeInstanceOf(Expression::class);
});

it('builds ltree function expressions that produce correct SQL against real rows', function () {
    Schema::create('categories', function ($table) {
        $table->id();
        $table->ltree('a')->nullable();
        $table->ltree('b')->nullable();
        $table->ltree('c')->nullable();
        $table->string('t')->nullable();
    });

    // `a` and `b` are sibling branches (neither an ancestor of the other) so
    // lca() lands on the genuine divergence point; `c` is a real suffix
    // slice of `a` so index() has something to actually locate.
    DB::table('categories')->insert([
        'a' => 'top.gaming.laptops',
        'b' => 'top.office.laptops',
        'c' => 'gaming.laptops',
        't' => 'top.office',
    ]);

    $grammar = DB::connection()->getQueryGrammar();

    $sql = 'select '
        .Ltree::nlevel('a')->getValue($grammar).' as nlevel, '
        .Ltree::subpath('a', 0, 2)->getValue($grammar).' as subpath, '
        .Ltree::subltree('a', 0, 2)->getValue($grammar).' as subltree, '
        .Ltree::index('a', 'c')->getValue($grammar).' as idx, '
        .Ltree::lca('a', 'b')->getValue($grammar).' as lca, '
        .Ltree::text2ltree('t')->getValue($grammar).' as t2l, '
        .Ltree::ltree2text('a')->getValue($grammar).' as l2t, '
        .Ltree::concat('b', 'a')->getValue($grammar).' as concatenated '
        .'from categories';

    expect($sql)->toContain('nlevel(')
        ->and($sql)->toContain('subpath(')
        ->and($sql)->toContain('subltree(')
        ->and($sql)->toContain('index(')
        ->and($sql)->toContain('lca(')
        ->and($sql)->toContain('text2ltree(')
        ->and($sql)->toContain('ltree2text(');

    $row = DB::selectOne($sql);

    expect($row)->not->toBeNull()
        ->and((int) $row->nlevel)->toBe(3)
        ->and($row->subpath)->toBe('top.gaming')
        ->and($row->subltree)->toBe('top.gaming')
        ->and((int) $row->idx)->toBe(1)
        ->and($row->lca)->toBe('top')
        ->and($row->t2l)->toBe('top.office')
        ->and($row->l2t)->toBe('top.gaming.laptops')
        ->and($row->concatenated)->toBe('top.office.laptops.top.gaming.laptops');
});

it('quotes every identifier passed to a multi-column helper, not just one', function () {
    Schema::create('mixed_case_columns', function ($table) {
        $table->id();
        $table->string('MixedCase')->nullable();
        $table->string('lower')->nullable();
    });

    DB::table('mixed_case_columns')->insert(['MixedCase' => 'Foo', 'lower' => 'Bar']);

    $grammar = DB::connection()->getQueryGrammar();
    $sql = 'select '.Ltree::concat('MixedCase', 'lower')->getValue($grammar).' as c from mixed_case_columns';

    // quoteAll() must map self::quote() over *every* identifier in the
    // varargs list. Postgres folds an unquoted `MixedCase` token to
    // `mixedcase`, which does not resolve against this real mixed-case
    // column — so if the per-identifier mapping were dropped (leaving the
    // raw, unquoted column names glued together), this would fail to find
    // the column instead of returning the concatenated value.
    expect(DB::selectOne($sql)->c)->toBe('FooBar');
});

it('escapes an embedded double quote inside an identifier', function () {
    Schema::create('quirky', function ($table) {
        $table->id();
        $table->string('wei"rd')->nullable();
    });

    DB::table('quirky')->insert(['wei"rd' => 'hello']);

    $grammar = DB::connection()->getQueryGrammar();
    $sql = 'select '.Ltree::concat('wei"rd')->getValue($grammar).' as c from quirky';

    // quote() must double an embedded `"` inside the identifier
    // (`wei"rd` -> `"wei""rd"`). Without that escaping the produced SQL
    // reads `"wei"rd"` — an unterminated quoted identifier — which Postgres
    // rejects with a syntax error instead of resolving to this real column.
    expect(DB::selectOne($sql)->c)->toBe('hello');
});
