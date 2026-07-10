<?php

declare(strict_types=1);

namespace Happenv\Ltree\Events;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

final readonly class CascadeDeleting
{
    /**
     * @param  Collection<int, Model>  $nodes
     */
    public function __construct(
        public Model $node,
        public Collection $nodes,
    ) {}
}
