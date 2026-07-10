<?php

declare(strict_types=1);

use Happenv\Ltree\Exceptions\LtreeException;
use Happenv\Ltree\ValueObjects\LtreePath;

it('builds from a dotted string', function () {
    $p = new LtreePath('electronics.laptop.asus');
    expect($p->segments())->toBe(['electronics', 'laptop', 'asus'])
        ->and($p->depth())->toBe(3)
        ->and($p->toString())->toBe('electronics.laptop.asus')
        ->and((string) $p)->toBe('electronics.laptop.asus');
});

it('builds from an array of labels', function () {
    expect((new LtreePath(['a', 'b']))->toString())->toBe('a.b');
});

it('builds from any iterable without importing Laravel', function () {
    expect((new LtreePath(new ArrayIterator(['a', 'b'])))->toString())->toBe('a.b');
});

it('casts numeric labels to strings', function () {
    expect((new LtreePath([1, 15, 234]))->segments())->toBe(['1', '15', '234']);
});

it('is constructible from another LtreePath', function () {
    $p = new LtreePath('a.b');
    expect((new LtreePath($p))->toString())->toBe('a.b');
});

it('exposes of() and empty() factories', function () {
    expect(LtreePath::of('a.b')->depth())->toBe(2)
        ->and(LtreePath::empty()->isEmpty())->toBeTrue()
        ->and(LtreePath::empty()->depth())->toBe(0);
});

it('explodes from a custom separator', function () {
    expect(LtreePath::explode('a/b/c', '/')->segments())->toBe(['a', 'b', 'c']);
});

it('explodes an empty string to an empty path', function () {
    // explode('', ...) short-circuits to []; without the guard, PHP's
    // explode('.', '') returns [''], and an empty segment is rejected by
    // normalizeInput() — so this also proves the guard prevents a spurious
    // LtreeException on the empty path.
    expect(LtreePath::explode('')->isEmpty())->toBeTrue()
        ->and(LtreePath::explode('')->depth())->toBe(0);
});

it('is countable and iterable', function () {
    $p = new LtreePath('a.b.c');
    expect(count($p))->toBe(3)
        ->and(iterator_to_array($p))->toBe(['a', 'b', 'c']);
});

it('serializes to the dotted string for json', function () {
    $p = new LtreePath('electronics.laptop');
    expect($p->jsonSerialize())->toBe('electronics.laptop')
        ->and(json_encode($p))->toBe('"electronics.laptop"');
});

it('rejects empty segments', function () {
    new LtreePath('a..b');
})->throws(LtreeException::class);

it('rejects a segment containing the separator', function () {
    new LtreePath(['a.b', 'c']);
})->throws(LtreeException::class);
