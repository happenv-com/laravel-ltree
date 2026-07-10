<?php

declare(strict_types=1);

namespace Happenv\Ltree\Events;

use Illuminate\Database\Eloquent\Model;

final readonly class Copied
{
    public function __construct(
        public Model $original,
        public Model $copy,
    ) {}
}
