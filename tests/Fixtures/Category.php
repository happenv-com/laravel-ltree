<?php

declare(strict_types=1);

namespace Happenv\Ltree\Tests\Fixtures;

use Happenv\Ltree\Concerns\HasLtree;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasLtree;

    protected $guarded = [];

    public $timestamps = false;
}
