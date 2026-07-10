<?php

declare(strict_types=1);

use Happenv\Ltree\Tests\DatabaseTestCase;
use Happenv\Ltree\Tests\Fixtures\Category;
use Happenv\Ltree\ValueObjects\LtreePath;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

beforeEach(function () {
    Schema::create('categories', function ($table) {
        $table->id();
        $table->foreignId('parent_id')->nullable();
        $table->ltree('path')->nullable();
    });
});

it('resolves column config from ltree config', function () {
    $c = new Category;
    expect($c->getLtreePathColumn())->toBe('path')
        ->and($c->getLtreeParentColumn())->toBe('parent_id')
        ->and($c->getLtreeOrderColumn())->toBeNull();
});

it('casts the path column to an LtreePath and derives helpers', function () {
    $c = new Category(['path' => 'a.b.c']);

    expect($c->path())->toBeInstanceOf(LtreePath::class)
        ->and($c->path()->toString())->toBe('a.b.c')
        ->and($c->depth())->toBe(3)
        ->and($c->level())->toBe(3)
        ->and($c->isRoot())->toBeFalse();

    expect((new Category(['path' => 'a']))->isRoot())->toBeTrue()
        ->and((new Category)->depth())->toBe(0);
});

it('defaults the ltree label to the primary key', function () {
    $c = new Category(['id' => 42]);
    expect($c->getLtreeLabel())->toBe('42');
});

it('reports isRoot()/hasParent() including the no-path fallback', function () {
    // No path attribute at all: path() is null, so both fall back to their
    // documented defaults (isRoot => false, hasParent => false) rather than
    // dereferencing a null path.
    $noPath = new Category;
    expect($noPath->isRoot())->toBeFalse()
        ->and($noPath->hasParent())->toBeFalse();

    // Root (depth 1): has no parent.
    expect((new Category(['path' => 'a']))->hasParent())->toBeFalse();

    // Depth 2: has a parent.
    expect((new Category(['path' => 'a.b']))->hasParent())->toBeTrue();
});

it('short-circuits getLtreeParent() without a query when there is no parent to resolve', function () {
    $root = Category::create([]);

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    // parent_id is null on a root row: getLtreeParent() must return null
    // without ever issuing a lookup query for it.
    expect($root->getLtreeParent())->toBeNull()
        ->and($queries)->toBe(0);
});

it('defaults normalizer.replacements to hyphen->underscore when the config key is entirely absent', function () {
    // config('ltree.normalizer.replacements', ['-' => '_'])'s own default
    // argument only matters when the dotted key is truly absent —
    // mergeConfigFrom() always merges the package default in, so it is never
    // actually absent in normal operation. Remove it directly to reach that
    // argument (as opposed to DefaultNormalizerTest, which constructs
    // DefaultNormalizer directly and never goes through this config lookup).
    //
    // Strategy is forced to 'throw' to make the default's *content* observable:
    // under the 'replace' strategy, DefaultNormalizer's illegal-char fallback
    // (preg_replace ILLEGAL -> '_') converts a leftover hyphen to the exact
    // same '_' regardless of whether the replacements map handled it first,
    // so a 'replace'-strategy assertion can't tell an empty map from
    // ['-' => '_']. Under 'throw', only the default map prevents the hyphen
    // from still being illegal by the time the throw check runs.
    config()->set('ltree.normalizer.strategy', 'throw');
    config(['ltree' => Arr::except(config('ltree'), ['normalizer.replacements'])]);
    expect(array_key_exists('replacements', config('ltree.normalizer')))->toBeFalse();

    $c = new Category;
    expect($c->normalizeLtreeLabel('a-b'))->toBe('a_b');
});
