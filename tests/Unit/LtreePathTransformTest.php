<?php

declare(strict_types=1);

use Happenv\Ltree\ValueObjects\LtreePath;

it('appends and prepends without mutating the original', function () {
    $p = new LtreePath('a.b');
    expect($p->append('c')->toString())->toBe('a.b.c')
        ->and($p->prepend('z')->toString())->toBe('z.a.b')
        ->and($p->toString())->toBe('a.b'); // unchanged
});

it('appends and prepends another path', function () {
    expect((new LtreePath('a'))->append(new LtreePath('b.c'))->toString())->toBe('a.b.c')
        ->and((new LtreePath('c'))->prepend(new LtreePath('a.b'))->toString())->toBe('a.b.c');
});

it('pops the last and shifts the first segment', function () {
    $p = new LtreePath('a.b.c');
    expect($p->pop()->toString())->toBe('a.b')
        ->and($p->shift()->toString())->toBe('b.c');
});

it('implodes with a custom separator', function () {
    expect((new LtreePath('a.b.c'))->implode('/'))->toBe('a/b/c');
});

it('is immutable — transforms return distinct new instances', function () {
    $p1 = new LtreePath('a.b');
    $p2 = $p1->append('c');
    expect($p1)->not->toBe($p2)
        ->and($p1->toString())->toBe('a.b')      // original unchanged
        ->and($p2->toString())->toBe('a.b.c');
});
