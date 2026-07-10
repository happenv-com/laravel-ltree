<?php

declare(strict_types=1);

namespace Happenv\Ltree\Events;

use Illuminate\Database\Eloquent\Model;

final readonly class CascadeDeleted
{
    /**
     * @param  array<int, int|string>  $keys
     */
    public function __construct(
        public Model $node,
        public array $keys,
    ) {}
}
