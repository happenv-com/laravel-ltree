<?php

declare(strict_types=1);

use Happenv\Ltree\Exceptions\LtreeException;
use Happenv\Ltree\Exceptions\UnsupportedIndexException;

it('is an ltree exception', function () {
    expect(new UnsupportedIndexException('x'))->toBeInstanceOf(LtreeException::class);
});
