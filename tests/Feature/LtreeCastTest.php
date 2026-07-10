<?php

declare(strict_types=1);

use Happenv\Ltree\Casts\LtreeCast;
use Happenv\Ltree\ValueObjects\LtreePath;
use Illuminate\Database\Eloquent\Model;

/** A throwaway model just to satisfy the cast signature. */
function ltreeCastModel(): Model
{
    return new class extends Model {};
}

it('gets an LtreePath from a stored string', function () {
    $value = (new LtreeCast)->get(ltreeCastModel(), 'path', 'a.b.c', []);
    expect($value)->toBeInstanceOf(LtreePath::class)
        ->and($value->toString())->toBe('a.b.c');
});

it('returns null for a null column on get', function () {
    expect((new LtreeCast)->get(ltreeCastModel(), 'path', null, []))->toBeNull();
});

it('sets from an LtreePath, string, array, or Collection', function () {
    $cast = new LtreeCast;
    expect($cast->set(ltreeCastModel(), 'path', new LtreePath('a.b'), []))->toBe('a.b')
        ->and($cast->set(ltreeCastModel(), 'path', 'a.b', []))->toBe('a.b')
        ->and($cast->set(ltreeCastModel(), 'path', ['a', 'b'], []))->toBe('a.b')
        ->and($cast->set(ltreeCastModel(), 'path', collect(['a', 'b']), []))->toBe('a.b')
        ->and($cast->set(ltreeCastModel(), 'path', null, []))->toBeNull();
});

it('serializes to a dotted string for model arrays/json', function () {
    $cast = new LtreeCast;
    expect($cast->serialize(ltreeCastModel(), 'path', new LtreePath('a.b'), []))->toBe('a.b')
        ->and($cast->serialize(ltreeCastModel(), 'path', null, []))->toBeNull();
});

it('reuses the same LtreePath instance instead of rebuilding it', function () {
    // toLtreePath() is private; its short-circuit is unobservable from set()'s
    // return value alone (an LtreePath rebuilt from another LtreePath has the
    // same segments either way — LtreePath's own constructor already
    // special-cases `self`). Assert the actual invariant via reflection:
    // passing an existing LtreePath must return that *same* instance, not a
    // new, value-equal one.
    $path = new LtreePath('a.b');
    $toLtreePath = new ReflectionMethod(LtreeCast::class, 'toLtreePath');

    expect($toLtreePath->invoke(null, $path))->toBe($path);
});

it('rejects an unsupported set value type', function () {
    (new LtreeCast)->set(ltreeCastModel(), 'path', 123, []);
})->throws(InvalidArgumentException::class);

it('rejects a non-string value on get', function () {
    (new LtreeCast)->get(ltreeCastModel(), 'path', 123, []);
})->throws(InvalidArgumentException::class);

it('rejects a non-string, non-LtreePath value on serialize', function () {
    (new LtreeCast)->serialize(ltreeCastModel(), 'path', 123, []);
})->throws(InvalidArgumentException::class);
