<?php

declare(strict_types=1);

use Happenv\Ltree\Exceptions\InvalidLabelException;
use Happenv\Ltree\Normalization\DefaultNormalizer;

it('replaces hyphens with underscores by default', function () {
    $n = new DefaultNormalizer;
    // A UUID: hyphens become underscores, hex kept.
    expect($n->normalize('3f2504e0-4f89-41d3-9a0c-0305e82c3301'))
        ->toBe('3f2504e0_4f89_41d3_9a0c_0305e82c3301');
});

it('casts integer ids to string labels unchanged', function () {
    expect((new DefaultNormalizer)->normalize(234))->toBe('234');
});

it('replaces any other illegal character with underscore', function () {
    expect((new DefaultNormalizer)->normalize('Gaming & Laptops!'))
        ->toBe('Gaming___Laptops_');
});

it('keeps already-valid labels intact', function () {
    expect((new DefaultNormalizer)->normalize('electronics_2'))->toBe('electronics_2');
});

it('throws in throw-strategy on a char the replacement map does not cover', function () {
    // '!' is not in the default replacements, so it survives to the illegal-char check
    // and throw-strategy rejects it. (A '-' would be replaced by '_' first and never throw.)
    (new DefaultNormalizer(strategy: 'throw'))->normalize('a!b');
})->throws(InvalidLabelException::class);

it('rejects empty values', function () {
    // Assert the exact message from the *first* empty-check (an empty input),
    // to distinguish it from the second empty-check's message (a value that
    // normalizes down to empty) — both throw the same exception class.
    (new DefaultNormalizer)->normalize('');
})->throws(InvalidLabelException::class, 'An ltree label cannot be empty.');

it('honours a custom replacement map', function () {
    $n = new DefaultNormalizer(replacements: ['-' => '', ' ' => '-']);
    // '-' removed first, then remaining illegal '-' (from the space) replaced by '_' in replace mode.
    expect($n->normalize('a-b c'))->toBe('ab_c');
});

it('throws when the value normalizes to an empty label', function () {
    // A replacement map that deletes the only character leaves nothing behind.
    (new DefaultNormalizer(replacements: ['-' => '']))->normalize('-');
})->throws(InvalidLabelException::class);
