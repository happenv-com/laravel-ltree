<?php

declare(strict_types=1);

namespace Happenv\Ltree\Contracts;

interface Normalizer
{
    public function normalize(int|string $value): string;
}
