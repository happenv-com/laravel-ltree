<?php

declare(strict_types=1);

use Happenv\Ltree\Exceptions\InvalidLabelException;
use Happenv\Ltree\Exceptions\LtreeException;

it('defines an ltree exception hierarchy', function () {
    expect(new LtreeException('x'))->toBeInstanceOf(RuntimeException::class);
    expect(new InvalidLabelException('x'))->toBeInstanceOf(LtreeException::class);
});
