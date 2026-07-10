<?php

declare(strict_types=1);

use Happenv\Ltree\Exceptions\LtreeException;
use Happenv\Ltree\ValueObjects\LtreePath;

it('returns the parent path or null at the root', function () {
    expect((new LtreePath('a.b.c'))->parent()?->toString())->toBe('a.b')
        // Depth-2 boundary case: pins the `<= 1` cutoff precisely (a `<= 2`
        // off-by-one would wrongly return null here instead of the parent).
        ->and((new LtreePath('a.b'))->parent()?->toString())->toBe('a')
        ->and((new LtreePath('a'))->parent())->toBeNull();
});

it('returns the root segment as a path', function () {
    expect((new LtreePath('a.b.c'))->root()->toString())->toBe('a');
});

it('throws when taking the root of an empty path', function () {
    LtreePath::empty()->root();
})->throws(LtreeException::class);

it('returns first and last labels', function () {
    $p = new LtreePath('a.b.c');
    expect($p->first())->toBe('a')->and($p->last())->toBe('c');
});

it('slices a sub-path', function () {
    $p = new LtreePath('a.b.c.d');
    expect($p->slice(1, 2)->toString())->toBe('b.c')
        ->and($p->slice(2)->toString())->toBe('c.d');
});
