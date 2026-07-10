<?php

declare(strict_types=1);

use Happenv\Ltree\Events\Moving;
use Happenv\Ltree\Exceptions\InvalidMoveException;
use Happenv\Ltree\Exceptions\LtreeException;
use Happenv\Ltree\Exceptions\MissingSortColumnException;
use Happenv\Ltree\Tests\Fixtures\Category;
use Happenv\Ltree\Tests\TestCase;

uses(TestCase::class);

it('wires the operations trait and its support types', function () {
    // HasLtree stays a zero-config, interface-free trait: models are NOT required
    // to `implements Ltreeable` (it is an internal Model&Ltreeable narrowing
    // contract). Assert the trait's helpers are mixed in, not `instanceof`.
    expect(method_exists(new Category, 'withLtreeTransaction'))->toBeTrue()
        ->and(method_exists(new Category, 'ltreePathColumnRaw'))->toBeTrue()
        ->and(is_subclass_of(InvalidMoveException::class, LtreeException::class))->toBeTrue()
        ->and(is_subclass_of(MissingSortColumnException::class, LtreeException::class))->toBeTrue()
        ->and(class_exists(Moving::class))->toBeTrue();
});
