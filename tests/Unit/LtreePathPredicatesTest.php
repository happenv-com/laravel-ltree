<?php

declare(strict_types=1);

use Happenv\Ltree\ValueObjects\LtreePath;

it('detects roots', function () {
    expect((new LtreePath('a'))->isRoot())->toBeTrue()
        ->and((new LtreePath('a.b'))->isRoot())->toBeFalse();
});

it('checks label membership', function () {
    expect((new LtreePath('a.b.c'))->contains('b'))->toBeTrue()
        ->and((new LtreePath('a.b.c'))->contains('x'))->toBeFalse();
});

it('checks prefix and suffix (inclusive of equal)', function () {
    $p = new LtreePath('a.b.c');
    expect($p->startsWith('a.b'))->toBeTrue()
        ->and($p->startsWith('a.b.c'))->toBeTrue()
        ->and($p->startsWith('b'))->toBeFalse()
        ->and($p->endsWith('b.c'))->toBeTrue()
        ->and($p->endsWith('a.b.c'))->toBeTrue()
        ->and($p->endsWith('a'))->toBeFalse()
        ->and($p->endsWith(''))->toBeTrue()
        ->and($p->endsWith(LtreePath::empty()))->toBeTrue();
});

it('checks equality', function () {
    expect((new LtreePath('a.b'))->equals('a.b'))->toBeTrue()
        ->and((new LtreePath('a.b'))->equals(new LtreePath('a.b')))->toBeTrue()
        ->and((new LtreePath('a.b'))->equals('a.c'))->toBeFalse();
});

it('checks proper ancestry and descent', function () {
    $node = new LtreePath('a.b.c');
    expect((new LtreePath('a.b'))->isAncestorOf($node))->toBeTrue()
        ->and($node->isAncestorOf($node))->toBeFalse()       // not proper
        ->and($node->isDescendantOf(new LtreePath('a.b')))->toBeTrue()
        ->and($node->isDescendantOf($node))->toBeFalse()
        ->and((new LtreePath('a.x'))->isAncestorOf($node))->toBeFalse();
});

it('compares label-by-label then by depth', function () {
    expect((new LtreePath('a.b'))->compareLexically('a.b'))->toBe(0)
        ->and((new LtreePath('a.a'))->compareLexically('a.b'))->toBe(-1)
        ->and((new LtreePath('a.b'))->compareLexically('a.a'))->toBe(1)
        ->and((new LtreePath('a'))->compareLexically('a.b'))->toBe(-1)   // prefix -> shorter first
        ->and((new LtreePath('b'))->compareLexically('a.b'))->toBe(1);   // first label wins over depth
});

it('compares numeric labels lexically, not numerically', function () {
    expect((new LtreePath('2'))->compareLexically('10'))->toBe(1)      // byte order: '2' > '10'
        ->and((new LtreePath('10'))->compareLexically('9'))->toBe(-1)  // byte order: '10' < '9'
        ->and((new LtreePath('01'))->compareLexically('1'))->toBe(-1); // distinct labels, never 0
});
