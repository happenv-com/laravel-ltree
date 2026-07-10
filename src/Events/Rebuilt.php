<?php

declare(strict_types=1);

namespace Happenv\Ltree\Events;

use Illuminate\Database\Eloquent\Model;

final readonly class Rebuilt
{
    public function __construct(
        public Model $node,
    ) {}
}
